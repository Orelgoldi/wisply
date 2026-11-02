<?php
/**
 * Real-estate edition profile (Levy).
 *
 * Same idea as the Be.live profile: the engine is the white-label Wisply chatbot,
 * and this file is the ONLY thing that makes the build "real-estate". On first
 * activation (and on each profile-version bump) it seeds the persona, the
 * WhatsApp skin, the agent presence copy, default weekly availability and the
 * booking defaults into settings the engine already reads. Applied once per
 * version, so the owner can freely edit anything in wp-admin afterwards without
 * it being stomped. Bump PROFILE_VERSION to push a new baseline.
 */
defined( 'ABSPATH' ) || exit;

class Realestate_Profile {

	private static ?self $instance = null;

	const PROFILE_VERSION = '1.0.0';

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'maybe_apply' ], 20 );
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function maybe_apply(): void {
		if ( ! class_exists( 'Wisply_Database' ) ) return;
		if ( (string) get_option( 'realestate_profile_version', '' ) === self::PROFILE_VERSION ) return;

		$db = Wisply_Database::get_instance();
		foreach ( $this->config() as $key => $value ) {
			// Never clobber a value the owner already set (first-run seed only per key).
			if ( (string) $db->get_setting( $key, '' ) === '' ) {
				$db->set_setting( $key, $value );
			}
		}
		// The persona IS the product — always refresh it to the shipped baseline on a bump.
		$db->set_setting( 'ai_custom_rules', $this->persona() );

		// Seed the gallery from the site's content once (owner curates afterwards).
		if ( class_exists( 'Wisply_Projects' ) && (string) $db->get_setting( 'projects', '' ) === '' ) {
			Wisply_Projects::get_instance()->seed_from_site();
		}

		update_option( 'realestate_profile_version', self::PROFILE_VERSION );
	}

	private function agent_name(): string {
		return 'דניאל'; // neutral, warm default; editable in settings → bot name
	}

	private function business_name(): string {
		return 'לוי נדל״ן';
	}

	private function persona(): string {
		$file = plugin_dir_path( WISPLY_PLUGIN_FILE ) . 'realestate-persona.he.txt';
		$txt  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return strtr( $txt, [
			'{{שם_הסוכן}}' => $this->agent_name(),
			'{{שם_העסק}}'  => $this->business_name(),
		] );
	}

	/** Default weekly availability: Sun–Thu 09:00–18:00 (0=Sun … 4=Thu). */
	private function default_availability(): string {
		$week = [];
		foreach ( [ 0, 1, 2, 3, 4 ] as $dow ) {
			$week[ (string) $dow ] = [ [ '09:00', '18:00' ] ];
		}
		return wp_json_encode( $week );
	}

	private function config(): array {
		return [
			// ── Brand / persona ──
			'product_name'   => 'נדל״ן AI',
			'bot_name'       => $this->agent_name(),
			'business_name'  => $this->business_name(),
			'business_type'  => 'משרד תיווך ושיווק פרויקטים בנדל״ן',
			'business_description' => 'ליווי מתעניינים בדירות ובפרויקטים: הצגת פרויקטים רלוונטיים וקביעת פגישה עם יועץ.',

			// ── WhatsApp-style widget ──
			'chat_skin'      => 'whatsapp',
			'agent_role_he'  => 'יועץ/ת נדל״ן',
			'agent_status_he'=> 'מחובר/ת עכשיו',
			'bot_avatar'     => 'av-1',
			'primary_color'  => '#25D366',   // WhatsApp green accent
			'secondary_color'=> '#128C7E',

			// ── Greetings / suggested questions ──
			'greeting_he'    => 'היי 👋 אני כאן לעזור למצוא את הנכס הנכון. מה אתם מחפשים?',
			'widget_title_he'=> 'יועץ נדל״ן',
			'suggested_questions_he' => "אילו פרויקטים יש לכם?\nאני רוצה לקבוע פגישה\nמה טווח המחירים?\nאיפה הפרויקטים ממוקמים?",

			// ── Booking ──
			'booking_enabled'      => '1',
			'booking_cta_he'       => 'קביעת פגישה',
			'booking_duration_min' => '30',
			'booking_buffer_min'   => '15',
			'booking_days_ahead'   => '14',
			'booking_timezone'     => 'Asia/Jerusalem',
			'booking_availability' => $this->default_availability(),

			// ── Gallery ──
			'projects_autocrawl'   => '1',

			// Hebrew only for this edition.
			'enabled_langs'  => 'he',
			'default_lang'   => 'he',
		];
	}
}
