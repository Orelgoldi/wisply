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
        $now      = current_time( 'mysql' );

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
            $headers[] = 'Reply-To: ' . sanitize_text_field( $name ) . ' <' . $email . '>';
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        return new WP_REST_Response( [ 'success' => true, 'mail_sent' => (bool) $sent ], 200 );
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
