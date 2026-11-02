<?php
defined( 'ABSPATH' ) || exit;

/**
 * Licensing + auto-updates.
 *
 * Talks to the Wisply licence server (WISPLY_API_URL) with the customer's key.
 * Design rule: FAIL-OPEN. If our server is unreachable the bot keeps working —
 * a paying customer must never lose the product because WE had an outage.
 * The bot is only gated when the server EXPLICITLY rejects the key (and even
 * then a previously-valid licence keeps working for GRACE_DAYS).
 */
class Wisply_License {

    private static ?self $instance = null;
    private Wisply_Database $db;

    /** Days a previously-active licence keeps working after the first rejection. */
    private const GRACE_DAYS = 7;

    /**
     * The ONLY answers that count as "this key is rejected" and may gate the bot.
     *
     * This list is a whitelist on purpose. Anything else — a soft error, a 400, an
     * empty body, a reason we do not recognise, a future server version — is treated
     * as "unknown" and fails OPEN. A licence server must never be able to disable a
     * paying customer's bot by accident; only by saying so, in words we already know.
     *
     * 'bad_site' is deliberately absent: a home_url() we cannot parse is our problem,
     * not grounds to punish the customer.
     */
    private const REJECTIONS = [ 'not_found', 'suspended', 'canceled', 'expired', 'quota', 'not_activated' ];

    /**
     * How many chat languages each plan may run at once. A local mirror of the
     * server's plan_max_langs(), used only as a fallback when a response does not
     * carry an explicit max_langs field (older server, partial payload).
     */
    private const PLAN_MAX_LANGS = [ 'spark' => 1, 'lite' => 1, 'business' => 4, 'pro' => 4, 'enterprise' => 4 ];

    /** How often admin_init is allowed to phone home. */
    private const CHECK_EVERY = 12 * HOUR_IN_SECONDS;

    /** How long the /api/update answer is cached. */
    private const UPDATE_CACHE = 6 * HOUR_IN_SECONDS;

    private const TRANSIENT_CHECK  = 'wisply_license_checked';
    private const TRANSIENT_UPDATE = 'wisply_update_info';

    /**
     * Master / demo keys. Entering one of these activates the plugin INSTANTLY and
     * OFFLINE — full access, all languages, no call to the licence server. For live
     * demos and internal use, so the bot never depends on wisply.io being reachable.
     * Compared case-insensitively (get_key() upper-cases the input).
     */
    private const MASTER_KEYS = [
        'WISPLY-MASTER-2026',
        'WSP-DEMO-FULL-ACCESS',
        'WISPLY-DEMO-GOLDSTEIN',
    ];

    /** True when the stored key is one of the offline master/demo keys. */
    private function is_master(): bool {
        return in_array( $this->get_key(), self::MASTER_KEYS, true );
    }

    private function __construct() {
        $this->db = Wisply_Database::get_instance();

        // Daily cron — registered on every load so the callback exists when WP-Cron fires
        if ( ! wp_next_scheduled( 'wisply_license_check' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wisply_license_check' );
        }
        add_action( 'wisply_license_check', [ $this, 'check' ] );

        // Opportunistic re-check, at most once every 12h
        add_action( 'admin_init', [ $this, 'maybe_check' ] );

        add_action( 'admin_notices', [ $this, 'admin_notice' ] );

        // Auto-updates
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function api_base(): string {
        return untrailingslashit( defined( 'WISPLY_API_URL' ) ? WISPLY_API_URL : 'https://wisply.vercel.app' );
    }

    public function get_key(): string {
        return strtoupper( trim( (string) $this->db->get_setting( 'license_key', '' ) ) );
    }

    private function site(): string {
        return home_url();
    }

    private function plugin_slug(): string {
        return dirname( plugin_basename( WISPLY_PLUGIN_FILE ) );
    }

    /**
     * POST to the licence server. Returns the decoded array, or null when the
     * server could not be reached / answered with something unusable. null is
     * ALWAYS treated as "unknown", never as a rejection.
     */
    private function post( string $path, string $key ): ?array {
        $res = wp_remote_post( $this->api_base() . $path, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'key' => $key, 'site' => $this->site() ] ),
        ] );

