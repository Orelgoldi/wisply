<?php
defined( 'ABSPATH' ) || exit;

class M360_Chatbot_API {

    private static ?self $instance = null;
    private M360_Database  $db;
    private M360_AI_Handler $ai;

    private function __construct() {
        $this->db = M360_Database::get_instance();
        $this->ai = M360_AI_Handler::get_instance();
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Route registration ───────────────────────────────────────────────────

    public function register_routes(): void {
        $ns = 'medical360/v1';

        // Public: send a message
        register_rest_route( $ns, '/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'message'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'session_id' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'lang'       => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
                'page_url'   => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ],
            ],
        ] );

        // Public: leave contact details (lead capture)
        register_rest_route( $ns, '/lead', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_lead' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'name'       => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'phone'      => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'email'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                'message'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_id' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'lang'       => [ 'required' => false, 'type' => 'string', 'default' => 'he' ],
                'page_url'   => [ 'required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' ],
            ],
        ] );

        // Public: speech-to-text (Whisper) — accepts base64 audio, returns text
        register_rest_route( $ns, '/voice/transcribe', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_transcribe' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'audio' => [ 'required' => true,  'type' => 'string' ], // base64
                'mime'  => [ 'required' => false, 'type' => 'string', 'default' => 'audio/webm', 'sanitize_callback' => 'sanitize_text_field' ],
                'lang'  => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: text-to-speech (OpenAI TTS) — accepts text, returns base64 mp3
        register_rest_route( $ns, '/voice/speak', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_speak' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'text' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: Realtime (speech-to-speech) — mint ephemeral WebRTC token
        register_rest_route( $ns, '/voice/realtime-token', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_realtime_token' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'lang' => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: Realtime tool callback — grounded content lookup
        register_rest_route( $ns, '/voice/lookup', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_voice_lookup' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'query' => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'lang'  => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: page-aware suggested questions
        register_rest_route( $ns, '/page-questions', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_page_questions' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'title' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'url'   => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
                'lang'  => [ 'required' => false, 'type' => 'string', 'default' => 'he', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Admin: get all conversations (requires manage_options)
        register_rest_route( $ns, '/admin/conversations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_conversations' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Admin: export CSV
        register_rest_route( $ns, '/admin/export', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'admin_export_csv' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Admin: save settings
        register_rest_route( $ns, '/admin/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_save_settings' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Admin: trigger reindex
        register_rest_route( $ns, '/admin/reindex', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_reindex' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );

        // Admin: test AI connection
        register_rest_route( $ns, '/admin/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'admin_test_connection' ],
            'permission_callback' => [ $this, 'admin_check' ],
        ] );
    }

    // ─── Permission callbacks ─────────────────────────────────────────────────

    public function admin_check(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Rate limit + origin check for all public endpoints.
     * Limit: 20 requests / 60 s per IP (chat is expensive, lead is spam-prone).
     */
    public function rate_limit_check( WP_REST_Request $request ): bool|WP_Error {
        if ( ! $this->origin_allowed() ) {
            return new WP_Error( 'forbidden_origin', 'Forbidden', [ 'status' => 403 ] );
        }

        $ip    = $this->get_ip();
        $key   = 'medical360_rl_' . md5( $ip . wp_salt( 'auth' ) );
        $count = (int) get_transient( $key );
        // A single voice exchange = transcribe + chat + speak (3 calls), so allow headroom
        $limit = 45;

        if ( $count >= $limit ) {
            return new WP_Error( 'rate_limit', 'Too many requests', [ 'status' => 429 ] );
        }

        set_transient( $key, $count + 1, 60 );
        return true;
    }

    // ─── Public: /chat ────────────────────────────────────────────────────────

    public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
        $message    = $request->get_param( 'message' );
        $session_id = $request->get_param( 'session_id' );
        $lang       = $request->get_param( 'lang' );
        $page_url   = $request->get_param( 'page_url' );
        $ip_hash    = md5( $this->get_ip() . wp_salt() );

        if ( mb_strlen( $message ) > 500 ) {
            return new WP_REST_Response( [ 'error' => 'Message too long' ], 400 );
        }

        // Get or create the session's conversation
        $conv_id = $this->db->get_or_create_conversation( $session_id, $lang, $ip_hash, $page_url );

        // Load recent history
        $history = $this->db->get_conversation_messages( $conv_id, 8 );

        // Store user message
        $this->db->add_message( $conv_id, 'user', $message );

        // Get AI reply
        $result = $this->ai->get_reply( $message, $lang, $history );

        // Store assistant message, flag unanswered if needed
        $this->db->add_message( $conv_id, 'assistant', $result['reply'], $result['unanswered'] );

        return new WP_REST_Response( [
            'reply'      => $result['reply'],
            'unanswered' => $result['unanswered'],
            'sources'    => $result['sources'] ?? [],
        ], 200 );
    }

    // ─── Public: /lead ────────────────────────────────────────────────────────

    public function handle_lead( WP_REST_Request $request ): WP_REST_Response {
        $name    = $request->get_param( 'name' );
        $phone   = $request->get_param( 'phone' );
        $email   = $request->get_param( 'email' );
        $message = $request->get_param( 'message' );
        $lang    = $request->get_param( 'lang' );
        $session = (string) $request->get_param( 'session_id' );

        if ( empty( $name ) || empty( $phone ) ) {
            return new WP_REST_Response( [ 'error' => 'Name and phone are required' ], 400 );
        }

        // ── Marketing consent (Opt-In, FR-006) ──
        $consent_required = $this->db->get_setting( 'consent_required', '1' ) !== '0';
        $consent          = (int) $request->get_param( 'consent' ) === 1;
        if ( $consent_required && ! $consent ) {
            return new WP_REST_Response( [ 'error' => 'Marketing consent is required' ], 422 );
        }
        $consent_version = (string) $this->db->get_setting( 'consent_version', '1.0' );
        $consent_text    = sanitize_text_field( (string) $request->get_param( 'consent_text' ) );

        // ── Context Engine (FR-001) ──
        $department   = sanitize_text_field( (string) $request->get_param( 'department' ) );
        $utm_source   = sanitize_text_field( (string) $request->get_param( 'utm_source' ) );
        $utm_medium   = sanitize_text_field( (string) $request->get_param( 'utm_medium' ) );
        $utm_campaign = sanitize_text_field( (string) $request->get_param( 'utm_campaign' ) );
        $referrer     = esc_url_raw( (string) $request->get_param( 'referrer' ) );
        $landing      = esc_url_raw( (string) $request->get_param( 'landing_page' ) );
        $page_url     = esc_url_raw( (string) $request->get_param( 'page_url' ) );
        $source       = $utm_source ?: ( $referrer ? 'referral' : 'direct' );

        // Pull the conversation context — WHY did they leave details, about what?
        $ctx_rows = $session ? $this->db->get_session_context( $session, 12 ) : [];
        $context  = $this->format_lead_context( $ctx_rows );
        $interest = $this->lead_interest( $ctx_rows );          // last thing the user asked about
        $summary  = $this->ai->summarize_conversation( $ctx_rows, $lang ); // FR-007 auto-summary
        // Classify: job-seeker vs marketing inquiry (from the conversation + interest + dept)
        $lead_type = $this->classify_lead_type( $context . ' ' . $interest . ' ' . $department . ' ' . $message );
        // Route to the correct Logicare branch (סניף) + department (מחלקה) by page
        $page_title_param = sanitize_text_field( (string) $request->get_param( 'page_title' ) );
        $route     = $this->resolve_logicare_route( $lead_type, $page_title_param, $department );
        $now      = current_time( 'mysql' );

        // Save the lead with the full data model (section 12)
        $leads   = get_option( 'm360_leads', [] );
        $leads[] = [
            'name'               => $name,
            'phone'              => $phone,
            'email'              => $email,
            'message'            => $message,
            'lang'               => $lang,
            'time'               => $now,
            'page'               => $page_url,
            'interest'           => $interest,
            'interest_category'  => $department ?: $interest,
            'context'            => $context,
            'summary'            => $summary,
            'source'             => $source,
            'channel'            => 'web',
            'department'         => $department,
            'campaign'           => $utm_campaign,
            'utm_source'         => $utm_source,
            'utm_medium'         => $utm_medium,
            'referrer'           => $referrer,
            'landing_page'       => $landing,
            'marketing_consent'  => $consent ? 1 : 0,
            'consent_version'    => $consent_version,
            'consent_text'       => $consent_text,
            'consent_time'       => $consent ? $now : '',
            'conversation_length'=> count( $ctx_rows ),
            'lead_status'        => 'new',
            'lead_type'          => $lead_type,   // 'job' | 'marketing'
            'crm_department_id'  => $route['department_id'],    // Logicare מחלקה (id)
            'crm_department'     => $route['department_name'],  // Logicare מחלקה (שם)
            'crm_branch'         => $route['home_name'],        // Logicare סניף
        ];
        // Push to the Logicare CRM (if configured) and record the outcome on the lead
        $crm = $this->send_to_logicare( [
            'name' => $name, 'phone' => $phone, 'email' => $email,
            'summary' => $summary, 'context' => $context, 'interest' => $interest,
            'source' => $source, 'campaign' => $utm_campaign,
            'landing_name' => $department,
            'route' => $route,
        ] );
        $leads[ array_key_last( $leads ) ]['logicare'] = $crm['status'];

        if ( count( $leads ) > 1000 ) {
            $leads = array_slice( $leads, -1000 );
        }
        update_option( 'm360_leads', $leads, false );

        // Audit log (section 13/20)
        error_log( sprintf(
            '[Medical360] Lead captured: %s | consent=%s v%s @ %s | source=%s | dept=%s',
            $phone, $consent ? 'yes' : 'no', $consent_version, $now, $source, $department ?: '-'
        ) );

        // Send an HTML email — like an Elementor form notification
        $to      = get_option( 'm360_lead_email' ) ?: get_option( 'admin_email' );
        $subject = 'ליד חדש מהעוזר החכם';

        // Context block: the subject of interest + the conversation that led to the lead
        $summary_html = $summary
            ? '<p><strong>סיכום השיחה:</strong> ' . esc_html( $summary ) . '</p>'
            : '';
        $interest_html = $interest
            ? '<p><strong>נושא ההתעניינות:</strong> <span style="background:#E0F5F5;padding:2px 8px;border-radius:6px">' . esc_html( $interest ) . '</span></p>'
            : '';
        $meta_bits = array_filter( [
            $department   ? 'מחלקה: ' . esc_html( $department ) : '',
            $source       ? 'מקור: ' . esc_html( $source ) : '',
            $utm_campaign ? 'קמפיין: ' . esc_html( $utm_campaign ) : '',
        ] );
        $meta_html = $meta_bits ? '<p style="color:#555">' . implode( ' · ', $meta_bits ) . '</p>' : '';
        $consent_html = '<p><strong>הסכמה שיווקית (Opt-In):</strong> '
            . ( $consent
                ? '<span style="color:#0a8f3c">✓ אושרה</span> (גרסה ' . esc_html( $consent_version ) . ', ' . esc_html( $now ) . ')'
                : '<span style="color:#b32d2e">✗ לא אושרה — אסור לפנות שיווקית</span>' )
            . '</p>';
        $context_html = $context
            ? '<h3 style="color:#007878;margin:18px 0 6px">הקשר השיחה (למה השאיר ליד):</h3>'
              . '<div style="background:#f7fafa;border:1px solid #e0eeee;border-radius:10px;padding:12px 14px;font-size:14px">'
              . $this->context_to_html( $ctx_rows )
              . '</div>'
            : '';

        $body    = sprintf(
            "<div dir=\"rtl\" style=\"font-family:Arial,sans-serif;font-size:15px;line-height:1.7\">"
            . "<h2 style=\"color:#00A3A3\">ליד חדש מהעוזר החכם 🤖</h2>"
            . "<p><strong>שם מלא:</strong> %s</p>"
            . "<p><strong>טלפון:</strong> <a href=\"tel:%s\">%s</a></p>"
            . "<p><strong>אימייל:</strong> %s</p>"
            . "<p><strong>הודעה:</strong> %s</p>"
            . "%s%s%s%s"  // summary, interest, meta, consent
            . "%s"        // context transcript
            . "<hr><p style=\"color:#888;font-size:13px\">שפה: %s · התקבל: %s · מהעמוד: %s</p>"
            . "</div>",
            esc_html( $name ),
            esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ), esc_html( $phone ),
            esc_html( $email ?: '—' ),
            nl2br( esc_html( $message ?: '—' ) ),
            $summary_html,
            $interest_html,
            $meta_html,
            $consent_html,
            $context_html,
            esc_html( $lang ),
            esc_html( $now ),
            esc_html( $page_url )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        // Reply-To the lead's email if provided, so you can reply directly
        if ( $email && is_email( $email ) ) {
            $headers[] = 'Reply-To: ' . sanitize_text_field( $name ) . ' <' . $email . '>';
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        return new WP_REST_Response( [ 'success' => true, 'mail_sent' => (bool) $sent ], 200 );
    }

    /**
     * Classify a lead as a job-seeker ('job') or a marketing inquiry ('marketing'),
     * based on career/recruitment keywords in the conversation.
     */
    private function classify_lead_type( string $text ): string {
        $t = mb_strtolower( $text );
        $job_kw = [
            'דרוש', 'דרושים', 'משרה', 'משרות', 'קריירה', 'גיוס', 'מגייס', 'מועמד',
            'קורות חיים', 'קו״ח', 'קו"ח', 'להגיש מועמדות', 'מחפש עבודה', 'מחפשת עבודה',
            'job', 'career', 'hiring', 'vacancy', 'recruit', 'employment', 'resume', 'cv',
            'ваканс', 'работу', 'карьер', 'резюме',
        ];
        foreach ( $job_kw as $k ) {
            if ( mb_strpos( $t, mb_strtolower( $k ) ) !== false ) return 'job';
        }
        return 'marketing';
    }

    /**
     * Logicare department catalog (from the CRM export "סניפים ומחלקות").
     * id => [ name, home_id (branch id), home (branch name) ].
     * Each department belongs to a fixed branch, so a department_id fully determines
     * the branch (סניף) too. Update this if departments change in Logicare.
     */
    private const LOGICARE_DEPARTMENTS = [
        // מדיקל קר - בית חולים (home 179)
        202 => [ 'name' => 'החלמה',                    'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        264 => [ 'name' => 'החלמה לאחר ניתוח',         'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        266 => [ 'name' => 'החלמה- לב',                'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        265 => [ 'name' => 'החלמה-סכרת',               'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        267 => [ 'name' => 'חוסן - צור קשר',           'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        218 => [ 'name' => 'כללי לא מסווג',            'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        263 => [ 'name' => 'מדיקל קר - דרושים',        'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        199 => [ 'name' => 'מונשמים',                  'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        216 => [ 'name' => 'מחלקות אשפוז',             'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        197 => [ 'name' => 'סיעודי',                   'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        198 => [ 'name' => 'סיעודי מורכב א',           'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        297 => [ 'name' => 'סיעודי מורכב ב',           'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        217 => [ 'name' => 'פרא רפואי',                'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        200 => [ 'name' => 'שיקום גריאטרי',            'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        243 => [ 'name' => 'שיקום יום',                'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        253 => [ 'name' => 'שיקום צעירים 4',           'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        201 => [ 'name' => 'שיקום צעירים 7',           'home_id' => 179, 'home' => 'מדיקל קר - בית חולים' ],
        // בית פז סדנאות (home 8)
        141 => [ 'name' => 'החלמה',                    'home_id' => 8,   'home' => 'בית פז סדנאות' ],
        189 => [ 'name' => 'חוסן',                     'home_id' => 8,   'home' => 'בית פז סדנאות' ],
        241 => [ 'name' => 'חיילים',                   'home_id' => 8,   'home' => 'בית פז סדנאות' ],
        220 => [ 'name' => 'פרא רפואי',                'home_id' => 8,   'home' => 'בית פז סדנאות' ],
    ];

    /** Build the routing record for a department id (name + branch, from the catalog). */
    private function logicare_department_record( string $id, string $name_override = '' ): array {
        $rec = self::LOGICARE_DEPARTMENTS[ (int) $id ] ?? null;
        return [
            'department_id'   => $id,
            'department_name' => $name_override !== '' ? $name_override : ( $rec['name'] ?? '' ),
            'home_id'         => $rec ? (string) $rec['home_id'] : '',
            'home_name'       => $rec['home'] ?? '',
        ];
    }

    /**
     * Decide which Logicare department (מחלקה) a lead belongs to, based on the page —
     * driven by an admin-editable routing table. The branch (סניף) follows from the
     * department. Rules setting `logicare_routing_rules`: one rule per line:
     *   keyword | department_id            (or)  keyword | department_id | department_name
     * First rule whose keyword appears in the page title/department wins. Lines
     * starting with # are comments. Job-seekers route to the recruitment department.
     */
    private function resolve_logicare_route( string $lead_type, string $page_title, string $department ): array {
        $default_id = trim( (string) $this->db->get_setting( 'logicare_default_department_id', '' ) );

        // Job-seekers always go to the recruitment (דרושים) department
        if ( $lead_type === 'job' ) {
            $jid = trim( (string) $this->db->get_setting( 'logicare_job_department_id', '' ) );
            if ( $jid !== '' ) return $this->logicare_department_record( $jid );
        }

        $hay = mb_strtolower( trim( $page_title . ' ' . $department ) );
        $rules = (string) $this->db->get_setting( 'logicare_routing_rules', '' );
        foreach ( preg_split( '/\r\n|\r|\n/', $rules ) as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) continue;
            $parts = array_map( 'trim', explode( '|', $line ) );
            if ( count( $parts ) < 2 || $parts[1] === '' ) continue;
            $kw = mb_strtolower( $parts[0] );
            if ( $kw !== '' && mb_strpos( $hay, $kw ) !== false ) {
                return $this->logicare_department_record( $parts[1], $parts[2] ?? '' );
            }
        }
        // No rule matched — fall back to the default department
        return $this->logicare_department_record( $default_id );
    }

    /**
     * Push a captured lead to the Logicare CRM (POST /logicare/api/new_lead/).
     * Returns [ 'status' => sent|failed:…|error:…|disabled|not_configured ].
     */
    private function send_to_logicare( array $lead ): array {
        if ( $this->db->get_setting( 'logicare_enabled', '0' ) !== '1' ) {
            return [ 'status' => 'disabled' ];
        }
        $base = rtrim( (string) $this->db->get_setting( 'logicare_base_url', '' ), '/' );
        $key  = (string) $this->db->get_setting( 'logicare_api_key', '' );
        if ( $base === '' || $key === '' ) {
            return [ 'status' => 'not_configured' ];
        }

        $details = trim( (string) ( $lead['summary'] ?? '' ) );
        if ( ! empty( $lead['context'] ) ) {
            $details .= ( $details !== '' ? "\n\n" : '' ) . $lead['context'];
        }

        // Route the lead to the right Logicare department (מחלקה) + branch (סניף).
        // The department_id is primary (it also fixes the branch); name + home fields
        // are sent under several key variants so whichever the Zapier endpoint maps
        // wins. Unmapped keys are ignored by Logicare.
        $route     = is_array( $lead['route'] ?? null ) ? $lead['route'] : [];
        $dept_id   = trim( (string) ( $route['department_id'] ?? '' ) );
        $dept_name = trim( (string) ( $route['department_name'] ?? '' ) );
        $home_id   = trim( (string) ( $route['home_id'] ?? '' ) );
        $home_name = trim( (string) ( $route['home_name'] ?? '' ) );

        $payload = [
            'api_key'         => $key,
            'name'            => (string) ( $lead['name'] ?? '' ),
            'phone1'          => preg_replace( '/[^\d+]/', '', (string) ( $lead['phone'] ?? '' ) ),
            'details'         => mb_substr( $details, 0, 5000 ),
            'referrer_notes'  => mb_substr( (string) ( $lead['interest'] ?? '' ), 0, 300 ),
            'referrer'        => (string) ( $lead['source'] ?? '' ) ?: 'בוט האתר',
            'campaign'        => (string) ( $lead['campaign'] ?? '' ),
            'landing'         => (string) ( $lead['landing_name'] ?? '' ),
            // Department (מחלקה) — id is primary, name variants as fallback
            'department_id'   => $dept_id,
            'department'      => $dept_id,
            'department_name' => $dept_name,
            // Branch / home (סניף) — derived from the department
            'home_id'         => $home_id,
            'branch_id'       => $home_id,
            'home_name'       => $home_name,
            'branch_name'     => $home_name,
            'branch'          => $home_name,
        ];
        if ( ! empty( $lead['email'] ) && is_email( $lead['email'] ) ) {
            $payload['email'] = $lead['email'];
        }
        // Drop empty optional fields (keep required ones)
        foreach ( [ 'referrer_notes', 'campaign', 'landing', 'department_id', 'department', 'department_name', 'home_id', 'branch_id', 'home_name', 'branch_name', 'branch' ] as $opt ) {
            if ( ( $payload[ $opt ] ?? '' ) === '' ) unset( $payload[ $opt ] );
        }

        $resp = wp_remote_post( $base . '/logicare/api/new_lead/', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $resp ) ) {
            error_log( '[Medical360] Logicare error: ' . $resp->get_error_message() );
            return [ 'status' => 'error: ' . $resp->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code === 201 && ! empty( $body['success'] ) ) {
            error_log( '[Medical360] Logicare: lead sent (' . ( $body['response'] ?? 'new' ) . ')' );
            return [ 'status' => 'sent' ];
        }
        $err = is_array( $body ) ? wp_json_encode( $body ) : substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 );
        error_log( '[Medical360] Logicare failed (HTTP ' . $code . '): ' . $err );
        return [ 'status' => 'failed: HTTP ' . $code ];
    }

    /** Validate the Logicare API key/base via /logicare/api/auth/ (used by System Check). */
    public function logicare_test(): array {
        $base = rtrim( (string) $this->db->get_setting( 'logicare_base_url', '' ), '/' );
        $key  = (string) $this->db->get_setting( 'logicare_api_key', '' );
        if ( $base === '' || $key === '' ) {
            return [ 'ok' => false, 'detail' => 'לא הוגדר base URL או מפתח Logicare' ];
        }
        $resp = wp_remote_post( $base . '/logicare/api/auth/', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'api_key' => $key ] ),
        ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'detail' => $resp->get_error_message() ];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! empty( $body['success'] ) ) {
            return [ 'ok' => true, 'detail' => 'מחובר: ' . ( $body['company'] ?? $body['name'] ?? '' ) ];
        }
        return [ 'ok' => false, 'detail' => 'מפתח/כתובת לא תקינים (HTTP ' . wp_remote_retrieve_response_code( $resp ) . ')' ];
    }

    /** Plain-text transcript of the conversation context, stored with the lead. */
    private function format_lead_context( array $rows ): string {
        $lines = [];
        foreach ( $rows as $r ) {
            $who  = ( $r['role'] ?? '' ) === 'user' ? 'הפונה' : 'הבוט';
            $text = trim( preg_replace( '/\[(SHOW_LEAD_FORM|ACTION:[a-z_]+)\]/i', '', (string) ( $r['content'] ?? '' ) ) );
            if ( $text !== '' ) $lines[] = $who . ': ' . $text;
        }
        return implode( "\n", $lines );
    }

    /** HTML version of the transcript for the notification email. */
    private function context_to_html( array $rows ): string {
        $out = '';
        foreach ( $rows as $r ) {
            $is_user = ( $r['role'] ?? '' ) === 'user';
            $text    = trim( preg_replace( '/\[(SHOW_LEAD_FORM|ACTION:[a-z_]+)\]/i', '', (string) ( $r['content'] ?? '' ) ) );
            if ( $text === '' ) continue;
            $label = $is_user ? 'הפונה' : 'הבוט';
            $color = $is_user ? '#007878' : '#555';
            $out  .= '<p style="margin:4px 0"><strong style="color:' . $color . '">' . $label . ':</strong> ' . esc_html( $text ) . '</p>';
        }
        return $out ?: '—';
    }

    /** The lead's topic of interest = their last question/statement before leaving details. */
    private function lead_interest( array $rows ): string {
        for ( $i = count( $rows ) - 1; $i >= 0; $i-- ) {
            if ( ( $rows[ $i ]['role'] ?? '' ) === 'user' ) {
                $t = trim( (string) ( $rows[ $i ]['content'] ?? '' ) );
                return mb_substr( $t, 0, 120 );
            }
        }
        return '';
    }

    // ─── Public: /voice/transcribe (Whisper STT) ──────────────────────────────

    public function handle_transcribe( WP_REST_Request $request ): WP_REST_Response {
        $b64  = (string) $request->get_param( 'audio' );
        $mime = (string) $request->get_param( 'mime' );
        $lang = (string) $request->get_param( 'lang' );

        // Strip an optional data-URL prefix ("data:audio/webm;base64,")
        if ( str_contains( $b64, ',' ) ) {
            $b64 = substr( $b64, strpos( $b64, ',' ) + 1 );
        }

        // Cap payload at ~8 MB of audio (base64 is ~33% larger) to prevent abuse
        if ( strlen( $b64 ) > 11 * 1024 * 1024 ) {
            return new WP_REST_Response( [ 'error' => 'Audio too large' ], 413 );
        }

        $audio = base64_decode( $b64, true );
        if ( $audio === false || $audio === '' ) {
            return new WP_REST_Response( [ 'error' => 'Invalid audio' ], 400 );
        }

        $result = $this->ai->transcribe_audio( $audio, $mime, $lang );
        if ( ! $result['success'] ) {
            return new WP_REST_Response( [ 'error' => $result['error'] ?? 'Transcription failed' ], 502 );
        }
        return new WP_REST_Response( [ 'text' => $result['text'] ], 200 );
    }

    // ─── Public: /voice/speak (OpenAI TTS) ─────────────────────────────────────

    public function handle_speak( WP_REST_Request $request ): WP_REST_Response {
        $text = (string) $request->get_param( 'text' );
        $lang = (string) $request->get_param( 'lang' );

        if ( trim( $text ) === '' ) {
            return new WP_REST_Response( [ 'error' => 'Empty text' ], 400 );
        }

        $result = $this->ai->synthesize_speech( $text, $lang );
        if ( ! $result['success'] ) {
            return new WP_REST_Response( [ 'error' => $result['error'] ?? 'Speech failed' ], 502 );
        }
        return new WP_REST_Response( [ 'audio' => $result['audio'], 'mime' => $result['mime'] ], 200 );
    }

    // ─── Public: /voice/realtime-token ─────────────────────────────────────────

    public function handle_realtime_token( WP_REST_Request $request ): WP_REST_Response {
        // Extra guardrail: real-time sessions are expensive, so cap how many a
        // single IP can open (6 per 10 min) on top of the shared rate limiter.
        $ip   = $this->get_ip();
        $key  = 'm360_rt_' . md5( $ip . wp_salt( 'auth' ) );
        $opened = (int) get_transient( $key );
        if ( $opened >= 6 ) {
            return new WP_REST_Response( [ 'error' => 'Too many voice sessions. Please try again later.' ], 429 );
        }
        set_transient( $key, $opened + 1, 600 );

        $lang   = (string) $request->get_param( 'lang' );
        $result = $this->ai->create_realtime_session( $lang );
        if ( ! $result['success'] ) {
            return new WP_REST_Response( [ 'error' => $result['error'] ?? 'Failed' ], 502 );
        }
        return new WP_REST_Response( [
            'client_secret' => $result['client_secret'],
            'expires_at'    => $result['expires_at'],
            'model'         => $result['model'],
            'voice'         => $result['voice'],
        ], 200 );
    }

    // ─── Public: /voice/lookup (Realtime tool callback) ────────────────────────

    public function handle_voice_lookup( WP_REST_Request $request ): WP_REST_Response {
        $query = (string) $request->get_param( 'query' );
        $lang  = (string) $request->get_param( 'lang' );
        if ( trim( $query ) === '' ) {
            return new WP_REST_Response( [ 'text' => '' ], 200 );
        }
        $text = $this->ai->retrieve_context( $query, $lang );
        if ( $text === '' ) {
            $text = 'לא נמצא מידע רלוונטי באתר לשאלה זו.';
        }
        return new WP_REST_Response( [ 'text' => $text ], 200 );
    }

    // ─── Public: /page-questions ───────────────────────────────────────────────

    public function handle_page_questions( WP_REST_Request $request ): WP_REST_Response {
        $title = (string) $request->get_param( 'title' );
        $url   = (string) $request->get_param( 'url' );
        $lang  = (string) $request->get_param( 'lang' );
        if ( trim( $title ) === '' && trim( $url ) === '' ) {
            return new WP_REST_Response( [ 'questions' => [] ], 200 );
        }
        $questions = $this->ai->generate_page_questions( $title, $url, $lang );
        return new WP_REST_Response( [ 'questions' => $questions ], 200 );
    }

    // ─── Admin: conversations ─────────────────────────────────────────────────

    public function admin_conversations( WP_REST_Request $request ): WP_REST_Response {
        $page    = max( 1, (int) $request->get_param( 'page' ) );
        $filters = [
            'lang'        => sanitize_text_field( $request->get_param( 'lang' ) ?? '' ),
            'unanswered'  => (bool) $request->get_param( 'unanswered' ),
            'date_from'   => sanitize_text_field( $request->get_param( 'date_from' ) ?? '' ),
            'date_to'     => sanitize_text_field( $request->get_param( 'date_to' ) ?? '' ),
        ];

        $data = $this->db->get_conversations_paginated( $page, 20, $filters );
        return new WP_REST_Response( $data, 200 );
    }

    // ─── Admin: CSV export ────────────────────────────────────────────────────

    public function admin_export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $csv = $this->db->export_conversations_csv();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="medical360-conversations-' . date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // BOM for Excel Hebrew support
        echo $csv;
        exit;
    }

    // ─── Admin: save settings ─────────────────────────────────────────────────

    public function admin_save_settings( WP_REST_Request $request ): WP_REST_Response {
        $allowed = [
            'ai_provider', 'ai_api_key', 'ai_model',
            'openai_api_key', 'openai_model',
            'primary_color', 'secondary_color', 'font_family', 'bubble_position',
            'greeting_he', 'greeting_en', 'greeting_ru',
            'widget_title_he', 'widget_title_en', 'widget_title_ru',
            'phone', 'map_url', 'max_context_docs', 'conversation_ttl_days',
            'voice_enabled', 'voice_provider', 'tts_model', 'tts_voice', 'stt_model',
            'realtime_enabled', 'realtime_model', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay', 'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru',
            'consent_required', 'consent_version', 'consent_text_he', 'consent_text_en', 'consent_text_ru',
            'emergency_msg_he', 'emergency_msg_en', 'emergency_msg_ru',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
            'logicare_enabled', 'logicare_base_url', 'logicare_api_key',
            'report_recipients', 'report_daily', 'report_weekly',
        ];

        $params      = $request->get_json_params();
        $secret_keys = [ 'ai_api_key', 'openai_api_key', 'logicare_api_key' ];
        $errors      = [];

        foreach ( $allowed as $key ) {
            if ( ! isset( $params[ $key ] ) ) continue;
            $val = sanitize_text_field( (string) $params[ $key ] );

            // For secret fields: skip if empty or masked (user didn't change it)
            if ( in_array( $key, $secret_keys, true ) ) {
                if ( empty( $val ) || str_contains( $val, '•' ) ) continue;
            }

            try {
                $this->db->set_setting( $key, $val );
            } catch ( Throwable $e ) {
                $errors[] = $key . ': ' . $e->getMessage();
            }
        }

        if ( ! empty( $errors ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => implode( '; ', $errors ),
            ], 500 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    // ─── Admin: reindex ───────────────────────────────────────────────────────

    public function admin_reindex(): WP_REST_Response {
        M360_Content_Indexer::get_instance();
        $result = M360_Content_Indexer::get_instance()->full_reindex();
        return new WP_REST_Response( array_merge( [ 'success' => true ], $result ), 200 );
    }

    // ─── Admin: test connection ───────────────────────────────────────────────

    public function admin_test_connection(): WP_REST_Response {
        $result = M360_AI_Handler::get_instance()->test_connection();
        return new WP_REST_Response( $result, $result['success'] ? 200 : 500 );
    }

    // ─── Utility ──────────────────────────────────────────────────────────────

    /**
     * Returns a reliable client IP.
     * Only trusts X-Forwarded-For if the site owner explicitly opts in via
     * the M360_TRUST_PROXY constant in wp-config.php, preventing IP spoofing
     * that would let an attacker bypass the rate limiter.
     */
    private function get_ip(): string {
        if ( defined( 'M360_TRUST_PROXY' ) && M360_TRUST_PROXY ) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ( $forwarded ) {
                // Take the left-most (client) IP; validate it's actually an IP
                $candidate = trim( explode( ',', $forwarded )[0] );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    return $candidate;
                }
            }
        }
        // Default: trust only the direct connection IP — not spoofable
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Verifies the request originates from the site itself.
     * Blocks cross-site API abuse while allowing the embedded widget (same origin)
     * and WordPress REST nonce (logged-in users).
     */
    private function origin_allowed(): bool {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        // Requests with a valid WP REST nonce are already authenticated
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
        if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return true;
        }

        // Check HTTP Origin header (set by all modern browsers on cross-origin XHR)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( $origin ) {
            $origin_host = wp_parse_url( $origin, PHP_URL_HOST );
            return $origin_host === $site_host;
        }

        // Check Referer as secondary signal (no Origin on same-origin requests)
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ( $referer ) {
            $referer_host = wp_parse_url( $referer, PHP_URL_HOST );
            return $referer_host === $site_host;
        }

        // Direct server-to-server calls with no browser headers — block
        return false;
    }
}
