<?php
defined( 'ABSPATH' ) || exit;

class Wisply_AI_Handler {

    private static ?self $instance = null;
    private Wisply_Database $db;

    // Short Hebrew note describing the current turn's structured product filter
    // (price/sort/category), set in get_reply and read by build_system_prompt so the
    // model phrases "here are rings under ₪300, cheapest first" accurately.
    private string $product_note = '';

    // True when the first context source is the exact page the visitor is viewing, so
    // build_system_prompt can tell the model to answer from it first.
    private bool $page_context = false;

    // Language codes as returned by the client
    private const SUPPORTED_LANGS = [ 'he', 'en', 'ru', 'ar' ];

    private function __construct() {
        $this->db = Wisply_Database::get_instance();
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Public entry point ───────────────────────────────────────────────────

    /**
     * Generate a reply to the user message.
     *
     * @param string $user_message   The raw user text.
     * @param string $lang           Language code: he / en / ru.
     * @param array  $history        Previous [role, content] pairs (newest first, max 8).
     * @param int    $turn           How many USER messages this session has, including the current one (0 = unknown).
     * @return array{reply:string, unanswered:bool, sources:array}
     */
    public function get_reply( string $user_message, string $lang, array $history = [], int $turn = 0, string $page_url = '' ): array {
        $lang = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';

        // Expand the query with synonyms for common intents (location/contact/hours),
        // so "איפה אתם ממוקמים" also matches a page that says "כתובת / מיקום / רחוב".
        $search_query = $this->expand_query( $user_message );

        // Retrieve relevant site content (fetch a few extra, then dedupe)
        $limit = (int) $this->db->get_setting( 'max_context_docs', 5 );
        $docs  = $this->db->search_content( $search_query, $lang, $limit + 3 );

        // If Hebrew query but nothing found, also try English index as fallback
        if ( empty( $docs ) && $lang === 'he' ) {
            $docs = $this->db->search_content( $search_query, 'en', 3 );
        }

        // Drop near-duplicate pages (same title) so context isn't wasted, then cap
        $docs = $this->dedupe_docs( $docs );
        $docs = array_slice( $docs, 0, $limit );

        // The visitor is looking at a specific page. Make ITS indexed content the FIRST,
        // authoritative source — so a suggested question generated from that page (or any
        // question asked while viewing it) is answered from the page itself, not from
        // whatever the keyword search happened to surface. This is the fix for "the bot
        // can't answer the questions it shows on the page".
        $this->page_context = false;
        if ( $page_url !== '' && function_exists( 'url_to_postid' ) ) {
            $pid = (int) url_to_postid( $page_url );
            if ( $pid > 0 ) {
                $page_doc = $this->db->get_content_by_post( $pid, $lang );
                if ( $page_doc && trim( (string) ( $page_doc['content'] ?? '' ) ) !== '' ) {
                    $page_url_norm = (string) ( $page_doc['url'] ?? '' );
                    $docs = array_values( array_filter( $docs, static fn( $d ) => ( $d['url'] ?? '' ) !== $page_url_norm ) );
                    array_unshift( $docs, $page_doc );
                    $docs = array_slice( $docs, 0, $limit + 1 );
                    $this->page_context = true;
                }
            }
        }

        // Live WooCommerce products (real-time price/stock/variations) when the module is on
        $products = [];
        $show_products = false;   // does THIS message actually want the carousel shown?
        $this->product_note = '';
        if ( class_exists( 'Wisply_Woo' ) && Wisply_Woo::get_instance()->is_active() ) {
            $woo          = Wisply_Woo::get_instance();
            $max_products = (int) $this->db->get_setting( 'woo_max_products', 8 );

            // Structured intent first (price ceiling/range, cheapest/priciest, best-sellers,
            // scoped to a category) — the plain text search can't express any of these.
            $intent = $woo->parse_query_intent( $user_message );
            if ( ! empty( $intent['has_filter'] ) ) {
                $products = $woo->query_products( $intent, $max_products );
                $note     = $woo->describe_intent( $intent );
                if ( ! empty( $products ) && ! empty( $products[0]['is_fallback'] ) ) {
                    $this->product_note = trim( "לא נמצאו מוצרים בדיוק לפי הבקשה ($note). המוצרים למטה הם הקרובים ביותר במחיר — אמור זאת בכנות והצג אותם כחלופה." );
                } elseif ( $note !== '' ) {
                    $this->product_note = "המוצרים למטה נבחרו לפי בקשת הלקוח: $note. הצג אותם ואשר בקצרה שאלה הפריטים לפי הבקשה.";
                }
            } else {
                $products = $woo->search_products( $user_message, $max_products );
            }

            // Similar products for the top hit — powers "מוצרים דומים" suggestions and
            // gives the model alternatives to offer when the match is out of stock.
            // Skipped for a filtered query (cheapest / under ₪X / best-sellers): those
            // results are precise and ordered, and padding them with "related" items
            // would break the ranking the shopper asked for.
            if ( empty( $intent['has_filter'] ) && ! empty( $products[0]['id'] ) ) {
                $seen = array_map( 'intval', array_column( $products, 'id' ) );
                foreach ( $woo->related_products( (int) $products[0]['id'], 2 ) as $rel ) {
                    if ( empty( $rel['id'] ) || in_array( (int) $rel['id'], $seen, true ) ) continue;
                    $rel['is_related'] = true;
                    $products[] = $rel;
                    $seen[]     = (int) $rel['id'];
                }
            }

            // Whether to AUTO-show the carousel. Kept deliberately conservative: only an
            // explicit browse ("show me / what do you have / catalogue / list / all the")
            // or a price/sort filter. NOT a bare category mention and NOT an "order/book"
            // verb — "אפשר להזמין הרצאות לארגון?" is a B2B service inquiry, not a request to
            // dump a catalogue. Everything nuanced (recommend the one nearest lecture, answer
            // a booking inquiry) is left to the model, which now reliably HAS the products in
            // context and leads with a specific [PRODUCTS: id] when a recommendation fits.
            $is_browse = (bool) preg_match( '/הצג|תראה|תראי|להראות|לראות|מה יש|אילו|איזה.{0,12}יש|קטלוג|רשימ|לעיין|כל ה|תן לי לראות/u', $user_message );
            $show_products = ! empty( $products )
                && ( ! empty( $intent['has_filter'] ) || $is_browse );

            // Consent-to-show net. Central to Be.live's consent-based selling: the bot
            // offers a product ("רוצה שאראה לך?") and the visitor answers with a bare
            // "כן / תראה לי". That short reply carries no product keywords, so the fresh
            // search above returned nothing — and without products in context the model
            // deflects ("אין לי מידע") instead of delivering what it just offered. When
            // this message is such a confirmation, re-run the search on the PREVIOUS
            // visitor message (which held the real intent) and force the carousel, since
            // the visitor explicitly asked to see it. This turns "offer → yes → dead end"
            // into "offer → yes → cards".
            if ( empty( $products ) && $this->is_show_consent( $user_message ) ) {
                $prev = $this->last_user_message( $history );
                if ( $prev !== '' ) {
                    $intent2 = $woo->parse_query_intent( $prev );
                    $reask   = ! empty( $intent2['has_filter'] )
                        ? $woo->query_products( $intent2, $max_products )
                        : $woo->search_products( $prev, $max_products );
                    if ( ! empty( $reask ) ) {
                        $products      = $reask;
                        $show_products = true;
                        $this->product_note = 'הגולש אישר שהוא רוצה לראות את מה שהצעת. הצג את הכרטיסים למטה, ואל תאמר "אין לי מידע".';
                    }
                }
            }
        }

        // All chat goes through the Wisply AI proxy (2.16.0) — the licence key
        // is the credential, provider keys no longer exist on this site.
        $result = $this->call_openai( $user_message, $lang, $history, $docs, $products, $turn );

        $result['sources'] = array_map( fn( $d ) => [ 'title' => $d['title'], 'url' => $d['url'] ], $docs );

        // Server-driven product cards. The model is asked to emit [PRODUCTS: ...] but
        // isn't reliable about it, so we ALSO return the ids the store found. The widget
        // auto-renders these ONLY when show_products is true (a genuine shopping intent,
        // decided above) — so plain info answers no longer drag a carousel with them,
        // while "show me / how much / order X" still gets its cards without depending on
        // the model remembering the marker.
        $result['product_ids'] = array_values( array_filter( array_map(
            static fn( $p ) => isset( $p['id'] ) ? (int) $p['id'] : null,
            is_array( $products ) ? $products : []
        ) ) );
        $result['show_products'] = $show_products;

        return $result;
    }

    /** Remove pages with duplicate (or near-duplicate) titles, keeping the longest. */
    private function dedupe_docs( array $docs ): array {
        $seen = [];
        $out  = [];
        foreach ( $docs as $d ) {
            $key = mb_strtolower( preg_replace( '/\s+/u', ' ', trim( (string) $d['title'] ) ) );
            if ( isset( $seen[ $key ] ) ) {
                // keep the longer-content version
                if ( mb_strlen( (string) $d['content'] ) > mb_strlen( (string) $out[ $seen[ $key ] ]['content'] ) ) {
                    $out[ $seen[ $key ] ] = $d;
                }
                continue;
            }
            $seen[ $key ] = count( $out );
            $out[] = $d;
        }
        return $out;
    }

    /**
     * Diagnostic: run a question and report exactly what the bot retrieved and
     * answered — so you can tell whether a weak answer is a content problem
     * (0 chars indexed), a retrieval problem (wrong pages), or an AI problem.
     */
    public function diagnose( string $question, string $lang = 'he' ): array {
        $expanded = $this->expand_query( $question );
        $limit    = (int) $this->db->get_setting( 'max_context_docs', 5 );
        $docs     = $this->db->search_content( $expanded, $lang, $limit );

        $reply = $this->get_reply( $question, $lang, [] );

        return [
            'reply'         => $reply['reply'],
            'search_query'  => $expanded,
            'docs'          => array_map( fn( $d ) => [
                'title' => $d['title'],
                'url'   => $d['url'],
                'chars' => mb_strlen( (string) $d['content'] ),
            ], $docs ),
        ];
    }

    // ─── Wisply AI Proxy ──────────────────────────────────────────────────────
    //
    // As of 2.16.0 the plugin holds NO provider API key. Every AI operation is
    // sent to the Wisply server with the licence key, and the server executes
    // it against the customer's dedicated, platform-managed OpenAI project key.
    // There is nothing to steal from this site's database, and usage is
    // measured and controlled per customer on the Wisply side.

    private function proxy_base(): string {
        return untrailingslashit( defined( 'WISPLY_API_URL' ) ? WISPLY_API_URL : 'https://wisply.io' );
    }

    private function license_key_present(): bool {
        return class_exists( 'Wisply_License' )
            && Wisply_License::get_instance()->get_key() !== '';
    }

    /**
     * Execute an AI operation through the Wisply proxy.
     * Responses mirror OpenAI's own shapes, so existing parsing stays intact.
     */
    private function proxy_request( string $op, array $payload, int $timeout = 40 ): ?array {
        $key = class_exists( 'Wisply_License' ) ? Wisply_License::get_instance()->get_key() : '';

        $response = wp_remote_post( $this->proxy_base() . '/api/plugin/ai', [
            'timeout' => $timeout,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'     => $key,
                'site'    => home_url(),
                'op'      => $op,
                'payload' => $payload,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->record_ai_error( 'proxy/network: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            $this->record_ai_error( 'proxy: HTTP ' . wp_remote_retrieve_response_code( $response ) . ' — תגובה לא תקינה' );
            return null;
        }
        if ( isset( $body['error']['message'] ) ) {
            $this->record_ai_error( 'proxy: ' . $body['error']['message'] );
        }
        return $body;
    }

    /** Direct OpenAI chat call with the site's own key (bypasses the Wisply proxy). */
    private function openai_direct( string $key, array $req, int $timeout = 40 ): ?array {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $req ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->record_ai_error( 'openai/network: ' . $response->get_error_message() );
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            $this->record_ai_error( 'openai: HTTP ' . wp_remote_retrieve_response_code( $response ) . ' — תגובה לא תקינה' );
            return null;
        }
        if ( isset( $body['error']['message'] ) ) {
            $this->record_ai_error( 'openai: ' . $body['error']['message'] );
        } else {
            $this->record_ai_error( '' ); // clear on success
        }
        return $body;
    }

    // ─── Claude (Anthropic) ───────────────────────────────────────────────────

    private function call_claude( string $user_message, string $lang, array $history, array $docs, array $products = [], int $turn = 0 ): array {
        $api_key = $this->db->get_setting( 'ai_api_key', '' );
        $model   = $this->db->get_setting( 'ai_model', 'claude-sonnet-4-6' );

        if ( empty( $api_key ) ) {
            return $this->fallback_no_config( $lang );
        }

        // During an active free trial, run on the cheaper model (premium is paid-only).
        if ( class_exists( 'Wisply_Trial' ) ) {
            $model = Wisply_Trial::get_instance()->effective_model( 'claude', (string) $model );
        }

        $system  = $this->build_system_prompt( $lang, $docs, $user_message, $products, $turn );
        $messages = $this->build_message_array( $history, $user_message );

        $payload = [
            'model'      => $model,
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => $messages,
        ];

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'reply' => $this->error_msg( $lang ), 'unanswered' => true ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['content'][0]['text'] ) ) {
            $reply = trim( $body['content'][0]['text'] );
            return [
                'reply'      => $reply,
                'unanswered' => $this->is_unanswered( $reply, $lang ),
            ];
        }

        return [ 'reply' => $this->error_msg( $lang ), 'unanswered' => true ];
    }

