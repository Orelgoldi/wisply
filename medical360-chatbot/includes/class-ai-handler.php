<?php
defined( 'ABSPATH' ) || exit;

class M360_AI_Handler {

    private static ?self $instance = null;
    private M360_Database $db;

    // Language codes as returned by the client
    private const SUPPORTED_LANGS = [ 'he', 'en', 'ru' ];

    private function __construct() {
        $this->db = M360_Database::get_instance();
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
     * @return array{reply:string, unanswered:bool, sources:array}
     */
    public function get_reply( string $user_message, string $lang, array $history = [] ): array {
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

        $provider = $this->db->get_setting( 'ai_provider', 'claude' );

        $result = match ( $provider ) {
            'openai' => $this->call_openai( $user_message, $lang, $history, $docs ),
            default  => $this->call_claude( $user_message, $lang, $history, $docs ),
        };

        $result['sources'] = array_map( fn( $d ) => [ 'title' => $d['title'], 'url' => $d['url'] ], $docs );
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

    // ─── Claude (Anthropic) ───────────────────────────────────────────────────

    private function call_claude( string $user_message, string $lang, array $history, array $docs ): array {
        $api_key = $this->db->get_setting( 'ai_api_key', '' );
        $model   = $this->db->get_setting( 'ai_model', 'claude-sonnet-4-6' );

        if ( empty( $api_key ) ) {
            return $this->fallback_no_config( $lang );
        }

        $system  = $this->build_system_prompt( $lang, $docs, $user_message );
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

    private function call_openai( string $user_message, string $lang, array $history, array $docs ): array {
        $api_key = $this->db->get_setting( 'openai_api_key', '' );
        $model   = $this->db->get_setting( 'openai_model', 'gpt-4o' );

        if ( empty( $api_key ) ) {
            return $this->fallback_no_config( $lang );
        }

        $system   = $this->build_system_prompt( $lang, $docs, $user_message );
        $messages = array_merge(
            [ [ 'role' => 'system', 'content' => $system ] ],
            $this->build_message_array( $history, $user_message )
        );

        $payload = [
            'model'       => $model,
            'max_tokens'  => 1024,
            'messages'    => $messages,
            'temperature' => 0.2,
        ];

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->record_ai_error( 'WP/network: ' . $response->get_error_message() );
            return [ 'reply' => $this->error_msg( $lang ), 'unanswered' => true ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['choices'][0]['message']['content'] ) ) {
            $reply = trim( $body['choices'][0]['message']['content'] );
            $this->record_ai_error( '' ); // clear any previous error on success
            return [
                'reply'      => $reply,
                'unanswered' => $this->is_unanswered( $reply, $lang ),
            ];
        }

        // Capture the real OpenAI error (e.g. invalid key / insufficient_quota / model access)
        $err = $body['error']['message']
            ?? ( 'HTTP ' . $code . ' — ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 300 ) );
        $this->record_ai_error( $err );
        return [ 'reply' => $this->error_msg( $lang ), 'unanswered' => true ];
    }

    /** Store the last AI error so the admin System Check can show what actually failed. */
    private function record_ai_error( string $msg ): void {
        update_option( 'm360_last_ai_error', $msg, false );
        if ( $msg !== '' ) {
            error_log( '[Medical360] AI error: ' . $msg );
        }
    }

    // ─── Prompt construction ──────────────────────────────────────────────────

    private function build_system_prompt( string $lang, array $docs, string $query = '' ): string {
        $context_block = '';
        if ( ! empty( $docs ) ) {
            $context_block = "\n\n=== תוכן רלוונטי מהאתר ===\n";
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

        $lang_instruction = match ( $lang ) {
            'en' => 'Always reply in English only, regardless of the question language.',
            'ru' => 'Всегда отвечай только на русском языке.',
            default => 'ענה בעברית בלבד.',
        };

        $phone = $this->db->get_setting( 'phone', '' );
        $phone_line = $phone
            ? "אם אינך יודע — הצע למשתמש להתקשר למספר $phone."
            : "אם אינך יודע — אמור זאת בכנות והצע לפנות לצוות בטלפון.";

        // Emergency escalation (FR-017)
        $emergency_msg = (string) $this->db->get_setting( 'emergency_msg_' . $lang, '' );
        $emergency_block = $emergency_msg
            ? "\n\nמצב חירום: אם הפונה מתאר מצוקה רפואית/נפשית דחופה (כאב חזק, קוצר נשימה, מחשבות אובדניות, פגיעה עצמית, מצב מסכן חיים) — עצור מיד, אל תיתן מידע אחר, השב אך ורק: \"$emergency_msg\", והוסף בסוף את הסמן [EMERGENCY] (שמציג כפתורי חיוג 101 וקווי סיוע נפשי)."
            : '';

        // Available quick-action buttons (donate / jobs / podcast / ...)
        $action_links = json_decode( (string) $this->db->get_setting( 'action_links', '' ), true );
        $action_block = '';
        if ( is_array( $action_links ) && ! empty( $action_links ) ) {
            $labels = [
                'donate' => 'לתרומה', 'jobs' => 'דרושים/קריירה',
                'podcast' => 'האזנה לפודקאסט', 'volunteer' => 'התנדבות', 'contact' => 'יצירת קשר',
            ];
            $avail = [];
            foreach ( $action_links as $k => $url ) {
                $avail[] = $k . ' (' . ( $labels[ $k ] ?? $k ) . ')';
            }
            $action_block = "\n\nכפתורי פעולה זמינים: " . implode( ', ', $avail ) . ".\n"
                . "אם המשתמש מביע רצון לבצע אחת מהפעולות האלה (למשל \"אני רוצה לתרום\", \"יש דרושים?\", \"איפה הפודקאסט\") — "
                . "הוסף בסוף התשובה את הסמן [ACTION:key] עם המפתח המתאים (לדוגמה [ACTION:donate]). הוסף סמן אחד בלבד, ורק כשזה באמת רלוונטי.";
        }

        // Job-seeker flow (FR): a *specific* role interest → jobs page + lead form together
        $jobs_block = '';
        if ( is_array( $action_links ) && ! empty( $action_links['jobs'] ) ) {
            $jobs_block = "\n\nטיפול בפניות דרושים/קריירה (תקף בכל שפה — עברית/אנגלית/רוסית):\n"
                . "• אם הפונה מחפש עבודה ומציין תפקיד / תחום / משרה ספציפיים שמעניינים אותו "
                . "(למשל \"אני פיזיותרפיסט ומחפש עבודה\", \"יש משרה לאחות?\", \"I'm a nurse looking for a job\", \"ищу работу медсестрой\") — "
                . "בצע את שני הדברים **באותה תשובה**: (א) הוסף [ACTION:jobs] כדי להפנות אותו לדף הדרושים; "
                . "(ב) הצע לו בחום להשאיר פרטים כדי שצוות הגיוס יחזור אליו לגבי אותה משרה, והוסף בסוף [ASK_LEAD].\n"
                . "• אם הפונה רק שואל בכלליות \"יש דרושים?\" בלי לציין תפקיד — הוסף [ACTION:jobs] ושאל אותו איזה תחום/תפקיד מעניין אותו (עדיין בלי טופס).\n"
                . "• במקרה הזה מותר לשלב [ACTION:jobs] יחד עם [ASK_LEAD] באותה תשובה (זהו החריג לכלל \"סמן אחד בלבד\").";
        }

        return <<<PROMPT
אתה "מדיקלי", העוזר החכם של מדיקל קר — מרכז שיקומי (medical360.org).
ענה לגולשים על שאלות בנושא: מחלקות שיקום, צוות ורופאים, תהליך קבלה, מיקום, סדנאות, פודקאסט ושירותים.

כללי מענה:
1. ענה לפי "תוכן רלוונטי מהאתר" שמופיע למטה. קרא את כל המקורות בעיון לפני שתאמר שאינך יודע.
2. היה עוזר ויוזם — אם בתוכן יש מידע קרוב או מקביל לשאלה, הצג אותו במקום לסרב. מונחים שונים מתארים לעיתים אותו דבר:
   "מחלקה" ≈ "אגף" ≈ "יחידה" ≈ "מערך"; "שיקום כללי" ≈ "אגף שיקום" ≈ "שיקום".
   דוגמה: אם נשאלת "מי מנהל מחלקת שיקום כללי?" ובתוכן כתוב "ד״ר תמרה בראונר מונתה למנהלת אגף שיקום" — ענה: "מנהלת אגף השיקום היא ד״ר תמרה בראונר", ואם רלוונטי ציין גם מנהלי מחלקות סמוכות (גריאטרי וכו').
3. ענה ישירות, בחום ובקצרה (עד 120 מילים). אם יש מספר אנשים/מחלקות רלוונטיים — פרט אותם.
   כשנשאלת "אילו מחלקות/שירותים יש?" — תן **רשימה מפורטת** של כל הפריטים שמופיעים בתוכן (במיוחד ברשימת התפריט/המחלקות), כל אחד בשורה. אל תסכם בכלליות "יש מספר מחלקות".
4. רק אם באמת אין שום מידע קרוב בתוכן — $phone_line אל תמציא שמות, מספרים או תאריכים שלא מופיעים בתוכן.
5. אל תיתן ייעוץ רפואי אישי — אינך תחליף לרופא.
6. אל תציג קישורים, כתובות URL, או רשימת "מקורות" בתשובה. ענה בשפה טבעית, כאילו אתה נציג שירות אנושי.
7. היה חם ומתעניין, כמו נציג מכירות מקצועי שאכפת לו. גלה עניין אמיתי במצב של הפונה.
8. $lang_instruction

תהליך מודרג להשארת פרטים — **אל תקפיץ טופס ישר!** עבוד בשלבים:

שלב 1 — בירור עם כפתורי בחירה: כשהפונה מתעניין בקטגוריה כללית (סדנאות / טיפולים / שירותים / מחלקות) בלי לציין פריט מסוים — אל תציג טופס. במקום זאת שאל בקצרה ובחום באיזה פריט ספציפי הוא מתעניין, והצג את האפשרויות האמיתיות מהתוכן בעזרת הסמן:
[OPTIONS: פריט 1 | פריט 2 | פריט 3]
(עד 5 אפשרויות אמיתיות בלבד מהתוכן, מופרדות בתו |). דוגמה: "יש לנו כמה סדנאות — באיזו אתה מתעניין?\n[OPTIONS: סדנת חוסן | סדנת הורים | סדנה לחיילים]".

שלב 2 — שאלת כן/לא: אחרי שהפונה בחר פריט ספציפי (או ציין אותו מראש) — ענה בקצרה ובחום על אותו פריט, ואז שאל אם הוא מעוניין שנשאיר פרטים כדי לקבל מידע נוסף/שיחזרו אליו, והוסף בסוף את הסמן [ASK_LEAD] (שמציג כפתורי כן/לא). **אל** תוסיף [SHOW_LEAD_FORM] בשלב הזה.

שלב 3 — טופס: רק כשהפונה אומר במפורש "כן" / "אני רוצה להשאיר פרטים" / "שיחזרו אליי" / "תאמו לי" — הוסף [SHOW_LEAD_FORM].

כללים:
- השתמש ב-[OPTIONS] רק כשבאמת יש כמה אפשרויות בתוכן. אם הפונה כבר ציין פריט ספציפי — דלג לשלב 2.
- **אל** תציג [ASK_LEAD] או טופס בשאלות עובדתיות-נקודתיות שכבר ענית עליהן (כתובת, טלפון, שעות, "מי הרופא") — אלא אם הפונה ממשיך ומגלה עניין להתקדם.
- אל תוסיף שום סמן רק בגלל שאינך יודע תשובה — במקרה כזה הצע להתקשר.
- הוסף לכל היותר סמן אחד מסוג [OPTIONS]/[ASK_LEAD]/[SHOW_LEAD_FORM] בכל תשובה.
$emergency_block
$action_block
$jobs_block
$context_block
PROMPT;
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
            default => 'מצטערים, אירעה שגיאה. נסה שוב או צור קשר טלפוני.',
        };
    }

    private function fallback_no_config( string $lang ): array {
        $msg = match ( $lang ) {
            'en'    => 'The chatbot is not configured yet. Please contact the site administrator.',
            'ru'    => 'Чат-бот ещё не настроен. Обратитесь к администратору сайта.',
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

        $lang_name = [ 'he' => 'עברית', 'en' => 'English', 'ru' => 'русском языке' ][ $lang ] ?? 'עברית';
        $system    = 'אתה יוצר שאלות נפוצות קצרות. החזר אך ורק מערך JSON של 4 מחרוזות (שאלות), ללא שום טקסט נוסף.';
        $user      = "להלן תוכן מתוך עמוד באתר מדיקל קר בשם \"$title\". "
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

        $lang_name = [ 'he' => 'עברית', 'en' => 'English', 'ru' => 'русском языке' ][ $lang ] ?? 'עברית';
        $system    = 'אתה מסכם שיחות שירות בקצרה ובאופן ענייני, ללא פתיח.';
        $user      = "סכם את השיחה הבאה במשפט אחד עד שניים ב$lang_name — מה הפונה רצה ובמה התעניין:\n\n$transcript";
        return trim( $this->raw_completion( $system, $user, 150 ) );
    }

    /** Minimal one-shot completion using the configured provider — for small helper tasks. */
    private function raw_completion( string $system, string $user, int $max_tokens = 300 ): string {
        $provider = $this->db->get_setting( 'ai_provider', 'openai' );

        if ( $provider === 'openai' ) {
            $api_key = $this->db->get_setting( 'openai_api_key', '' );
            if ( empty( $api_key ) ) return '';
            $model = $this->db->get_setting( 'openai_model', 'gpt-4o' );
            $resp  = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'timeout' => 20,
                'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'model'       => $model,
                    'max_tokens'  => $max_tokens,
                    'temperature' => 0.4,
                    'messages'    => [
                        [ 'role' => 'system', 'content' => $system ],
                        [ 'role' => 'user',   'content' => $user ],
                    ],
                ] ),
            ] );
            if ( is_wp_error( $resp ) ) return '';
            $b = json_decode( wp_remote_retrieve_body( $resp ), true );
            return (string) ( $b['choices'][0]['message']['content'] ?? '' );
        }

        $api_key = $this->db->get_setting( 'ai_api_key', '' );
        if ( empty( $api_key ) ) return '';
        $model = $this->db->get_setting( 'ai_model', 'claude-sonnet-4-6' );
        $resp  = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 20,
            'headers' => [ 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
            ] ),
        ] );
        if ( is_wp_error( $resp ) ) return '';
        $b = json_decode( wp_remote_retrieve_body( $resp ), true );
        return (string) ( $b['content'][0]['text'] ?? '' );
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
        $api_key = $this->db->get_setting( 'openai_api_key', '' );
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'OpenAI API key not configured' ];
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

        $response = wp_remote_post( 'https://api.openai.com/v1/realtime/client_secrets', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'session' => $session ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
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

        $err = $body['error']['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
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
            'description' => 'חיפוש מידע רשמי באתר medical360.org: מחלקות שיקום, רופאים, צוות, תהליך קבלה, מיקום, שעות ביקור, סדנאות, שירותים, פרטי קשר ותרומות. חובה להפעיל את הפונקציה הזו ולהמתין לתוצאה לפני כל תשובה עובדתית.',
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
            default => 'דבר וענה בעברית בלבד.',
        };
        $phone      = (string) $this->db->get_setting( 'phone', '' );
        $phone_line = $phone
            ? "אם המידע לא חזר מהפונקציה — אמור שאינך בטוח והצע להתקשר ל-$phone."
            : "אם המידע לא חזר מהפונקציה — אמור שאינך בטוח והצע לפנות לצוות בטלפון.";

        return "אתה \"מדיקלי\", העוזר הקולי של מדיקל קר — מרכז שיקומי (medical360.org). $lang_line\n"
            . "כללי ברזל:\n"
            . "1. לכל שאלה עובדתית (מחלקות, רופאים, צוות, קבלה, מיקום, שעות, סדנאות, שירותים, תרומות) — קרא תחילה לפונקציה lookup_site_info, והמתן לתוצאה. ענה אך ורק לפי מה שהיא מחזירה.\n"
            . "2. אל תמציא שמות, מספרים, טלפונים או תאריכים. $phone_line\n"
            . "3. דבר בחום, בקצרה ובאופן טבעי, כמו נציג אנושי שאכפת לו. אל תקריא כתובות אינטרנט בקול.\n"
            . "4. אל תיתן ייעוץ רפואי אישי — אינך תחליף לרופא.\n"
            . "5. כשאתה מקריא מספר טלפון — הקרא כל ספרה בנפרד ובאיטיות (למשל \"אפס, שלוש, חמש, אפס, אפס...\"), כדי שלא ייווצרו טעויות. אל תקריא מספר טלפון כמספר שלם.\n"
            . "6. אם הפונה מגלה עניין בסדנה/טיפול/שירות — גלה עניין אמיתי, ענה בפירוט, והצע שישאיר פרטים או יתקשר כדי שהצוות יחזור אליו.";
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
        $api_key = $this->db->get_setting( 'openai_api_key', '' );
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'OpenAI API key not configured' ];
        }
        if ( empty( $audio_binary ) ) {
            return [ 'success' => false, 'error' => 'Empty audio' ];
        }

        $model = $this->db->get_setting( 'stt_model', 'whisper-1' );
        $lang  = in_array( $lang, self::SUPPORTED_LANGS, true ) ? $lang : 'he';

        // Map the browser's MIME to a filename extension Whisper accepts
        $ext = match ( true ) {
            str_contains( $mime, 'webm' ) => 'webm',
            str_contains( $mime, 'mp4' ), str_contains( $mime, 'm4a' ), str_contains( $mime, 'aac' ) => 'm4a',
            str_contains( $mime, 'ogg' ), str_contains( $mime, 'opus' ) => 'ogg',
            str_contains( $mime, 'wav' ) => 'wav',
            str_contains( $mime, 'mpeg' ), str_contains( $mime, 'mp3' ) => 'mp3',
            default => 'webm',
        };

        // Build a multipart/form-data body by hand (wp_remote_post has no file helper)
        $boundary = 'm360' . bin2hex( random_bytes( 12 ) );
        $eol      = "\r\n";
        $body     = '';

        $body .= "--$boundary$eol";
        $body .= "Content-Disposition: form-data; name=\"model\"$eol$eol$model$eol";

        $body .= "--$boundary$eol";
        $body .= "Content-Disposition: form-data; name=\"language\"$eol$eol$lang$eol";

        $body .= "--$boundary$eol";
        $body .= "Content-Disposition: form-data; name=\"response_format\"{$eol}{$eol}json{$eol}";

        $body .= "--$boundary$eol";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"speech.$ext\"$eol";
        $body .= "Content-Type: " . ( $mime ?: 'application/octet-stream' ) . "$eol$eol";
        $body .= $audio_binary . $eol;

        $body .= "--$boundary--$eol";

        $response = wp_remote_post( 'https://api.openai.com/v1/audio/transcriptions', [
            'timeout' => 40,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
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
        $api_key = $this->db->get_setting( 'openai_api_key', '' );
        if ( empty( $api_key ) ) {
            return [ 'success' => false, 'error' => 'OpenAI API key not configured' ];
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

        $model = $this->db->get_setting( 'tts_model', 'gpt-4o-mini-tts' );
        $voice = $voice_override !== '' ? $voice_override : $this->db->get_setting( 'tts_voice', 'shimmer' );

        $response = wp_remote_post( 'https://api.openai.com/v1/audio/speech', [
            'timeout' => 40,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'           => $model,
                'voice'           => $voice,
                'input'           => $text,
                'response_format' => 'mp3',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );

        // On success OpenAI returns binary audio; on error it returns JSON
        if ( $code === 200 && $raw !== '' && $raw[0] !== '{' ) {
            return [ 'success' => true, 'audio' => base64_encode( $raw ), 'mime' => 'audio/mpeg' ];
        }

        $decoded = json_decode( $raw, true );
        $err     = $decoded['error']['message'] ?? 'Speech synthesis failed';
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
