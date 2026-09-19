<?php
/**
 * Be.live edition profile.
 *
 * The engine is the white-label Wisply chatbot. This file is the ONLY thing that
 * makes this build "Be.live": on first activation (and on each profile-version bump)
 * it seeds Roni Shinkman's / Be.live's configuration into the settings the AI brain
 * already reads — persona, safety escalation, team handoff, catalogue matching,
 * WhatsApp-first, Hebrew. Everything the client manual describes maps onto existing
 * settings keys, so there is no forked logic to maintain, only this profile.
 *
 * The profile is applied ONCE per profile version (guarded by belive_profile_version),
 * so after it seeds, the owner is free to edit any value in wp-admin without it being
 * overwritten on the next page load. Bump BELIVE_PROFILE_VERSION to push a new baseline.
 */
defined( 'ABSPATH' ) || exit;

class Belive_Profile {

	private static ?self $instance = null;

	// Bump to re-apply the baseline profile (e.g. after updating the persona file).
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

	/** Apply the profile once per version, without stomping later owner edits. */
	public function maybe_apply(): void {
		if ( ! class_exists( 'Wisply_Database' ) ) return;
		$applied = (string) get_option( 'belive_profile_version', '' );
		if ( $applied === self::PROFILE_VERSION ) return;

		$db = Wisply_Database::get_instance();
		foreach ( $this->config() as $key => $value ) {
			$db->set_setting( $key, $value );
		}
		update_option( 'belive_profile_version', self::PROFILE_VERSION );
	}

	/** The agent's display name. The one open decision in the manual ([שם הסוכן]). */
	private function agent_name(): string {
		return 'לב';
	}

	/** Load the persona (ai_custom_rules) from the shipped file, name-substituted. */
	private function persona(): string {
		$file = plugin_dir_path( WISPLY_PLUGIN_FILE ) . 'belive-persona.he.txt';
		$txt  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return str_replace( '{{שם_הסוכן}}', $this->agent_name(), $txt );
	}

	/** The full Be.live configuration map, keyed by Wisply setting name. */
	private function config(): array {
		$team = 'https://wa.me/972503114822';
		return [
			// ── Brand / persona ──
			'product_name'         => 'Be.live',
			'powered_by_enabled'   => '1',
			'bot_name'             => $this->agent_name(),
			'business_name'        => 'Be.live — המרכז למערכות יחסים מבית רוני שינקמן',
			'business_type'        => 'מרכז לליווי במערכות יחסים, זוגיות, פרידה, ערך עצמי והתפתחות אישית',
			'business_description'  => 'מלווה דיגיטלי מבוסס בינה מלאכותית של Be.live, שמבוסס על הגישה של רוני שינקמן ואינו רוני עצמה. מלווה מנויים בין המפגשים: עוזר לעשות סדר, להפריד עובדה מפרשנות, לזהות דפוסים ולבחור צעד קטן. אינו תחליף למאמן אנושי, מטפל, רופא או מוקד חירום.',
			'ai_custom_rules'      => $this->persona(),

			// ── Language: Hebrew only ──
			'default_lang'         => 'he',
			'enabled_langs'        => 'he',

			// ── Widget copy ──
			'widget_title_he'      => 'Be.live',
			'greeting_he'          => 'היי, אני ' . $this->agent_name() . ', המלווה הדיגיטלי של Be.live. אני כאן כדי לעזור לך לעשות סדר במה שעובר עליך עכשיו. במה נתחיל?',
			'suggested_questions_he' => "אני אחרי פרידה ולא מצליח/ה להתאושש\nחוזר/ת שוב ושוב לדייטים שלא מתאימים לי\nיש לנו ריבים חוזרים בזוגיות\nמה כולל המנוי של Be.live?",

			// ── Safety escalation (drives the [EMERGENCY] block) ──
			'emergency_msg_he'     => 'מה שאת/ה מתאר/ת דורש עזרה אנושית עכשיו, ולא נכון להישאר עם זה רק כאן. אם יש סכנה מיידית, פנה/י עכשיו למוקד החירום המקומי או למיון ובקש/י מאדם קרוב להיות איתך. אפשר גם ליצור קשר עם צוות Be.live: ' . $team,
			'emergency_phone'      => '101',

			// ── Human handoff to the Be.live team ──
			'handoff_enabled'      => '1',
			'handoff_wa_number'    => '972503114822',
			'handoff_label'        => 'לדבר עם צוות Be.live',

			// ── E-commerce (ronishe.co.il is WooCommerce) ──
			'woo_enabled'              => '1',
			'woo_max_products'         => '3',
			'woo_show_stock'           => '0',
			'woo_bundle_enabled'       => '1',
			'woo_order_status_enabled' => '1',

			// ── Consent (DRAFT — must pass legal/privacy review before launch) ──
			'consent_required'     => '1',
			'consent_text_he'      => 'כדי ליצור רצף בין שיחות ה-AI לפגישות עם הצוות, נשמור את התשובות, הסיכומים, המשימות וההתקדמות שלך. מידע רלוונטי בלבד עשוי להיות משותף עם המאמן או איש הצוות שמטפל בפנייה שלך. לא נעביר מידע לאיש מקצוע חיצוני בלי הסכמה. אפשר לבקש מהצוות לעיין, לתקן או למחוק מידע בהתאם למדיניות החברה.',

			// WhatsApp channel stays OFF until Roni wires her Meta app credentials.
		];
	}
}
