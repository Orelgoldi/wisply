<?php
defined( 'ABSPATH' ) || exit;

/**
 * First-run setup wizard (Wisply product onboarding).
 *
 * A multi-step wizard shown once on a fresh install (gated by the
 * `wisply_setup_complete` option). Steps:
 *   1. Scan the site (published pages/posts/menus + optional sitemap) into the KB.
 *   2. Confirm persona + answer a few business questions (type, tone, CTA, lead fields).
 *   3. Approve / edit suggested FAQ questions + the primary CTA button.
 *   4. Finish → mark setup complete and start the free trial.
 *
 * Reuses Wisply_Database::autofill_branding_from_site() and the existing content
 * indexer. AJAX reuses the shared `wisply_admin_nonce` (localized as WisplyAdmin).
 *
 * This is the wired skeleton: every step persists real settings the AI prompt and
 * widget already consume, so finishing produces a live, configured bot.
 */
class Wisply_Setup_Wizard {

    private static ?self $instance = null;
    private Wisply_Database $db;

    const PAGE = 'wisply-setup';

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
        add_action( 'admin_menu',  [ $this, 'register_page' ] );
        add_action( 'admin_init',  [ $this, 'maybe_redirect' ] );
        add_action( 'wp_ajax_wisply_setup_scan',   [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_wisply_setup_save',   [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_wisply_setup_finish', [ $this, 'ajax_finish' ] );
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_complete(): bool {
        return get_option( 'wisply_setup_complete', '0' ) === '1';
    }

    /** Register the wizard as a hidden admin page (reached via redirect / direct link). */
    public function register_page(): void {
        $product = defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply';
        add_submenu_page(
            '',
            'הגדרת ' . $product,
            '',
            'manage_options',
            self::PAGE,
            [ $this, 'render' ]
        );
    }

    /** One-time post-activation redirect into the wizard for a fresh install. */
    public function maybe_redirect(): void {
        if ( ! get_transient( 'wisply_activation_redirect' ) ) {
            return;
        }
        delete_transient( 'wisply_activation_redirect' );

        if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( isset( $_GET['activate-multi'] ) ) {
            return; // bulk plugin activation — never hijack the screen
        }
        if ( $this->is_complete() ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        // Prefill bot/business name from the site title before the persona step reads it.
        $this->db->autofill_branding_from_site();
        require WISPLY_PLUGIN_DIR . 'admin/views/setup-wizard.php';
    }

    // ─── AJAX ──────────────────────────────────────────────────────────────────

    private function guard(): void {
        check_ajax_referer( 'wisply_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
    }

    /** Step 1: scan the site into the knowledge base (+ optional sitemap top-up). */
    public function ajax_scan(): void {
        $this->guard();

        $this->db->autofill_branding_from_site();
        $result = Wisply_Content_Indexer::get_instance()->full_reindex();

        $sitemap = esc_url_raw( wp_unslash( $_POST['sitemap_url'] ?? '' ) );
        if ( $sitemap !== '' ) {
            $sm = Wisply_Content_Indexer::get_instance()->index_sitemap( $sitemap );
            if ( ! empty( $sm['indexed'] ) ) {
                $result['indexed'] = (int) ( $result['indexed'] ?? 0 ) + (int) $sm['indexed'];
            }
        }

        $result['content_count'] = $this->db->get_content_count();
        $result['business_name'] = (string) $this->db->get_setting( 'business_name', '' );
        wp_send_json_success( $result );
    }

    /** Steps 2 & 3: persist persona answers, lead-field modes, primary CTA and FAQ. */
    public function ajax_save(): void {
        $this->guard();

        foreach ( [ 'business_name', 'bot_name', 'business_type' ] as $k ) {
            if ( isset( $_POST[ $k ] ) ) {
                $this->db->set_setting( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
            }
        }

        // Tone + "what to collect in a lead" fold into the persona description that the
        // AI prompt already injects (build_system_prompt → $desc_block).
        if ( isset( $_POST['tone'] ) || isset( $_POST['collect'] ) ) {
            $tone    = sanitize_text_field( wp_unslash( $_POST['tone'] ?? '' ) );
            $collect = sanitize_text_field( wp_unslash( $_POST['collect'] ?? '' ) );
            $bits    = [];
            if ( $tone !== '' )    { $bits[] = 'טון הדיבור הרצוי: ' . $tone . '.'; }
            if ( $collect !== '' ) { $bits[] = 'כשמשאירים פרטים, אסוף: ' . $collect . '.'; }
            if ( ! empty( $bits ) ) {
                $this->db->set_setting( 'business_description', implode( ' ', $bits ) );
            }
        }

        // Lead-form field modes (חובה / רשות / מוסתר).
        foreach ( [ 'lead_field_name', 'lead_field_phone', 'lead_field_email' ] as $k ) {
            if ( isset( $_POST[ $k ] ) ) {
                $v = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
                if ( in_array( $v, [ 'required', 'optional', 'hidden' ], true ) ) {
                    $this->db->set_setting( $k, $v );
                }
            }
        }

        // Primary CTA → one action button, which the AI prompt consumes via available_actions().
        $cta_label = sanitize_text_field( wp_unslash( $_POST['cta_label'] ?? '' ) );
        $cta_url   = esc_url_raw( wp_unslash( $_POST['cta_url'] ?? '' ) );
        if ( $cta_label !== '' && $cta_url !== '' ) {
            $this->db->set_setting( 'action_buttons', wp_json_encode( [ [
                'key'      => 'cta',
                'label_he' => $cta_label,
                'url'      => $cta_url,
            ] ] ) );
        }

        // Suggested FAQ questions (one per line) shown in the widget.
        if ( isset( $_POST['faq_he'] ) ) {
            $this->db->set_setting( 'suggested_questions_he', sanitize_textarea_field( wp_unslash( $_POST['faq_he'] ) ) );
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    /** Step 4: finish — mark setup complete and start the free trial. */
    public function ajax_finish(): void {
        $this->guard();

        update_option( 'wisply_setup_complete', '1' );
        if ( class_exists( 'Wisply_Trial' ) ) {
            Wisply_Trial::get_instance()->start_trial();
        }

        wp_send_json_success( [ 'redirect' => admin_url( 'admin.php?page=wisply-chatbot' ) ] );
    }
}
