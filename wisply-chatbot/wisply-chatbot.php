<?php
/**
 * Plugin Name:       Wisply — AI Chat Assistant
 * Plugin URI:        https://goldstein.studio
 * Description:       White-label AI chat assistant (text + voice) for any website. Answers in Hebrew, English, Russian & Arabic based only on your own site content. Set your bot name, branding, colours and persona — no code.
 * Version:           2.18.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Goldstein Studio
 * Author URI:        https://goldstein.studio
 * License:           Proprietary
 * Text Domain:       wisply-chatbot
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WISPLY_VERSION',     '2.18.1' );
define( 'WISPLY_PLUGIN_FILE', __FILE__ );
define( 'WISPLY_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WISPLY_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WISPLY_TEXT_DOMAIN', 'wisply-chatbot' );
// White-label product name (shown in admin menu + "Powered by"). Change to rebrand.
defined( 'WISPLY_PRODUCT_NAME' ) || define( 'WISPLY_PRODUCT_NAME', 'Wisply' );
// Licence + auto-update server. Override in wp-config.php to point at a staging server.
defined( 'WISPLY_API_URL' ) || define( 'WISPLY_API_URL', 'https://wisply.io' );

require_once WISPLY_PLUGIN_DIR . 'includes/class-database.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-license.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-woo.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-ai-handler.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-content-indexer.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-chatbot-api.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-whatsapp.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-admin.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-trial.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-setup-wizard.php';

register_activation_hook( __FILE__,   [ 'Wisply_Database', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Wisply_Database', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( WISPLY_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $db = Wisply_Database::get_instance();
    // Always ensure tables exist — handles updates without deactivate/activate
    $db->create_tables();

    // On version change, seed any newly-introduced default settings (idempotent —
    // only inserts keys that don't exist yet, e.g. the voice_* options in 4.2.0).
    $wisply_prev_version = (string) get_option( 'wisply_db_version', '' );
    if ( $wisply_prev_version !== WISPLY_VERSION ) {
        $db->seed_default_settings();

        // Language defaults (2.8.0). Only ever set when still unset, so it never
        // overrides a choice the owner made. A site upgrading from an older version
        // keeps the historical he/en/ru set (clamped to its plan at render time), so
        // multilingual sites are not silently reduced; a brand-new install starts at
        // just its own site language, which is the intended default going forward.
        if ( (string) $db->get_setting( 'enabled_langs', '' ) === '' ) {
            if ( $wisply_prev_version !== '' && version_compare( $wisply_prev_version, '2.8.0', '<' ) ) {
                $db->set_setting( 'enabled_langs', 'he,en,ru' );
                $db->set_setting( 'default_lang', 'he' );
            } else {
                $site = substr( (string) determine_locale(), 0, 2 );
                $lang = in_array( $site, [ 'he', 'en', 'ru', 'ar' ], true ) ? $site : 'he';
                $db->set_setting( 'enabled_langs', $lang );
                $db->set_setting( 'default_lang', $lang );
            }
        }

        update_option( 'wisply_db_version', WISPLY_VERSION );
    }

    Wisply_License::get_instance();
    Wisply_Chatbot_API::get_instance();
    Wisply_WhatsApp::get_instance();
    Wisply_Admin::get_instance();

    // Wisply-product monetization: free-trial state machine + first-run setup wizard.
    Wisply_Trial::get_instance();
    if ( is_admin() ) {
        Wisply_Setup_Wizard::get_instance();
    }

    // Admin-only store diagnostics: visit while logged in as admin —
    //   /wp-admin/admin-ajax.php?action=wisply_woo_diagnose&q=שרשראות זהב
    // Answers "are these real WooCommerce products and does search find them?".
    add_action( 'wp_ajax_wisply_woo_diagnose', function () {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'forbidden', 403 ); }
        if ( ! class_exists( 'Wisply_Woo' ) ) { wp_send_json_error( 'woo module not loaded' ); }
        $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        wp_send_json( Wisply_Woo::get_instance()->diagnose( $q ) );
    } );

    // Register the scheduled leads-report cron (daily + weekly) on every load
    $db->register_reports();

    // Inject widget on all front-end pages
    add_action( 'wp_footer', 'wisply_render_widget' );
    add_action( 'wp_enqueue_scripts', 'wisply_enqueue_public_assets' );
} );

function wisply_enqueue_public_assets(): void {
    // Same gate as the widget. Hiding only the div we print is not enough: the JS
    // also adopts a #wisply-root / #wisply-chatbot-root that came from the theme or a
    // page builder, and would then build a bot whose every request 403s in front of a
    // visitor. No licence → ship no script and no config at all.
    if ( class_exists( 'Wisply_License' ) && ! Wisply_License::get_instance()->is_valid() ) {
        return;
    }

    // The CSS is injected INTO the Shadow DOM by the JS (not enqueued on the page),
    // so theme styles cannot leak in. We only need the URL.
    wp_enqueue_script(
        'wisply-chatbot',
        WISPLY_PLUGIN_URL . 'public/js/chatbot.js',
        [],
        WISPLY_VERSION,
        true
    );
    $db       = Wisply_Database::get_instance();
    $widget   = $db->get_widget_settings();
    // Authoritative plan gate: resolve + clamp the active languages at render time,
    // so a plan downgrade takes effect even before the owner re-opens settings.
    [ $wisply_enabled_langs, $wisply_default_lang ] = wisply_resolve_langs( $db );
    $widget['enabled_langs'] = $wisply_enabled_langs;
    $widget['default_lang']  = $wisply_default_lang;

    wp_localize_script( 'wisply-chatbot', 'WisplyConfig', [
        'apiUrl'      => rest_url( 'wisply/v1' ),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'siteUrl'     => home_url(),
        'lang'        => determine_locale(),
        'cssUrl'      => WISPLY_PLUGIN_URL . 'public/css/chatbot.css?ver=' . WISPLY_VERSION,
        'avatarBase'  => WISPLY_PLUGIN_URL . 'public/avatars/',
        'avatarVer'   => WISPLY_VERSION,
        'settings'    => $widget,
        'strings'     => wisply_js_strings(),
    ] );
}

/**
 * Resolve the widget's active languages: stored set → site language when unset →
 * clamped to the plan's allowance (the default language is kept, never dropped).
 *
 * @return array{0:string,1:string} [ enabled_csv, default_lang ]
 */
