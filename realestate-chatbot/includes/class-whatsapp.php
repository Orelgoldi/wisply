<?php
/**
 * WhatsApp channel — connects the same AI brain (Wisply_AI_Handler) to WhatsApp via
 * Meta's WhatsApp Cloud API. Free and direct: each white-label client wires up their own
 * Meta app + WhatsApp Business number, pastes the webhook URL, and the bot answers on
 * WhatsApp exactly as it does on the site. Reactive only (replies within Meta's 24h
 * customer-service window), so no template messaging is needed.
 */
defined( 'ABSPATH' ) || exit;

class Wisply_WhatsApp {

	private static ?self $instance = null;
	private Wisply_Database $db;

	private function __construct() {
		$this->db = Wisply_Database::get_instance();
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Settings accessors ─────────────────────────────────────────────────────
	private function enabled(): bool        { return $this->db->get_setting( 'wa_enabled', '0' ) === '1'; }
	private function access_token(): string { return (string) $this->db->get_setting( 'wa_access_token', '' ); }
	private function phone_id(): string     { return (string) $this->db->get_setting( 'wa_phone_number_id', '' ); }
	private function verify_token(): string  { return (string) $this->db->get_setting( 'wa_verify_token', '' ); }
	private function app_secret(): string    { return (string) $this->db->get_setting( 'wa_app_secret', '' ); }

	// ─── Routes ─────────────────────────────────────────────────────────────────
	public function register_routes(): void {
		register_rest_route( 'wisply/v1', '/whatsapp', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'handle_verify' ],   'permission_callback' => '__return_true' ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'handle_incoming' ], 'permission_callback' => '__return_true' ],
		] );
	}

	/**
	 * Meta webhook verification handshake (GET). Meta sends hub.mode / hub.verify_token /
	 * hub.challenge; PHP turns the dots into underscores in the query keys. Echo the
	 * challenge only when the verify token matches the one the owner configured.
	 */
	public function handle_verify( WP_REST_Request $req ) {
		$mode      = (string) $req->get_param( 'hub_mode' );
		$token     = (string) $req->get_param( 'hub_verify_token' );
		$challenge = (string) $req->get_param( 'hub_challenge' );
		$expected  = $this->verify_token();
		if ( $mode === 'subscribe' && $expected !== '' && hash_equals( $expected, $token ) ) {
			return new WP_REST_Response( (int) $challenge, 200 );
		}
		return new WP_REST_Response( 'forbidden', 403 );
	}

	/**
	 * Incoming message webhook (POST). Verifies Meta's HMAC signature, extracts text
	 * messages, runs each through the AI brain, and replies on WhatsApp. Always answers
	 * 200 fast so Meta does not retry; message-id dedupe guards against any retry anyway.
	 */
	public function handle_incoming( WP_REST_Request $req ) {
		if ( ! $this->enabled() ) {
			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}

		// Verify X-Hub-Signature-256 against the app secret (when configured).
		$secret = $this->app_secret();
		if ( $secret !== '' ) {
			$sig      = (string) $req->get_header( 'x_hub_signature_256' );
			$expected = 'sha256=' . hash_hmac( 'sha256', $req->get_body(), $secret );
			if ( $sig === '' || ! hash_equals( $expected, $sig ) ) {
				return new WP_REST_Response( 'bad signature', 403 );
			}
		}

		$data = $req->get_json_params();
		try {
			foreach ( (array) ( $data['entry'] ?? [] ) as $entry ) {
				foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
					$messages = $change['value']['messages'] ?? [];
					foreach ( (array) $messages as $msg ) {
						if ( ( $msg['type'] ?? '' ) !== 'text' ) continue;

						// Dedupe on the WhatsApp message id — a slow AI reply can make Meta
						// retry the same webhook, which would otherwise answer twice.
						$mid = (string) ( $msg['id'] ?? '' );
						if ( $mid !== '' ) {
							$key = 'wisply_wa_' . md5( $mid );
							if ( get_transient( $key ) ) continue;
							set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
						}

						$from = preg_replace( '/\D/', '', (string) ( $msg['from'] ?? '' ) );
						$text = trim( (string) ( $msg['text']['body'] ?? '' ) );
						if ( $from === '' || $text === '' ) continue;

						// If this is the AGENT replying during a live handoff, route it into
						// the website conversation instead of letting the bot answer it.
						if ( $this->route_agent_reply( $from, $text ) ) continue;

						$this->handle_message( $from, $text );
					}
				}
			}
		} catch ( Throwable $e ) {
			error_log( '[Wisply WhatsApp] ' . $e->getMessage() );
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/** Run one WhatsApp message through the AI brain and reply. */
	private function handle_message( string $from, string $text ): void {
		if ( ! class_exists( 'Wisply_AI_Handler' ) ) return;

		$lang    = (string) $this->db->get_setting( 'default_lang', 'he' );
		if ( $lang === '' ) $lang = 'he';
		$session = 'wa:' . $from;                       // one conversation per phone number
		$ip_hash = md5( 'whatsapp:' . $from . wp_salt() );

		$conv_id = $this->db->get_or_create_conversation( $session, $lang, $ip_hash, 'whatsapp' );
		$history = $this->db->get_conversation_messages( $conv_id, 8 );
		$this->db->add_message( $conv_id, 'user', $text );

		$result = Wisply_AI_Handler::get_instance()->get_reply( $text, $lang, $history, 0, '' );
		$reply  = $this->plainify( (string) ( $result['reply'] ?? '' ) );
		$this->db->add_message( $conv_id, 'assistant', $reply, (bool) ( $result['unanswered'] ?? false ) );

		if ( $reply !== '' ) {
			$this->send_text( $from, $reply );
		}
	}

	/** Strip the widget-only markers so WhatsApp receives clean, human text. */
	private function plainify( string $s ): string {
		$s = preg_replace( '/\[(ACTION|OPTIONS|PRODUCTS|SUGGEST|ORDER_FORM|ASK_LEAD|SHOW_LEAD_FORM|EMERGENCY)[:\]][^\]]*\]?/i', '', $s );
		return trim( (string) $s );
	}

	// ─── Live-agent handoff bridge ──────────────────────────────────────────────
	// State lives in transients (6h), so no schema change:
	//   wisply_ho_{md5(session)} → [ agent, conv, started ]   (session is in human mode)
	//   wisply_ag_{agentDigits}  → session                    (agent's active conversation)

	const HANDOFF_TTL = 6 * HOUR_IN_SECONDS;

	public static function ho_key( string $session ): string { return 'wisply_ho_' . md5( $session ); }
	public static function ag_key( string $agent ): string   { return 'wisply_ag_' . preg_replace( '/\D/', '', $agent ); }

	/** Put a website conversation into human mode and bind it to an agent's WhatsApp. */
	public function start_handoff( string $session, int $conv_id, string $agent_digits ): void {
		set_transient( self::ho_key( $session ), [ 'agent' => $agent_digits, 'conv' => $conv_id, 'started' => time() ], self::HANDOFF_TTL );
		set_transient( self::ag_key( $agent_digits ), $session, self::HANDOFF_TTL );
	}

	/** Current human-mode state for a session, or null. */
	public function handoff_state( string $session ): ?array {
		$s = get_transient( self::ho_key( $session ) );
		return is_array( $s ) ? $s : null;
	}

	/** End human mode for a session (back to the bot). */
	public function end_handoff( string $session ): void {
		$s = $this->handoff_state( $session );
		if ( $s && ! empty( $s['agent'] ) ) delete_transient( self::ag_key( $s['agent'] ) );
		delete_transient( self::ho_key( $session ) );
	}

	/** Public send — used by the handoff bridge to message the agent. */
	public function notify( string $to, string $body ): void {
		$this->send_text( preg_replace( '/\D/', '', $to ), $body );
	}

	/** The configured agent's WhatsApp digits (for handoff), or ''. */
	public function agent_number(): string {
		return preg_replace( '/\D/', '', (string) $this->db->get_setting( 'handoff_wa_number', '' ) );
	}

	/**
	 * A message arrived FROM the agent's number while a handoff is live. Route it into
	 * the bound website conversation (as an 'agent' message the visitor's widget polls
	 * for) instead of answering it with the bot. Returns true when it was handled.
	 */
	private function route_agent_reply( string $from, string $text ): bool {
		$agent = preg_replace( '/\D/', '', $from );
		if ( $agent === '' || $agent !== $this->agent_number() ) return false;

		$session = get_transient( self::ag_key( $agent ) );
		if ( ! is_string( $session ) || $session === '' ) return false;

		$conv_id = $this->db->find_conversation( $session );
		if ( $conv_id <= 0 ) return false;

		// Agent command to release the chat back to the bot.
		if ( preg_match( '/^(סיום|סגור|end|close|bye)\s*$/iu', trim( $text ) ) ) {
			$this->end_handoff( $session );
			$this->db->add_message( $conv_id, 'system', 'השיחה עם הנציג הסתיימה.' );
			$this->notify( $agent, '✅ סגרת את השיחה. הבוט חזר לענות ללקוח.' );
			return true;
		}

		$this->db->add_message( $conv_id, 'agent', $text );
		// keep the window fresh
		$this->start_handoff( $session, $conv_id, $agent );
		return true;
	}

	/** Send a text reply via the WhatsApp Cloud API. */
	private function send_text( string $to, string $body ): void {
		$phone_id = $this->phone_id();
		$token    = $this->access_token();
		if ( $phone_id === '' || $token === '' ) return;

		wp_remote_post( "https://graph.facebook.com/v21.0/{$phone_id}/messages", [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'messaging_product' => 'whatsapp',
				'to'                => $to,
				'type'              => 'text',
				'text'              => [ 'body' => mb_substr( $body, 0, 4000 ) ],
			] ),
		] );
	}
}
