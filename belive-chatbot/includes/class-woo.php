<?php
defined( 'ABSPATH' ) || exit;

class Wisply_Woo {

    /** Tags WooCommerce uses in get_price_html(); <del>/<ins> carry the sale meaning. */
    private const PRICE_TAGS = [
        'del'  => [],
        'ins'  => [],
        'span' => [ 'class' => [] ],
        'bdi'  => [],
        'small'=> [ 'class' => [] ],
    ];

    private static ?self $instance = null;
    private Wisply_Database $db;

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Availability ─────────────────────────────────────────────────────────

    public function is_active(): bool {
        if ( ! class_exists( 'WooCommerce' ) ) return false;
        return $this->db->get_setting( 'woo_enabled', '0' ) === '1';
    }

    private function max_products(): int {
        $max = (int) $this->db->get_setting( 'woo_max_products', '8' );
        return $max > 0 ? $max : 4;
    }

    // ─── Search ───────────────────────────────────────────────────────────────

    /**
     * Search products by free text. Runs three passes (text / SKU / category name),
     * merged in relevance order and deduped by id.
     */
    /**
     * Find products for a query by SCORING every product's name + category words
     * against the query's words. This is what actually works for a Hebrew jewellery
     * store: WooCommerce's own `s` search can't match a nikud-pointed title, and it
     * can't bridge a Hebrew query ("שרשראות") to an English category ("Necklaces").
     * Here we normalise (strip nikud), match words by prefix (so plural "שרשראות"
     * hits singular "שרשרת"), and use a small He→En retail synonym map for category
     * names. Verified against the live catalogue before shipping.
     */
    public function search_products( string $query, int $limit = 4 ): array {
        if ( ! $this->is_active() ) return [];

        $tokens = $this->query_tokens( $query );
        if ( empty( $tokens ) ) return [];

        $limit = $limit > 0 ? min( $limit, $this->max_products() ) : $this->max_products();

        // Weighted so the ITEM TYPE dominates. A photo of a ring yields a description
        // like "טבעת זהב אבן ירוקה"; without weighting, a gold bracelet ("זהב") and a
        // green necklace tie with the actual rings. Category-type hits (ring/necklace/
        // earring/bracelet) score highest, material/theme categories (gold/sale) less,
        // and plain name words least — so rings always outrank a merely-gold anything.
        $scored = [];
        foreach ( $this->product_index() as $id => $entry ) {
            $s = 0;
            foreach ( $tokens as $qt ) {
                $syn = self::SYNONYMS[ $qt ] ?? null;
                // Category hit — direct word match or He→En synonym prefix.
                $cat_hit = false;
                foreach ( $entry['c'] as $w ) {
                    if ( $this->token_match( $qt, $w ) || ( $syn !== null && strncmp( $w, $syn, strlen( $syn ) ) === 0 ) ) { $cat_hit = true; break; }
                }
                if ( $cat_hit ) {
                    $s += ( $syn !== null && in_array( $syn, self::TYPE_SYNONYMS, true ) ) ? 5 : 2;
                    continue;
                }
                // Otherwise a plain product-name word match.
                foreach ( $entry['n'] as $w ) { if ( $this->token_match( $qt, $w ) ) { $s += 1; break; } }
            }
            if ( $s > 0 ) $scored[] = [ 'id' => $id, 'score' => $s ];
        }

        if ( empty( $scored ) ) return [];

        // Best matches first; the top-N get a LIVE map_product (fresh price/stock).
        usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

        $out = [];
        foreach ( $scored as $row ) {
            if ( count( $out ) >= $limit ) break;
            $product = wc_get_product( $row['id'] );
            $data    = $product ? $this->map_product( $product ) : null;
            if ( $data !== null ) $out[] = $data;
        }
        return $out;
    }

    /** Synonyms that name a PRODUCT TYPE (a category), not a material/theme — these
     *  dominate scoring so a "ring" photo can't be outranked by a gold bracelet. */
    private const TYPE_SYNONYMS = [ 'necklace', 'ring', 'earring', 'bracelet' ];

    /** He→En retail nouns, so a Hebrew query resolves an English category name. */
    private const SYNONYMS = [
        'שרשרת' => 'necklace', 'שרשראות' => 'necklace', 'שרשרות' => 'necklace',
        'טבעת' => 'ring', 'טבעות' => 'ring',
        'עגיל' => 'earring', 'עגילים' => 'earring', 'עגילי' => 'earring',
        'צמיד' => 'bracelet', 'צמידים' => 'bracelet',
        'זהב' => 'gold', 'כסף' => 'silver', 'מבצע' => 'sale', 'מבצעים' => 'sale',
        'טבעות' => 'ring', 'מתנה' => 'gift', 'מתנות' => 'gift',
    ];

