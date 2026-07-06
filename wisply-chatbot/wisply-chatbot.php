<?php
/**
 * Plugin Name:       Wisply — AI Chat Assistant
 * Plugin URI:        https://goldstein.studio
 * Description:       White-label AI chat assistant (text + voice) for any website. Answers in Hebrew, English & Russian based only on your own site content. Set your bot name, branding, colours and persona — no code.
 * Version:           2.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Goldstein Studio
 * Author URI:        https://goldstein.studio
 * License:           Proprietary
 * Text Domain:       wisply-chatbot
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WISPLY_VERSION',     '2.2.1' );
define( 'WISPLY_PLUGIN_FILE', __FILE__ );
define( 'WISPLY_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WISPLY_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WISPLY_TEXT_DOMAIN', 'wisply-chatbot' );
// White-label product name (shown in admin menu + "Powered by"). Change to rebrand.
defined( 'WISPLY_PRODUCT_NAME' ) || define( 'WISPLY_PRODUCT_NAME', 'Wisply' );

require_once WISPLY_PLUGIN_DIR . 'includes/class-database.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-ai-handler.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-content-indexer.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-chatbot-api.php';
require_once WISPLY_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__,   [ 'Wisply_Database', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Wisply_Database', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( WISPLY_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $db = Wisply_Database::get_instance();
    // Always ensure tables exist — handles updates without deactivate/activate
    $db->create_tables();

    // On version change, seed any newly-introduced default settings (idempotent —
    // only inserts keys that don't exist yet, e.g. the voice_* options in 4.2.0).
    if ( get_option( 'wisply_db_version' ) !== WISPLY_VERSION ) {
        $db->seed_default_settings();
        update_option( 'wisply_db_version', WISPLY_VERSION );
    }

    Wisply_Chatbot_API::get_instance();
    Wisply_Admin::get_instance();

    // Register the scheduled leads-report cron (daily + weekly) on every load
    $db->register_reports();

    // Inject widget on all front-end pages
    add_action( 'wp_footer', 'wisply_render_widget' );
    add_action( 'wp_enqueue_scripts', 'wisply_enqueue_public_assets' );
} );

function wisply_enqueue_public_assets(): void {
    // The CSS is injected INTO the Shadow DOM by the JS (not enqueued on the page),
    // so theme styles cannot leak in. We only need the URL.
    wp_enqueue_script(
        'wisply-chatbot',
        WISPLY_PLUGIN_URL . 'public/js/chatbot.js',
        [],
        WISPLY_VERSION,
        true
    );
    wp_localize_script( 'wisply-chatbot', 'WisplyConfig', [
        'apiUrl'      => rest_url( 'wisply/v1' ),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'siteUrl'     => home_url(),
        'lang'        => determine_locale(),
        'cssUrl'      => WISPLY_PLUGIN_URL . 'public/css/chatbot.css?ver=' . WISPLY_VERSION,
        'settings'    => Wisply_Database::get_instance()->get_widget_settings(),
        'strings'     => wisply_js_strings(),
    ] );
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
