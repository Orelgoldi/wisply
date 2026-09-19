<?php
defined( 'ABSPATH' ) || exit;

class Wisply_Admin {

    private static ?self $instance = null;
    private Wisply_Database $db;

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        // Leads CSV export — must run BEFORE any admin HTML is printed, otherwise the
        // download headers fail (headers already sent) and the file comes out as garbage.
        add_action( 'admin_init',             [ $this, 'maybe_export_leads' ] );
        // admin-ajax handlers (bypass REST API entirely — more reliable on restrictive hosts)
        add_action( 'wp_ajax_wisply_save_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_wisply_reindex',        [ $this, 'ajax_reindex' ] );
        add_action( 'wp_ajax_wisply_test_ai',        [ $this, 'ajax_test_ai' ] );
        add_action( 'wp_ajax_wisply_delete_indexed', [ $this, 'ajax_delete_indexed' ] );
        add_action( 'wp_ajax_wisply_add_source',     [ $this, 'ajax_add_source' ] );
        add_action( 'wp_ajax_wisply_test_voice',     [ $this, 'ajax_test_voice' ] );
        add_action( 'wp_ajax_wisply_system_check',   [ $this, 'ajax_system_check' ] );
        // Make sure content indexer is available when triggered from admin
        Wisply_Content_Indexer::get_instance();
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Menu ─────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        $product = defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply';
        add_menu_page(
            $product,
            $product,
            'manage_options',
            'wisply-chatbot',
            [ $this, 'page_dashboard' ],
            'dashicons-format-chat',
            25
        );
        add_submenu_page(
            'wisply-chatbot',
            'שיחות',
            'שיחות',
            'manage_options',
            'wisply-conversations',
            [ $this, 'page_conversations' ]
        );
        add_submenu_page(
            'wisply-chatbot',
            'לידים',
            'לידים',
            'manage_options',
            'wisply-leads',
            [ $this, 'page_leads' ]
        );
        add_submenu_page(
            'wisply-chatbot',
            'הגדרות',
            'הגדרות',
            'manage_options',
            'wisply-settings',
            [ $this, 'page_settings' ]
        );
        add_submenu_page(
            'wisply-chatbot',
            'אינדוקס תוכן',
            'אינדוקס תוכן',
            'manage_options',
            'wisply-index',
            [ $this, 'page_index' ]
        );
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wisply' ) === false ) return;

        // The avatar picker's "upload image" button uses the WP media modal.
        wp_enqueue_media();

        wp_enqueue_style(
            'wisply-admin',
            WISPLY_PLUGIN_URL . 'admin/css/admin.css',
            [ 'wp-components' ],
            WISPLY_VERSION
        );
        wp_enqueue_script(
            'wisply-admin',
            WISPLY_PLUGIN_URL . 'admin/js/admin.js',
            [ 'wp-api-fetch', 'wp-i18n' ],
            WISPLY_VERSION,
            true
        );
        $settings = $this->db->get_all_settings();
        // Never expose API keys / tokens to the browser — replace with a masked indicator
        foreach ( [ 'ai_api_key', 'openai_api_key', 'wa_access_token', 'wa_app_secret' ] as $k ) {
            if ( ! empty( $settings[ $k ] ) ) {
                $settings[ $k ] = '••••••••';
            }
        }

