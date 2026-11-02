<?php
/**
 * Real-estate edition — project gallery store.
 *
 * Projects are kept as a JSON array in the `projects` setting: the owner curates
 * them in wp-admin, and/or they are seeded automatically from the site's own
 * published pages/posts that carry a featured image (the "both" model the client
 * chose). The chat widget pulls them through /wisply/v1/projects and renders a
 * WhatsApp-style image carousel.
 *
 * Each project: { id, name, location, price, image, url, enabled }.
 * `id` is a stable slug derived from the URL so re-seeding never duplicates a row
 * nor clobbers a manual edit.
 */
defined( 'ABSPATH' ) || exit;

class Wisply_Projects {

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

	public function register_routes(): void {
		register_rest_route( 'wisply/v1', '/projects', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_projects' ],
			'permission_callback' => [ Wisply_Chatbot_API::get_instance(), 'rate_limit_check' ],
			'args'                => [
				'ids' => [ 'required' => false, 'type' => 'array', 'default' => [] ],
			],
		] );
	}

	/** Public: return enabled projects, optionally narrowed to the requested ids. */
	public function handle_projects( WP_REST_Request $req ) {
		$ids  = array_filter( array_map( 'strval', (array) $req->get_param( 'ids' ) ) );
		$all  = $this->get_projects();
		$out  = [];
		foreach ( $all as $p ) {
			if ( empty( $p['enabled'] ) ) continue;
			if ( $ids && ! in_array( (string) $p['id'], $ids, true ) ) continue;
			$out[] = [
				'id'       => (string) $p['id'],
				'name'     => (string) $p['name'],
				'location' => (string) ( $p['location'] ?? '' ),
				'price'    => (string) ( $p['price'] ?? '' ),
				'image'    => (string) ( $p['image'] ?? '' ),
				'url'      => (string) ( $p['url'] ?? '' ),
			];
			if ( count( $out ) >= 24 ) break; // hard cap — a carousel, not a dump
		}
		return rest_ensure_response( [ 'projects' => $out ] );
	}

	/** The curated list, decoded and normalised. */
	public function get_projects(): array {
		$raw = json_decode( (string) $this->db->get_setting( 'projects', '' ), true );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/** Persist the list (used by the admin screen). */
	public function save_projects( array $projects ): void {
		$clean = [];
		foreach ( $projects as $p ) {
			$url  = esc_url_raw( (string) ( $p['url'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( $name === '' && $url === '' ) continue;
			$clean[] = [
				'id'       => (string) ( $p['id'] ?? $this->slug( $url ?: $name ) ),
				'name'     => $name,
				'location' => sanitize_text_field( (string) ( $p['location'] ?? '' ) ),
				'price'    => sanitize_text_field( (string) ( $p['price'] ?? '' ) ),
				'image'    => esc_url_raw( (string) ( $p['image'] ?? '' ) ),
				'url'      => $url,
				'enabled'  => ! empty( $p['enabled'] ),
			];
		}
		$this->db->set_setting( 'projects', wp_json_encode( $clean ) );
	}

	/**
	 * Seed the gallery from the site's own content: every published page/post that
	 * carries a featured image becomes a candidate project. Existing rows (matched by
	 * id/url) are never overwritten, so this is safe to run repeatedly and never
	 * clobbers a manual edit. Returns the number of NEW projects added.
	 */
	public function seed_from_site( int $limit = 40 ): int {
		$existing = $this->get_projects();
		$seen     = [];
		foreach ( $existing as $p ) { $seen[ (string) $p['id'] ] = true; }

		$posts = get_posts( [
			'post_type'      => [ 'page', 'post' ],
			'post_status'    => 'publish',
			'numberposts'    => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$added = 0;
		foreach ( $posts as $post ) {
			$thumb = get_the_post_thumbnail_url( $post->ID, 'large' );
			if ( ! $thumb ) continue;                       // no image → not a gallery card
			$url  = get_permalink( $post );
			$id   = $this->slug( $url );
			if ( isset( $seen[ $id ] ) ) continue;
			$existing[] = [
				'id'       => $id,
				'name'     => get_the_title( $post ),
				'location' => '',
				'price'    => '',
				'image'    => $thumb,
				'url'      => $url,
				'enabled'  => true,
			];
			$seen[ $id ] = true;
			$added++;
		}
		if ( $added ) {
			$this->db->set_setting( 'projects', wp_json_encode( array_values( $existing ) ) );
		}
		return $added;
	}

	private function slug( string $s ): string {
		return substr( md5( $s ), 0, 12 );
	}
}
