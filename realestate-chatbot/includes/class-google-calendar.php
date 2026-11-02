<?php
/**
 * Real-estate edition — Google Calendar two-way sync.
 *
 * Per-site OAuth2: the agent enters their own Google Cloud client id/secret in
 * settings, clicks Connect, and we store the long-lived refresh token (encrypted,
 * via the DB layer's ENCRYPTED_KEYS). From then on the scheduler can:
 *   • read free/busy  → never offer a slot that collides with a real calendar event
 *   • insert an event → a booked meeting lands straight in the agent's calendar
 *
 * The redirect URI the agent must whitelist in Google Cloud is shown in the admin
 * screen and equals  <site>/wp-json/wisply/v1/gcal/callback .
 */
defined( 'ABSPATH' ) || exit;

class Wisply_Google_Calendar {

	private static ?self $instance = null;
	private Wisply_Database $db;

	const SCOPE     = 'https://www.googleapis.com/auth/calendar';
	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/calendar/v3';

	private function __construct() {
		$this->db = Wisply_Database::get_instance();
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// The "Connect" button points here (admin-only), which 302s to Google.
		add_action( 'admin_post_wisply_gcal_connect', [ $this, 'start_oauth' ] );
		add_action( 'admin_post_wisply_gcal_disconnect', [ $this, 'disconnect' ] );
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes(): void {
		// Google redirects the browser here after consent. Public by necessity
		// (Google holds no WP cookie), but it is single-use: it only exchanges a
		// one-time code it itself issued, and verifies our state nonce.
		register_rest_route( 'wisply/v1', '/gcal/callback', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'oauth_callback' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function redirect_uri(): string {
		return rest_url( 'wisply/v1/gcal/callback' );
	}

	public function is_connected(): bool {
		return (string) $this->db->get_setting( 'gcal_refresh_token', '' ) !== '';
	}

	/** Admin-only: kick off the OAuth consent flow. */
	public function start_oauth(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		$client_id = (string) $this->db->get_setting( 'gcal_client_id', '' );
		if ( $client_id === '' ) wp_die( 'Set the Google client id/secret first.' );

		$state = wp_create_nonce( 'wisply_gcal' );
		set_transient( 'wisply_gcal_state', $state, 600 );
		$args = [
			'client_id'     => $client_id,
			'redirect_uri'  => $this->redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent', // force a refresh_token every time
			'state'         => $state,
		];
		wp_redirect( self::AUTH_URL . '?' . http_build_query( $args ) );
		exit;
	}

	public function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		check_admin_referer( 'wisply_gcal_disconnect' );
		$this->db->set_setting( 'gcal_refresh_token', '' );
		wp_safe_redirect( admin_url( 'admin.php?page=wisply-booking&gcal=disconnected' ) );
		exit;
	}

	/** Google's redirect lands here with ?code=…&state=…. Exchange for a refresh token. */
	public function oauth_callback( WP_REST_Request $req ) {
		$code  = (string) $req->get_param( 'code' );
		$state = (string) $req->get_param( 'state' );
		$saved = (string) get_transient( 'wisply_gcal_state' );
		if ( ! $code || ! $state || ! hash_equals( $saved, $state ) ) {
			return $this->bounce( 'error' );
		}
		delete_transient( 'wisply_gcal_state' );

		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'code'          => $code,
				'client_id'     => (string) $this->db->get_setting( 'gcal_client_id', '' ),
				'client_secret' => (string) $this->db->get_setting( 'gcal_client_secret', '' ),
				'redirect_uri'  => $this->redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $res ) ) return $this->bounce( 'error' );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['refresh_token'] ) ) return $this->bounce( 'error' );

		$this->db->set_setting( 'gcal_refresh_token', (string) $body['refresh_token'] );
		return $this->bounce( 'connected' );
	}

	private function bounce( string $status ) {
		wp_safe_redirect( admin_url( 'admin.php?page=wisply-booking&gcal=' . $status ) );
		exit;
	}

	/** A fresh access token from the stored refresh token (cached ~55 min). */
	private function access_token(): ?string {
		$cached = get_transient( 'wisply_gcal_access' );
		if ( $cached ) return (string) $cached;

		$refresh = (string) $this->db->get_setting( 'gcal_refresh_token', '' );
		if ( $refresh === '' ) return null;

		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'client_id'     => (string) $this->db->get_setting( 'gcal_client_id', '' ),
				'client_secret' => (string) $this->db->get_setting( 'gcal_client_secret', '' ),
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			],
		] );
		if ( is_wp_error( $res ) ) return null;
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['access_token'] ) ) return null;

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
		set_transient( 'wisply_gcal_access', $body['access_token'], $ttl );
		return (string) $body['access_token'];
	}

	/**
	 * Busy intervals in [from, to] as a list of [startTs, endTs] (unix seconds).
	 * Returns [] when not connected or on any failure — the scheduler then falls
	 * back to its own availability windows + locally-recorded bookings, so a
	 * calendar hiccup never blocks a booking (it just can't catch an external clash).
	 */
	public function busy( int $from, int $to ): array {
		$token = $this->access_token();
		if ( ! $token ) return [];
		$cal = (string) $this->db->get_setting( 'gcal_calendar_id', 'primary' ) ?: 'primary';

		$res = wp_remote_post( self::API_BASE . '/freeBusy', [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'timeMin' => gmdate( 'c', $from ),
				'timeMax' => gmdate( 'c', $to ),
				'items'   => [ [ 'id' => $cal ] ],
			] ),
		] );
		if ( is_wp_error( $res ) ) return [];
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$slots = $body['calendars'][ $cal ]['busy'] ?? [];
		$out = [];
		foreach ( (array) $slots as $b ) {
			if ( empty( $b['start'] ) || empty( $b['end'] ) ) continue;
			$out[] = [ strtotime( $b['start'] ), strtotime( $b['end'] ) ];
		}
		return $out;
	}

	/**
	 * Create a calendar event. Returns the event id on success, null otherwise.
	 * Times are unix seconds; $tz is an IANA zone (e.g. Asia/Jerusalem).
	 */
	public function insert_event( int $start, int $end, string $summary, string $desc, string $tz, string $attendee_email = '' ): ?string {
		$token = $this->access_token();
		if ( ! $token ) return null;
		$cal = (string) $this->db->get_setting( 'gcal_calendar_id', 'primary' ) ?: 'primary';

		$event = [
			'summary'     => $summary,
			'description' => $desc,
			'start'       => [ 'dateTime' => gmdate( 'c', $start ), 'timeZone' => $tz ],
			'end'         => [ 'dateTime' => gmdate( 'c', $end ),   'timeZone' => $tz ],
		];
		if ( $attendee_email && is_email( $attendee_email ) ) {
			$event['attendees'] = [ [ 'email' => $attendee_email ] ];
		}

		$res = wp_remote_post( self::API_BASE . '/calendars/' . rawurlencode( $cal ) . '/events', [
			'timeout' => 20,
			'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $event ),
		] );
		if ( is_wp_error( $res ) ) return null;
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return isset( $body['id'] ) ? (string) $body['id'] : null;
	}
}