    /**
     * Two normalised words match. Beyond the raw comparison we RE-TRY with a leading
     * Hebrew one-letter prefix stripped off either side. Hebrew glues the article and
     * particles onto the word (ה/ו/ב/כ/ל/מ/ש), so "ההרצאה" (the-lecture) would never
     * prefix-match the category "הרצאות" — the extra article ה breaks it, and the whole
     * search silently returns nothing. We never REPLACE the word (that would maul a root
     * ה, e.g. "הרצאה" itself); we only try the stripped form as an ADDITIONAL candidate,
     * so it can only add recall, never remove a real match.
     */
    private function token_match( string $a, string $b ): bool {
        if ( $this->raw_match( $a, $b ) ) return true;
        $as = $this->strip_he_prefix( $a );
        $bs = $this->strip_he_prefix( $b );
        if ( $as !== $a && $this->raw_match( $as, $b ) ) return true;
        if ( $bs !== $b && $this->raw_match( $a, $bs ) ) return true;
        if ( $as !== $a && $bs !== $b && $this->raw_match( $as, $bs ) ) return true;
        return false;
    }

    /** Drop one leading Hebrew inseparable prefix (article/conjunction/preposition), but
     *  only when ≥3 letters remain, so a base word like "הרצאה" is left intact. */
    private function strip_he_prefix( string $w ): string {
        if ( mb_strlen( $w ) >= 4 && mb_strpos( 'הובכלמש', mb_substr( $w, 0, 1 ) ) !== false ) {
            return mb_substr( $w, 1 );
        }
        return $w;
    }

    /** Raw match: equal, one a prefix of the other (≥3), or a shared ≥3-char prefix while
     *  both are ≥4 long — enough for Hebrew plural↔singular. */
    private function raw_match( string $a, string $b ): bool {
        if ( $a === $b ) return true;
        $short = mb_strlen( $a ) <= mb_strlen( $b ) ? $a : $b;
        $long  = $short === $a ? $b : $a;
        if ( mb_strlen( $short ) >= 3 && mb_strpos( $long, $short ) === 0 ) return true;
        $n = min( mb_strlen( $a ), mb_strlen( $b ) );
        $common = 0;
        for ( $i = 0; $i < $n; $i++ ) {
            if ( mb_substr( $a, $i, 1 ) === mb_substr( $b, $i, 1 ) ) $common++; else break;
        }
        return $common >= 3 && $n >= 4;
    }

