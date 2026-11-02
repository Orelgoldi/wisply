<?php
/**
 * Real-estate edition — meeting scheduler.
 *
 * The agent defines weekly availability windows (settings → Booking). From those
 * the bot offers concrete slots, minus anything already booked here AND anything
 * marked busy in the connected Google Calendar (free/busy). Booking records the
 * meeting locally, creates a Google Calendar event, and emails the agent.
 *
 * Availability JSON shape (0 = Sunday … 6 = Saturday), 24h local times:
 *   { "0": [["09:00","13:00"],["16:00","19:00"]], "1": [["09:00","18:00"]], ... }
 *
 * REST (public widget):
 *   GET  /wisply/v1/booking/slots   → { days: [ { date, dow, label, slots:[{start,label}] } ] }
 *   POST /wisply/v1/booking/create  → { ok } | { ok:false, reason:'taken'|'invalid' }
 */
defined( 'ABSPATH' ) || exit;

class Wisply_Scheduler {

	private static ?self $instance = null;
	private Wisply_Database $db;

	const TABLE = 'wisply_bookings';

	private function __construct() {
		$this->db = Wisply_Database::get_instance();
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->maybe_create_table();
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Self-contained bookings table (edition-only), created once via dbDelta. */
	private function maybe_create_table(): void {
		if ( get_option( 'wisply_bookings_schema', '' ) === '1' ) return;
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = $this->table();
		dbDelta( "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			start_ts BIGINT UNSIGNED NOT NULL,
			end_ts BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(64) NOT NULL DEFAULT '',
			page TEXT NULL,
			gcal_event_id VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY start_ts (start_ts)
		) $charset;" );
		update_option( 'wisply_bookings_schema', '1' );
	}

	public function register_routes(): void {
		$perm = [ Wisply_Chatbot_API::get_instance(), 'rate_limit_check' ];
		register_rest_route( 'wisply/v1', '/booking/slots', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_slots' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( 'wisply/v1', '/booking/create', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_create' ],
			'permission_callback' => $perm,
			'args'                => [
				'start' => [ 'required' => true, 'type' => 'string' ],
				'name'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'phone' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'page'  => [ 'required' => false, 'type' => 'string' ],
			],
		] );
	}

	private function tz(): DateTimeZone {
		$name = (string) $this->db->get_setting( 'booking_timezone', 'Asia/Jerusalem' ) ?: 'Asia/Jerusalem';
		try { return new DateTimeZone( $name ); } catch ( \Exception $e ) { return new DateTimeZone( 'Asia/Jerusalem' ); }
	}

	private function availability(): array {
		$raw = json_decode( (string) $this->db->get_setting( 'booking_availability', '' ), true );
		return is_array( $raw ) ? $raw : [];
	}

	/** GET /booking/slots — the open day→time grid for the widget. */
	public function handle_slots( WP_REST_Request $req ) {
		if ( (string) $this->db->get_setting( 'booking_enabled', '1' ) === '0' ) {
			return rest_ensure_response( [ 'days' => [] ] );
		}
		return rest_ensure_response( [ 'days' => $this->build_days() ] );
	}

	private function build_days(): array {
		$tz        = $this->tz();
		$dur       = max( 10, (int) $this->db->get_setting( 'booking_duration_min', '30' ) );
		$buffer    = max( 0, (int) $this->db->get_setting( 'booking_buffer_min', '0' ) );
		$ahead     = min( 60, max( 1, (int) $this->db->get_setting( 'booking_days_ahead', '14' ) ) );
		$avail     = $this->availability();

		$now       = new DateTime( 'now', $tz );
		$windowEnd = ( clone $now )->modify( "+{$ahead} days" );
		$busy      = $this->busy_intervals( $now->getTimestamp(), $windowEnd->getTimestamp() );

		$dowHe = [ 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת' ];
		$days  = [];

		for ( $d = 0; $d < $ahead; $d++ ) {
			$day = ( clone $now )->modify( "+{$d} days" );
			$dow = (int) $day->format( 'w' ); // 0=Sun … 6=Sat
			$windows = $avail[ (string) $dow ] ?? [];
			if ( ! $windows ) continue;

			$slots = [];
			foreach ( $windows as $w ) {
				if ( empty( $w[0] ) || empty( $w[1] ) ) continue;
				$start = $this->at( $day, (string) $w[0] );
				$end   = $this->at( $day, (string) $w[1] );
				if ( ! $start || ! $end ) continue;

				for ( $t = clone $start; ; $t->modify( "+{$dur} minutes" ) ) {
					$slotEnd = ( clone $t )->modify( "+{$dur} minutes" );
					if ( $slotEnd > $end ) break;
					$ts = $t->getTimestamp();
					if ( $ts <= $now->getTimestamp() + 1800 ) continue;       // ≥30 min lead time
					if ( $this->collides( $ts, $slotEnd->getTimestamp(), $buffer, $busy ) ) continue;
					$slots[] = [
						'start' => $t->format( 'c' ),                          // ISO8601 w/ offset
						'label' => $t->format( 'H:i' ),
					];
					if ( count( $slots ) >= 24 ) break;
				}
			}
			if ( ! $slots ) continue;
			$days[] = [
				'date'  => $day->format( 'Y-m-d' ),
				'dow'   => $dowHe[ $dow ],
				'label' => $day->format( 'd/m' ),
				'slots' => $slots,
			];
			if ( count( $days ) >= 10 ) break; // show up to ~10 open days
		}
		return $days;
	}

	/** A DateTime at HH:MM on the given day, in the booking timezone. */
	private function at( DateTime $day, string $hhmm ): ?DateTime {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) return null;
		$dt = clone $day;
		$dt->setTime( (int) $m[1], (int) $m[2], 0 );
		return $dt;
	}

	/** Busy = local bookings ∪ Google Calendar busy, as [ [startTs,endTs], … ]. */
	private function busy_intervals( int $from, int $to ): array {
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT start_ts, end_ts FROM $table WHERE end_ts >= %d AND start_ts <= %d",
			$from, $to
		), ARRAY_A );
		$busy = [];
		foreach ( (array) $rows as $r ) { $busy[] = [ (int) $r['start_ts'], (int) $r['end_ts'] ]; }

		if ( class_exists( 'Wisply_Google_Calendar' ) ) {
			foreach ( Wisply_Google_Calendar::get_instance()->busy( $from, $to ) as $b ) { $busy[] = $b; }
		}
		return $busy;
	}

	private function collides( int $start, int $end, int $bufferMin, array $busy ): bool {
		$pad = $bufferMin * 60;
		foreach ( $busy as $b ) {
			if ( $start < ( $b[1] + $pad ) && ( $end + $pad ) > $b[0] ) return true;
		}
		return false;
	}

	/** POST /booking/create — validate the slot is still open, then book it. */
	public function handle_create( WP_REST_Request $req ) {
		if ( (string) $this->db->get_setting( 'booking_enabled', '1' ) === '0' ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'disabled' ], 400 );
		}
		$tz    = $this->tz();
		$name  = trim( (string) $req->get_param( 'name' ) );
		$phone = trim( (string) $req->get_param( 'phone' ) );
		$page  = esc_url_raw( (string) $req->get_param( 'page' ) );

		try { $start = new DateTime( (string) $req->get_param( 'start' ) ); $start->setTimezone( $tz ); }
		catch ( \Exception $e ) { return new WP_REST_Response( [ 'ok' => false, 'reason' => 'invalid' ], 400 ); }

		if ( mb_strlen( $name ) < 2 || strlen( preg_replace( '/\D/', '', $phone ) ) < 7 ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'invalid' ], 400 );
		}

		$dur     = max( 10, (int) $this->db->get_setting( 'booking_duration_min', '30' ) );
		$buffer  = max( 0, (int) $this->db->get_setting( 'booking_buffer_min', '0' ) );
		$startTs = $start->getTimestamp();
		$endTs   = $startTs + $dur * 60;

		if ( $startTs <= time() ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'invalid' ], 400 );
		}
		// Re-check collision at commit time (the slot may have been taken since it was
		// shown). Widen the lookup by the buffer so a near-adjacent meeting within the
		// buffer window is still seen by collides().
		$pad  = $buffer * 60 + 1;
		$busy = $this->busy_intervals( $startTs - $pad, $endTs + $pad );
		if ( $this->collides( $startTs, $endTs, $buffer, $busy ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'taken' ], 409 );
		}

		// Calendar event first — if Google is connected but rejects, we still record
		// locally (the agent gets the email); a missing event id just means no sync.
		$event_id = '';
		if ( class_exists( 'Wisply_Google_Calendar' ) && Wisply_Google_Calendar::get_instance()->is_connected() ) {
			$summary = sprintf( 'פגישה: %s', $name );
			$desc    = sprintf( "נקבע דרך הבוט באתר.\nשם: %s\nטלפון: %s\nעמוד: %s", $name, $phone, $page ?: '-' );
			$id = Wisply_Google_Calendar::get_instance()->insert_event(
				$startTs, $endTs, $summary, $desc, $tz->getName()
			);
			if ( $id ) $event_id = $id;
		}

		global $wpdb;
		$wpdb->insert( $this->table(), [
			'start_ts'      => $startTs,
			'end_ts'        => $endTs,
			'name'          => $name,
			'phone'         => $phone,
			'page'          => $page,
			'gcal_event_id' => $event_id,
			'created_at'    => current_time( 'mysql' ),
		] );

		$this->notify( $start, $name, $phone, $page, (bool) $event_id );

		// Forward to the CRM webhook (no-op unless configured). Never blocks the booking.
		if ( class_exists( 'Wisply_CRM' ) ) {
			Wisply_CRM::get_instance()->send_booking( [
				'name'     => $name,
				'phone'    => $phone,
				'start'    => $start->format( 'c' ),
				'end'      => gmdate( 'c', $endTs ),
				'duration' => $dur,
				'page'     => $page,
				'synced'   => (bool) $event_id,
			] );
		}

		return rest_ensure_response( [ 'ok' => true ] );
	}

	/** Email the agent that a meeting was booked. */
	private function notify( DateTime $start, string $name, string $phone, string $page, bool $synced ): void {
		$to = (string) $this->db->get_setting( 'booking_notify_email', '' );
		if ( ! is_email( $to ) ) $to = (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) return;

		$when = $start->format( 'd/m/Y H:i' );
		$subj = sprintf( 'פגישה חדשה נקבעה — %s', $when );
		$lines = [
			"נקבעה פגישה חדשה דרך הבוט באתר.",
			"",
			"מועד: {$when}",
			"שם: {$name}",
			"טלפון: {$phone}",
			$page ? "עמוד: {$page}" : '',
			$synced ? "סונכרן ליומן Google ✓" : "לא סונכרן ליומן (לא מחובר)",
		];
		wp_mail( $to, $subj, implode( "\n", array_filter( $lines ) ) );
	}
}
