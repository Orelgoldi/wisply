<?php
/**
 * Plugin Name:       Medical360 AI Chatbot
 * Plugin URI:        https://medical360.org
 * Description:       צ׳אט AI חכם לאתר medical360.org — עונה בעברית, אנגלית ורוסית על בסיס תוכן האתר בלבד.
 * Version:           4.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Orel Goldstein Studio
 * Author URI:        https://goldstein.studio
 * License:           Proprietary — medical360.org only
 * Text Domain:       medical360-chatbot
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'M360_VERSION',     '4.6.0' );
define( 'M360_PLUGIN_FILE', __FILE__ );
define( 'M360_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'M360_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'M360_TEXT_DOMAIN', 'medical360-chatbot' );

require_once M360_PLUGIN_DIR . 'includes/class-database.php';
require_once M360_PLUGIN_DIR . 'includes/class-ai-handler.php';
require_once M360_PLUGIN_DIR . 'includes/class-content-indexer.php';
require_once M360_PLUGIN_DIR . 'includes/class-chatbot-api.php';
require_once M360_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__,   [ 'M360_Database', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'M360_Database', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( M360_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $db = M360_Database::get_instance();
    // Always ensure tables exist — handles updates without deactivate/activate
    $db->create_tables();

    // On version change, seed any newly-introduced default settings (idempotent —
    // only inserts keys that don't exist yet, e.g. the voice_* options in 4.3.0).
    if ( get_option( 'm360_db_version' ) !== M360_VERSION ) {
        $db->seed_default_settings();
        update_option( 'm360_db_version', M360_VERSION );
    }

    M360_Chatbot_API::get_instance();
    M360_Admin::get_instance();

    // Register the scheduled leads-report cron (daily + weekly) on every load
    $db->register_reports();

    // Inject widget on all front-end pages
    add_action( 'wp_footer', 'm360_render_widget' );
    add_action( 'wp_enqueue_scripts', 'm360_enqueue_public_assets' );
} );

function m360_enqueue_public_assets(): void {
    // The CSS is injected INTO the Shadow DOM by the JS (not enqueued on the page),
    // so theme styles cannot leak in. We only need the URL.
    wp_enqueue_script(
        'medical360-chatbot',
        M360_PLUGIN_URL . 'public/js/chatbot.js',
        [],
        M360_VERSION,
        true
    );
    wp_localize_script( 'medical360-chatbot', 'M360Config', [
        'apiUrl'      => rest_url( 'medical360/v1' ),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'siteUrl'     => home_url(),
        'lang'        => determine_locale(),
        'cssUrl'      => M360_PLUGIN_URL . 'public/css/chatbot.css?ver=' . M360_VERSION,
        'settings'    => M360_Database::get_instance()->get_widget_settings(),
        'strings'     => m360_js_strings(),
    ] );
}

function m360_js_strings(): array {
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

function m360_render_widget(): void {
    $settings = M360_Database::get_instance()->get_widget_settings();

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
    <div id="m360-root"
         data-phone="<?php echo esc_attr( $settings['phone'] ?? '' ); ?>"
         data-map-url="<?php echo esc_attr( $settings['map_url'] ?? '' ); ?>"
         data-page-title="<?php echo esc_attr( $page_title ); ?>"
         data-department="<?php echo esc_attr( $department ); ?>"
         aria-label="Medical360 AI Chatbot"
         role="complementary">
    </div>
    <?php
}