    /**
     * Normalised name + category words per published product, cached 10 min. Names and
     * categories change rarely, so this stays out of the DB on every chat turn; live
     * price/stock are NOT cached here (map_product reads those fresh for the top hits).
     *
     * @return array<int,array{n:string[],c:string[]}>
     */
    private function product_index(): array {
        $cached = get_transient( self::INDEX_TRANSIENT );
        if ( is_array( $cached ) ) return $cached;

        $index = [];
        if ( function_exists( 'wc_get_products' ) ) {
            try {
                $ids = wc_get_products( [ 'status' => 'publish', 'limit' => 2000, 'return' => 'ids' ] );
            } catch ( Throwable $e ) {
                $ids = [];
            }
            foreach ( (array) $ids as $id ) {
                $name_words = array_values( array_filter( explode( ' ', $this->normalize_he( (string) get_the_title( $id ) ) ) ) );
                $cat_words  = [];
                $terms = wp_get_post_terms( (int) $id, 'product_cat', [ 'fields' => 'names' ] );
                if ( ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $cn ) {
                        foreach ( explode( ' ', $this->normalize_he( (string) $cn ) ) as $w ) {
                            if ( $w !== '' ) $cat_words[] = $w;
                        }
                    }
                }
                $index[ (int) $id ] = [ 'n' => $name_words, 'c' => array_values( array_unique( $cat_words ) ) ];
            }
        }
        set_transient( self::INDEX_TRANSIENT, $index, 10 * MINUTE_IN_SECONDS );
        return $index;
    }

    private const INDEX_TRANSIENT = 'wisply_woo_index';

    /**
     * Diagnostics for "why no product cards": how many real WooCommerce products
     * exist, a few sample names, and exactly what a query resolves to. Admin-only
     * (see the AJAX handler) — it answers the question "are these even WooCommerce
     * products, or just page text the content index picked up?".
     */
    public function diagnose( string $query ): array {
        $active = $this->is_active();
        $total  = 0;
        $sample = [];
        if ( function_exists( 'wc_get_products' ) ) {
            try {
                $ids    = wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ] );
                $total  = is_array( $ids ) ? count( $ids ) : 0;
                foreach ( array_slice( (array) $ids, 0, 5 ) as $id ) {
                    $sample[] = [ 'id' => (int) $id, 'name' => get_the_title( $id ) ];
                }
            } catch ( Throwable $e ) {}
        }
        $cats  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'names' ] );
        $hits  = $this->search_products( $query, $this->max_products() );
        return [
            'woocommerce_installed' => class_exists( 'WooCommerce' ),
            'module_enabled'        => $this->db->get_setting( 'woo_enabled', '0' ) === '1',
            'is_active'             => $active,
            'total_products'        => $total,
            'sample_products'       => $sample,
            'categories'            => is_wp_error( $cats ) ? [] : array_values( (array) $cats ),
            'query'                 => $query,
            'query_tokens'          => $this->query_tokens( $query ),
            'indexed_products'      => count( $this->product_index() ),
            'search_result_count'   => count( $hits ),
            'search_result_names'   => array_map( static fn( $p ) => $p['name'] ?? '', $hits ),
            // Exactly what the /products route would hand the widget to render as cards.
            // If this is populated but no cards show, the problem is a stale cached JS,
            // not the server — hard-refresh the site.
            'card_preview'          => array_map( static fn( $p ) => [
                'id'    => $p['id'] ?? 0,
                'name'  => $p['name'] ?? '',
                'price' => wp_strip_all_tags( (string) ( $p['price_html'] ?? '' ) ),
                'image' => ( $p['image'] ?? '' ) !== '',
            ], $hits ),
        ];
    }

    /**
     * Normalise Hebrew for fuzzy matching: strip nikud + cantillation marks (product
     * names here are pointed, e.g. "שֶׁפַע"), turn punctuation into spaces, collapse
     * whitespace, lowercase. Without this a plain query never matches a pointed name.
     */
    private function normalize_he( string $s ): string {
        $s = (string) preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $s ); // nikud + te'amim
        $s = (string) preg_replace( '/[^\p{L}\p{N} ]+/u', ' ', $s );    // punctuation → space
        $s = (string) preg_replace( '/\s+/u', ' ', $s );
        return trim( mb_strtolower( $s ) );
    }

    /** Meaningful query words — drop short words and common Hebrew filler/question words. */
    private function query_tokens( string $query ): array {
        static $stop = [
            'מה','יש','לכם','לך','את','של','אני','רוצה','מחפש','מחפשת','בקטגוריה','בקטגוריית',
            'קטגוריה','קטגוריית','אפשר','האם','עם','על','או','גם','הבא','הבאים','פריט','פריטים',
            'מוצר','מוצרים','להציג','תראה','תראי','הצג','הציג','לי','לנו','כמה','עולה','עולים',
            'המחיר','מחיר','זה','זו','כל','עוד','נוסף','אילו','איזה','איזו',
            'and','the','you','have','for','show','price','me','all','any','list',
        ];
        $out = [];
        foreach ( explode( ' ', $this->normalize_he( $query ) ) as $t ) {
            if ( mb_strlen( $t ) < 2 || in_array( $t, $stop, true ) ) continue;
            $out[] = $t;
        }
        return array_values( array_unique( $out ) );
    }

    // ─── Structured query (price / range / sort / best-sellers) ────────────────

    /**
     * Parse a natural-language product question into a structured filter the text
     * search can't express: price ceiling/floor/range, sort (cheapest / priciest /
     * best-selling / newest), and the category it applies to. Returns has_filter=false
     * when the message carries no such intent, so the caller falls back to text search.
     *
     * @return array{has_filter:bool,category:?string,category_name:?string,term_id:int,price_min:?float,price_max:?float,sort:?string}
     */
    public function parse_query_intent( string $message ): array {
        $intent = [
            'has_filter'    => false,
            'category'      => null,
            'category_name' => null,
            'term_id'       => 0,
            'price_min'     => null,
            'price_max'     => null,
            'sort'          => null,
        ];
        $norm = $this->normalize_he( $message );

        // Sort intent. \b is ASCII-only in PCRE and never fires between Hebrew letters,
        // so match on substrings (normalise already stripped nikud/punctuation). Later
        // rules win, so popularity/newest override a bare cheap/expensive word.
        if ( preg_match( '/זול/u', $norm ) )                                          $intent['sort'] = 'price_asc';
        if ( preg_match( '/יקר|יוקר/u', $norm ) )                                      $intent['sort'] = 'price_desc';
        if ( preg_match( '/נמכר|מבוקש|פופולר|רב.?מכר|להיט|מובילים|הכי.?טוב/u', $norm ) ) $intent['sort'] = 'popularity';
        if ( preg_match( '/חדש|האחרונ/u', $norm ) )                                    $intent['sort'] = 'date';

        // Price numbers — only when the message is actually about price (avoids reading
        // a ring size or quantity as a budget).
        $price_ctx = preg_match( '/שקל|ש["\']?ח|₪|מחיר|תקציב|עולה|בתקציב|nis/iu', $message )
            || preg_match( '/עד|מתחת|פחות\s*מ|מעל|יותר\s*מ|לפחות|החל\s*מ|בין/u', $norm );

        if ( $price_ctx && preg_match_all( '/(\d[\d,]*)/u', $message, $m ) ) {
            $nums = [];
            foreach ( $m[1] as $n ) { $v = (float) str_replace( ',', '', $n ); if ( $v > 0 ) $nums[] = $v; }
            if ( $nums ) {
                $is_range = ( count( $nums ) >= 2 )
                    && ( preg_match( '/בין/u', $norm ) || preg_match( '/(\d[\d,]*)\s*(עד|-|–|ל)\s*(\d)/u', $message ) );
                if ( $is_range ) {
                    sort( $nums );
                    $intent['price_min'] = $nums[0];
                    $intent['price_max'] = $nums[ count( $nums ) - 1 ];
                } elseif ( preg_match( '/מעל|יותר\s*מ|לפחות|החל\s*מ/u', $norm ) ) {
                    $intent['price_min'] = $nums[0];
                } elseif ( preg_match( '/עד|מתחת|פחות\s*מ|לא\s*יותר\s*מ|זול\s*מ|בתקציב/u', $norm ) ) {
                    $intent['price_max'] = $nums[0];
                } else {
                    // Bare "ב-300 שח" / "במחיר 300" — read as a budget ceiling, and default
                    // to priciest-first so the shopper sees the best they can get for it.
                    $intent['price_max'] = $nums[0];
                    if ( $intent['sort'] === null ) $intent['sort'] = 'price_desc';
                }
            }
        }

        $term = $this->resolve_category( $message );
        if ( $term ) {
            $intent['category']      = (string) $term->slug;
            $intent['category_name'] = (string) $term->name;
            $intent['term_id']       = (int) $term->term_id;
        }

        $intent['has_filter'] = $intent['price_min'] !== null
            || $intent['price_max'] !== null
            || $intent['sort'] !== null;

        return $intent;
    }

    /** Best-matching product_cat term for a free-text message, bridging He→En names. */
    private function resolve_category( string $message ): ?\WP_Term {
        $tokens = $this->query_tokens( $message );
        if ( empty( $tokens ) ) return null;

        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return null;

        $best = null; $best_score = 0;
        foreach ( $terms as $term ) {
            $slug = strtolower( (string) $term->slug );
            if ( in_array( $slug, [ 'uncategorized' ], true ) ) continue;
            $words = array_values( array_filter( explode( ' ', $this->normalize_he( (string) $term->name ) ) ) );
            $score = 0;
            foreach ( $tokens as $qt ) {
                $syn = self::SYNONYMS[ $qt ] ?? null;
                foreach ( $words as $w ) {
                    if ( $this->token_match( $qt, $w ) || ( $syn !== null && strncmp( $w, $syn, strlen( $syn ) ) === 0 ) ) { $score++; break; }
                }
                if ( $syn !== null && strncmp( $slug, $syn, strlen( $syn ) ) === 0 ) $score++;
            }
            if ( $score > $best_score ) { $best_score = $score; $best = $term; }
        }
        return $best_score > 0 ? $best : null;
    }

    /**
     * Run a parsed intent against WooCommerce with real price/sort/category filters.
     * When a price constraint matches nothing, degrades to the closest few (cheapest
     * for a budget ceiling, priciest for a floor) flagged is_fallback, so the bot can
     * honestly say "nothing under ₪300 — the cheapest is ₪340".
     */
    public function query_products( array $intent, int $limit = 0 ): array {
        if ( ! $this->is_active() || ! function_exists( 'wc_get_products' ) ) return [];
        $limit = $limit > 0 ? min( $limit, $this->max_products() ) : $this->max_products();

        $args = [
            'status'  => 'publish',
            'limit'   => 300,           // candidate pool; price-filter + slice in PHP
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        if ( ! empty( $intent['category'] ) ) {
            $args['category'] = [ (string) $intent['category'] ];
        }
        switch ( $intent['sort'] ?? '' ) {
            case 'price_asc':  $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price';      $args['order'] = 'ASC';  break;
            case 'price_desc': $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price';      $args['order'] = 'DESC'; break;
            case 'popularity': $args['orderby'] = 'meta_value_num'; $args['meta_key'] = 'total_sales'; $args['order'] = 'DESC'; break;
            case 'date':       $args['orderby'] = 'date';                                              $args['order'] = 'DESC'; break;
            default:           $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_price';      $args['order'] = 'ASC';  break;
        }

        try { $ids = wc_get_products( $args ); } catch ( Throwable $e ) { return []; }
        if ( empty( $ids ) || ! is_array( $ids ) ) return [];

        $min = $intent['price_min'] !== null ? (float) $intent['price_min'] : null;
        $max = $intent['price_max'] !== null ? (float) $intent['price_max'] : null;

        $out = [];
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product instanceof WC_Product ) continue;
            if ( $min !== null || $max !== null ) {
                $price = $product->get_price();
                if ( $price === '' || $price === null ) continue;
                $price = (float) $price;
                if ( $min !== null && $price < $min ) continue;
                if ( $max !== null && $price > $max ) continue;
            }
            $data = $this->map_product( $product );
            if ( $data !== null ) $out[] = $data;
            if ( count( $out ) >= $limit ) break;
        }

        // Nothing in the price window → offer the closest instead of an empty hand.
        if ( empty( $out ) && ( $min !== null || $max !== null ) ) {
            $fb = $intent;
            $fb['price_min'] = null;
            $fb['price_max'] = null;
            $fb['sort']      = $max !== null ? 'price_asc' : 'price_desc';
            $fallback = $this->query_products( $fb, 3 );
            foreach ( $fallback as $i => $f ) { $fallback[ $i ]['is_fallback'] = true; }
            return $fallback;
        }

        return $out;
    }

    /** Published product-category names — vocabulary for the AI's smart matching. */
    public function category_names(): array {
        if ( ! $this->is_active() ) return [];
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];
        return array_values( array_filter(
            array_map( 'strval', (array) $terms ),
            static fn( $n ) => strtolower( $n ) !== 'uncategorized'
        ) );
    }

    /** Short Hebrew description of a parsed intent, for the AI to phrase its reply. */
    public function describe_intent( array $intent ): string {
        if ( empty( $intent['has_filter'] ) ) return '';
        $parts = [];
        if ( ! empty( $intent['category_name'] ) ) $parts[] = (string) $intent['category_name'];
        $min = $intent['price_min'] ?? null;
        $max = $intent['price_max'] ?? null;
        if ( $min !== null && $max !== null )      $parts[] = sprintf( 'במחיר %s–%s ₪', $this->num( $min ), $this->num( $max ) );
        elseif ( $max !== null )                   $parts[] = sprintf( 'עד %s ₪', $this->num( $max ) );
        elseif ( $min !== null )                   $parts[] = sprintf( 'מעל %s ₪', $this->num( $min ) );
        switch ( $intent['sort'] ?? '' ) {
            case 'price_asc':  $parts[] = 'מהזול ליקר';       break;
            case 'price_desc': $parts[] = 'מהיקר לזול';       break;
            case 'popularity': $parts[] = 'הנמכרים ביותר';    break;
            case 'date':       $parts[] = 'החדשים ביותר';     break;
        }
        return implode( ', ', $parts );
    }

    private function num( float $n ): string {
        return rtrim( rtrim( number_format( $n, 2, '.', ',' ), '0' ), '.' );
    }

    // ─── Order status lookup (order # + email — both must match) ───────────────

    /**
     * Safe status summary for the chat "where's my order" flow. Returns data ONLY
     * when the order id AND its billing email both match — the same cross-check as
     * WooCommerce's own guest order-tracking form, so an email alone never exposes
     * anyone's orders. Returns null on any mismatch; the caller must not distinguish
     * "wrong email" from "no such order" (anti-enumeration).
     */
    public function lookup_order( int $order_id, string $email ): ?array {
        if ( ! $this->is_active() || ! function_exists( 'wc_get_order' ) ) return null;
        $email = trim( strtolower( $email ) );
        if ( $order_id <= 0 || ! is_email( $email ) ) return null;

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) return null;

        // The cross-check: billing email on the order must equal the one supplied.
        if ( strtolower( (string) $order->get_billing_email() ) !== $email ) return null;

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [ 'name' => $item->get_name(), 'qty' => (int) $item->get_quantity() ];
            if ( count( $items ) >= 12 ) break;
        }

        return [
            'number'       => (string) $order->get_order_number(),
            'status'       => (string) $order->get_status(),
            'status_label' => $this->order_status_label( (string) $order->get_status() ),
            'date'         => $order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'd/m/Y' ) : '',
            'total'        => wp_strip_all_tags( (string) $order->get_formatted_order_total() ),
            'items'        => $items,
            'tracking'     => $this->order_tracking( $order ),
        ];
    }

    /** Hebrew label for a WooCommerce order status. */
    private function order_status_label( string $status ): string {
        $map = [
            'pending'    => 'ממתינה לתשלום',
            'processing' => 'בהכנה',
            'on-hold'    => 'בהמתנה',
            'completed'  => 'הושלמה / נשלחה',
            'cancelled'  => 'בוטלה',
            'refunded'   => 'זוכתה',
            'failed'     => 'נכשלה',
        ];
        return $map[ $status ] ?? ( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status );
    }

    /** Best-effort tracking number/provider/url from the common tracking plugins. */
    private function order_tracking( WC_Order $order ): array {
        $out = [];
        $items = $order->get_meta( '_wc_shipment_tracking_items' );
        if ( is_array( $items ) ) {
            foreach ( $items as $t ) {
                $out[] = [
                    'number'   => (string) ( $t['tracking_number'] ?? '' ),
                    'provider' => (string) ( $t['tracking_provider'] ?? ( $t['custom_tracking_provider'] ?? '' ) ),
                    'url'      => (string) ( $t['custom_tracking_link'] ?? '' ),
                ];
            }
        }
        if ( empty( $out ) ) {
            $num = (string) $order->get_meta( '_tracking_number' );
            if ( $num !== '' ) {
                $out[] = [
                    'number'   => $num,
                    'provider' => (string) $order->get_meta( '_tracking_provider' ),
                    'url'      => (string) $order->get_meta( '_tracking_url' ),
                ];
            }
        }
        return array_values( array_filter( $out, static fn( $t ) => ( $t['number'] ?? '' ) !== '' ) );
    }

    // ─── Single product / related ─────────────────────────────────────────────

    public function get_product( int $id ): ?array {
        if ( ! $this->is_active() || ! function_exists( 'wc_get_product' ) ) return null;

        $product = wc_get_product( $id );
        if ( ! $product instanceof WC_Product ) return null;

        return $this->map_product( $product );
    }

    public function related_products( int $id, int $limit = 3 ): array {
        if ( ! $this->is_active() || ! function_exists( 'wc_get_related_products' ) ) return [];

        try {
            $ids = wc_get_related_products( $id, $limit );
        } catch ( Throwable $e ) {
            return [];
        }
        if ( empty( $ids ) || ! is_array( $ids ) ) return [];

        $out = [];
        foreach ( $ids as $related_id ) {
            $data = $this->get_product( (int) $related_id );
            if ( $data !== null ) $out[] = $data;
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    // ─── Mapping ──────────────────────────────────────────────────────────────

    /**
     * WC_Product → the shared contract array.
     */
    private function map_product( WC_Product $product ): ?array {
        if ( ! $product->is_visible() && $product->get_status() !== 'publish' ) return null;

        $image = '';
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $src = wp_get_attachment_image_url( (int) $image_id, 'woocommerce_thumbnail' );
            if ( $src ) $image = $src;
        }

        $categories = [];
        $cat_links  = [];
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $term ) {
                $categories[] = $term->name;
                // Skip generic buckets — a "view all Uncategorized" button helps nobody.
                $slug = strtolower( (string) $term->slug );
                if ( in_array( $slug, [ 'uncategorized', 'sale', 'gift-card', 'gift_card' ], true ) ) continue;
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) ) {
                    $cat_links[] = [ 'name' => $term->name, 'url' => (string) $link, 'id' => (int) $term->term_id ];
                }
            }
        }

        $show_stock = $this->db->get_setting( 'woo_show_stock', '1' ) === '1';

        return [
            'id'              => $product->get_id(),
            'name'            => $product->get_name(),
            'permalink'       => (string) $product->get_permalink(),
            // Keep WooCommerce's real markup (it carries the <del>/<ins> sale distinction),
            // sanitised so the widget can render it safely. Stripping tags here would
            // collapse a sale into an ambiguous "₪100.00 ₪80.00".
            'price_html'      => $this->clean_price_html( $product->get_price_html() ),
            // Unambiguous plain values — this is what the AI is told to quote.
            'price'           => $this->money( $product->get_price() ),
            'regular_price'   => $this->money( $product->get_regular_price() ),
            'sale_price'      => $this->money( $product->get_sale_price() ),
            'on_sale'         => (bool) $product->is_on_sale(),
            'stock_status'    => (string) $product->get_stock_status(),
            'stock_qty'       => $show_stock && $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
            'image'           => $image,
            'sku'             => (string) $product->get_sku(),
            'categories'      => $categories,
            'cat_links'       => $cat_links,
            'short_desc'      => $this->clean_desc( $product ),
            'type'            => (string) $product->get_type(),
            'attributes'      => $this->map_attributes( $product ),
            'variations'      => $this->map_variations( $product ),
            'add_to_cart_url' => (string) $product->add_to_cart_url(),
        ];
    }

    /**
     * Sanitise WooCommerce price markup for the widget.
     *
     * get_price_html() on a variable product embeds a hidden
     * <span class="screen-reader-text">Price range: … to …</span>. Our Shadow DOM has
     * no .screen-reader-text rule to hide it, and wp_kses drops the tag but keeps its
     * text — so the range renders twice ("₪240 – טווח מחירים:₪1,600 ₪240 עד ₪1,600").
     * Strip that span (tag + content) before sanitising the rest.
     */
    private function clean_price_html( $html ): string {
        $html = preg_replace( '#<span[^>]*class="[^"]*screen-reader-text[^"]*"[^>]*>.*?</span>#is', '', (string) $html );
        return wp_kses( (string) $html, self::PRICE_TAGS );
    }

    /** Plain formatted money ('' when the product has no such price). */
    private function money( $amount ): string {
        if ( $amount === '' || $amount === null ) return '';
        return wp_strip_all_tags( (string) wc_price( (float) $amount ) );
    }

    private function clean_desc( WC_Product $product ): string {
        $desc = $product->get_short_description();
        if ( trim( $desc ) === '' ) $desc = $product->get_description();

        $desc = wp_strip_all_tags( strip_shortcodes( (string) $desc ) );
        $desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );

        if ( mb_strlen( $desc ) > 300 ) {
            $desc = mb_substr( $desc, 0, 297 ) . '...';
        }
        return $desc;
    }

    /**
     * [ 'מידה' => ['S','M','L'], 'צבע' => ['אדום'] ]
     */
    private function map_attributes( WC_Product $product ): array {
        $out = [];
        foreach ( $product->get_attributes() as $attribute ) {
            if ( ! $attribute instanceof WC_Product_Attribute ) continue;

            $label   = wc_attribute_label( $attribute->get_name(), $product );
            $options = $attribute->is_taxonomy()
                ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] )
                : $attribute->get_options();

            if ( is_wp_error( $options ) || empty( $options ) ) continue;
            $out[ $label ] = array_values( array_map( 'strval', $options ) );
        }
        return $out;
    }

    /**
     * Variable products only. Maps WooCommerce's attribute_pa_* keys back to
     * human labels so the AI sees "מידה: M" rather than "attribute_pa_size: m".
     */
    private function map_variations( WC_Product $product ): array {
        if ( ! $product instanceof WC_Product_Variable ) return [];

        try {
            $variations = $product->get_available_variations();
        } catch ( Throwable $e ) {
            return [];
        }
        if ( empty( $variations ) || ! is_array( $variations ) ) return [];

        $show_stock = $this->db->get_setting( 'woo_show_stock', '1' ) === '1';
        $out = [];

        foreach ( $variations as $variation ) {
            $variation_id = isset( $variation['variation_id'] ) ? (int) $variation['variation_id'] : 0;
            if ( ! $variation_id ) continue;

            $obj = wc_get_product( $variation_id );
            if ( ! $obj instanceof WC_Product ) continue;

            $attrs = [];
            foreach ( (array) ( $variation['attributes'] ?? [] ) as $key => $value ) {
                $taxonomy = str_replace( 'attribute_', '', (string) $key );
                $label    = wc_attribute_label( $taxonomy, $product );
                $attrs[ $label ] = $this->attribute_value_label( $taxonomy, (string) $value );
            }

            $out[] = [
                'id'           => $variation_id,
                'attrs'        => $attrs,
                // Same rule as the parent: keep the sale markup, drop the SR range span.
                'price_html'   => $this->clean_price_html( $obj->get_price_html() ),
                'price'        => $this->money( $obj->get_price() ),
                'stock_status' => (string) $obj->get_stock_status(),
                'stock_qty'    => $show_stock && $obj->managing_stock() ? (int) $obj->get_stock_quantity() : null,
            ];
        }
        return $out;
    }

    /**
     * Taxonomy-backed variation values arrive as slugs — resolve to term names.
     */
    private function attribute_value_label( string $taxonomy, string $value ): string {
        if ( $value === '' ) return '';
        if ( ! taxonomy_exists( $taxonomy ) ) return $value;

        $term = get_term_by( 'slug', $value, $taxonomy );
        return $term instanceof WP_Term ? $term->name : $value;
    }

    // ─── Shipping ─────────────────────────────────────────────────────────────

    /**
     * [ [ 'zone' => 'ישראל', 'methods' => [ [ 'title' => 'משלוח שליח', 'cost' => '₪29' ] ] ] ]
     */
    public function get_shipping_info(): array {
        if ( ! $this->is_active() || ! class_exists( 'WC_Shipping_Zones' ) ) return [];

        try {
            $zones = WC_Shipping_Zones::get_zones();
        } catch ( Throwable $e ) {
            return [];
        }
        if ( ! is_array( $zones ) ) $zones = [];

        // Zone 0 ("שאר העולם") is not part of get_zones() — append it explicitly.
        $rest = WC_Shipping_Zones::get_zone( 0 );
        if ( $rest ) {
            $zones[] = [
                'zone_name'        => $rest->get_zone_name(),
                'shipping_methods' => $rest->get_shipping_methods( true ),
            ];
        }

        $out = [];
        foreach ( $zones as $zone ) {
            $methods = [];
            foreach ( (array) ( $zone['shipping_methods'] ?? [] ) as $method ) {
                if ( ! is_object( $method ) || ! method_exists( $method, 'get_title' ) ) continue;
                if ( method_exists( $method, 'is_enabled' ) && ! $method->is_enabled() ) continue;
                $methods[] = [
                    'title' => (string) $method->get_title(),
                    'cost'  => $this->method_cost( $method ),
                ];
            }
            if ( empty( $methods ) ) continue;

            $out[] = [
                'zone'    => (string) ( $zone['zone_name'] ?? '' ),
                'methods' => $methods,
            ];
        }
        return $out;
    }

    private function method_cost( object $method ): string {
        $id = $method->id ?? '';
        if ( ! method_exists( $method, 'get_option' ) ) return '';

        if ( $id === 'free_shipping' ) {
            $min = $method->get_option( 'min_amount' );
            return $min ? sprintf( 'חינם מעל %s', wp_strip_all_tags( wc_price( (float) $min ) ) ) : 'חינם';
        }

        if ( $id === 'local_pickup' ) return 'איסוף עצמי';

        $cost = $method->get_option( 'cost' );
        if ( $cost === '' || $cost === null ) return '';

        // Costs may contain formulas ([qty] * 5) — pass those through as-is.
        return is_numeric( $cost )
            ? wp_strip_all_tags( wc_price( (float) $cost ) )
            : (string) $cost;
    }

    // ─── Prompt formatting ────────────────────────────────────────────────────

    /** "מחיר: ₪80 (במבצע! מחיר רגיל ₪100)" — explicit, so the AI can't confuse the two. */
    private function price_for_prompt( array $product ): string {
        $current = (string) ( $product['price'] ?? '' );
        if ( $current === '' ) {
            // Variable products price by range — fall back to the stripped range text.
            $range = wp_strip_all_tags( (string) ( $product['price_html'] ?? '' ) );
            return $range !== '' ? 'טווח מחירים: ' . $range : '';
        }
        $out = 'מחיר נוכחי: ' . $current;
        if ( ! empty( $product['on_sale'] ) && ! empty( $product['regular_price'] ) ) {
            $out .= ' (במבצע! מחיר רגיל: ' . $product['regular_price'] . ')';
        }
        return $out;
    }

    /**
     * Compact Hebrew block injected into the AI system prompt. Each product is
     * capped so a full result set stays token-cheap.
     */
    public function format_products_for_prompt( array $products, string $query = '' ): string {
        if ( empty( $products ) ) return '';

        $show_stock = $this->db->get_setting( 'woo_show_stock', '1' ) === '1';
        $lines      = [ '=== מוצרים מהחנות (מידע חי) ===' ];

        foreach ( $products as $product ) {
            if ( ! is_array( $product ) || empty( $product['id'] ) ) continue;

            $tag   = ! empty( $product['is_related'] ) ? ' (מוצר דומה — להצעה כחלופה)' : '';
            $parts = [ sprintf( '[מוצר %d] %s%s', (int) $product['id'], $product['name'] ?? '', $tag ) ];

            // Quote plain, unambiguous prices — never price_html (its <del>/<ins>
            // markup would flatten into "₪100 ₪80" and the model could quote the
            // pre-sale price as the current one.
            $price = $this->price_for_prompt( $product );
            if ( $price !== '' ) $parts[] = $price;

            if ( $show_stock && ! empty( $product['stock_status'] ) ) {
                $parts[] = 'מלאי: ' . $this->stock_label( $product['stock_status'], $product['stock_qty'] ?? null );
            }

            if ( ! empty( $product['categories'] ) ) {
                $parts[] = 'קטגוריות: ' . implode( ', ', array_slice( (array) $product['categories'], 0, 3 ) );
            }

            if ( ! empty( $product['attributes'] ) ) {
                $attrs = [];
                foreach ( (array) $product['attributes'] as $label => $options ) {
                    $attrs[] = $label . ': ' . implode( ',', array_slice( (array) $options, 0, 8 ) );
                }
                $parts[] = 'וריאציות: ' . implode( ' | ', $attrs );
            }

            $line = implode( ' | ', $parts );

            $out_of_stock = $this->out_of_stock_variations( $product );
            if ( $out_of_stock !== '' ) $line .= "\nאזל מהמלאי: " . $out_of_stock;

            if ( ! empty( $product['short_desc'] ) ) {
                $line .= "\n" . mb_substr( (string) $product['short_desc'], 0, 160 );
            }

            $lines[] = mb_substr( $line, 0, 400 );
        }

        if ( count( $lines ) === 1 ) return '';

        // Live shipping zones/rates — only when the visitor actually asked about delivery,
        // so we don't spend tokens on it in every message.
        $shipping = $this->shipping_for_prompt( $query );
        if ( $shipping !== '' ) $lines[] = $shipping;

        return implode( "\n", $lines ) . "\n";
    }

    /** Shipping block, injected only for delivery-related questions. */
    private function shipping_for_prompt( string $query ): string {
        if ( $query === '' ) return '';

        $keywords = [ 'משלוח', 'משלוחים', 'לשלוח', 'שילוח', 'אספקה', 'מתי יגיע', 'איסוף עצמי',
                      'shipping', 'delivery', 'ship', 'доставк' ];
        $haystack = mb_strtolower( $query );
        $asked    = false;
        foreach ( $keywords as $kw ) {
            if ( mb_strpos( $haystack, mb_strtolower( $kw ) ) !== false ) { $asked = true; break; }
        }
        if ( ! $asked ) return '';

        $zones = $this->get_shipping_info();
        if ( empty( $zones ) ) return '';

        $out = [ '=== משלוחים (מידע חי מהחנות) ===' ];
        foreach ( array_slice( $zones, 0, 6 ) as $zone ) {
            $methods = [];
            foreach ( (array) ( $zone['methods'] ?? [] ) as $method ) {
                $cost      = trim( (string) ( $method['cost'] ?? '' ) );
                $methods[] = ( $method['title'] ?? '' ) . ( $cost !== '' ? ' — ' . $cost : '' );
            }
            if ( empty( $methods ) ) continue;
            $out[] = ( $zone['zone'] ?? '' ) . ': ' . implode( ' | ', $methods );
        }
        return count( $out ) > 1 ? implode( "\n", $out ) : '';
    }

    /**
     * Only unavailable variations are listed — the AI can assume the rest are in stock.
     */
    private function out_of_stock_variations( array $product ): string {
        if ( empty( $product['variations'] ) ) return '';

        $labels = [];
        foreach ( (array) $product['variations'] as $variation ) {
            if ( ( $variation['stock_status'] ?? '' ) === 'instock' ) continue;
            $attrs = array_filter( (array) ( $variation['attrs'] ?? [] ) );
            if ( empty( $attrs ) ) continue;

            $pairs = [];
            foreach ( $attrs as $label => $value ) $pairs[] = $label . ' ' . $value;
            $labels[] = implode( ' ', $pairs );
        }

        return empty( $labels ) ? '' : implode( ', ', array_slice( $labels, 0, 6 ) );
    }

    private function stock_label( string $status, ?int $qty ): string {
        switch ( $status ) {
            case 'outofstock':
                return 'אזל מהמלאי';
            case 'onbackorder':
                return 'בהזמנה מראש';
            case 'instock':
            default:
                return $qty !== null ? sprintf( 'במלאי (%d יחידות)', $qty ) : 'במלאי';
        }
    }
}
