<?php
defined( 'ABSPATH' ) || exit;

class M360_Admin {

    private static ?self $instance = null;
    private M360_Database $db;

    private function __construct() {
        $this->db = M360_Database::get_instance();
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        // admin-ajax handlers (bypass REST API entirely — more reliable on restrictive hosts)
        add_action( 'wp_ajax_m360_save_settings', [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_m360_reindex',        [ $this, 'ajax_reindex' ] );
        add_action( 'wp_ajax_m360_test_ai',        [ $this, 'ajax_test_ai' ] );
        add_action( 'wp_ajax_m360_delete_indexed', [ $this, 'ajax_delete_indexed' ] );
        add_action( 'wp_ajax_m360_add_source',     [ $this, 'ajax_add_source' ] );
        add_action( 'wp_ajax_m360_test_voice',     [ $this, 'ajax_test_voice' ] );
        add_action( 'wp_ajax_m360_system_check',   [ $this, 'ajax_system_check' ] );
        // Make sure content indexer is available when triggered from admin
        M360_Content_Indexer::get_instance();
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Menu ─────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_menu_page(
            'Medical360 Chatbot',
            'Medical360 Chat',
            'manage_options',
            'medical360-chatbot',
            [ $this, 'page_dashboard' ],
            'dashicons-format-chat',
            25
        );
        add_submenu_page(
            'medical360-chatbot',
            'שיחות',
            'שיחות',
            'manage_options',
            'medical360-conversations',
            [ $this, 'page_conversations' ]
        );
        add_submenu_page(
            'medical360-chatbot',
            'לידים',
            'לידים',
            'manage_options',
            'medical360-leads',
            [ $this, 'page_leads' ]
        );
        add_submenu_page(
            'medical360-chatbot',
            'הגדרות',
            'הגדרות',
            'manage_options',
            'medical360-settings',
            [ $this, 'page_settings' ]
        );
        add_submenu_page(
            'medical360-chatbot',
            'אינדוקס תוכן',
            'אינדוקס תוכן',
            'manage_options',
            'medical360-index',
            [ $this, 'page_index' ]
        );
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'medical360' ) === false ) return;

        wp_enqueue_style(
            'medical360-admin',
            M360_PLUGIN_URL . 'admin/css/admin.css',
            [ 'wp-components' ],
            M360_VERSION
        );
        wp_enqueue_script(
            'medical360-admin',
            M360_PLUGIN_URL . 'admin/js/admin.js',
            [ 'wp-api-fetch', 'wp-i18n' ],
            M360_VERSION,
            true
        );
        $settings = $this->db->get_all_settings();
        // Never expose API keys to the browser — replace with a masked indicator
        foreach ( [ 'ai_api_key', 'openai_api_key' ] as $k ) {
            if ( ! empty( $settings[ $k ] ) ) {
                $settings[ $k ] = '••••••••';
            }
        }

        wp_localize_script( 'medical360-admin', 'M360Admin', [
            'apiBase'        => rest_url( 'medical360/v1' ),
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce'      => wp_create_nonce( 'm360_admin_nonce' ),
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
        require M360_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_conversations(): void {
        $this->no_cache();
        require M360_PLUGIN_DIR . 'admin/views/conversations.php';
    }

    public function page_settings(): void {
        $this->no_cache();
        require M360_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function page_index(): void {
        $this->no_cache();
        require M360_PLUGIN_DIR . 'admin/views/content-index.php';
    }

    public function page_leads(): void {
        $this->no_cache();
        require M360_PLUGIN_DIR . 'admin/views/leads.php';
    }

    // ─── admin-ajax handlers ──────────────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $allowed     = [
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
        $secret_keys = [ 'ai_api_key', 'openai_api_key' ];
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
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        $result = M360_Content_Indexer::get_instance()->full_reindex();
        wp_send_json_success( $result );
    }

    public function ajax_test_ai(): void {
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        $result = M360_AI_Handler::get_instance()->test_connection();
        wp_send_json( $result );
    }

    public function ajax_test_voice(): void {
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $voice = sanitize_text_field( $_POST['voice'] ?? '' );
        $text  = sanitize_text_field( $_POST['text'] ?? 'שלום, זו דוגמה לקול של העוזר החכם של מדיקל קר.' );
        $result = M360_AI_Handler::get_instance()->synthesize_speech( $text, 'he', $voice );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result['error'] ?? 'TTS failed', 502 );
        }
        wp_send_json_success( [ 'audio' => $result['audio'], 'mime' => $result['mime'] ] );
    }

    public function ajax_system_check(): void {
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $db  = $this->db;
        $out = [];

        // 1) Can we write & read a setting? (proves the DB save path works)
        $token = 'chk-' . substr( md5( (string) microtime( true ) ), 0, 8 );
        $db->set_setting( 'm360_selftest', $token );
        $read = $db->get_setting( 'm360_selftest', '' );
        $out['settings_write'] = [
            'ok'     => ( $read === $token ),
            'detail' => ( $read === $token ) ? 'תקין — ההגדרות נכתבות ונקראות מהמסד'
                                             : "נכשל — נכתב \"$token\" אך נקרא \"$read\"",
        ];

        // 2) Is the OpenAI key present?
        $key = (string) $db->get_setting( 'openai_api_key', '' );
        $out['openai_key'] = [
            'ok'     => ( $key !== '' ),
            'detail' => ( $key !== '' ) ? ( 'מפתח מוגדר (' . strlen( $key ) . ' תווים)' )
                                        : 'לא מוגדר מפתח OpenAI כלל',
        ];

        // 3) Does a real OpenAI chat call succeed?
        $chat = M360_AI_Handler::get_instance()->test_connection();
        $err  = (string) get_option( 'm360_last_ai_error', '' );
        $out['openai_chat'] = [
            'ok'     => ! empty( $chat['success'] ),
            'detail' => ! empty( $chat['success'] ) ? 'תקין — OpenAI מגיב' : ( $err ?: ( $chat['reply'] ?? 'נכשל' ) ),
        ];

        // 4) Does text-to-speech succeed?
        $tts = M360_AI_Handler::get_instance()->synthesize_speech( 'בדיקה', 'he' );
        $out['openai_tts'] = [
            'ok'     => ! empty( $tts['success'] ),
            'detail' => ! empty( $tts['success'] ) ? 'תקין — הופק קול' : ( $tts['error'] ?? 'נכשל' ),
        ];

        // 5) Is the Realtime API (instant speech-to-speech) available on this account?
        $rt = M360_AI_Handler::get_instance()->create_realtime_session( 'he' );
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
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $type    = sanitize_text_field( $_POST['source_type'] ?? '' );
        $indexer = M360_Content_Indexer::get_instance();

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
        check_ajax_referer( 'm360_admin_nonce', 'nonce' );
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