        if ( is_wp_error( $res ) ) return null;
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code >= 500 || $code === 0 ) return null; // our fault → unknown, not a rejection

        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        return is_array( $data ) ? $data : null;
    }

    /** Persist the outcome of a server answer. */
    private function store_status( string $status, string $message ): void {
        if ( $status === 'active' ) {
            // Remember that this licence HAS worked here. Tracked separately from the
            // live status, which any passing blip overwrites with 'unknown' — if the
            // grace window keyed off the status alone it would essentially never arm,
            // because a 12-hourly poll almost always records an 'unknown' before a
            // real rejection ever lands.
            $this->db->set_setting( 'license_ever_active', '1' );
            $this->db->set_setting( 'license_grace_until', '0' );
        }

        if ( $status === 'invalid' ) {
            $ever  = (string) $this->db->get_setting( 'license_ever_active', '0' ) === '1';
            $grace = (int) $this->db->get_setting( 'license_grace_until', 0 );

            // Arm the window once, on the first rejection of a licence that once worked.
            // Re-arming on every rejection would roll it forward forever; a key that has
            // never worked here (typo, wrong key) gets no grace at all.
            if ( $ever && $grace === 0 ) {
                $this->db->set_setting( 'license_grace_until', (string) ( time() + self::GRACE_DAYS * DAY_IN_SECONDS ) );
            }
        }

        $this->db->set_setting( 'license_status', $status );
        $this->db->set_setting( 'license_message', $message );

        // Only a real answer from the server counts as a successful check
        if ( $status !== 'unknown' ) {
            $this->db->set_setting( 'license_last_check', (string) time() );
        }
    }

    /**
     * Normalise a server answer into our stored state and hand it back verbatim.
     *
     * Rejection is opt-in, never inferred: we gate only on a reason we recognise.
     * A falsy `ok` on its own is NOT enough — the server returns ok:false with
     * status/reason "unknown" when IT is broken, and reading that as a rejection is
     * exactly how our outage would take a paying customer's bot down.
     */
    private function apply( ?array $res ): array {
        $unreachable = 'לא הצלחנו ליצור קשר עם שרת הרישיונות. הבוט ממשיך לפעול כרגיל.';

        if ( $res === null ) {
            $this->store_status( 'unknown', $unreachable );
            return [ 'ok' => false, 'status' => 'unknown', 'message' => $unreachable ];
        }

        // Learn the plan's language allowance from any real answer that carries it
        // (active OR a rejection like 'quota' still names the plan). STICKY: we only
        // ever overwrite with a value we were told — an 'unknown' never resets it, so
        // a confirmed Business customer keeps 4 languages straight through our outage.
        $ml = $this->max_langs_from_response( $res );
        if ( $ml > 0 ) $this->db->set_setting( 'license_max_langs', (string) $ml );

        $message = (string) ( $res['message'] ?? '' );

        if ( ! empty( $res['ok'] ) ) {
            $this->store_status( 'active', $message !== '' ? $message : 'הרישיון פעיל.' );
            return $res;
        }

        $reason = (string) ( $res['reason'] ?? '' );

        if ( in_array( $reason, self::REJECTIONS, true ) ) {
            $this->store_status( 'invalid', $message !== '' ? $message : 'הרישיון לא אומת מול השרת.' );
        } else {
            // Soft error, malformed body, or a reason from a newer server we don't
            // know yet → we simply could not tell. Fail open.
            $this->store_status( 'unknown', $message !== '' ? $message : $unreachable );
        }

        return $res;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /** Activate this site against a key. Called after the key is saved in the admin. */
    public function activate( string $key ): array {
        $key = strtoupper( trim( $key ) );
        if ( $key === '' ) {
            $this->store_status( 'unknown', 'לא הוזן מפתח רישיון.' );
            return [ 'ok' => false, 'status' => 'unknown', 'message' => 'לא הוזן מפתח רישיון.' ];
        }
        // Master / demo key: activate offline, full access, no server round-trip.
        if ( in_array( $key, self::MASTER_KEYS, true ) ) {
            $this->db->set_setting( 'license_max_langs', '4' );
            $this->store_status( 'active', 'רישיון דמו/מאסטר פעיל (ללא שרת).' );
            return [ 'ok' => true, 'status' => 'active', 'message' => 'רישיון דמו/מאסטר פעיל.' ];
        }
        delete_transient( self::TRANSIENT_UPDATE );
        set_transient( self::TRANSIENT_CHECK, 1, self::CHECK_EVERY );
        return $this->apply( $this->post( '/api/license/activate', $key ) );
    }

    /**
     * Daily cron + throttled admin check. Never throws, never echoes.
     *
     * Self-healing on purpose. Only activate() creates the server-side activation
     * row, and it runs once, when the key is saved. If that one call happened to
     * land during an outage of ours, the row was never created — and from then on
     * every check would come back 'not_activated', which is a rejection, on a
     * licence that had never been 'active' here and so gets no grace. A single
     * blip on our side would kill a paying customer's bot permanently.
     *
     * So a 'not_activated' answer is read as "this site still needs registering",
     * not as a verdict, and we register it. license_activate is idempotent for a
     * site it already knows (on conflict → touch last_seen_at), so the retry costs
     * nothing when the row does exist. It also repairs a site that moved domain,
     * as long as the plan still has a free slot.
     */
    public function check(): void {
        $key = $this->get_key();
        if ( $key === '' ) return;

        // Master / demo key never phones home — keep it active, all languages.
        if ( $this->is_master() ) {
            $this->db->set_setting( 'license_max_langs', '4' );
            $this->store_status( 'active', 'רישיון דמו/מאסטר פעיל (ללא שרת).' );
            return;
        }

        $res = $this->post( '/api/license/check', $key );

        if ( is_array( $res ) && ( $res['reason'] ?? '' ) === 'not_activated' ) {
            $res = $this->post( '/api/license/activate', $key );
        }

        $this->apply( $res );
    }

    // Deliberately no deactivate() here: freeing a seat requires a logged-in owner,
    // so it lives in the customer portal. The plugin holds only a key, and a key is
    // not authority to release someone's site.

    /** admin_init: re-check at most once every 12 hours. */
    public function maybe_check(): void {
        if ( $this->get_key() === '' ) return;
        if ( get_transient( self::TRANSIENT_CHECK ) ) return;
        set_transient( self::TRANSIENT_CHECK, 1, self::CHECK_EVERY );
        $this->check();
    }

    /**
     * THE GATE. Answers "may this install serve the bot?"
     *
     *   no key  → true  (see below)
     *   active  → true
     *   invalid → false, once the 7-day grace window of a licence that once worked
     *             here has run out. Explicit rejections only.
     *   unknown → true  (fail-open: our outage must never break a customer's bot)
     *
     * On "no key": an install that has NEVER had a working licence keeps working.
     * That is deliberate — otherwise every existing site would go dark the moment it
     * updates to this version, before anyone had a key to paste. Loud notice, no gate.
     *
     * But an install where a licence once WAS active and the key is now gone is a
     * different animal: that is someone deleting the key to escape the gate. Without
     * the ever_active test, clearing the field was a permanent, irreversible bypass —
     * check() and maybe_check() both bail on an empty key, so the site would never
     * phone home again and could never re-gate itself.
     */
    public function is_valid(): bool {
        if ( $this->is_master() ) return true;   // offline demo/master key

        if ( $this->get_key() === '' ) {
            return (string) $this->db->get_setting( 'license_ever_active', '0' ) !== '1';
        }

        $status = (string) $this->db->get_setting( 'license_status', 'unknown' );

        if ( $status === 'active' ) return true;

        if ( $status === 'invalid' ) {
            $grace = (int) $this->db->get_setting( 'license_grace_until', 0 );
            return $grace > 0 && time() < $grace; // recently-valid licence keeps working
        }

        return true; // unknown / never checked → fail-open
    }

    /** Read a plan's language allowance out of a server response (explicit field, or via plan). */
    private function max_langs_from_response( array $res ): int {
        if ( isset( $res['max_langs'] ) && is_numeric( $res['max_langs'] ) ) {
            return max( 0, (int) $res['max_langs'] );
        }
        $plan = strtolower( trim( (string) ( $res['plan'] ?? '' ) ) );
        return self::PLAN_MAX_LANGS[ $plan ] ?? 0;
    }

    /**
     * How many chat languages this install may run at once.
     *
     * Defaults to 1 when we have never heard a real answer — languages beyond the
     * first are the premium feature, and a fresh install's enabled set is a single
     * language anyway, so a cap of 1 changes nothing until a qualifying plan is
     * confirmed. Once confirmed the value is sticky (see apply()).
     */
    public function max_langs(): int {
        if ( $this->is_master() ) return 4;      // demo/master → all languages
        $stored = (int) $this->db->get_setting( 'license_max_langs', 0 );
        return $stored > 0 ? $stored : 1;
    }

    /** Where the licence field lives. */
    private function settings_url(): string {
        return admin_url( 'admin.php?page=wisply-settings' );
    }

    public function admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $key = $this->get_key();
        $url = esc_url( $this->settings_url() );

        if ( $key === '' ) {
            echo '<div class="notice notice-info" dir="rtl"><p>'
                . '🔑 הזינו מפתח רישיון כדי להפעיל עדכונים אוטומטיים ותמיכה — '
                . '<a href="' . $url . '">מעבר להגדרות</a>.'
                . '</p></div>';
            return;
        }

        if ( $this->is_valid() ) return;

        $msg = (string) $this->db->get_setting( 'license_message', '' );
        echo '<div class="notice notice-warning" dir="rtl"><p>'
            . '⚠️ הרישיון של ' . esc_html( defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply' ) . ' אינו פעיל'
            . ( $msg !== '' ? ' — ' . esc_html( $msg ) : '' ) . '. '
            . '<a href="' . $url . '">בדקו את מפתח הרישיון בהגדרות</a>.'
            . '</p></div>';
    }

    // ─── Auto-updates ─────────────────────────────────────────────────────────

    /**
     * Ask the server what the current release is. Cached for 6h so we do not hit
     * the API on every admin page load. Returns null on any problem.
     */
    private function get_remote_update(): ?array {
        $key = $this->get_key();
        if ( $key === '' ) return null;

        $cached = get_transient( self::TRANSIENT_UPDATE );
        if ( is_array( $cached ) ) return $cached;
        if ( $cached === 'none' ) return null; // negative cache — don't retry for 6h

        $url = add_query_arg( [
            'key'     => rawurlencode( $key ),
            'site'    => rawurlencode( $this->site() ),
            'version' => rawurlencode( WISPLY_VERSION ),
        ], $this->api_base() . '/api/update' );

        $res = wp_remote_get( $url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
            set_transient( self::TRANSIENT_UPDATE, 'none', self::UPDATE_CACHE );
            return null;
        }

        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $data ) || empty( $data['version'] ) ) {
            set_transient( self::TRANSIENT_UPDATE, 'none', self::UPDATE_CACHE );
            return null;
        }

        set_transient( self::TRANSIENT_UPDATE, $data, self::UPDATE_CACHE );
        return $data;
    }

    /** Add our plugin to the WordPress update list when a newer release exists. */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) return $transient;

        try {
            $info = $this->get_remote_update();
            if ( ! $info ) return $transient;
            if ( empty( $info['package'] ) ) return $transient;
            if ( version_compare( (string) $info['version'], WISPLY_VERSION, '<=' ) ) return $transient;

            $basename = plugin_basename( WISPLY_PLUGIN_FILE );

            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = [];
            }
            $transient->response[ $basename ] = (object) [
                'slug'        => $this->plugin_slug(),
                'plugin'      => $basename,
                'new_version' => (string) $info['version'],
                'package'     => (string) $info['package'],
                'url'         => (string) ( $info['url'] ?? 'https://goldstein.studio' ),
                'tested'      => (string) ( $info['tested'] ?? '' ),
                'requires'    => (string) ( $info['requires'] ?? '' ),
            ];
        } catch ( Throwable $e ) {
            // Never block the update screen because of us
        }

        return $transient;
    }

    /** "View details" popup. */
    public function plugin_information( $result, $action = '', $args = null ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! is_object( $args ) || empty( $args->slug ) || $args->slug !== $this->plugin_slug() ) return $result;

        try {
            $info = $this->get_remote_update();
            if ( ! $info ) return $result;

            return (object) [
                'name'     => defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply',
                'slug'     => $this->plugin_slug(),
                'version'  => (string) $info['version'],
                'author'   => '<a href="https://goldstein.studio">Goldstein Studio</a>',
                'requires' => (string) ( $info['requires'] ?? '' ),
                'tested'   => (string) ( $info['tested'] ?? '' ),
                'download_link' => (string) ( $info['package'] ?? '' ),
                'sections' => [
                    'changelog' => wp_kses_post( (string) ( $info['changelog'] ?? 'אין פרטי עדכון.' ) ),
                ],
            ];
        } catch ( Throwable $e ) {
            return $result;
        }
    }
}
