<?php
defined( 'ABSPATH' ) || exit;

class Wisply_Chatbot_API {

    private static ?self $instance = null;
    private Wisply_Database  $db;
    private Wisply_AI_Handler $ai;

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
        $this->ai = Wisply_AI_Handler::get_instance();
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
        $ns = 'wisply/v1';

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
                // Required-ness is decided in the handler — each field can be חובה/רשות/מוסתר
                'name'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'phone'      => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
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

        // Public: hydrate product cards for the IDs the AI emitted in [PRODUCTS: ...]
        register_rest_route( $ns, '/products', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_products' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'ids' => [ 'required' => true, 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
            ],
        ] );

        // Public: text product query for the model's [SUGGEST: ...] marker — powers the
        // complementary-product ("bundle") flow. Query in, matching product cards out.
        register_rest_route( $ns, '/product-query', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_product_query' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'query' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: order status lookup for the "where's my order" flow. Requires BOTH
        // order number and matching billing email (anti-enumeration), rate-limited.
        register_rest_route( $ns, '/order-status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_order_status' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'order_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'email'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Public: live-agent poll — the widget asks for new agent/system messages since a
        // given id while a human handoff is active, and whether it is still active.
        register_rest_route( $ns, '/agent-poll', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_agent_poll' ],
            'permission_callback' => [ $this, 'poll_permission' ],
            'args'                => [
                'session_id' => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'after'      => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            ],
        ] );

        // Public: visual product search — image in, matching products out
        register_rest_route( $ns, '/product-image-search', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_product_image_search' ],
            'permission_callback' => [ $this, 'rate_limit_check' ],
            'args'                => [
                'image' => [ 'required' => true,  'type' => 'string' ], // base64 data URL
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
     * Licence + rate limit + origin check for all public endpoints.
     * Limit: 20 requests / 60 s per IP (chat is expensive, lead is spam-prone).
     *
     * Every public route hangs off this one callback, which makes it the single
     * choke point for the licence. Admin routes use admin_check() and are never
     * gated — the owner must always be able to reach the settings screen and fix
     * their key. Wisply_License::is_valid() fails open on anything short of an
     * explicit rejection, so this cannot fire because our server had a bad day.
     */
    /**
     * Lighter gate for the live-agent poll: it fires every few seconds while a handoff is
     * open, so it must NOT burn the per-IP request budget (rate_limit_check). Licence and
     * origin are still enforced.
     */
    public function poll_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( class_exists( 'Wisply_License' ) && ! Wisply_License::get_instance()->is_valid() ) {
            return new WP_Error( 'license_inactive', 'הרישיון של הבוט אינו פעיל.', [ 'status' => 403 ] );
        }
        if ( ! $this->origin_allowed() ) {
            return new WP_Error( 'forbidden_origin', 'Forbidden', [ 'status' => 403 ] );
        }
        return true;
    }

    public function rate_limit_check( WP_REST_Request $request ): bool|WP_Error {
        if ( class_exists( 'Wisply_License' ) && ! Wisply_License::get_instance()->is_valid() ) {
            return new WP_Error(
                'license_inactive',
                'הרישיון של הבוט אינו פעיל.',
                [ 'status' => 403 ]
            );
        }

        if ( ! $this->origin_allowed() ) {
            return new WP_Error( 'forbidden_origin', 'Forbidden', [ 'status' => 403 ] );
        }

        $ip    = $this->get_ip();
        $key   = 'wisply_rl_' . md5( $ip . wp_salt( 'auth' ) );
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

        // ── Live-agent handoff: while this session is in human mode, the visitor talks to
        // a real agent (on WhatsApp), NOT the bot. Relay the message to the agent and skip
        // the AI entirely. The widget shows the agent's replies via /agent-poll.
        if ( class_exists( 'Wisply_WhatsApp' ) ) {
            $ho = Wisply_WhatsApp::get_instance()->handoff_state( $session_id );
            if ( is_array( $ho ) && ! empty( $ho['agent'] ) ) {
                $this->db->add_message( $conv_id, 'user', $message );
                Wisply_WhatsApp::get_instance()->notify( $ho['agent'], '👤 ' . mb_substr( $message, 0, 3000 ) );
                return new WP_REST_Response( [
                    'reply'              => '',
                    'human'              => true,
                    'unanswered'         => false,
                    'sources'            => [],
                    'conversation_ended' => false,
                ], 200 );
            }
        }

        // ── Free-trial gate (Wisply product monetization) ──
        // Inert unless a trial has actually been started by the setup wizard (status
        // '' / 'converted' → skipped entirely), so existing/paid installs are untouched.
        // The hard cap counts VISITOR-driven conversations (the first user message of a
        // session's conversation), never bot replies, and blocks the AI call the moment
        // the 14-day window or the 100-conversation cap is exceeded.
        if ( class_exists( 'Wisply_Trial' ) ) {
            $trial = Wisply_Trial::get_instance();
            if ( $trial->is_trial_mode() ) {
                $is_new_convo = $this->count_user_turns( $conv_id ) === 0;

                // Trial already over (time or cap) — pause gracefully, retain all data.
                if ( ! $trial->is_active() ) {
                    $trial->mark_ended();
                    return new WP_REST_Response( [
                        'reply'              => $trial->upgrade_message( $lang ),
                        'conversation_ended' => true,
                        'trial_ended'        => true,
                        'sources'            => [],
                    ], 200 );
                }

                // A brand-new conversation consumes one trial credit. If none remain,
                // this new conversation tips the trial over its cap → end it now.
                if ( $is_new_convo ) {
                    if ( $trial->conversations_left() <= 0 ) {
                        $trial->mark_ended();
                        return new WP_REST_Response( [
                            'reply'              => $trial->upgrade_message( $lang ),
                            'conversation_ended' => true,
                            'trial_ended'        => true,
                            'sources'            => [],
                        ], 200 );
                    }
                    $trial->increment_conversation();
                }
            }
        }

        // Message limit (0 = unlimited): the turn is the session's USER messages + this one.
        // Only counted when a limit is set, so the default path stays a single-query flow.
        $max_messages = (int) $this->db->get_setting( 'max_messages', '0' );
        $turn         = $max_messages > 0 ? $this->count_user_turns( $conv_id ) + 1 : 0;

        // Over the limit — the conversation is over: no AI call, no extra messages stored
        if ( $max_messages > 0 && $turn > $max_messages ) {
            return new WP_REST_Response( [
                'reply'              => $this->limit_reply( $lang ),
                'conversation_ended' => true,
                'sources'            => [],
            ], 200 );
        }

        // Load recent history
        $history = $this->db->get_conversation_messages( $conv_id, 8 );

        // Store user message
        $this->db->add_message( $conv_id, 'user', $message );

        // Get AI reply — page_url lets the AI ground its answer in the exact page the
        // visitor is viewing (so it can answer the questions shown on that page).
        $result = $this->ai->get_reply( $message, $lang, $history, $turn, (string) $page_url );

        // Human handoff: the bot flags [HANDOFF] (or the visitor clearly asked for a
        // person). When an agent number is set, hand them a click-to-WhatsApp link that
        // opens the agent's WhatsApp pre-filled with a short summary of what they wanted.
        $handoff = $this->build_handoff( (string) $result['reply'], $message, $conv_id, $session_id );
        $result['reply'] = $handoff['reply'];   // [HANDOFF] stripped
        // When the two-way bridge starts, the bot steps aside — use its short note if the
        // model didn't already write a hand-off sentence.
        if ( $handoff['bridge'] && trim( (string) $result['reply'] ) === '' ) {
            $result['reply'] = $handoff['note'];
            $result['unanswered'] = false;
        }

        // Store assistant message, flag unanswered if needed
        $this->db->add_message( $conv_id, 'assistant', $result['reply'], $result['unanswered'] );

        return new WP_REST_Response( [
            'reply'              => $result['reply'],
            'unanswered'         => $result['unanswered'],
            'sources'            => $result['sources'] ?? [],
            'product_ids'        => $result['product_ids'] ?? [],
            'show_products'      => $result['show_products'] ?? false,
            'handoff_url'        => $handoff['url'],
            'handoff_label'      => $handoff['label'],
            'human'              => $handoff['bridge'],
            'conversation_ended' => false,
        ], 200 );
    }

    /**
     * Decide whether to offer a WhatsApp handoff to a human agent, and build the
     * click-to-WhatsApp URL (pre-filled with a short chat summary). Returns the reply
     * with the [HANDOFF] marker stripped plus url/label ('' when not offered).
     *
     * @return array{reply:string,url:string,label:string}
     */
    private function build_handoff( string $reply, string $message, int $conv_id, string $session_id ): array {
        $marked = (bool) preg_match( '/\[HANDOFF\]/i', $reply );
        $reply  = trim( (string) preg_replace( '/\[HANDOFF\]/i', '', $reply ) );

        $out = [ 'reply' => $reply, 'url' => '', 'label' => '', 'bridge' => false, 'note' => '' ];

        if ( $this->db->get_setting( 'handoff_enabled', '0' ) !== '1' ) return $out;

        $asked = $marked || (bool) preg_match(
            '/נציג|נציגה|בן ?אדם|בנאדם|אדם אמיתי|מישהו אמיתי|אנושי|לדבר עם מישהו|human|representative|real person|live agent/iu',
            $message
        );
        if ( ! $asked ) return $out;

        $num = preg_replace( '/\D/', '', (string) $this->db->get_setting( 'handoff_wa_number', '' ) );
        if ( $num === '' ) return $out;

        // Two-way bridge when the WhatsApp Cloud API is configured: notify the agent and
        // put this website conversation into human mode. The visitor keeps chatting on the
        // site; the agent answers from WhatsApp. Falls back to a click-to-WhatsApp button
        // when the API isn't set up.
        $wa_ready = class_exists( 'Wisply_WhatsApp' )
            && $this->db->get_setting( 'wa_enabled', '0' ) === '1'
            && (string) $this->db->get_setting( 'wa_phone_number_id', '' ) !== ''
            && (string) $this->db->get_setting( 'wa_access_token', '' ) !== '';

        if ( $wa_ready ) {
            $wa = Wisply_WhatsApp::get_instance();
            $wa->start_handoff( $session_id, $conv_id, $num );
            $wa->notify( $num, $this->handoff_summary( $conv_id ) . "\n\n↩️ השב/י כאן וההודעה תופיע ללקוח באתר. לסיום כתוב/י \"סיום\"." );
            $out['bridge'] = true;
            $out['note']   = 'מעביר אותך לנציג/ה שלנו, הוא/היא יחזרו אליך כאן בעוד רגע.';
            return $out;
        }

        $out['url']   = 'https://wa.me/' . $num . '?text=' . rawurlencode( $this->handoff_summary( $conv_id ) );
        $label        = trim( (string) $this->db->get_setting( 'handoff_label', '' ) );
        $out['label'] = $label !== '' ? $label : 'המשך עם נציג בוואטסאפ';
        return $out;
    }

    /** Short WhatsApp opener with the visitor's last few questions, for the agent's context. */
    private function handoff_summary( int $conv_id ): string {
        $site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $lines = [ "היי, הגעתי מהצ׳אט של {$site} ואשמח לדבר עם נציג/ה." ];

        $qs = [];
        foreach ( (array) $this->db->get_conversation_messages( $conv_id, 12 ) as $m ) {
            if ( ( $m['role'] ?? '' ) === 'user' ) {
                $t = trim( (string) ( $m['content'] ?? '' ) );
                if ( $t !== '' ) $qs[] = $t;
            }
        }
        $qs = array_slice( array_values( array_unique( $qs ) ), -3 );
        if ( $qs ) {
            $lines[] = '';
            $lines[] = 'מה שכתבתי בצ׳אט:';
            foreach ( $qs as $q ) $lines[] = '• ' . mb_substr( $q, 0, 200 );
        }
        return implode( "\n", $lines );
    }

    /** Live-agent poll: new agent/system messages since $after, and whether still human. */
    public function handle_agent_poll( WP_REST_Request $request ): WP_REST_Response {
        $session = (string) $request->get_param( 'session_id' );
        $after   = (int) $request->get_param( 'after' );
        if ( $session === '' ) {
            return new WP_REST_Response( [ 'messages' => [], 'human' => false ], 200 );
        }

        $human = class_exists( 'Wisply_WhatsApp' )
            && Wisply_WhatsApp::get_instance()->handoff_state( $session ) !== null;

        $out     = [];
        $conv_id = $this->db->find_conversation( $session );
        if ( $conv_id > 0 ) {
            foreach ( $this->db->get_messages_after( $conv_id, $after, [ 'agent', 'system' ] ) as $m ) {
                $out[] = [ 'id' => (int) $m['id'], 'role' => (string) $m['role'], 'content' => (string) $m['content'] ];
            }
        }
        return new WP_REST_Response( [ 'messages' => $out, 'human' => $human ], 200 );
    }

    /** True when at least one contact field is visible, so we can demand one of them. */
    private function contact_required(): bool {
        return $this->db->get_setting( 'lead_field_phone', 'required' ) !== 'hidden'
            || $this->db->get_setting( 'lead_field_email', 'optional' ) !== 'hidden';
    }

    /**
     * Closing line when the message limit is reached. Localised, and it only promises
     * a call-back when a lead form is actually going to be shown.
     */
    private function limit_reply( string $lang ): string {
        $action = $this->db->get_setting( 'conversation_end_action', 'lead' );
        $asks   = in_array( $action, [ 'lead', 'both' ], true );
        $calls  = $action === 'call';

        return match ( $lang ) {
            'en' => $asks  ? 'Thanks for the chat! 🙏 Leave your details and we\'ll get back to you.'
                  : ( $calls ? 'Thanks for the chat! 🙏 Feel free to call us and we\'ll be happy to help.'
                             : 'Thanks for the chat! 🙏 Have a great day.' ),
            'ru' => $asks  ? 'Спасибо за беседу! 🙏 Оставьте контакты, и мы свяжемся с вами.'
                  : ( $calls ? 'Спасибо за беседу! 🙏 Звоните нам — будем рады помочь.'
                             : 'Спасибо за беседу! 🙏 Хорошего дня.' ),
            default => $asks ? 'תודה רבה על השיחה! 🙏 כדי להמשיך מכאן, השאירו פרטים ונחזור אליכם.'
                  : ( $calls ? 'תודה רבה על השיחה! 🙏 מוזמנים להתקשר אלינו ונשמח לעזור.'
                             : 'תודה רבה על השיחה! 🙏 יום נעים.' ),
        };
    }

    /** How many USER messages the conversation already holds (before the current one). */
    private function count_user_turns( int $conv_id ): int {
        $rows = $this->db->get_conversation_full( $conv_id );
        return count( array_filter( $rows, static fn( $r ) => ( $r['role'] ?? '' ) === 'user' ) );
    }

    // ─── Public: /lead ────────────────────────────────────────────────────────

    public function handle_lead( WP_REST_Request $request ): WP_REST_Response {
        $name    = $request->get_param( 'name' );
        $phone   = $request->get_param( 'phone' );
        $email   = $request->get_param( 'email' );
        $message = $request->get_param( 'message' );
        $lang    = $request->get_param( 'lang' );
        $session = (string) $request->get_param( 'session_id' );

        // ── Lead-form field modes (חובה / רשות / מוסתר) ──
        [ $name, $phone, $email, $missing ] = $this->apply_lead_field_modes( $name, $phone, $email );
        if ( $missing ) {
            return new WP_REST_Response( [ 'error' => 'חסרים שדות חובה: ' . implode( ', ', $missing ) ], 422 );
        }
        // A lead we cannot contact is worthless — but only enforce this when the admin
        // actually left a contact field visible. If both are hidden that's an explicit
        // (if odd) choice, and rejecting here would silently 422 every single lead, since
        // the widget has no field to offer. The widget mirrors this exact rule.
        if ( $this->contact_required() && $phone === '' && $email === '' ) {
            return new WP_REST_Response( [ 'error' => 'נדרש אמצעי יצירת קשר אחד לפחות — טלפון או אימייל.' ], 422 );
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
        $now      = current_time( 'mysql' );

        // Classify the inquiry: job-seeker vs. marketing lead — from the VISITOR's words only,
        // never the bot's replies (which may quote site content and cause a false job match).
        $user_said = implode( ' ', array_map(
            static fn( $r ) => (string) ( $r['content'] ?? '' ),
            array_filter( $ctx_rows, static fn( $r ) => ( $r['role'] ?? '' ) === 'user' )
        ) );
        $lead_type = $this->classify_lead_type( $user_said . ' ' . $interest . ' ' . $department . ' ' . $message );

        // Save the lead with the full data model (section 12)
        $leads   = get_option( 'wisply_leads', [] );
        $leads[] = [
            'name'               => $name,
            'phone'              => $phone,
            'email'              => $email,
            'message'            => $message,
            'lang'               => $lang,
            'time'               => $now,
            'page'               => $page_url,
            'lead_type'          => $lead_type,   // 'job' | 'marketing'
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
        ];

        if ( count( $leads ) > 1000 ) {
            $leads = array_slice( $leads, -1000 );
        }
        update_option( 'wisply_leads', $leads, false );

        // Audit log (section 13/20)
        error_log( sprintf(
            '[Wisply] Lead captured: %s | consent=%s v%s @ %s | source=%s | dept=%s',
            $phone, $consent ? 'yes' : 'no', $consent_version, $now, $source, $department ?: '-'
        ) );

        // Send an HTML email — like an Elementor form notification
        $to      = get_option( 'wisply_lead_email' ) ?: get_option( 'admin_email' );
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
            $headers[] = 'Reply-To: ' . ( $name !== ''
                ? sanitize_text_field( $name ) . ' <' . $email . '>'
                : $email );
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        return new WP_REST_Response( [ 'success' => true, 'mail_sent' => (bool) $sent ], 200 );
    }

    /**
     * Apply the admin's per-field modes to a submitted lead.
     * Each of name/phone/email is 'required' (חובה), 'optional' (רשות) or 'hidden' (מוסתר):
     * a hidden field is forced empty (the widget never shows it, so ignore what was sent),
     * a required field that arrived empty is collected as a missing field.
     * Returns [ name, phone, email, missing_field_labels ].
     */
    private function apply_lead_field_modes( ?string $name, ?string $phone, ?string $email ): array {
        $fields = [
            'name'  => [ 'value' => trim( (string) $name ),  'label' => 'שם',    'default' => 'required' ],
            'phone' => [ 'value' => trim( (string) $phone ), 'label' => 'טלפון', 'default' => 'required' ],
            'email' => [ 'value' => trim( (string) $email ), 'label' => 'אימייל', 'default' => 'optional' ],
        ];
        $missing = [];

        foreach ( $fields as $key => $field ) {
            $mode = (string) $this->db->get_setting( 'lead_field_' . $key, $field['default'] );
            if ( $mode === 'hidden' ) {
                $fields[ $key ]['value'] = '';
            } elseif ( $mode === 'required' && $field['value'] === '' ) {
                $missing[] = $field['label'];
            }
        }

        return [ $fields['name']['value'], $fields['phone']['value'], $fields['email']['value'], $missing ];
    }

    /**
     * Classify an inquiry as a job-seeker ('job') or a marketing lead ('marketing')
     * by scanning the conversation for career-related keywords (HE / EN / RU).
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
        $key  = 'wisply_rt_' . md5( $ip . wp_salt( 'auth' ) );
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

    // ─── Public: /products ────────────────────────────────────────────────────

    public function handle_products( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'Wisply_Woo' ) || ! Wisply_Woo::get_instance()->is_active() ) {
            return new WP_REST_Response( [ 'products' => [] ], 200 );
        }

        $ids = (array) $request->get_param( 'ids' );
        $ids = array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) );
        $ids = array_slice( $ids, 0, 8 );

        $woo      = Wisply_Woo::get_instance();
        $products = array_values( array_filter( array_map(
            static fn( $id ) => $woo->get_product( $id ),
            $ids
        ) ) );

        return new WP_REST_Response( [ 'products' => $products ], 200 );
    }

    // ─── Public: /product-query (text → products, for [SUGGEST:] bundle flow) ───

    public function handle_product_query( WP_REST_Request $request ): WP_REST_Response {
        if ( ! class_exists( 'Wisply_Woo' ) || ! Wisply_Woo::get_instance()->is_active() ) {
            return new WP_REST_Response( [ 'products' => [] ], 200 );
        }

        $query = trim( (string) $request->get_param( 'query' ) );
        if ( $query === '' ) {
            return new WP_REST_Response( [ 'products' => [] ], 200 );
        }

        $woo    = Wisply_Woo::get_instance();
        // Honour price/sort/category intent in the model's suggestion when present,
        // otherwise fall back to the scored text search.
        $intent = $woo->parse_query_intent( $query );
        $products = ! empty( $intent['has_filter'] )
            ? $woo->query_products( $intent, 6 )
            : $woo->search_products( $query, 6 );

        return new WP_REST_Response( [ 'products' => array_values( $products ) ], 200 );
    }

    // ─── Public: /order-status (order # + email → safe status summary) ─────────

    public function handle_order_status( WP_REST_Request $request ): WP_REST_Response {
        $enabled = $this->db->get_setting( 'woo_order_status_enabled', '1' ) === '1';
        if ( ! $enabled || ! class_exists( 'Wisply_Woo' ) || ! Wisply_Woo::get_instance()->is_active() ) {
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }

        // Accept "#1234" / "1234" and similar — pull the numeric id out.
        $order_id = (int) preg_replace( '/\D+/', '', (string) $request->get_param( 'order_id' ) );
        $email    = (string) $request->get_param( 'email' );

        $order = Wisply_Woo::get_instance()->lookup_order( $order_id, $email );
        if ( $order === null ) {
            // Generic — never reveal whether the id or the email was the mismatch.
            return new WP_REST_Response( [ 'found' => false ], 200 );
        }
        return new WP_REST_Response( [ 'found' => true, 'order' => $order ], 200 );
    }

    // ─── Public: /product-image-search ────────────────────────────────────────

    public function handle_product_image_search( WP_REST_Request $request ): WP_REST_Response {
        $enabled = $this->db->get_setting( 'woo_visual_search', '0' ) === '1';
        if ( ! $enabled || ! class_exists( 'Wisply_Woo' ) || ! Wisply_Woo::get_instance()->is_active() ) {
            return new WP_REST_Response( [ 'error' => 'disabled' ], 403 );
        }

        $image = (string) $request->get_param( 'image' );
        $lang  = (string) $request->get_param( 'lang' );

        if ( ! preg_match( '#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $image ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid image' ], 400 );
        }
        // Cap at ~3 MB of image (base64 is ~33% larger than the bytes it encodes)
        if ( strlen( $image ) > 4 * 1024 * 1024 ) {
            return new WP_REST_Response( [ 'error' => 'Image too large' ], 400 );
        }

        $description = $this->describe_image( $image, $lang );
        $products    = $description !== ''
            ? Wisply_Woo::get_instance()->search_products( $description, 4 )
            : [];

        $reply = $products
            ? 'מצאתי כמה מוצרים דומים למה שחיפשת:'
            : 'לא מצאתי מוצר דומה בחנות. אפשר לתאר לי במילים מה אתה מחפש?';

        return new WP_REST_Response( [
            'reply'    => $reply,
            'products' => $products,
            'query'    => $description,
        ], 200 );
    }

    /**
     * Turns a product photo into short search keywords via OpenAI vision.
     * Returns '' on any failure so the caller degrades to "no match found".
     */
    private function describe_image( string $data_url, string $lang ): string {
        $api_key = (string) $this->db->get_setting( 'openai_api_key', '' );
        if ( $api_key === '' ) {
            return '';
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'gpt-4o-mini',
                'max_tokens' => 60,
                'messages'   => [ [
                    'role'    => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "זהה את סוג הפריט בתמונה וכתוב אותו כמילה הראשונה, מתוך: שרשרת, טבעת, עגילים, צמיד, תליון, טבעת (אם זו תכשיט אחר, בחר את הקרוב ביותר). אחרי סוג הפריט הוסף 2-3 מילות תיאור קצרות (חומר/צבע, למשל: זהב, כסף). דוגמה: 'טבעת זהב אבן ירוקה'. החזר רק את המילים, בלי משפט.",
                        ],
                        [
                            'type'      => 'image_url',
                            'image_url' => [ 'url' => $data_url ],
                        ],
                    ],
                ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            error_log( '[Wisply] Image description failed: ' . ( is_wp_error( $response )
                ? $response->get_error_message()
                : wp_remote_retrieve_body( $response ) ) );
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['choices'][0]['message']['content'] ?? '';

        return sanitize_text_field( trim( (string) $text ) );
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
        header( 'Content-Disposition: attachment; filename="wisply-conversations-' . date( 'Y-m-d' ) . '.csv"' );
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
        ];

        $params      = $request->get_json_params();
        $secret_keys = [ 'ai_api_key', 'openai_api_key' ];
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
        Wisply_Content_Indexer::get_instance();
        $result = Wisply_Content_Indexer::get_instance()->full_reindex();
        return new WP_REST_Response( array_merge( [ 'success' => true ], $result ), 200 );
    }

    // ─── Admin: test connection ───────────────────────────────────────────────

    public function admin_test_connection(): WP_REST_Response {
        $result = Wisply_AI_Handler::get_instance()->test_connection();
        return new WP_REST_Response( $result, $result['success'] ? 200 : 500 );
    }

    // ─── Utility ──────────────────────────────────────────────────────────────

    /**
     * Returns a reliable client IP.
     * Only trusts X-Forwarded-For if the site owner explicitly opts in via
     * the WISPLY_TRUST_PROXY constant in wp-config.php, preventing IP spoofing
     * that would let an attacker bypass the rate limiter.
     */
    private function get_ip(): string {
        if ( defined( 'WISPLY_TRUST_PROXY' ) && WISPLY_TRUST_PROXY ) {
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