        wp_localize_script( 'wisply-admin', 'WisplyAdmin', [
            'apiBase'        => rest_url( 'wisply/v1' ),
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce'      => wp_create_nonce( 'wisply_admin_nonce' ),
            'settings'       => $settings,
            'unansweredCount'=> $this->db->get_unanswered_count(),
            'contentCount'   => $this->db->get_content_count(),
        ] );
    }

    // ─── Pages ────────────────────────────────────────────────────────────────

    /**
     * Force the browser/CDN/caching plugins NOT to cache our admin screens.
     * Without this, a cached settings page reloads with STALE values, making it
     * look like saved settings "revert" even though they persisted in the DB.
     */
    private function no_cache(): void {
        if ( ! headers_sent() ) {
            nocache_headers();
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }

    public function page_dashboard(): void {
        $this->no_cache();
        require WISPLY_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_conversations(): void {
        $this->no_cache();
        require WISPLY_PLUGIN_DIR . 'admin/views/conversations.php';
    }

    public function page_settings(): void {
        $this->no_cache();
        require WISPLY_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function page_index(): void {
        $this->no_cache();
        require WISPLY_PLUGIN_DIR . 'admin/views/content-index.php';
    }

    public function page_leads(): void {
        $this->no_cache();
        require WISPLY_PLUGIN_DIR . 'admin/views/leads.php';
    }

    /**
     * Stream the leads as a CSV (Excel-friendly, UTF-8 BOM) — columns mirror the
     * on-screen leads table. Runs on admin_init so the download headers are sent
     * before any HTML output (otherwise Excel shows gibberish).
     */
    public function maybe_export_leads(): void {
        if ( ( $_GET['page'] ?? '' ) !== 'wisply-leads' || ! isset( $_GET['export'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $type_labels = [ 'marketing' => 'שיווקי', 'job' => 'דרושים' ];
        $type_filter = in_array( $_GET['type'] ?? '', [ 'marketing', 'job' ], true ) ? $_GET['type'] : '';

        $leads = array_reverse( (array) get_option( 'wisply_leads', [] ) ); // newest first
        if ( $type_filter !== '' ) {
            $leads = array_values( array_filter(
                $leads, fn( $l ) => ( $l['lead_type'] ?? 'marketing' ) === $type_filter
            ) );
        }

        // Keep every lead on a single row: collapse newlines inside a cell
        $cell = static fn( $v ) => str_replace(
            '"', '""',
            trim( (string) preg_replace( '/[\r\n]+/u', ' / ', (string) ( $v ?? '' ) ) )
        );

        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        nocache_headers();
        $suffix = $type_filter ? '-' . $type_filter : '';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="wisply-leads' . $suffix . '-' . date( 'Y-m-d' ) . '.csv"' );

        echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads Hebrew correctly
        echo "תאריך,שם,סוג,טלפון,אימייל,התעניינות,סיכום,מחלקה,שיחה מלאה,מקור,קמפיין,Opt-In\n";
        foreach ( $leads as $l ) {
            $row = [
                $l['time'] ?? '',
                $l['name'] ?? '',
                $type_labels[ $l['lead_type'] ?? 'marketing' ] ?? 'שיווקי',
                $l['phone'] ?? '',
                $l['email'] ?? '',
                $l['interest'] ?? '',
                $l['summary'] ?? '',
                $l['department'] ?? '',
                $l['context'] ?? ( $l['message'] ?? '' ),
                $l['source'] ?? '',
                $l['campaign'] ?? '',
                ! empty( $l['marketing_consent'] ) ? 'כן' : 'לא',
            ];
            echo '"' . implode( '","', array_map( $cell, $row ) ) . "\"\n";
        }
        exit;
    }

    // ─── admin-ajax handlers ──────────────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $allowed     = [
            'license_key',
            'ai_provider', 'ai_api_key', 'ai_model', 'ai_custom_rules',
            'openai_api_key', 'openai_model',
            'primary_color', 'secondary_color', 'font_family', 'bubble_position', 'bot_avatar', 'bot_avatar_url',
            'greeting_he', 'greeting_en', 'greeting_ru',
            'widget_title_he', 'widget_title_en', 'widget_title_ru',
            'phone', 'map_url', 'max_context_docs', 'conversation_ttl_days',
            'voice_enabled', 'voice_provider', 'tts_model', 'tts_voice', 'stt_model',
            'realtime_enabled', 'realtime_model', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay', 'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru',
            'desktop_autoopen_enabled', 'desktop_autoopen_delay',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru',
            'consent_required', 'consent_version', 'consent_text_he', 'consent_text_en', 'consent_text_ru',
            'emergency_msg_he', 'emergency_msg_en', 'emergency_msg_ru',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
            'report_recipients', 'report_daily', 'report_weekly',
            'woo_enabled', 'woo_max_products', 'woo_show_stock', 'woo_visual_search', 'woo_bundle_enabled', 'woo_order_status_enabled',
            'wa_enabled', 'wa_phone_number_id', 'wa_access_token', 'wa_verify_token', 'wa_app_secret',
            'handoff_enabled', 'handoff_wa_number', 'handoff_label',
            'lead_field_name', 'lead_field_phone', 'lead_field_email',
            'conversation_end_action', 'max_messages', 'wrapup_margin',
        ];
        $secret_keys = [ 'ai_api_key', 'openai_api_key', 'wa_access_token', 'wa_app_secret' ];
        $errors      = [];

        $params = $_POST;

        foreach ( $allowed as $key ) {
            if ( ! isset( $params[ $key ] ) ) continue;
            $val = sanitize_text_field( (string) $params[ $key ] );
            if ( in_array( $key, $secret_keys, true ) && empty( $val ) ) continue;
            try {
                $this->db->set_setting( $key, $val );
            } catch ( Throwable $e ) {
                $errors[] = $key . ': ' . $e->getMessage();
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( implode( '; ', $errors ), 500 );
        }
        wp_send_json_success( [ 'saved' => true ] );
    }

    public function ajax_reindex(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        $result = Wisply_Content_Indexer::get_instance()->full_reindex();
        wp_send_json_success( $result );
    }

    public function ajax_test_ai(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        $result = Wisply_AI_Handler::get_instance()->test_connection();
        wp_send_json( $result );
    }

    public function ajax_test_voice(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $voice = sanitize_text_field( $_POST['voice'] ?? '' );
        $text  = sanitize_text_field( $_POST['text'] ?? 'שלום, זו דוגמה לקול של העוזר החכם של האתר.' );
        $result = Wisply_AI_Handler::get_instance()->synthesize_speech( $text, 'he', $voice );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result['error'] ?? 'TTS failed', 502 );
        }
        wp_send_json_success( [ 'audio' => $result['audio'], 'mime' => $result['mime'] ] );
    }

    public function ajax_system_check(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $db  = $this->db;
        $out = [];

        // 1) Can we write & read a setting? (proves the DB save path works)
        $token = 'chk-' . substr( md5( (string) microtime( true ) ), 0, 8 );
        $db->set_setting( 'wisply_selftest', $token );
        $read = $db->get_setting( 'wisply_selftest', '' );
        $out['settings_write'] = [
            'ok'     => ( $read === $token ),
            'detail' => ( $read === $token ) ? 'תקין — ההגדרות נכתבות ונקראות מהמסד'
                                             : "נכשל — נכתב \"$token\" אך נקרא \"$read\"",
        ];

        // 2) Is the Wisply licence key present? (It is the AI credential now —
        //    the proxy serves AI with the customer's platform-managed key.)
        $key = class_exists( 'Wisply_License' ) ? Wisply_License::get_instance()->get_key() : '';
        $out['openai_key'] = [
            'ok'     => ( $key !== '' ),
            'detail' => ( $key !== '' ) ? 'מפתח רישיון מוגדר — ה-AI מנוהל דרך Wisply'
                                        : 'לא הוזן מפתח רישיון (לשונית "מתקדם")',
        ];

        // 3) Does a real OpenAI chat call succeed?
        $chat = Wisply_AI_Handler::get_instance()->test_connection();
        $err  = (string) get_option( 'wisply_last_ai_error', '' );
        $out['openai_chat'] = [
            'ok'     => ! empty( $chat['success'] ),
            'detail' => ! empty( $chat['success'] ) ? 'תקין — OpenAI מגיב' : ( $err ?: ( $chat['reply'] ?? 'נכשל' ) ),
        ];

        // 4) Does text-to-speech succeed?
        $tts = Wisply_AI_Handler::get_instance()->synthesize_speech( 'בדיקה', 'he' );
        $out['openai_tts'] = [
            'ok'     => ! empty( $tts['success'] ),
            'detail' => ! empty( $tts['success'] ) ? 'תקין — הופק קול' : ( $tts['error'] ?? 'נכשל' ),
        ];

        // 5) Is the Realtime API (instant speech-to-speech) available on this account?
        $rt = Wisply_AI_Handler::get_instance()->create_realtime_session( 'he' );
        $out['realtime'] = [
            'ok'     => ! empty( $rt['success'] ),
            'detail' => ! empty( $rt['success'] )
                ? 'זמין — דיבור מיידי אפשרי 🎉'
                : ( 'לא זמין: ' . ( $rt['error'] ?? 'אין גישה' ) . ' — לכן הקול עובר למצב "כותב ואז מדבר"' ),
        ];

        // Current saved values (so you can confirm what's actually active)
        $out['values'] = [
            'ai_provider'      => (string) $db->get_setting( 'ai_provider', '' ),
            'openai_model'     => (string) $db->get_setting( 'openai_model', '' ),
            'voice_provider'   => (string) $db->get_setting( 'voice_provider', '' ),
            'realtime_enabled' => (string) $db->get_setting( 'realtime_enabled', '' ),
            'voice_text_mode'  => (string) $db->get_setting( 'voice_text_mode', '' ),
            'tts_voice'        => (string) $db->get_setting( 'tts_voice', '' ),
            'tts_model'        => (string) $db->get_setting( 'tts_model', '' ),
            'stt_model'        => (string) $db->get_setting( 'stt_model', '' ),
        ];

        wp_send_json_success( $out );
    }

    public function ajax_add_source(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $type    = sanitize_text_field( $_POST['source_type'] ?? '' );
        $indexer = Wisply_Content_Indexer::get_instance();

        switch ( $type ) {
            case 'sitemap':
                $url    = esc_url_raw( $_POST['sitemap_url'] ?? '' );
                $result = $indexer->index_sitemap( $url );
                break;

            case 'url_list':
                $list   = sanitize_textarea_field( $_POST['url_list'] ?? '' );
                $lang   = sanitize_text_field( $_POST['lang'] ?? 'he' );
                $result = $indexer->index_url_list( $list, $lang );
                break;

            case 'url':
                $url    = esc_url_raw( $_POST['url'] ?? '' );
                $lang   = sanitize_text_field( $_POST['lang'] ?? 'he' );
                $result = $indexer->index_url( $url, $lang );
                break;

            case 'text':
                $title   = sanitize_text_field( $_POST['text_title'] ?? '' );
                $content = sanitize_textarea_field( $_POST['text_content'] ?? '' );
                $lang    = sanitize_text_field( $_POST['lang'] ?? 'he' );
                $result  = $indexer->index_text( $title, $content, $lang );
                break;

            default:
                wp_send_json_error( 'Unknown source type' );
                return;
        }

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result['error'] ?? 'Error' );
    }

    public function ajax_delete_indexed(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $lang    = sanitize_text_field( $_POST['lang'] ?? '' );
        if ( $post_id && $lang ) {
            $this->db->delete_indexed_item( $post_id, $lang );
            wp_send_json_success();
        }
        wp_send_json_error( 'Missing params' );
    }
}
