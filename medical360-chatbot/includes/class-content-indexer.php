<?php
defined( 'ABSPATH' ) || exit;

class M360_Content_Indexer {

    private static ?self $instance = null;
    private M360_Database $db;

    // Post types to index
    private const POST_TYPES = [ 'post', 'page', 'podcast', 'workshop', 'department', 'team_member', 'faq' ];

    // WPML / Polylang locale → our lang code
    private const LOCALE_MAP = [
        'he_IL' => 'he',
        'en_US' => 'en',
        'en_GB' => 'en',
        'ru_RU' => 'ru',
    ];

    private function __construct() {
        $this->db = M360_Database::get_instance();
        $this->register_hooks();
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function register_hooks(): void {
        // Index / remove individual posts on save/delete
        add_action( 'save_post',   [ $this, 'on_save_post' ], 10, 2 );
        add_action( 'delete_post', [ $this, 'on_delete_post' ] );
        add_action( 'trashed_post', [ $this, 'on_delete_post' ] );
    }

    // ─── Triggered on post save ───────────────────────────────────────────────

    public function on_save_post( int $post_id, WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || $post->post_status !== 'publish' ) return;
        if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) return;
        $this->index_post( $post );
    }

    public function on_delete_post( int $post_id ): void {
        // Removing from index is handled automatically: next full reindex will skip missing posts.
    }

    // ─── Full reindex ─────────────────────────────────────────────────────────

    public function full_reindex(): array {
        // Ensure tables exist before writing — abort with a clear error if not
        $table_errors = $this->db->create_tables();
        if ( ! empty( $table_errors ) ) {
            return [ 'indexed' => 0, 'failed' => 0, 'db_error' => implode( '; ', $table_errors ) ];
        }

        $this->db->clear_content_index();

        @set_time_limit( 300 );

        $indexed  = 0;
        $failed   = 0;
        $page     = 1;

        do {
            $query = new WP_Query( [
                'post_type'      => self::POST_TYPES,
                'post_status'    => 'publish',
                'posts_per_page' => 50,
                'paged'          => $page,
                'no_found_rows'  => false,
            ] );

            foreach ( $query->posts as $post ) {
                $ok = $this->index_post( $post );
                $ok ? $indexed++ : $failed++;
            }

            $page++;
        } while ( $query->max_num_pages >= $page );

        // Index navigation menus — the authoritative list of departments/services
        $indexed += $this->index_menus();

        // Index the global footer — address, phones, directions (not in any page body)
        $indexed += $this->index_footer();

        // Index the contact page via rendered HTML — captures every department's
        // phone & email (our text filter drops bare phone numbers otherwise)
        $indexed += $this->index_contact_page();

        $result = [ 'indexed' => $indexed, 'failed' => $failed ];
        if ( $failed > 0 ) {
            $result['db_error'] = $this->db->get_last_db_error();
        }
        return $result;
    }

    // ─── Index a single post ──────────────────────────────────────────────────

    public function index_post( WP_Post $post ): bool {
        $lang  = $this->detect_lang( $post );
        $text  = $this->extract_content( $post );
        $title = get_the_title( $post );

        $ok = $this->db->upsert_content( [
            'post_id'   => $post->ID,
            'post_type' => $post->post_type,
            'lang'      => $lang,
            'title'     => $title,
            'content'   => $text,
            'url'       => get_permalink( $post->ID ),
        ] );

        if ( function_exists( 'icl_get_languages' ) ) {
            $this->index_wpml_translations( $post );
        }
        return $ok;
    }

    // ─── Index navigation menus ───────────────────────────────────────────────

    /**
     * Index every WordPress nav menu as a structured list. Departments/services
     * usually live in the menu, NOT in page content — so this is the only way
     * the bot can answer "what departments are there?" with the full list.
     */
    private function index_menus(): int {
        if ( ! function_exists( 'wp_get_nav_menus' ) ) return 0;
        $menus = wp_get_nav_menus();
        if ( empty( $menus ) ) return 0;

        $count = 0;
        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            if ( empty( $items ) ) continue;

            // Build a hierarchical bullet list (children indented under parents)
            $by_parent = [];
            foreach ( $items as $it ) {
                $by_parent[ (int) $it->menu_item_parent ][] = $it;
            }
            $lines = [];
            $render = function ( $parent_id, $depth ) use ( &$render, &$by_parent, &$lines ) {
                foreach ( $by_parent[ $parent_id ] ?? [] as $it ) {
                    $title = trim( wp_strip_all_tags( $it->title ) );
                    if ( $title ) {
                        $lines[] = str_repeat( '  ', $depth ) . '• ' . $title;
                    }
                    $render( (int) $it->ID, $depth + 1 );
                }
            };
            $render( 0, 0 );

            if ( empty( $lines ) ) continue;

            $content = "רשימת המחלקות, השירותים והעמודים באתר מדיקל קר (מתוך תפריט \"{$menu->name}\"):\n"
                     . implode( "\n", $lines );

            $ok = $this->db->upsert_content( [
                'post_id'   => 900000 + (int) $menu->term_id,
                'post_type' => 'menu',
                'lang'      => 'he',
                'title'     => 'רשימת המחלקות והשירותים (תפריט האתר)',
                'content'   => $content,
                'url'       => home_url( '/' ),
            ] );
            if ( $ok ) $count++;
        }
        return $count;
    }

    // ─── Index global footer (contact / address / directions) ─────────────────

    /**
     * Fetch the home page and index the footer — address, phone numbers and
     * directions live in the global footer/header template, not in any page
     * body, so the crawler never sees them otherwise.
     */
    private function index_footer(): int {
        $response = wp_remote_get( home_url( '/' ), [ 'timeout' => 15, 'user-agent' => 'Medical360-Bot/1.0' ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return 0;
        }
        $html = wp_remote_retrieve_body( $response );

        // Detect action links (donate / jobs / podcast / volunteer / contact)
        $this->detect_action_links( $html );

        // Footer markup (take the longest <footer>…</footer>)
        $footer_text = '';
        if ( preg_match_all( '/<footer\b[^>]*>(.*?)<\/footer>/is', $html, $m ) && ! empty( $m[1] ) ) {
            usort( $m[1], fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
            $footer_text = $this->extract_text_from_html( $m[1][0] );
        }

        // Pull address + phones from the whole page (robust against markup)
        $page_text = $this->extract_text_from_html( $html );

        $phones = [];
        if ( preg_match_all( '/0\d{1,2}[-\s]?\d{7}/', $page_text, $pm ) ) {
            $phones = array_values( array_unique( $pm[0] ) );
        }

        $address = '';
        if ( preg_match( '/[^.\n]*(?:כתובת|רחוב|רח׳|שדרות|בת ים|ברדיצ)[^.\n]{0,90}/u', $page_text, $am ) ) {
            $address = trim( preg_replace( '/\s+/u', ' ', $am[0] ) );
        }

        if ( ! $footer_text && ! $address && empty( $phones ) ) {
            return 0;
        }

        $parts = [ 'פרטי קשר, כתובת והגעה — מדיקל קר:' ];
        if ( $address )  $parts[] = $address;
        if ( $phones )   $parts[] = 'טלפונים: ' . implode( ', ', array_slice( $phones, 0, 4 ) );
        if ( $footer_text ) $parts[] = "\n" . mb_substr( $footer_text, 0, 2000 );

        $ok = $this->db->upsert_content( [
            'post_id'   => 950000,
            'post_type' => 'contact',
            'lang'      => 'he',
            'title'     => 'פרטי קשר, כתובת והגעה למדיקל קר',
            'content'   => implode( "\n", $parts ),
            'url'       => home_url( '/' ),
        ] );
        return $ok ? 1 : 0;
    }

    /**
     * Find the contact page (by title) and index its full rendered text as a
     * high-priority 'contact' doc — so per-department phones & emails are searchable.
     */
    private function index_contact_page(): int {
        $pages = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
        ] );
        foreach ( (array) $pages as $pid ) {
            if ( ! preg_match( '/יצירת קשר|צור קשר|צרו קשר|contact/iu', get_the_title( $pid ) ) ) {
                continue;
            }
            $text = $this->fetch_rendered_text( $pid );   // full visible text incl. phone numbers
            if ( mb_strlen( $text ) < 50 ) {
                $post = get_post( $pid );
                $text = $post ? $this->extract_content( $post ) : '';
            }
            if ( mb_strlen( $text ) < 50 ) return 0;

            $this->db->upsert_content( [
                'post_id'   => (int) $pid,
                'post_type' => 'contact',
                'lang'      => 'he',
                'title'     => 'יצירת קשר — טלפונים ומיילים של המחלקות',
                'content'   => $text,
                'url'       => get_permalink( $pid ),
            ] );
            return 1;
        }
        return 0;
    }

    /**
     * Scan the home page for the site's key action links and store them, so the
     * bot can offer a "go to donations / jobs / podcast" button.
     */
    private function detect_action_links( string $html ): void {
        $patterns = [
            'donate'    => '/תרומ|donat/iu',
            'jobs'      => '/דרושים|קריירה|jobs/iu',
            'podcast'   => '/פודקאסט|podcast/iu',
            'volunteer' => '/התנדב|volunt/iu',
            'contact'   => '/יצירת קשר|צרו קשר|צור קשר|contact/iu',
        ];
        $found = [];

        if ( preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $a ) {
                $href  = trim( $a[1] );
                $label = trim( wp_strip_all_tags( $a[2] ) );
                if ( ! $href || $href[0] === '#' || stripos( $href, 'javascript:' ) === 0 ) continue;
                foreach ( $patterns as $key => $re ) {
                    if ( isset( $found[ $key ] ) ) continue;
                    if ( preg_match( $re, $label ) || preg_match( $re, $href ) ) {
                        $found[ $key ] = esc_url_raw( $href );
                    }
                }
            }
        }

        if ( ! empty( $found ) ) {
            $this->db->set_setting( 'action_links', wp_json_encode( $found ) );
        }
    }

    // ─── WPML translation indexing ────────────────────────────────────────────

    private function index_wpml_translations( WP_Post $post ): void {
        $trid         = apply_filters( 'wpml_element_trid', null, $post->ID, 'post_' . $post->post_type );
        $translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post->post_type );

        if ( ! is_array( $translations ) ) return;

        foreach ( $translations as $locale => $translation ) {
            if ( empty( $translation->element_id ) || $translation->element_id == $post->ID ) continue;

            $translated = get_post( $translation->element_id );
            if ( ! $translated || $translated->post_status !== 'publish' ) continue;

            $lang  = self::LOCALE_MAP[ $locale ] ?? substr( $locale, 0, 2 );
            $text  = $this->extract_content( $translated );
            $title = get_the_title( $translated );

            $this->db->upsert_content( [
                'post_id'   => $translated->ID,
                'post_type' => $translated->post_type,
                'lang'      => $lang,
                'title'     => $title,
                'content'   => $text,
                'url'       => get_permalink( $translated->ID ),
            ] );
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract ALL visible text from a post, regardless of how it was built:
     *  1. the_content filter (Gutenberg blocks, shortcodes, classic editor)
     *  2. Elementor / page-builder data stored in post meta as JSON
     *  3. Excerpt + common custom fields (ACF, SEO, etc.)
     *  4. If still too little text, fetch the rendered page HTML as a last resort
     */
    private function extract_content( WP_Post $post ): string {
        $parts = [];

        // 1. Rendered content (handles Gutenberg blocks + shortcodes)
        $rendered = apply_filters( 'the_content', $post->post_content );
        $parts[]  = $this->html_to_text( $rendered );

        // 2. Page-builder content (Elementor, WPBakery, etc.) lives in meta JSON
        $parts[] = $this->extract_builder_text( $post->ID );

        // 3. Excerpt + custom fields
        if ( $post->post_excerpt ) {
            $parts[] = $this->html_to_text( $post->post_excerpt );
        }
        $meta_keys = [ 'description', '_yoast_wpseo_metadesc', 'faq_answer', 'department_info', 'address', 'phone' ];
        foreach ( $meta_keys as $key ) {
            $val = get_post_meta( $post->ID, $key, true );
            if ( $val && is_string( $val ) ) {
                $parts[] = $this->html_to_text( $val );
            }
        }

        $content = trim( implode( "\n", array_filter( array_map( 'trim', $parts ) ) ) );

        // 4. Fallback: if we still have very little, fetch the rendered page
        if ( mb_strlen( $content ) < 120 ) {
            $fetched = $this->fetch_rendered_text( $post->ID );
            if ( mb_strlen( $fetched ) > mb_strlen( $content ) ) {
                $content = $fetched;
            }
        }

        // Normalise whitespace
        $content = preg_replace( "/\n{3,}/", "\n\n", $content );
        return trim( $content );
    }

    /** Strip HTML to readable text, preserving line breaks for block elements. */
    private function html_to_text( string $html ): string {
        $html = preg_replace( '/<(script|style|noscript)[^>]*>.*?<\/\1>/si', '', $html );
        $html = preg_replace( '/<\/(p|div|li|h[1-6]|br|tr|td|th|section)>/i', "\n", $html );
        $text = wp_strip_all_tags( $html );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        return trim( $text );
    }

    /**
     * Pull all human-readable text out of page-builder JSON stored in post meta.
     * Covers Elementor (_elementor_data) and any meta holding a JSON tree.
     */
    private function extract_builder_text( int $post_id ): string {
        $out = [];

        // Elementor
        $elementor = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor ) ) {
            $decoded = is_string( $elementor ) ? json_decode( $elementor, true ) : $elementor;
            if ( is_array( $decoded ) ) {
                $this->collect_strings( $decoded, $out );
            }
        }

        // Keep original order AND repeated labels — dropping duplicates would
        // detach repeated headings (e.g. "מנהל המחלקה:") from later departments.
        return implode( "\n", $out );
    }

    /** Recursively collect strings that look like human text from a nested array. */
    private function collect_strings( $node, array &$out ): void {
        if ( ! is_array( $node ) ) return;
        foreach ( $node as $val ) {
            if ( is_array( $val ) ) {
                $this->collect_strings( $val, $out );
            } elseif ( is_string( $val ) ) {
                $clean = $this->html_to_text( $val );
                if ( $this->looks_like_text( $clean ) ) {
                    $out[] = $clean;
                }
            }
        }
    }

    /** Heuristic: is this string human-readable content (not a color/number/url/css)? */
    private function looks_like_text( string $s ): bool {
        if ( mb_strlen( $s ) < 3 )                          return false; // too short
        // Keep phone numbers and emails even though they have no letters
        if ( preg_match( '/0\d{1,2}[-\s]?\d{6,7}/', $s ) )  return true;  // phone
        if ( preg_match( '/[\w.+-]+@[\w-]+\.\w+/', $s ) )   return true;  // email
        if ( ! preg_match( '/\p{L}/u', $s ) )               return false; // no letters
        if ( preg_match( '/^#?[0-9a-f]{3,8}$/i', $s ) )     return false; // hex color
        if ( preg_match( '/^[0-9\s.,%a-z-]{1,6}$/i', $s ) ) return false; // size/unit token
        if ( preg_match( '/^https?:\/\/\S+$/i', $s ) )      return false; // bare URL
        if ( preg_match( '/^[a-z0-9_-]+$/i', $s ) && mb_strlen( $s ) < 15 ) return false; // css class / slug
        return true;
    }

    /** Last-resort: fetch the post's public HTML and extract visible text. */
    private function fetch_rendered_text( int $post_id ): string {
        $url      = get_permalink( $post_id );
        if ( ! $url ) return '';
        $response = wp_remote_get( $url, [ 'timeout' => 12, 'user-agent' => 'Medical360-Bot/1.0' ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return '';
        }
        return $this->extract_text_from_html( wp_remote_retrieve_body( $response ) );
    }

    private function detect_lang( WP_Post $post ): string {
        if ( function_exists( 'wpml_get_language_information' ) ) {
            $info   = wpml_get_language_information( null, $post->ID );
            $locale = $info['locale'] ?? '';
            if ( $locale ) return self::LOCALE_MAP[ $locale ] ?? substr( $locale, 0, 2 );
        }
        if ( function_exists( 'pll_get_post_language' ) ) {
            $lang = pll_get_post_language( $post->ID );
            if ( $lang ) return $lang;
        }
        $locale = get_locale();
        return self::LOCALE_MAP[ $locale ] ?? 'he';
    }

    // ─── Index from URL ───────────────────────────────────────────────────────

    public function index_url( string $url, string $lang = '' ): array {
        // Ensure tables exist (guard against running on every loop iteration)
        static $tables_ready = false;
        if ( ! $tables_ready ) { $this->db->create_tables(); $tables_ready = true; }

        $url = esc_url_raw( $url );
        if ( ! $url ) return [ 'success' => false, 'error' => 'Invalid URL' ];

        $response = wp_remote_get( $url, [
            'timeout'    => 12,
            'user-agent' => 'Medical360-Bot/1.0',
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'success' => false, 'error' => "HTTP $code" ];
        }

        $html    = wp_remote_retrieve_body( $response );
        $lang    = $lang ?: $this->detect_lang_from_html( $html );
        $title   = $this->extract_title_from_html( $html );
        $content = $this->extract_text_from_html( $html );

        if ( empty( $content ) ) {
            return [ 'success' => false, 'error' => 'No content extracted' ];
        }

        // Use a stable pseudo post_id based on URL hash
        $post_id = abs( crc32( $url ) );

        $this->db->upsert_content( [
            'post_id'   => $post_id,
            'post_type' => 'external_url',
            'lang'      => $lang,
            'title'     => $title ?: $url,
            'content'   => $content,
            'url'       => $url,
        ] );

        return [ 'success' => true, 'title' => $title, 'chars' => mb_strlen( $content ) ];
    }

    // ─── Index from Sitemap ───────────────────────────────────────────────────

    // Max pages fetched per sitemap run, to stay within PHP execution limits.
    private const SITEMAP_MAX_URLS = 50;

    public function index_sitemap( string $sitemap_url ): array {
        $this->db->create_tables();
        @set_time_limit( 300 );

        $urls = $this->collect_sitemap_urls( $sitemap_url );
        if ( $urls === null ) {
            return [ 'success' => false, 'error' => 'לא ניתן לקרוא את ה-Sitemap (XML לא תקין או שגיאת רשת)', 'indexed' => 0, 'errors' => 0 ];
        }

        $urls    = array_slice( array_unique( $urls ), 0, self::SITEMAP_MAX_URLS );
        $indexed = 0;
        $errors  = 0;

        foreach ( $urls as $url ) {
            $result = $this->index_url( $url );
            $result['success'] ? $indexed++ : $errors++;
        }

        return [
            'success'   => true,
            'indexed'   => $indexed,
            'errors'    => $errors,
            'total'     => count( $urls ),
            'capped'    => count( $urls ) >= self::SITEMAP_MAX_URLS,
        ];
    }

    /**
     * Recursively collect page URLs from a sitemap (handles sitemap-index files).
     * Returns null on fetch/parse failure.
     */
    private function collect_sitemap_urls( string $sitemap_url, int $depth = 0 ): ?array {
        if ( $depth > 2 ) return [];

        $response = wp_remote_get( $sitemap_url, [ 'timeout' => 15, 'user-agent' => 'Medical360-Bot/1.0' ] );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
        if ( ! $xml ) return null;

        $urls = [];
        foreach ( $xml->url ?? [] as $node ) {
            $loc = trim( (string) $node->loc );
            if ( $loc ) $urls[] = $loc;
        }
        // Nested sitemap index → recurse
        foreach ( $xml->sitemap ?? [] as $node ) {
            $sub = $this->collect_sitemap_urls( trim( (string) $node->loc ), $depth + 1 );
            if ( is_array( $sub ) ) $urls = array_merge( $urls, $sub );
            if ( count( $urls ) >= self::SITEMAP_MAX_URLS ) break;
        }
        return $urls;
    }

    // ─── Index URL list (newline-separated) ───────────────────────────────────

    public function index_url_list( string $raw_list, string $lang = '' ): array {
        $this->db->create_tables();
        @set_time_limit( 300 );

        $urls    = array_filter( array_map( 'trim', explode( "\n", $raw_list ) ) );
        $indexed = 0;
        $errors  = [];

        foreach ( $urls as $url ) {
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                $errors[] = "$url — invalid URL";
                continue;
            }
            $result = $this->index_url( $url, $lang );
            $result['success'] ? $indexed++ : $errors[] = "$url — {$result['error']}";
        }

        return [ 'success' => true, 'indexed' => $indexed, 'errors' => $errors ];
    }

    // ─── Index manual text ────────────────────────────────────────────────────

    public function index_text( string $title, string $content, string $lang = 'he', string $source_url = '' ): array {
        if ( empty( $title ) || empty( $content ) ) {
            return [ 'success' => false, 'error' => 'Title and content are required' ];
        }

        $this->db->create_tables();
        $post_id = abs( crc32( $title . $content ) );
        $url     = $source_url ?: home_url( '#text-' . $post_id );

        $this->db->upsert_content( [
            'post_id'   => $post_id,
            'post_type' => 'manual_text',
            'lang'      => $lang,
            'title'     => $title,
            'content'   => wp_strip_all_tags( $content ),
            'url'       => $url,
        ] );

        return [ 'success' => true, 'chars' => mb_strlen( $content ) ];
    }

    // ─── HTML helpers ─────────────────────────────────────────────────────────

    private function extract_title_from_html( string $html ): string {
        if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
            return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }
        if ( preg_match( '/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m ) ) {
            return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
        }
        return '';
    }

    private function extract_text_from_html( string $html ): string {
        // Remove scripts, styles, nav, header, footer, aside
        $html = preg_replace( '/<(script|style|nav|header|footer|aside|noscript)[^>]*>.*?<\/\1>/si', '', $html );
        // Remove HTML comments
        $html = preg_replace( '/<!--.*?-->/s', '', $html );
        // Convert block tags to newlines
        $html = preg_replace( '/<\/(p|div|li|h[1-6]|br|tr|td|th)>/i', "\n", $html );
        // Strip remaining tags
        $html = wp_strip_all_tags( $html );
        // Normalize whitespace
        $html = preg_replace( '/[ \t]+/', ' ', $html );
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );

        return trim( $html );
    }

    private function detect_lang_from_html( string $html ): string {
        if ( preg_match( '/<html[^>]+lang=["\']([^"\']+)["\']/i', $html, $m ) ) {
            $locale = $m[1];
            return self::LOCALE_MAP[ $locale ] ?? substr( $locale, 0, 2 );
        }
        return 'he';
    }
}