    // ─── OpenAI ───────────────────────────────────────────────────────────────

    private function call_openai( string $user_message, string $lang, array $history, array $docs, array $products = [], int $turn = 0 ): array {
        // Bring-your-own-key path: when the site has its own OpenAI key configured, call
        // OpenAI DIRECTLY (no Wisply proxy, no dependency on the licence server). Used for
        // demos / self-hosted setups. Real subscriptions leave the key blank and run
        // through the managed proxy, which needs a licence key the server recognises.
        $direct_key = trim( (string) $this->db->get_setting( 'openai_api_key', '' ) );

        if ( $direct_key === '' && ! $this->license_key_present() ) {
            return $this->fallback_no_config( $lang );
        }

        $model = $this->db->get_setting( 'openai_model', 'gpt-4o' );

        // During an active free trial, run on the cheaper model (premium is paid-only).
        if ( class_exists( 'Wisply_Trial' ) ) {
            $model = Wisply_Trial::get_instance()->effective_model( 'openai', (string) $model );
        }

        $system   = $this->build_system_prompt( $lang, $docs, $user_message, $products, $turn );
        $messages = array_merge(
            [ [ 'role' => 'system', 'content' => $system ] ],
            $this->build_message_array( $history, $user_message )
        );

        $req = [
            'model'       => $model,
            'max_tokens'  => 1024,
            'messages'    => $messages,
            'temperature' => 0.2,
        ];

        $body = $direct_key !== ''
            ? $this->openai_direct( $direct_key, $req )
            : $this->proxy_request( 'chat', $req );

        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            $reply = trim( $body['choices'][0]['message']['content'] );
            $this->record_ai_error( '' ); // clear any previous error on success
            return [
                'reply'      => $reply,
                'unanswered' => $this->is_unanswered( $reply, $lang ),
            ];
        }