function wisply_resolve_langs( Wisply_Database $db ): array {
    $supported = [ 'he', 'en', 'ru', 'ar' ];
    $enabled   = array_values( array_intersect(
        $supported,
        array_filter( array_map( 'trim', explode( ',', (string) $db->get_setting( 'enabled_langs', '' ) ) ) )
    ) );
    if ( empty( $enabled ) ) {
        $site    = substr( (string) determine_locale(), 0, 2 );
        $enabled = [ in_array( $site, $supported, true ) ? $site : 'he' ];
    }
    $default = (string) $db->get_setting( 'default_lang', '' );
    if ( ! in_array( $default, $enabled, true ) ) $default = $enabled[0];

    $max     = class_exists( 'Wisply_License' ) ? Wisply_License::get_instance()->max_langs() : 1;
    $enabled = array_merge( [ $default ], array_values( array_diff( $enabled, [ $default ] ) ) );
    $enabled = array_slice( $enabled, 0, max( 1, $max ) );

    return [ implode( ',', $enabled ), $default ];
}

function wisply_js_strings(): array {
    return [
        'placeholder_he' => 'שאל/י שאלה...',
        'placeholder_en' => 'Ask a question...',
        'placeholder_ru' => 'Задайте вопрос...',
        'send_he'        => 'שלח',
        'send_en'        => 'Send',
        'send_ru'        => 'Отправить',
        'typing_he'      => 'מקליד...',
        'typing_en'      => 'Typing...',
        'typing_ru'      => 'Печатает...',
        'error_he'       => 'שגיאה. נסה שוב.',
        'error_en'       => 'An error occurred. Please try again.',
        'error_ru'       => 'Произошла ошибка. Попробуйте ещё раз.',
        'close'          => '✕',
        'minimize'       => '—',
    ];
}

function wisply_render_widget(): void {
    // No licence, no widget. Better a clean absence than a bubble that opens and
    // then 403s in a visitor's face. is_valid() fails open unless the server has
    // explicitly rejected the key and the grace window has run out.
    if ( class_exists( 'Wisply_License' ) && ! Wisply_License::get_instance()->is_valid() ) {
        return;
    }

    $settings = Wisply_Database::get_instance()->get_widget_settings();

    // Detect the current page's title + department so the bot can offer page-specific
    // help and attach the right context (Department) to leads (FR-001).
    $page_title = '';
    $department = '';
    if ( is_singular() && ! is_front_page() ) {
        $oid        = get_queried_object_id();
        $page_title = wp_strip_all_tags( get_the_title( $oid ) );
        $ptype      = get_post_type( $oid );
        // Treat department-type pages (or their primary category) as the lead's department
        if ( in_array( $ptype, [ 'department', 'workshop' ], true ) ) {
            $department = $page_title;
        } else {
            $cats = get_the_category( $oid );
            if ( ! empty( $cats ) ) {
                $department = $cats[0]->name;
            }
        }
    }
    ?>
    <div id="wisply-root"
         data-phone="<?php echo esc_attr( $settings['phone'] ?? '' ); ?>"
         data-map-url="<?php echo esc_attr( $settings['map_url'] ?? '' ); ?>"
         data-page-title="<?php echo esc_attr( $page_title ); ?>"
         data-department="<?php echo esc_attr( $department ); ?>"
         aria-label="<?php echo esc_attr( defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply' ); ?>"
         role="complementary">
    </div>
    <?php
}
