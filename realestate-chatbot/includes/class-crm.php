<?php
/**
 * Real-estate edition — generic CRM webhook.
 *
 * The client's CRM is unknown up front, so instead of coding to one vendor we
 * forward every booking and every captured lead to a single configurable webhook
 * as clean JSON. Whatever CRM they use (or a Make/Zapier scenario in front of it),
 * they paste one URL and it starts receiving events — "enter it and it works".
 *
 * Payload (POST, application/json):
 *   { event: 'booking'|'lead', ts: <unix>, site: <home_url>, data: { … } }
 * Signed with X-Wisply-Signature: sha256=<hmac(body, secret)> when a secret is set,
 * so the CRM can verify authenticity.
 */
defined( 'ABSPATH' ) || exit;

class Wisply_CRM {

	private static ?self $instance = null;
	private Wisply_Database $db;

	private function __construct() {
		$this->db = Wisply_Database::get_instance();
		// Leads flow through the engine's lead handler, which now fires this action.
		add_action( 'wisply_lead_captured', [ $this, 'on_lead' ] );
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_enabled(): bool {
		return (string) $this->db->get_setting( 'crm_enabled', '0' ) === '1'
			&& $this->webhook_url() !== '';
	}

	private function webhook_url(): string {
		return esc_url_raw( (string) $this->db->get_setting( 'crm_webhook_url', '' ) );
	}

	/** Forward a captured lead (hooked). */
	public function on_lead( array $lead ): void {
		$this->send( 'lead', $lead );
	}

	/** Forward a booked meeting (called directly by the scheduler). */
	public function send_booking( array $booking ): void {
		$this->send( 'booking', $booking );
	}

	/**
	 * POST an event to the configured webhook. Returns [ ok, code, body ] so the
	 * admin "test" button can show a real result. Never throws — a CRM outage must
	 * not break a booking or a lead; failures are logged and reported.
	 */
	public function send( string $event, array $data ): array {
		$url = $this->webhook_url();
		if ( $url === '' ) return [ 'ok' => false, 'code' => 0, 'body' => 'no url' ];
		if ( ! $this->is_enabled() && $event !== 'test' ) {
			return [ 'ok' => false, 'code' => 0, 'body' => 'disabled' ];
		}

		$payload = wp_json_encode( [
			'event' => $event,
			'ts'    => time(),
			'site'  => home_url(),
			'data'  => $data,
		] );

		$headers = [ 'Content-Type' => 'application/json' ];
		$secret  = (string) $this->db->get_setting( 'crm_webhook_secret', '' );
		if ( $secret !== '' ) {
			$headers['X-Wisply-Signature'] = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );
		}

		$res = wp_remote_post( $url, [
			'timeout' => 12,
			'headers' => $headers,
			'body'    => $payload,
		] );
		if ( is_wp_error( $res ) ) {
			error_log( '[Wisply CRM] webhook failed: ' . $res->get_error_message() );
			return [ 'ok' => false, 'code' => 0, 'body' => $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;
		if ( ! $ok ) {
			error_log( '[Wisply CRM] webhook HTTP ' . $code );
		}
		return [ 'ok' => $ok, 'code' => $code, 'body' => substr( (string) wp_remote_retrieve_body( $res ), 0, 300 ) ];
	}
}