        return [ 'reply' => $this->error_msg( $lang ), 'unanswered' => true ];
    }

    /** Store the last AI error so the admin System Check can show what actually failed. */
    private function record_ai_error( string $msg ): void {
        update_option( 'wisply_last_ai_error', $msg, false );
        if ( $msg !== '' ) {
            error_log( '[Wisply] AI error: ' . $msg );
        }
    }

    // ─── Prompt construction ──────────────────────────────────────────────────

    private function build_system_prompt( string $lang, array $docs, string $query = '', array $products = [], int $turn = 0 ): string {
        $context_block = '';
        if ( ! empty( $docs ) ) {
            $context_block = "\n\n=== תוכן רלוונטי מהאתר ===\n";
            if ( $this->page_context ) {
                $context_block .= "(שים לב: [מקור 1] הוא העמוד המדויק שהגולש צופה בו כרגע. אם השאלה נוגעת לעמוד הזה — למשל שאלה שהוצעה לו בעמוד — ענה עליה קודם כל על סמך המקור הזה.)\n";
            }
            foreach ( $docs as $i => $doc ) {
                $clean   = preg_replace( '/\s+/u', ' ', strip_tags( $doc['content'] ) );
                $snippet = $this->relevant_snippet( $clean, $query, 2400 );
                $context_block .= sprintf(
                    "\n[מקור %d] %s\nכתובת: %s\n%s\n",
                    $i + 1,
                    $doc['title'],
                    $doc['url'],
                    $snippet
                );
            }
        } else {
            $context_block = "\n\n=== תוכן רלוונטי מהאתר ===\n(לא נמצא תוכן רלוונטי לשאלה זו באתר)\n";
        }

        // Live product data block — authoritative over any indexed site content
        $products_block = '';
        if ( ! empty( $products ) && class_exists( 'Wisply_Woo' ) ) {
            // Card cap must track the admin setting — otherwise an admin who allows 8
            // products still only ever gets 4 cards rendered.
            $card_cap = max( 1, min( 12, (int) $this->db->get_setting( 'woo_max_products', 8 ) ) );
            $filter_note = $this->product_note !== '' ? "הערת סינון: {$this->product_note}\n\n" : '';
            $products_block = "\n\n=== מוצרים מהחנות (נתונים חיים ומעודכנים) ===\n"
                . $filter_note
                . Wisply_Woo::get_instance()->format_products_for_prompt( $products, $query )
                . "\n\nכללי מענה על מוצרים:\n"
                . "• בבלוק המוצרים למעלה יש פריטים אמיתיים ומעודכנים מהחנות (כולל הרצאות, סדנאות וקורסים). אם השאלה נוגעת לפריט שמופיע בבלוק — ענה ממנו ישירות והצג את הכרטיסים. שמות הפריטים כוללים לעיתים תאריך ומיקום (למשל \"04.08 חולון\"), קרא אותם משם וענה עליהם. **אסור לומר \"אין לי מידע\" ואסור להפנות להשארת פרטים כאשר קיים בבלוק פריט רלוונטי לשאלה.** השארת פרטים היא רק כשאין בבלוק שום פריט מתאים.\n"
                // The product vision: a proactive salesperson, not a passive catalogue.
                // Crucial framing: the bot only PRESENTS the product and links to its page;
                // it never adds to cart or places an order itself. Phrasing must reflect that.
                . "• היה יזום ומעודד רכישה, כמו איש מכירות חם ולא כמו קטלוג. כשיש פריט אחד שהכי עונה לשאלה (ההרצאה הקרובה ביותר, ההתאמה הטובה ביותר) — **הובל איתו באופן ספציפי**: נקוב בשמו עם הפרט הקונקרטי (תאריך, מיקום, מחיר) והצג את הכרטיס שלו עם [PRODUCTS: id] של אותו פריט בודד.\n"
                . "• חשוב מאוד לגבי הניסוח: אתה **מציג** את המוצר בלבד ומפנה לעמוד שלו, אתה **לא** מוסיף לסל ולא מבצע הזמנה בעצמך. אל תגיד \"אתפוס לך כרטיס\", \"הוספתי לסל\" או \"הזמנתי עבורך\". במקום זה הזמן את הגולש להשלים בעצמו, למשל: \"הנה ההרצאה, אפשר לתפוס כרטיס דרך הכפתור בכרטיס\", \"להזמנה לחצו 'לצפייה במוצר'\", \"הכל מחכה לך בעמוד המוצר\".\n"
                . "• העדף המלצה על פריט ספציפי אחד (או שניים) על פני רשימה כללית. רשימה ארוכה רק אם הגולש ביקש במפורש לראות את כל האפשרויות או לעיין בקטגוריה.\n"
                // Service / B2B inquiries are a conversation, not a catalogue dump.
                . "• אם השאלה היא בירור על שירות, אפשרות או תהליך (למשל \"אפשר להזמין הרצאות לארגון?\", \"איך זה עובד?\", \"אתם עושים גם X?\") — ענה בחום ובקצרה על האפשרות ועל התהליך, והצע להתחבר או להשאיר פרטים אם רלוונטי. אל תציג קרוסלת מוצרים בתגובה לבירור כזה; הצג כרטיס רק אם הגולש ביקש לראות או להזמין פריט ספציפי.\n"
                . "• ענה על מחיר, מלאי ווריאציות אך ורק לפי הנתונים שבבלוק המוצרים למעלה. הנתונים האלה גוברים על כל מחיר או מידע שמופיע בתוכן האתר. לעולם אל תמציא מחיר או מצב מלאי, ואל תשלים מספרים מהזיכרון.\n"
                . "• כשאתה מוסר מחיר — מסור את \"מחיר נוכחי\". אם צוין שהמוצר במבצע, ציין גם את המחיר הרגיל. לעולם אל תציג את המחיר הרגיל כאילו הוא המחיר לתשלום.\n"
                . "• אם למוצר יש כמה וריאציות (מידה/צבע וכד') — שאל את הגולש איזו מתאימה לו, ורק אז מסור את המחיר והמלאי של הווריאציה הנכונה.\n"
                . "• אם מוצר אזל מהמלאי — אמור זאת בכנות והצע חלופה מתוך רשימת המוצרים למעלה (מוצרים המסומנים \"מוצר דומה\" נועדו בדיוק לכך).\n"
                . "• אם יש בלוק משלוחים — ענה על עלויות וזמני משלוח אך ורק לפיו.\n"
                // The core fix for "it dumps products on every question": gate the cards on
                // intent. Answer first; show the carousel only when products are actually
                // wanted, otherwise OFFER. Never both an info answer and a carousel at once.
                . "• מתי להציג כרטיסי מוצר: אך ורק כשהגולש באמת רוצה לראות, לעיין או לקנות מוצרים, או ששאל שאלה ישירה על מוצר/מחיר/קטגוריה, או שביקש התאמה. במקרה כזה כתוב משפט קצר אחד (למשל \"הנה כמה שיכולים להתאים:\") "
                . sprintf( "ומיד אחריו [PRODUCTS: id,id] עם עד %d מזהים. אל תפרט שמות/מחירים כטקסט, הכרטיסים מציגים את זה.\n", $card_cap )
                . "• אם זו שאלת מידע או תוכן (על סדנה, הרצאה, נושא, \"מה זה X\", \"ספר לי על Y\") — ענה קודם על השאלה עצמה בטקסט, בלי כרטיסים באותה הודעה. אם יש פריטים קשורים שעשויים לעניין, סיים בהצעה קצרה כמו \"רוצה שאראה לך את הפריטים הקשורים?\" עם [OPTIONS: כן, הראו לי | לא, תודה]. רק אם הגולש יאשר — בהודעה הבאה הוסף [SUGGEST: מילות חיפוש] לפי מה ששאל, והכרטיסים יופיעו.\n"
                . "• לעולם לא גם תשובת-תוכן וגם כרטיסים באותה הודעה כשלא התבקשו מוצרים. או שעונים, או שמציעים — לא שניהם.\n"
                // Product questions are e-commerce, not lead-gen. The card + "view product"
                // link IS the call to action; nudging the visitor to leave contact details
                // here is wrong and annoying.
                . "• בשאלות על מוצרים אל תשתמש בסמן [ASK_LEAD] ואל תציע להשאיר פרטים ליצירת קשר. הפעולה הנכונה היא הכרטיסים והמעבר לעמוד המוצר. הצע להשאיר פרטים אך ורק אם הגולש ביקש במפורש הצעת מחיר מותאמת, ייעוץ אישי, או דבר שאין עליו תשובה בחנות.";
        }

        // Cross-sell / bundle flow — active when the store module is on and the admin
        // enabled complementary matching. The widget shows a "match me a complementary
        // product" button and sends that request here; the model runs a short guided
        // flow and emits [SUGGEST: terms], which the widget resolves to real cards.
        $bundle_block = '';
        if ( class_exists( 'Wisply_Woo' ) && Wisply_Woo::get_instance()->is_active()
            && $this->db->get_setting( 'woo_bundle_enabled', '1' ) === '1' ) {
            $bundle_block = "\n\nהמלצה על מוצר משלים (Cross-sell) — זרימה של שני שלבים נפרדים בהודעות נפרדות:\n"
                . "• כשהלקוח מבקש שתתאים או תמליץ על מוצר משלים (\"מוצר משלים\", \"שילך עם\", \"להשלים את הלוק\", \"סט\") — נהל שיחה קצרה וחכמה, אל תזרוק המלצה יבשה.\n"
                . "• שלב א׳ (הודעה זו): שאל שאלה אחת ממוקדת כדי להבין את הצורך (למשל: למי זה מיועד? לאיזה אירוע? איזה סגנון אוהבים?), עם הסמן [OPTIONS: אפשרות | אפשרות | אפשרות] ותשובות אמיתיות. **בשלב הזה אסור להוסיף את הסמן [SUGGEST] ואסור להציג מוצרים בכלל** — רק השאלה.\n"
                . "• שלב ב׳ (הודעה נפרדת, רק אחרי שהלקוח ענה על השאלה): המלץ על פריט משלים **מקטגוריה אחרת** מזו שכבר הוצגה (לטבעת מתאימים עגילים או שרשרת; לשרשרת מתאימים עגילים או צמיד). כתוב משפט אישי קצר ומנומק, ומיד אחריו הוסף את הסמן [SUGGEST: מילות חיפוש בעברית] עם 2 עד 4 מילים (סוג + חומר/סגנון, למשל [SUGGEST: עגילי זהב עדינים]). הכרטיסים יוצגו אוטומטית — אל תפרט אותם כטקסט.\n"
                . "• חוקים: לעולם אל תשלב [OPTIONS] ו-[SUGGEST] באותה הודעה. אל תשתמש ב-[SUGGEST] לפני שהלקוח ענה לשאלת ההכוונה. אל תמליץ על אותה קטגוריה שכבר הוצגה, ואל תשתמש ב-[ASK_LEAD] בזרימה הזו.";
        }

        // Order-status flow — active when the store module is on and the admin enabled it.
        // The model just recognises the intent and emits [ORDER_FORM]; the widget then
        // collects order number + email and looks up the status securely server-side.
        $order_block = '';
        if ( class_exists( 'Wisply_Woo' ) && Wisply_Woo::get_instance()->is_active()
            && $this->db->get_setting( 'woo_order_status_enabled', '1' ) === '1' ) {
            $order_block = "\n\nבדיקת סטטוס הזמנה:\n"
                . "• אם הלקוח שואל על הזמנה קיימת שלו (\"איפה ההזמנה שלי\", \"מה הסטטוס\", \"מתי יגיע המשלוח\", \"מספר מעקב\", \"בוצע חיוב?\") — הוסף בסוף התשובה את הסמן [ORDER_FORM]. הגולש יקבל טופס קצר להזנת מספר הזמנה + המייל שאיתו הזמין, והמערכת תבדוק ותציג את הסטטוס בעצמה.\n"
                . "• כתוב משפט קצר ומזמין (למשל: אשמח לבדוק, רק צריך את מספר ההזמנה והמייל שאיתו הוזמנה) והוסף את הסמן. חשוב: אל תבקש את הפרטים כטקסט חופשי, אל תבדוק בעצמך, ולעולם אל תמציא סטטוס, תאריך או מספר מעקב.";
        }

        // Human handoff to WhatsApp — active when the owner set an agent number.
        $handoff_block = '';
        if ( $this->db->get_setting( 'handoff_enabled', '0' ) === '1'
            && trim( (string) $this->db->get_setting( 'handoff_wa_number', '' ) ) !== '' ) {
            $handoff_block = "\n\nהעברה לנציג אנושי:\n"
                . "• אם הלקוח מבקש לדבר עם נציג / בן אדם / מישהו אמיתי, או שאתה מזהה תסכול או צורך שדורש טיפול אנושי — כתוב משפט קצר וחם (למשל \"אני מעביר אותך לנציג/ה שלנו בוואטסאפ, רגע אחד\") והוסף בסוף התשובה את הסמן [HANDOFF]. הגולש יקבל כפתור שמעביר אותו לוואטסאפ של הנציג עם סיכום השיחה.\n"
                . "• אחרי שהלקוח ביקש נציג אל תמשיך לנסות לענות בעצמך על אותה שאלה, פשוט העבר.";
        }

        // Per-site owner rules (empty by default). Authoritative business knowledge,
        // tone, and product-matching logic the owner typed in settings — injected high
        // in the prompt so the bot acts by them. Stored per install, never in code.
        $custom_rules = trim( (string) $this->db->get_setting( 'ai_custom_rules', '' ) );
        $custom_block = $custom_rules !== ''
            ? "\n\n=== חוקים והנחיות של בעל העסק (חשוב מאוד — פעל לפיהם תמיד) ===\n$custom_rules\n"
            : '';

        // Smart product matching by what the customer DESCRIBES (emotion / occasion /
        // recipient / budget) rather than a product name. Generic — works for any store,
        // and leans on the owner rules above + the live category vocabulary. Gated on the
        // store module being on; otherwise these markers would resolve to nothing.
        $catalog_block = '';
        $match_block   = '';
        if ( class_exists( 'Wisply_Woo' ) && Wisply_Woo::get_instance()->is_active() ) {
            $cats = Wisply_Woo::get_instance()->category_names();
            if ( ! empty( $cats ) ) {
                $catalog_block = "\n\nקטגוריות המוצרים בחנות (אוצר המילים שלך להתאמה): " . implode( ', ', array_slice( $cats, 0, 40 ) ) . ".";
            }
            $match_block = "\n\nהתאמת מוצר חכמה לפי מה שהלקוח מתאר:\n"
                . "• כשהלקוח מתאר תחושה, אירוע, מצב, נמען או צורך בלי לנקוב בשם מוצר או קטגוריה (\"מחפשת מתנה ליום נישואין\", \"משהו שישמח אותי\", \"מתנה לאמא\", \"אני מרגישה חגיגית\", \"תקציב קטן\") — אל תשלוף מוצרים אקראית. הבן מה באמת מתאים לפי החוקים של בעל העסק והקטגוריות הקיימות.\n"
                . "• כתוב משפט אישי חם שמסביר בקצרה למה זה מתאים למה שתיאר, ומיד אחריו הוסף [SUGGEST: מילות חיפוש בעברית] עם סוג הפריט או הקטגוריה המתאימה (למשל [SUGGEST: שרשראות זהב אלגנטיות]). הכרטיסים יוצגו אוטומטית — אל תפרט אותם כטקסט.\n"
                . "• אם חסר פרט קריטי להתאמה (תקציב, למי המתנה, סגנון) — שאל שאלה קצרה אחת עם [OPTIONS: ...] לפני ההצעה. אחרת הצע ישר.\n"
                . "• אם בעל העסק לא הגדיר חוקי התאמה — התאם לפי היגיון קמעונאי בריא (אירוע חגיגי -> פריט אלגנטי; לשמח -> פריט צבעוני; קלאסי -> פריט עדין ובטוח).\n"
                // The reported bug: a purchasable lecture was answered from site content, and
                // when the visitor said "I want to order tickets" the message no longer named
                // the item, so the store search found nothing and the bot fell to "no info +
                // leave details". [SUGGEST:] re-surfaces the item's card (with its buy link)
                // from the conversation topic, so purchase intent always lands on the product.
                . "• רכישה / הזמנה / הרשמה / כרטיסים: אם הגולש רוצה לקנות, להזמין, להירשם או לקבל כרטיסים לפריט שנמכר בחנות (מוצר, הרצאה, סדנה, קורס) — גם אם ענית עליו קודם מתוך תוכן האתר — אל תאמר \"אין לי מידע\" ואל תבקש להשאיר פרטים. הצג את כרטיס הפריט עם [SUGGEST: מילות חיפוש של אותו פריט] (למשל אם דיברתם על ההרצאה \"איך לשחק עם הקלפים\" השתמש ב-[SUGGEST: איך לשחק עם הקלפים]). בכרטיס יש כפתור \"לצפייה במוצר\" שדרכו הגולש משלים את ההזמנה בעצמו. רק אם באמת אין פריט תואם בחנות — הצע ליצור קשר או להשאיר פרטים.\n"
                . "• זכור: הרצאות, סדנאות וקורסים יכולים להיות מוצרים בחנות בדיוק כמו כל מוצר. כשמתעניינים להירשם או להזמין אחד מהם, נסה תמיד קודם להציג את הכרטיס שלו עם [SUGGEST:].";
        }

        $lang_instruction = match ( $lang ) {
            'en' => 'Always reply in English only, regardless of the question language.',
            'ru' => 'Всегда отвечай только на русском языке.',
            'ar' => 'أجب دائمًا باللغة العربية فقط، بغض النظر عن لغة السؤال.',
            default => 'ענה בעברית בלבד.',
        };

        $p           = $this->persona();
        $bot         = $p['bot'];
        $business    = $p['business'];
        $type_suffix = $p['type'] !== '' ? " — {$p['type']}" : '';
        $desc_block  = $p['desc'] !== '' ? "\nרקע על $business: {$p['desc']}\n" : '';

        $phone = $this->db->get_setting( 'phone', '' );
        $phone_line = $phone
            ? "אם אינך יודע — הצע למשתמש להתקשר למספר $phone."
            : "אם אינך יודע — אמור זאת בכנות והצע לפנות לצוות $business.";

        // Emergency escalation
        $emergency_msg = (string) $this->db->get_setting( 'emergency_msg_' . $lang, '' );
        $emergency_block = $emergency_msg
            ? "\n\nמצב חירום: אם הפונה מתאר מצוקה דחופה (מצב מסכן חיים, מחשבות אובדניות, פגיעה עצמית, מצוקה רפואית/נפשית חריפה) — עצור מיד, אל תיתן מידע אחר, השב אך ורק: \"$emergency_msg\", והוסף בסוף את הסמן [EMERGENCY]."
            : '';

        // Available quick-action buttons (admin-defined, generic)
        $actions = $this->available_actions();
        $action_block = '';
        if ( ! empty( $actions ) ) {
            $avail = [];
            foreach ( $actions as $k => $label ) {
                $avail[] = $k . ' (' . $label . ')';
            }
            $action_block = "\n\nכפתורי פעולה זמינים: " . implode( ', ', $avail ) . ".\n"
                . "אם המשתמש מביע רצון לבצע אחת מהפעולות האלה — "
                . "הוסף בסוף התשובה את הסמן [ACTION:key] עם המפתח המתאים (לדוגמה [ACTION:" . array_key_first( $actions ) . "]). הוסף סמן אחד בלבד, ורק כשזה באמת רלוונטי.";
        }

        // Job-seeker flow: a *specific* role interest → jobs page + lead form together.
        // Active only when a 'jobs' (careers) action button is configured.
        $jobs_block = '';
        if ( isset( $actions['jobs'] ) ) {
            $jobs_block = "\n\nטיפול בפניות דרושים/קריירה (תקף בכל שפה — עברית/אנגלית/רוסית/ערבית):\n"
                . "• אם הפונה מחפש עבודה ומציין תפקיד / תחום / משרה ספציפיים שמעניינים אותו "
                . "(למשל \"אני מחפש עבודה כפיזיותרפיסט\", \"יש משרה לאחות?\", \"I'm a nurse looking for a job\", \"ищу работу медсестрой\") — "
                . "בצע את שני הדברים **באותה תשובה**: (א) הוסף [ACTION:jobs] כדי להפנות אותו לדף הדרושים; "
                . "(ב) הצע לו בחום להשאיר פרטים כדי שצוות הגיוס יחזור אליו לגבי אותה משרה, והוסף בסוף [ASK_LEAD].\n"
                . "• אם הפונה רק שואל בכלליות \"יש דרושים?\" בלי לציין תפקיד — הוסף [ACTION:jobs] ושאל אותו איזה תחום/תפקיד מעניין אותו (עדיין בלי טופס).\n"
                . "• במקרה הזה מותר לשלב [ACTION:jobs] יחד עם [ASK_LEAD] באותה תשובה (זהו החריג לכלל \"סמן אחד בלבד\").";
        }

        // Wrap-up: as the message limit nears, converge toward closing + lead capture
        $max_messages   = (int) $this->db->get_setting( 'max_messages', '0' );
        $wrapup_margin  = (int) $this->db->get_setting( 'wrapup_margin', '2' );
        $wrapup_block   = '';
        if ( $turn > 0 && $max_messages > 0 && $turn >= $max_messages - $wrapup_margin ) {
            $wrapup_block = "\n\nסיום שיחה מתקרב:\n"
                . "• השיחה מתקרבת לסיומה — אל תפתח נושאים חדשים ואל תאריך.\n"
                . "• סכם בקצרה את מה שרלוונטי לפונה, וחתור לכך שישאיר פרטים כדי שנחזור אליו.\n"
                . "• הוסף בסוף התשובה את הסמן [ASK_LEAD] (אלא אם כבר הושארו פרטים בשיחה).";
        }

        return <<<PROMPT
אתה "$bot", העוזר החכם של $business$type_suffix.
ענה לגולשים על שאלות הקשורות ל-$business, בהתבסס אך ורק על תוכן האתר שמופיע למטה.$desc_block$custom_block

כללי מענה:
1. ענה לפי "תוכן רלוונטי מהאתר" שמופיע למטה. קרא את כל המקורות בעיון לפני שתאמר שאינך יודע.
2. היה עוזר ויוזם — אם בתוכן יש מידע קרוב או מקביל לשאלה, הצג אותו במקום לסרב. שים לב שמונחים שונים עשויים לתאר את אותו הדבר (מילים נרדפות, צורות שונות של אותה מילה).
3. ענה ישירות, בחום ובקצרה (עד 120 מילים). אם יש מספר פריטים רלוונטיים — פרט אותם.
   כשנשאלת שאלה כללית כמו "מה יש לכם?" / "אילו שירותים/מוצרים יש?" — תן **רשימה מפורטת** של כל הפריטים שמופיעים בתוכן (במיוחד ברשימת התפריט), כל אחד בשורה. אל תסכם בכלליות.
4. רק אם באמת אין שום מידע קרוב בתוכן — $phone_line אל תמציא שמות, מספרים, מחירים או תאריכים שלא מופיעים בתוכן.
5. אל תיתן ייעוץ מקצועי אישי (רפואי, משפטי, פיננסי וכד') שאינו מופיע במפורש בתוכן — הפנה לאיש מקצוע מטעם $business.
6. אל תציג קישורים, כתובות URL, או רשימת "מקורות" בתשובה. ענה בשפה טבעית, כאילו אתה נציג שירות אנושי של $business.
7. היה חם ומתעניין, כמו נציג מקצועי שאכפת לו. גלה עניין אמיתי בצורך של הפונה.
8. $lang_instruction

תהליך מודרג להשארת פרטים — **אל תקפיץ טופס ישר!** עבוד בשלבים:

שלב 1 — בירור עם כפתורי בחירה: כשהפונה מתעניין בקטגוריה כללית (מוצר/שירות/קטגוריה) בלי לציין פריט מסוים — אל תציג טופס. במקום זאת שאל בקצרה ובחום באיזה פריט ספציפי הוא מתעניין, והצג את האפשרויות האמיתיות מהתוכן בעזרת הסמן:
[OPTIONS: פריט 1 | פריט 2 | פריט 3]
(עד 5 אפשרויות אמיתיות בלבד מהתוכן, מופרדות בתו |).

שלב 2 — שאלת כן/לא: אחרי שהפונה בחר פריט ספציפי (או ציין אותו מראש) — ענה בקצרה ובחום על אותו פריט, ואז שאל אם הוא מעוניין שנשאיר פרטים כדי לקבל מידע נוסף/שיחזרו אליו, והוסף בסוף את הסמן [ASK_LEAD]. **אל** תוסיף [SHOW_LEAD_FORM] בשלב הזה.

שלב 3 — טופס: רק כשהפונה אומר במפורש "כן" / "אני רוצה להשאיר פרטים" / "שיחזרו אליי" / "תאמו לי" — הוסף [SHOW_LEAD_FORM].

כללים:
- השתמש ב-[OPTIONS] רק כשבאמת יש כמה אפשרויות בתוכן. אם הפונה כבר ציין פריט ספציפי — דלג לשלב 2.
- **אל** תציג [ASK_LEAD] או טופס בשאלות עובדתיות-נקודתיות שכבר ענית עליהן (כתובת, טלפון, שעות) — אלא אם הפונה ממשיך ומגלה עניין להתקדם.
- אל תוסיף שום סמן רק בגלל שאינך יודע תשובה — במקרה כזה הצע להתקשר.
- הוסף לכל היותר סמן אחד מסוג [OPTIONS]/[ASK_LEAD]/[SHOW_LEAD_FORM] בכל תשובה.
$emergency_block
$action_block
$jobs_block
$wrapup_block
$context_block
$products_block
$catalog_block
$match_block
$bundle_block
$order_block
$handoff_block
PROMPT;
    }

    /** Resolve the white-label persona from settings, with sensible fallbacks. */
    private function persona(): array {
        $business = trim( (string) $this->db->get_setting( 'business_name', '' ) );
        if ( $business === '' ) $business = trim( (string) get_bloginfo( 'name' ) );
        if ( $business === '' ) $business = 'האתר';
        $bot = trim( (string) $this->db->get_setting( 'bot_name', '' ) );
        if ( $bot === '' ) $bot = $business;
        return [
            'bot'      => $bot,
            'business' => $business,
            'type'     => trim( (string) $this->db->get_setting( 'business_type', '' ) ),
            'desc'     => trim( (string) $this->db->get_setting( 'business_description', '' ) ),
        ];
    }

    /** Build the available quick-action map [ key => Hebrew label ] from admin settings. */
    private function available_actions(): array {
        $out = [];
        $buttons = json_decode( (string) $this->db->get_setting( 'action_buttons', '' ), true );
        if ( is_array( $buttons ) ) {
            foreach ( $buttons as $b ) {
                if ( ! empty( $b['key'] ) && ! empty( $b['url'] ) ) {
                    $key = sanitize_key( (string) $b['key'] );
                    $out[ $key ] = (string) ( $b['label_he'] ?? $b['label'] ?? $key );
                }
            }
        }
        if ( empty( $out ) ) {
            $links = json_decode( (string) $this->db->get_setting( 'action_links', '' ), true );
            if ( is_array( $links ) ) {
                foreach ( $links as $k => $url ) { $out[ $k ] = $k; }
            }
        }
        return $out;
    }

    private function build_message_array( array $history, string $user_message ): array {
        // history is newest-first; reverse to chronological
        $messages = [];
        foreach ( array_reverse( array_slice( $history, 0, 8 ) ) as $h ) {
            $messages[] = [ 'role' => $h['role'], 'content' => $h['content'] ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $user_message ];
        return $messages;
    }

    /**
     * Is this a short "yes / show me" reply that confirms a product offer the bot just
     * made? Kept tight (length-capped, explicit affirmatives only) so a normal sentence
     * that merely starts with "כן" is not mistaken for a bare confirmation.
     */
    private function is_show_consent( string $msg ): bool {
        $m = trim( $msg );
        if ( $m === '' || mb_strlen( $m ) > 40 ) return false;
        // Explicit "show me / send them".
        if ( preg_match( '/(תראה|תראי|תראו|להראות|לראות|הצג|הראה|הראו|שלח|תשלח)/u', $m ) ) return true;
        // Bare affirmatives at the start, as a standalone token. NOTE: PCRE \b does not
        // work against Hebrew letters, so match "end-or-separator" explicitly instead —
        // otherwise "כן" on its own would never match (and "כנראה" must NOT match).
        if ( preg_match( '/^(כן|בטח|בהחלט|בבקשה|אשמח|סבבה|יאללה|אוקיי|אוקי|וכן|ok|okay|yes|sure)($|[\s,.!;:])/iu', $m ) ) return true;
        return false;
    }

    /** The most recent visitor message from the (newest-first) history, or ''. */
    private function last_user_message( array $history ): string {
        foreach ( $history as $h ) {
            if ( ( $h['role'] ?? '' ) === 'user' ) {
                return trim( (string) ( $h['content'] ?? '' ) );
            }
        }
        return '';
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Expand a user query with synonyms for common navigational intents, so the
     * keyword search reaches the right page despite Hebrew word variations.
     */
    private function expand_query( string $query ): string {
        $q       = mb_strtolower( $query );
        $extra   = [];

        $intents = [
            // location / directions  →  contact-page vocabulary
            'מיקום כתובת רחוב להגיע נסיעה חניה מפה' =>
                [ 'איפה','ממוקמ','מיקום','כתובת','להגיע','מגיע','נמצא','רחוב','חניה','מפה','address','location','located','directions','where' ],
            // phone / contact
            'טלפון צרו קשר מספר ליצירת קשר פנייה' =>
                [ 'טלפון','להתקשר','תתקשר','מספר','צור קשר','ליצור קשר','קשר','phone','call','contact' ],
            // opening hours
            'שעות פעילות שעות פתיחה ימים' =>
                [ 'שעות','פתוח','פתיחה','פעילות','מתי','hours','open','schedule' ],
            // admission / intake
            'תהליך קבלה אשפוז הפניה טופס הרשמה' =>
                [ 'קבלה','להתקבל','אשפוז','הפניה','הרשמה','admission','intake','referral' ],
            // staff: doctor / department manager / head nurse  →  team-page vocabulary
            'מנהל המחלקה מנהלת המחלקה רופא אחות אחראית צוות הנהלה אנשי סגל' =>
                [ 'רופא','הרופא','מנהל','מנהלת','אחות','אחראית','צוות','סגל','מי','דוקטור','דר','פרופ','doctor','nurse','manager','staff','team' ],
        ];

        foreach ( $intents as $additions => $triggers ) {
            foreach ( $triggers as $trigger ) {
                if ( mb_strpos( $q, $trigger ) !== false ) {
                    $extra[] = $additions;
                    break;
                }
            }
        }

        return $extra ? $query . ' ' . implode( ' ', $extra ) : $query;
    }

    /**
     * Return up to $max chars of $content, centred on the most specific query
     * word (longest = most distinctive, e.g. "כללי"/"ממוקמים") so relevant info
     * deep in a long page (like a staff list) isn't cut off.
     */
    private function relevant_snippet( string $content, string $query, int $max ): string {
        if ( mb_strlen( $content ) <= $max ) return $content;

        // Most specific words first (longest), so we centre on the distinctive term
        $words = array_filter(
            preg_split( '/\s+/u', trim( $query ) ),
            fn( $w ) => mb_strlen( $w ) >= 3
        );
        usort( $words, fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );

        $pos = false;
        foreach ( $words as $w ) {
            $p = mb_stripos( $content, $w );
            if ( $p !== false ) { $pos = $p; break; }
        }

        if ( $pos === false ) {
            return mb_substr( $content, 0, $max ) . '…';
        }

        // Centre a window on the match
        $start   = max( 0, $pos - (int) ( $max * 0.4 ) );
        $snippet = mb_substr( $content, $start, $max );
        return ( $start > 0 ? '…' : '' ) . $snippet . '…';
    }

    private function is_unanswered( string $reply, string $lang ): bool {
        $markers = [
            'he' => [ 'אינני יודע', 'לא מצאתי', 'אין לי מידע', 'צור קשר', 'פנה אלינו' ],
            'en' => [ "I don't know", "I couldn't find", 'no information', 'contact us', 'please call' ],
            'ru' => [ 'не знаю', 'не нашел', 'нет информации', 'свяжитесь', 'позвоните' ],
            'ar' => [ 'لا أعرف', 'لم أجد', 'لا توجد معلومات', 'اتصل بنا', 'تواصل معنا' ],
        ];
        $lower = mb_strtolower( $reply );
        foreach ( ( $markers[ $lang ] ?? [] ) as $marker ) {
            if ( str_contains( $lower, mb_strtolower( $marker ) ) ) return true;
        }
        return false;
    }

    private function error_msg( string $lang ): string {
        return match ( $lang ) {
            'en'    => 'Sorry, I encountered an error. Please try again or contact us by phone.',
            'ru'    => 'Извините, произошла ошибка. Пожалуйста, попробуйте ещё раз или позвоните нам.',
            'ar'    => 'عذرًا، حدث خطأ. يرجى المحاولة مرة أخرى أو الاتصال بنا هاتفيًا.',
            default => 'מצטערים, אירעה שגיאה. נסה שוב או צור קשר טלפוני.',
        };
    }

    private function fallback_no_config( string $lang ): array {
        $msg = match ( $lang ) {
            'en'    => 'The chatbot is not configured yet. Please contact the site administrator.',
            'ru'    => 'Чат-бот ещё не настроен. Обратитесь к администратору сайта.',
            'ar'    => 'لم يتم إعداد المحادثة بعد. يرجى التواصل مع مسؤول الموقع.',
            default => 'הצ׳אט טרם הוגדר. אנא פנה למנהל האתר.',
        };
        return [ 'reply' => $msg, 'unanswered' => true ];
    }

    // ─── Page-aware suggested questions ────────────────────────────────────────

    /**
     * Generate up to 4 short, page-specific questions a visitor might ask, based on
     * the indexed content of the page they're on. Used by the proactive bubble.
     *
     * @return string[] up to 4 questions (empty if no content / no AI).
     */
    public function generate_page_questions( string $title, string $url, string $lang ): array {
        $lang  = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';
        $query = $title !== '' ? $title : $url;
        if ( trim( $query ) === '' ) return [];

        // Pull the indexed content for this page
        $docs    = $this->db->search_content( $query, $lang, 3 );
        $content = '';
        foreach ( $docs as $d ) {
            $content .= ' ' . strip_tags( (string) $d['content'] );
            if ( mb_strlen( $content ) > 3000 ) break;
        }
        $content = trim( preg_replace( '/\s+/u', ' ', $content ) );
        if ( $content === '' ) return [];
        $content = mb_substr( $content, 0, 2800 );

        $lang_name = [ 'he' => 'עברית', 'en' => 'English', 'ru' => 'русском языке', 'ar' => 'اللغة العربية' ][ $lang ] ?? 'עברית';
        $system    = 'אתה יוצר שאלות נפוצות קצרות. החזר אך ורק מערך JSON של 4 מחרוזות (שאלות), ללא שום טקסט נוסף.';
        $user      = "להלן תוכן מתוך עמוד באתר בשם \"$title\". "
            . "צור בדיוק 4 שאלות קצרות וברורות (עד 6 מילים כל אחת) שגולש המתעניין בעמוד הזה עשוי לשאול, ב$lang_name. "
            . "התבסס רק על התוכן. החזר מערך JSON בלבד.\n\nתוכן:\n$content";

        $raw = $this->raw_completion( $system, $user, 300 );
        if ( preg_match( '/\[.*\]/s', $raw, $m ) ) {
            $arr = json_decode( $m[0], true );
            if ( is_array( $arr ) ) {
                $out = [];
                foreach ( $arr as $q ) {
                    $q = trim( (string) $q );
                    if ( $q !== '' ) $out[] = mb_substr( $q, 0, 80 );
                }
                return array_slice( $out, 0, 4 );
            }
        }
        return [];
    }

    /**
     * One-to-two sentence summary of a conversation (FR-007), stored with leads so
     * the team instantly understands what the person wanted.
     *
     * @param array $rows  [['role'=>..,'content'=>..], ...]
     */
    public function summarize_conversation( array $rows, string $lang ): string {
        if ( empty( $rows ) ) return '';
        $lang       = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';
        $transcript = '';
        foreach ( $rows as $r ) {
            $who = ( $r['role'] ?? '' ) === 'user' ? 'משתמש' : 'בוט';
            $t   = trim( preg_replace( '/\[(SHOW_LEAD_FORM|ACTION:[a-z_]+)\]/i', '', (string) ( $r['content'] ?? '' ) ) );
            if ( $t !== '' ) $transcript .= "$who: $t\n";
        }
        if ( trim( $transcript ) === '' ) return '';

        $lang_name = [ 'he' => 'עברית', 'en' => 'English', 'ru' => 'русском языке', 'ar' => 'اللغة العربية' ][ $lang ] ?? 'עברית';
        $system    = 'אתה מסכם שיחות שירות בקצרה ובאופן ענייני, ללא פתיח.';
        $user      = "סכם את השיחה הבאה במשפט אחד עד שניים ב$lang_name — מה הפונה רצה ובמה התעניין:\n\n$transcript";
        return trim( $this->raw_completion( $system, $user, 150 ) );
    }

    /** Minimal one-shot completion through the Wisply proxy — for small helper tasks. */
    private function raw_completion( string $system, string $user, int $max_tokens = 300 ): string {
        if ( ! $this->license_key_present() ) return '';

        // Helper tasks (page questions, lead summaries) always run on the
        // economical model — quality there doesn't justify gpt-4o pricing.
        $b = $this->proxy_request( 'chat', [
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => $max_tokens,
            'temperature' => 0.4,
            'messages'    => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user ],
            ],
        ], 25 );
        return (string) ( $b['choices'][0]['message']['content'] ?? '' );
    }

    // ─── Voice: Realtime (speech-to-speech) session + grounded lookup ──────────

    /**
     * Mint a short-lived ephemeral token for a browser WebRTC Realtime session.
     * The session is configured to answer ONLY from site content via a
     * `lookup_site_info` function tool, so grounding/RAG is preserved.
     *
     * @return array{success:bool, client_secret?:string, expires_at?:mixed, model?:string, voice?:string, error?:string}
     */
    public function create_realtime_session( string $lang ): array {
        if ( ! $this->license_key_present() ) {
            return [ 'success' => false, 'error' => 'Wisply licence key not configured' ];
        }
        $lang  = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';
        $model = (string) $this->db->get_setting( 'realtime_model', 'gpt-realtime' );
        // Old beta model names no longer work with the GA endpoint — force a GA model
        if ( $model === '' || str_contains( $model, 'preview' ) || str_contains( $model, '4o-realtime' ) ) {
            $model = 'gpt-realtime';
        }
        $voice = $this->realtime_voice();

        // GA Realtime API: ephemeral token via /v1/realtime/client_secrets, nested session config
        $session = [
            'type'         => 'realtime',
            'model'        => $model,
            'instructions' => $this->build_realtime_instructions( $lang ),
            'audio'        => [
                'input'  => [
                    'transcription'  => [ 'model' => 'whisper-1' ],
                    'turn_detection' => [
                        'type'                => 'server_vad',
                        'threshold'           => 0.5,
                        'prefix_padding_ms'   => 300,
                        'silence_duration_ms' => 600,
                    ],
                ],
                'output' => [ 'voice' => $voice ],
            ],
            'tools'        => [ $this->realtime_lookup_tool() ],
            'tool_choice'  => 'auto',
        ];

        $body  = $this->proxy_request( 'realtime', [ 'session' => $session ], 25 );
        $value = $body['value'] ?? ( $body['client_secret']['value'] ?? null );
        if ( $value ) {
            return [
                'success'       => true,
                'client_secret' => $value,
                'expires_at'    => $body['expires_at'] ?? ( $body['client_secret']['expires_at'] ?? null ),
                'model'         => $model,
                'voice'         => $voice,
            ];
        }

        $err = $body['error']['message'] ?? 'שירות הקול אינו זמין כרגע';
        return [ 'success' => false, 'error' => $err ];
    }

    /** Map the configured TTS voice to a valid Realtime voice (falls back to shimmer). */
    private function realtime_voice(): string {
        $v     = (string) $this->db->get_setting( 'tts_voice', 'shimmer' );
        $valid = [ 'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse' ];
        return in_array( $v, $valid, true ) ? $v : 'shimmer';
    }

    /** The function tool the Realtime model must call before answering factual questions. */
    private function realtime_lookup_tool(): array {
        return [
            'type'        => 'function',
            'name'        => 'lookup_site_info',
            'description' => 'חיפוש מידע רשמי בתוכן האתר: מוצרים, שירותים, מחירים, מיקום, שעות פעילות, פרטי קשר וכל מידע אחר. חובה להפעיל את הפונקציה הזו ולהמתין לתוצאה לפני כל תשובה עובדתית.',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'שאלת המשתמש או מילות המפתח לחיפוש, באותה שפה שבה נשאל.',
                    ],
                ],
                'required'   => [ 'query' ],
            ],
        ];
    }

    /** System instructions for the Realtime session — strict content grounding. */
    private function build_realtime_instructions( string $lang ): string {
        $lang_line = match ( $lang ) {
            'en' => 'Speak and reply in English.',
            'ru' => 'Говори и отвечай только по-русски.',
            'ar' => 'تحدث وأجب باللغة العربية فقط.',
            default => 'דבר וענה בעברית בלבד.',
        };
        $p          = $this->persona();
        $bot        = $p['bot'];
        $business   = $p['business'];
        $type_suffix = $p['type'] !== '' ? " — {$p['type']}" : '';
        $phone      = (string) $this->db->get_setting( 'phone', '' );
        $phone_line = $phone
            ? "אם המידע לא חזר מהפונקציה — אמור שאינך בטוח והצע להתקשר ל-$phone."
            : "אם המידע לא חזר מהפונקציה — אמור שאינך בטוח והצע לפנות לצוות $business.";

        return "אתה \"$bot\", העוזר הקולי של $business$type_suffix. $lang_line\n"
            . "כללי ברזל:\n"
            . "1. לכל שאלה עובדתית — קרא תחילה לפונקציה lookup_site_info, והמתן לתוצאה. ענה אך ורק לפי מה שהיא מחזירה.\n"
            . "2. אל תמציא שמות, מספרים, מחירים, טלפונים או תאריכים. $phone_line\n"
            . "3. דבר בחום, בקצרה ובאופן טבעי, כמו נציג אנושי שאכפת לו. אל תקריא כתובות אינטרנט בקול.\n"
            . "4. אל תיתן ייעוץ מקצועי אישי (רפואי/משפטי/פיננסי) שאינו בתוכן — הפנה לאיש מקצוע מטעם $business.\n"
            . "5. כשאתה מקריא מספר טלפון — הקרא כל ספרה בנפרד ובאיטיות (למשל \"אפס, שלוש, חמש, אפס, אפס...\"), כדי שלא ייווצרו טעויות. אל תקריא מספר טלפון כמספר שלם.\n"
            . "6. אם הפונה מגלה עניין במוצר/שירות — גלה עניין אמיתי, ענה בפירוט, והצע שישאיר פרטים או יתקשר כדי שהצוות יחזור אליו.";
    }

    /**
     * Run the same content retrieval used by the text chat and return a plain-text
     * context block — used by the Realtime tool callback to keep answers grounded.
     */
    public function retrieve_context( string $query, string $lang ): string {
        $lang     = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';
        $expanded = $this->expand_query( $query );
        $limit    = (int) $this->db->get_setting( 'max_context_docs', 5 );

        $docs = $this->db->search_content( $expanded, $lang, $limit + 3 );
        if ( empty( $docs ) && $lang === 'he' ) {
            $docs = $this->db->search_content( $expanded, 'en', 3 );
        }
        $docs = array_slice( $this->dedupe_docs( $docs ), 0, $limit );
        if ( empty( $docs ) ) {
            return '';
        }

        $out = '';
        foreach ( $docs as $i => $doc ) {
            $clean   = preg_replace( '/\s+/u', ' ', strip_tags( $doc['content'] ) );
            $snippet = $this->relevant_snippet( $clean, $query, 1500 );
            $out    .= sprintf( "[מקור %d] %s\n%s\n\n", $i + 1, $doc['title'], $snippet );
        }
        return trim( $out );
    }

    /**
     * Convert phone-number-like sequences into space-separated digits so a TTS
     * engine reads each digit individually ("0 3 5 0 0 8 8 3 3") instead of
     * misreading the number as one large quantity.
     */
    private function spell_out_phone_numbers( string $text ): string {
        return preg_replace_callback(
            '/\+?\d[\d\-\.\s\(\)]{5,}\d/u',
            function ( array $m ): string {
                $digits = preg_replace( '/\D/', '', $m[0] );
                $len    = strlen( $digits );
                // Only treat plausible phone lengths as phone numbers (7–15 digits)
                if ( $len < 7 || $len > 15 ) {
                    return $m[0];
                }
                // Comma between digits adds a tiny natural pause and forces digit-by-digit reading
                return implode( ' ', str_split( $digits ) );
            },
            $text
        );
    }

    // ─── Voice: speech-to-text (Whisper) ──────────────────────────────────────

    /**
     * Transcribe spoken audio to text using OpenAI Whisper.
     *
     * @param string $audio_binary  Raw audio bytes (webm/mp4/ogg/wav from the browser).
     * @param string $mime          The audio MIME type the browser recorded.
     * @param string $lang          Language hint (he/en/ru) — improves accuracy.
     * @return array{success:bool, text?:string, error?:string}
     */
    public function transcribe_audio( string $audio_binary, string $mime, string $lang ): array {
        if ( ! $this->license_key_present() ) {
            return [ 'success' => false, 'error' => 'Wisply licence key not configured' ];
        }
        if ( empty( $audio_binary ) ) {
            return [ 'success' => false, 'error' => 'Empty audio' ];
        }

        $lang = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';

        // The proxy rebuilds the multipart upload server-side from base64.
        $decoded = $this->proxy_request( 'transcribe', [
            'audio' => base64_encode( $audio_binary ),
            'mime'  => $mime,
            'lang'  => $lang,
            'model' => $this->db->get_setting( 'stt_model', 'whisper-1' ),
        ], 45 );

        if ( isset( $decoded['text'] ) ) {
            return [ 'success' => true, 'text' => trim( $decoded['text'] ) ];
        }

        $err = $decoded['error']['message'] ?? 'Transcription failed';
        return [ 'success' => false, 'error' => $err ];
    }

    // ─── Voice: text-to-speech (OpenAI TTS) ────────────────────────────────────

    /**
     * Synthesize natural speech from text using OpenAI TTS.
     *
     * @param string $text  The reply text to read aloud.
     * @param string $lang  Language code (TTS auto-detects; kept for parity).
     * @return array{success:bool, audio?:string, mime?:string, error?:string}  audio = base64 mp3
     */
    public function synthesize_speech( string $text, string $lang, string $voice_override = '' ): array {
        if ( ! $this->license_key_present() ) {
            return [ 'success' => false, 'error' => 'Wisply licence key not configured' ];
        }

        // Strip markdown / control markers so they aren't read aloud, and cap length
        $text = trim( preg_replace( '/\[(SHOW_LEAD_FORM|ACTION:[a-z_]+)\]/i', '', $text ) );
        $text = preg_replace( '/[*_#>`]/u', '', $text );
        // Read phone numbers digit-by-digit so the TTS doesn't mangle them
        $text = $this->spell_out_phone_numbers( $text );
        if ( $text === '' ) {
            return [ 'success' => false, 'error' => 'Empty text' ];
        }
        if ( mb_strlen( $text ) > 1200 ) {
            $text = mb_substr( $text, 0, 1200 );
        }

        $decoded = $this->proxy_request( 'speak', [
            'text'  => $text,
            'model' => $this->db->get_setting( 'tts_model', 'gpt-4o-mini-tts' ),
            'voice' => $voice_override !== '' ? $voice_override : $this->db->get_setting( 'tts_voice', 'shimmer' ),
        ], 45 );

        if ( isset( $decoded['audio'] ) && $decoded['audio'] !== '' ) {
            return [ 'success' => true, 'audio' => (string) $decoded['audio'], 'mime' => (string) ( $decoded['mime'] ?? 'audio/mpeg' ) ];
        }

        $err = $decoded['error']['message'] ?? 'Speech synthesis failed';
        return [ 'success' => false, 'error' => $err ];
    }

    // ─── Connection test ──────────────────────────────────────────────────────

    public function test_connection(): array {
        $result = $this->get_reply( 'שלום, בדיקת חיבור', 'he', [] );
        return [
            'success' => ! $result['unanswered'],
            'reply'   => $result['reply'],
        ];
    }
}
