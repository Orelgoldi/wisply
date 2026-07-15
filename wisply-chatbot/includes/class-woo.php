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
        $max = (int) $this->db->get_setting( 'woo_max_products', '4' );
        return $max > 0 ? $max : 4;
    }

    // ─── Search ───────────────────────────────────────────────────────────────

    /**
     * Search products by free text. Runs three passes (text / SKU / category name),
     * merged in relevance order and deduped by id.
     */
    public function search_products( string $query, int $limit = 4 ): array {
        if ( ! $this->is_active() ) return [];

        $query = trim( wp_strip_all_tags( $query ) );
        if ( $query === '' ) return [];

        $limit = $limit > 0 ? min( $limit, $this->max_products() ) : $this->max_products();

        $found = [];
        foreach ( [ 'text', 'sku', 'category' ] as $pass ) {
            foreach ( $this->run_search_pass( $pass, $query, $limit ) as $product ) {
                $id = $product->get_id();
                if ( isset( $found[ $id ] ) ) continue;
                $data = $this->map_product( $product );
                if ( $data !== null ) $found[ $id ] = $data;
                if ( count( $found ) >= $limit ) break 2;
            }
        }

        return array_values( $found );
    }

    /**
     * @return WC_Product[]
     */
    private function run_search_pass( string $pass, string $query, int $limit ): array {
        if ( ! function_exists( 'wc_get_products' ) ) return [];

        $args = [
            'status'  => 'publish',
            'limit'   => $limit,
            'orderby' => 'relevance',
            'return'  => 'objects',
        ];

        switch ( $pass ) {
            case 'text':
                $args['s'] = $query;
                break;

            case 'sku':
                $args['sku']     = $query;
                $args['orderby'] = 'date';
                break;

            case 'category':
                $slugs = $this->matching_category_slugs( $query );
                if ( empty( $slugs ) ) return [];
                $args['category'] = $slugs;
                $args['orderby']  = 'date';
                break;

            default:
                return [];
        }

        try {
            $products = wc_get_products( $args );
        } catch ( Throwable $e ) {
            return [];
        }

        return is_array( $products ) ? $products : [];
    }

    /**
     * Category slugs whose name contains (or is contained in) the query — lets
     * "יש לכם חולצות?" hit the "חולצות" category even with no title match.
     */
    private function matching_category_slugs( string $query ): array {
        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
        ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) return [];

        $slugs = [];
        foreach ( $terms as $term ) {
            $name = $term->name;
            if ( mb_stripos( $query, $name ) !== false || mb_stripos( $name, $query ) !== false ) {
                $slugs[] = $term->slug;
            }
        }
        return $slugs;
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
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $term ) $categories[] = $term->name;
        }

        $show_stock = $this->db->get_setting( 'woo_show_stock', '1' ) === '1';

        return [
            'id'              => $product->get_id(),
            'name'            => $product->get_name(),
            'permalink'       => (string) $product->get_permalink(),
            // Keep WooCommerce's real markup (it carries the <del>/<ins> sale distinction),
            // sanitised so the widget can render it safely. Stripping tags here would
            // collapse a sale into an ambiguous "₪100.00 ₪80.00".
            'price_html'      => wp_kses( (string) $product->get_price_html(), self::PRICE_TAGS ),
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
            'short_desc'      => $this->clean_desc( $product ),
            'type'            => (string) $product->get_type(),
            'attributes'      => $this->map_attributes( $product ),
            'variations'      => $this->map_variations( $product ),
            'add_to_cart_url' => (string) $product->add_to_cart_url(),
        ];
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
                // Same rule as the parent: keep the sale markup, sanitised.
                'price_html'   => wp_kses( (string) $obj->get_price_html(), self::PRICE_TAGS ),
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
