<?php
defined( 'ABSPATH' ) || exit;

class Wisply_Database {

    private static ?self $instance = null;
    private wpdb $db;

    private const TABLE_CONVERSATIONS = 'wisply_conversations';
    private const TABLE_MESSAGES      = 'wisply_messages';
    private const TABLE_CONTENT_INDEX = 'wisply_content_index';
    private const TABLE_SETTINGS      = 'wisply_settings';

    private function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public static function activate(): void {
        self::get_instance()->create_tables();
        self::get_instance()->seed_default_settings();
        self::get_instance()->autofill_branding_from_site();
        self::get_instance()->schedule_cleanup();
        self::get_instance()->schedule_reindex();
        // Flush rewrite rules so REST routes register immediately
        flush_rewrite_rules();
    }

    /**
     * White-label onboarding: pre-fill bot/business name from the WordPress site
     * title so the assistant is personalised out-of-the-box. Never overwrites a choice.
     */
    public function autofill_branding_from_site(): void {
        $site_name = trim( (string) get_bloginfo( 'name' ) );
        if ( $site_name === '' ) return;
        if ( (string) $this->get_setting( 'business_name', '' ) === '' ) {
            $this->set_setting( 'business_name', $site_name );
        }
        if ( (string) $this->get_setting( 'bot_name', '' ) === '' ) {
            $this->set_setting( 'bot_name', $site_name );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'wisply_cleanup_old_conversations' );
        wp_clear_scheduled_hook( 'wisply_reindex_content' );
        wp_clear_scheduled_hook( 'wisply_daily_leads_report' );
        wp_clear_scheduled_hook( 'wisply_weekly_leads_report' );
    }

    // ─── Table creation ───────────────────────────────────────────────────────

    /**
     * Create all tables with direct queries (more reliable than dbDelta for
     * the content index, whose FULLTEXT key caused silent dbDelta failures).
     * No FULLTEXT key — MySQL fulltext does not tokenise Hebrew; search uses LIKE.
     *
     * @return array List of tables that failed to create (empty = success).
     */
    public function create_tables(): array {
        $charset = $this->db->get_charset_collate();
        $conv    = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $msg     = $this->db->prefix . self::TABLE_MESSAGES;
        $idx     = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        $set     = $this->db->prefix . self::TABLE_SETTINGS;
        $errors  = [];

        $tables = [
            $conv => "CREATE TABLE IF NOT EXISTS $conv (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id   VARCHAR(64)     NOT NULL,
                lang         VARCHAR(10)     NOT NULL DEFAULT 'he',
                ip_hash      VARCHAR(64)     DEFAULT NULL,
                page_url     TEXT            DEFAULT NULL,
                started_at   DATETIME        NOT NULL,
                last_at      DATETIME        NOT NULL,
                resolved     TINYINT(1)      NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY session_id (session_id),
                KEY started_at (started_at)
            ) $charset",

            $msg => "CREATE TABLE IF NOT EXISTS $msg (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                role            VARCHAR(16)     NOT NULL,
                content         TEXT            NOT NULL,
                unanswered      TINYINT(1)      NOT NULL DEFAULT 0,
                created_at      DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY conversation_id (conversation_id),
                KEY unanswered (unanswered)
            ) $charset",

            $idx => "CREATE TABLE IF NOT EXISTS $idx (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id      BIGINT UNSIGNED NOT NULL,
                post_type    VARCHAR(50)     NOT NULL,
                lang         VARCHAR(10)     NOT NULL DEFAULT 'he',
                title        TEXT            NOT NULL,
                content      LONGTEXT        NOT NULL,
                url          TEXT            NOT NULL,
                indexed_at   DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY post_lang (post_id, lang),
                KEY post_type (post_type)
            ) $charset",

            $set => "CREATE TABLE IF NOT EXISTS $set (
                option_name  VARCHAR(191)    NOT NULL,
                option_value LONGTEXT        DEFAULT NULL,
                PRIMARY KEY (option_name)
            ) $charset",
        ];

        foreach ( $tables as $name => $sql ) {
            $this->db->query( $sql );
            // Verify the table actually exists now
            $exists = $this->db->get_var(
                $this->db->prepare( 'SHOW TABLES LIKE %s', $name )
            );
            if ( $exists !== $name ) {
                $errors[] = $name . ( $this->db->last_error ? ' — ' . $this->db->last_error : '' );
            }
        }

        return $errors;
    }

    // ─── Default settings ─────────────────────────────────────────────────────

    public function seed_default_settings(): void {
        $defaults = [
            // ── White-label persona (settings-driven) ──
            'product_name'         => 'Wisply',   // shown in the "Powered by" footer line
            'powered_by_enabled'   => '1',
            'bot_name'             => '',          // auto-filled from the site name on activation
            'business_name'        => '',          // auto-filled from the site name on activation
            'business_type'        => '',          // e.g. "online store", "law firm", "clinic"
            'business_description'  => '',          // free-text persona — injected into the AI prompt
            'action_buttons'       => '',          // JSON array of custom CTA buttons
            'suggested_questions_he' => "מה אתם מציעים?\nאיך יוצרים איתכם קשר?\nאיפה אתם ממוקמים?\nמהן שעות הפעילות?",
            'suggested_questions_en' => "What do you offer?\nHow can I contact you?\nWhere are you located?\nWhat are your opening hours?",
            'suggested_questions_ru' => "Что вы предлагаете?\nКак с вами связаться?\nГде вы находитесь?\nКакие у вас часы работы?",

            'ai_provider'       => 'openai',
            'ai_api_key'        => '',
            'ai_model'          => 'claude-sonnet-4-6',
            'openai_api_key'    => '',
            'openai_model'      => 'gpt-4o',
            'primary_color'     => '#00A3A3',
            'secondary_color'   => '#007878',
            'font_family'       => 'Open Sans Hebrew, sans-serif',
            'bubble_position'   => 'bottom-right',
            'greeting_he'       => 'שלום 👋 אני העוזר החכם של האתר. אשמח לעזור בכל שאלה.',
            'greeting_en'       => 'Hi 👋 I\'m the site\'s smart assistant. How can I help you today?',
            'greeting_ru'       => 'Здравствуйте 👋 Я умный помощник сайта. Чем могу помочь?',
            'phone'             => '',
            'map_url'           => '',
            'widget_title_he'   => 'עוזר חכם',
            'widget_title_en'   => 'Smart Assistant',
            'widget_title_ru'   => 'Умный помощник',
            'max_context_docs'  => 5,
            'conversation_ttl_days' => 60,
            'reindex_interval'  => 'daily',
            // Voice (ChatGPT-style): OpenAI Whisper for speech-to-text + OpenAI TTS for natural speech
            'voice_enabled'     => '1',
            'voice_provider'    => 'openai',          // 'openai' = Whisper + TTS, 'browser' = free Web Speech API
            'tts_model'         => 'gpt-4o-mini-tts',  // natural-sounding, low cost
            'tts_voice'         => 'shimmer',          // warm female voice — fits a care setting
            'stt_model'         => 'whisper-1',        // excellent Hebrew transcription
            // Real-time voice (ChatGPT-style speech-to-speech via the Realtime API)
            'realtime_enabled'  => '1',                // try low-latency speech-to-speech first
            'realtime_model'    => 'gpt-realtime',     // GA Realtime model
            // How spoken text appears in the chat: none | end (write transcript on hang-up) | live
            'voice_text_mode'   => 'end',
            // Proactive page-aware teaser bubble
            'proactive_enabled' => '1',
            'proactive_delay'   => '5',  // seconds before the bubble appears
            'proactive_msg_he'  => 'היי 👋 אשמח לתת לך עוד פרטים על {subject}. יש לך שאלה?',
            'proactive_msg_en'  => 'Hi 👋 Happy to tell you more about {subject}. Any questions?',
            'proactive_msg_ru'  => 'Здравствуйте 👋 Расскажу подробнее о {subject}. Есть вопросы?',
            // Desktop auto-open — open the full chat window automatically on desktop (call-to-action)
            'desktop_autoopen_enabled' => '0',
            'desktop_autoopen_delay'   => '3',   // seconds before the window opens
            'desktop_autoopen_msg_he'  => 'היי 👋 בואו לדבר עם העוזר החכם שלנו — אני כאן לכל שאלה!',
            'desktop_autoopen_msg_en'  => 'Hi 👋 Come chat with our smart assistant — I\'m here for any question!',
            'desktop_autoopen_msg_ru'  => 'Здравствуйте 👋 Поговорите с нашим умным ассистентом — я здесь для любого вопроса!',
            // Marketing consent (Opt-In) — legal requirement before saving a marketing lead
            'consent_required'  => '1',
            'consent_version'   => '1.0',
            'consent_text_he'   => 'אני מסכים/ה לקבל פניות שיווקיות (כולל בטלפון, SMS ודוא״ל). ניתן לבטל את ההסכמה בכל עת. [יש להתאים את הנוסח לפרטי העסק והחוק].',
            'consent_text_en'   => 'I agree to receive marketing communications (including by phone, SMS and email). Consent can be withdrawn at any time. [Edit to match your business and local law].',
            'consent_text_ru'   => 'Я согласен(на) получать маркетинговые сообщения (по телефону, SMS и эл. почте). Согласие можно отозвать в любое время. [Измените под ваш бизнес и закон].',
            // Emergency escalation (FR-017)
            'emergency_msg_he'  => '🚨 במקרה חירום רפואי חייגו 101 או פנו למיון הקרוב. אני לא מיועד למצבי חירום ולא מהווה תחליף לייעוץ רפואי.',
            'emergency_msg_en'  => '🚨 In a medical emergency call 101 or go to the nearest ER. I am not designed for emergencies and am not a substitute for medical advice.',
            'emergency_msg_ru'  => '🚨 В случае неотложной медицинской ситуации звоните 101 или обратитесь в ближайшее приёмное отделение. Я не предназначен для экстренных случаев.',
            'emergency_phone'     => '101',
            'emergency_eran_url'  => 'https://www.eran.org.il/',
            'emergency_sahar_url' => 'https://sahar.org.il/',
            // Automated leads reports (daily / weekly digest by email)
            'report_recipients'  => '',   // comma / newline separated emails
            'report_daily'       => '1',
            'report_weekly'      => '1',
            // E-commerce module (WooCommerce) — live product data via WooCommerce's PHP API
            'woo_enabled'        => '0',
            'woo_max_products'   => '4',
            'woo_show_stock'     => '1',
            'woo_visual_search'  => '0',
        ];
        foreach ( $defaults as $key => $value ) {
            $this->set_setting( $key, $value, false );
        }
    }

    // ─── Scheduling ───────────────────────────────────────────────────────────

    private function schedule_cleanup(): void {
        if ( ! wp_next_scheduled( 'wisply_cleanup_old_conversations' ) ) {
            wp_schedule_event( time(), 'daily', 'wisply_cleanup_old_conversations' );
        }
        add_action( 'wisply_cleanup_old_conversations', [ $this, 'cleanup_old_conversations' ] );
    }

    private function schedule_reindex(): void {
        if ( ! wp_next_scheduled( 'wisply_reindex_content' ) ) {
            wp_schedule_event( time(), 'daily', 'wisply_reindex_content' );
        }
        add_action( 'wisply_reindex_content', function () {
            Wisply_Content_Indexer::get_instance()->full_reindex();
        } );
    }

    /**
     * Register the scheduled leads-report cron (daily + weekly). Called on every
     * load so the action callbacks exist when WP-Cron fires.
     */
    public function register_reports(): void {
        add_filter( 'cron_schedules', function ( $s ) {
            if ( ! isset( $s['weekly'] ) ) {
                $s['weekly'] = [ 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' ];
            }
            return $s;
        } );
        if ( ! wp_next_scheduled( 'wisply_daily_leads_report' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 8:00' ), 'daily', 'wisply_daily_leads_report' );
        }
        if ( ! wp_next_scheduled( 'wisply_weekly_leads_report' ) ) {
            wp_schedule_event( strtotime( 'next monday 8:00' ), 'weekly', 'wisply_weekly_leads_report' );
        }
        add_action( 'wisply_daily_leads_report',  fn() => $this->send_leads_report( 'day' ) );
        add_action( 'wisply_weekly_leads_report', fn() => $this->send_leads_report( 'week' ) );
    }

    /** Build + email a leads digest for the period ('day' | 'week') to the configured recipients. */
    public function send_leads_report( string $period ): void {
        $enabled = $this->get_setting( $period === 'day' ? 'report_daily' : 'report_weekly', '1' );
        if ( $enabled !== '1' ) return;

        $to = array_values( array_filter(
            array_map( 'trim', preg_split( '/[,;\s]+/', (string) $this->get_setting( 'report_recipients', '' ) ) ),
            'is_email'
        ) );
        if ( empty( $to ) ) return;

        $since  = $period === 'day' ? strtotime( '-1 day' ) : strtotime( '-7 days' );
        $leads  = array_reverse( (array) get_option( 'wisply_leads', [] ) );
        $recent = array_values( array_filter( $leads, fn( $l ) => strtotime( (string) ( $l['time'] ?? '' ) ) >= $since ) );

        $jobs = array_filter( $recent, fn( $l ) => ( $l['lead_type'] ?? 'marketing' ) === 'job' );
        $mkt  = array_filter( $recent, fn( $l ) => ( $l['lead_type'] ?? 'marketing' ) !== 'job' );
        $period_he = $period === 'day' ? 'יומי' : 'שבועי';

        $section = function ( string $title, array $set ): string {
            $rows = '';
            if ( empty( $set ) ) {
                $rows = '<tr><td colspan="5" style="color:#999;padding:8px">— אין —</td></tr>';
            } else {
                foreach ( $set as $l ) {
                    $rows .= '<tr>'
                        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $l['time'] ?? '' ) . '</td>'
                        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $l['name'] ?? '' ) . '</td>'
                        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $l['phone'] ?? '' ) . '</td>'
                        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $l['interest'] ?? ( $l['department'] ?? '' ) ) . '</td>'
                        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $l['summary'] ?? '' ) . '</td>'
                        . '</tr>';
                }
            }
            return '<h3 style="color:#007878;margin:18px 0 6px">' . $title . ' (' . count( $set ) . ')</h3>'
                . '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#f0fafa">'
                . '<th style="padding:6px 10px;text-align:right">זמן</th><th style="padding:6px 10px;text-align:right">שם</th>'
                . '<th style="padding:6px 10px;text-align:right">טלפון</th><th style="padding:6px 10px;text-align:right">התעניינות</th>'
                . '<th style="padding:6px 10px;text-align:right">סיכום</th></tr>' . $rows . '</table>';
        };

        $subject = sprintf( 'דוח לידים %s — %d לידים חדשים', $period_he, count( $recent ) );
        $body = '<div dir="rtl" style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6">'
            . '<h2 style="color:#00A3A3">דוח לידים ' . $period_he . '</h2>'
            . '<p>סה״כ <strong>' . count( $recent ) . '</strong> לידים חדשים · 🎯 שיווקי: ' . count( $mkt ) . ' · 💼 דרושים: ' . count( $jobs ) . '</p>'
            . $section( '🎯 פניות שיווקיות', array_values( $mkt ) )
            . $section( '💼 מחפשי עבודה', array_values( $jobs ) )
            . '<hr><p style="color:#888;font-size:12px">דוח אוטומטי · ' . esc_html( current_time( 'mysql' ) ) . '</p></div>';

        // CSV attachment for the period
        $lbl = [ 'marketing' => 'שיווקי', 'job' => 'דרושים' ];
        $csv = "שם,טלפון,אימייל,סוג,התעניינות,מחלקה,מקור,קמפיין,הסכמה,תאריך\n";
        foreach ( $recent as $l ) {
            $csv .= '"' . implode( '","', array_map( fn( $v ) => str_replace( '"', '""', (string) $v ), [
                $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '',
                $lbl[ $l['lead_type'] ?? 'marketing' ] ?? 'שיווקי',
                $l['interest'] ?? '', $l['department'] ?? '', $l['source'] ?? '', $l['campaign'] ?? '',
                ! empty( $l['marketing_consent'] ) ? 'כן' : 'לא', $l['time'] ?? '',
            ] ) ) . "\"\n";
        }
        $attachments = [];
        $up = wp_upload_dir();
        $file = trailingslashit( $up['basedir'] ) . 'wisply-leads-' . $period . '-' . date( 'Ymd-His' ) . '.csv';
        if ( wp_mkdir_p( $up['basedir'] ) && false !== file_put_contents( $file, "\xEF\xBB\xBF" . $csv ) ) {
            $attachments[] = $file;
        }

        wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ], $attachments );
        if ( ! empty( $attachments ) ) { @unlink( $file ); }
    }

    public function cleanup_old_conversations(): void {
        $days = (int) $this->get_setting( 'conversation_ttl_days', 60 );
        $conv = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $msg  = $this->db->prefix . self::TABLE_MESSAGES;

        $old_ids = $this->db->get_col(
            $this->db->prepare(
                "SELECT id FROM $conv WHERE started_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        if ( empty( $old_ids ) ) return;

        $placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
        $this->db->query( $this->db->prepare( "DELETE FROM $msg WHERE conversation_id IN ($placeholders)", ...$old_ids ) );
        $this->db->query( $this->db->prepare( "DELETE FROM $conv WHERE id IN ($placeholders)", ...$old_ids ) );
    }

    // ─── Settings ─────────────────────────────────────────────────────────────

    /** Fields that contain secrets and must be stored encrypted. */
    private const ENCRYPTED_KEYS = [ 'ai_api_key', 'openai_api_key' ];

    public function get_setting( string $key, mixed $default = null ): mixed {
        $table = $this->db->prefix . self::TABLE_SETTINGS;
        $val   = $this->db->get_var(
            $this->db->prepare( "SELECT option_value FROM $table WHERE option_name = %s", $key )
        );
        if ( $val === null ) return $default;
        return in_array( $key, self::ENCRYPTED_KEYS, true ) ? $this->decrypt( $val ) : $val;
    }

    public function set_setting( string $key, mixed $value, bool $update = true ): void {
        $table   = $this->db->prefix . self::TABLE_SETTINGS;
        $stored  = in_array( $key, self::ENCRYPTED_KEYS, true )
            ? $this->encrypt( (string) $value )
            : (string) $value;

        // Explicit UPDATE-then-INSERT rather than REPLACE: this persists correctly
        // even if a legacy install created the settings table without a UNIQUE key
        // on option_name (where REPLACE silently fails to upsert). Updating ALL rows
        // for the key also collapses any duplicate rows left by that legacy bug.
        $exists = (int) $this->db->get_var(
            $this->db->prepare( "SELECT COUNT(*) FROM $table WHERE option_name = %s", $key )
        );

        if ( $exists > 0 ) {
            if ( $update ) {
                $this->db->query(
                    $this->db->prepare(
                        "UPDATE $table SET option_value = %s WHERE option_name = %s",
                        $stored, $key
                    )
                );
            }
            // when seeding (!$update) and the key already exists, leave the saved value as-is
        } else {
            $this->db->insert( $table, [ 'option_name' => $key, 'option_value' => $stored ] );
        }
    }

    /**
     * Symmetric encryption using WordPress AUTH_KEY as the secret.
     * XChaCha20-Poly1305 via sodium (available in PHP 7.2+ with libsodium).
     * Falls back to base64 obfuscation if sodium is unavailable.
     */
    private function encrypt( string $plaintext ): string {
        if ( empty( $plaintext ) ) return '';
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $key   = $this->derive_key();
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
            return 'sod1:' . base64_encode( $nonce . $cipher );
        }
        // Fallback: at minimum prevent plaintext storage in DB dumps
        return 'b64:' . base64_encode( $plaintext );
    }

    private function decrypt( string $stored ): string {
        if ( empty( $stored ) ) return '';
        if ( str_starts_with( $stored, 'sod1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $raw   = base64_decode( substr( $stored, 5 ) );
            $nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher= substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->derive_key() );
            return $plain !== false ? $plain : '';
        }
        if ( str_starts_with( $stored, 'b64:' ) ) {
            return base64_decode( substr( $stored, 4 ) ) ?: '';
        }
        return $stored; // legacy plaintext — returned as-is until re-saved
    }

    private function derive_key(): string {
        // Stretch WordPress AUTH_KEY into a 32-byte sodium key
        $secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wisply-fallback-secret';
        return substr( hash( 'sha256', 'wisply:' . $secret, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    public function get_all_settings(): array {
        $table = $this->db->prefix . self::TABLE_SETTINGS;
        $rows  = $this->db->get_results( "SELECT option_name, option_value FROM $table", ARRAY_A );
        $out   = [];
        foreach ( (array) $rows as $row ) {
            $out[ $row['option_name'] ] = $row['option_value'];
        }
        return $out;
    }

    public function get_widget_settings(): array {
        $all = $this->get_all_settings();
        return array_intersect_key( $all, array_flip( [
            'primary_color', 'secondary_color', 'font_family', 'bubble_position',
            'greeting_he', 'greeting_en', 'greeting_ru',
            'widget_title_he', 'widget_title_en', 'widget_title_ru',
            'phone', 'map_url', 'action_links', 'action_buttons',
            'product_name', 'powered_by_enabled', 'bot_name', 'business_name',
            'suggested_questions_he', 'suggested_questions_en', 'suggested_questions_ru',
            'voice_enabled', 'voice_provider', 'realtime_enabled', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay',
            'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru',
            'desktop_autoopen_enabled', 'desktop_autoopen_delay',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru',
            'consent_required', 'consent_version',
            'consent_text_he', 'consent_text_en', 'consent_text_ru',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
            'woo_enabled', 'woo_show_stock', 'woo_visual_search',
        ] ) );
    }

    // ─── Conversations ────────────────────────────────────────────────────────

    public function create_conversation( string $session_id, string $lang, string $ip_hash, string $page_url ): int {
        $conv = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $this->db->insert( $conv, [
            'session_id' => $session_id,
            'lang'       => $lang,
            'ip_hash'    => $ip_hash,
            'page_url'   => $page_url,
            'started_at' => current_time( 'mysql' ),
            'last_at'    => current_time( 'mysql' ),
        ] );
        return (int) $this->db->insert_id;
    }

    public function get_or_create_conversation( string $session_id, string $lang, string $ip_hash, string $page_url ): int {
        $conv  = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $today = current_time( 'Y-m-d' ); // WP-local day, matches stored started_at
        $id    = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM $conv WHERE session_id = %s AND DATE(started_at) = %s ORDER BY id DESC LIMIT 1",
                $session_id, $today
            )
        );
        if ( $id ) {
            $this->db->update( $conv, [ 'last_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
            return (int) $id;
        }
        return $this->create_conversation( $session_id, $lang, $ip_hash, $page_url );
    }

    public function add_message( int $conversation_id, string $role, string $content, bool $unanswered = false ): int {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        $this->db->insert( $msg, [
            'conversation_id' => $conversation_id,
            'role'            => $role,
            'content'         => $content,
            'unanswered'      => $unanswered ? 1 : 0,
            'created_at'      => current_time( 'mysql' ),
        ] );
        return (int) $this->db->insert_id;
    }

    /**
     * Return the most recent messages of a session's conversation, in chronological
     * order — used to attach the conversation context to a captured lead so the team
     * knows WHY the person left details and in what context.
     */
    public function get_session_context( string $session_id, int $limit = 8 ): array {
        $conv  = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $msg   = $this->db->prefix . self::TABLE_MESSAGES;
        $today = current_time( 'Y-m-d' ); // WP-local day, matches stored started_at

        $conv_id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM $conv WHERE session_id = %s ORDER BY id DESC LIMIT 1",
                $session_id
            )
        );
        if ( ! $conv_id ) return [];

        // Pull the last $limit messages, then return them oldest-first
        $rows = (array) $this->db->get_results(
            $this->db->prepare(
                "SELECT role, content, created_at FROM $msg WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
                (int) $conv_id, $limit
            ),
            ARRAY_A
        );
        return array_reverse( $rows );
    }

    public function get_conversation_messages( int $conversation_id, int $limit = 10 ): array {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        return (array) $this->db->get_results(
            $this->db->prepare(
                "SELECT role, content FROM $msg WHERE conversation_id = %d ORDER BY created_at DESC LIMIT %d",
                $conversation_id, $limit
            ),
            ARRAY_A
        );
    }

    /** All messages of a conversation in chronological order (for admin view). */
    public function get_conversation_full( int $conversation_id ): array {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        return (array) $this->db->get_results(
            $this->db->prepare(
                "SELECT role, content, unanswered, created_at FROM $msg WHERE conversation_id = %d ORDER BY id ASC",
                $conversation_id
            ),
            ARRAY_A
        );
    }

    public function mark_message_answered( int $conversation_id ): void {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        $this->db->update( $msg, [ 'unanswered' => 0 ], [ 'conversation_id' => $conversation_id, 'unanswered' => 1 ] );
    }

    // ─── Admin queries ────────────────────────────────────────────────────────

    public function get_conversations_paginated( int $page = 1, int $per_page = 20, array $filters = [] ): array {
        $conv   = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $msg    = $this->db->prefix . self::TABLE_MESSAGES;
        $offset = ( $page - 1 ) * $per_page;
        $where  = '1=1';
        $args   = [];

        if ( ! empty( $filters['lang'] ) ) {
            $where .= ' AND c.lang = %s';
            $args[]  = $filters['lang'];
        }
        if ( ! empty( $filters['unanswered'] ) ) {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $msg . ' m WHERE m.conversation_id = c.id AND m.unanswered = 1)';
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND c.started_at >= %s';
            $args[]  = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND c.started_at <= %s';
            $args[]  = $filters['date_to'] . ' 23:59:59';
        }

        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM $msg m WHERE m.conversation_id = c.id) as message_count,
                (SELECT COUNT(*) FROM $msg m WHERE m.conversation_id = c.id AND m.unanswered = 1) as unanswered_count
                FROM $conv c WHERE $where ORDER BY c.last_at DESC LIMIT %d OFFSET %d";

        // LIMIT/OFFSET are always placeholders, so always prepare the data query.
        $data_args = array_merge( $args, [ $per_page, $offset ] );
        $rows = $this->db->get_results( $this->db->prepare( $sql, ...$data_args ), ARRAY_A );

        // Count query only has the filter placeholders (no LIMIT/OFFSET)
        $count_sql = "SELECT COUNT(*) FROM $conv c WHERE $where";
        $total = empty( $args )
            ? (int) $this->db->get_var( $count_sql )
            : (int) $this->db->get_var( $this->db->prepare( $count_sql, ...$args ) );

        return [ 'rows' => (array) $rows, 'total' => $total ];
    }

    public function get_unanswered_count(): int {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        return (int) $this->db->get_var( "SELECT COUNT(*) FROM $msg WHERE unanswered = 1" );
    }

    public function get_conversations_count(): int {
        $conv = $this->db->prefix . self::TABLE_CONVERSATIONS;
        return (int) $this->db->get_var( "SELECT COUNT(*) FROM $conv" );
    }

    /** Most frequently asked user questions (for the analytics dashboard). */
    public function get_top_user_questions( int $limit = 8 ): array {
        $msg = $this->db->prefix . self::TABLE_MESSAGES;
        return (array) $this->db->get_results(
            $this->db->prepare(
                "SELECT content, COUNT(*) AS cnt FROM $msg
                 WHERE role = 'user' AND CHAR_LENGTH(content) BETWEEN 4 AND 120
                 GROUP BY content ORDER BY cnt DESC, MAX(created_at) DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    public function export_conversations_csv( array $filters = [] ): string {
        $conv = $this->db->prefix . self::TABLE_CONVERSATIONS;
        $msg  = $this->db->prefix . self::TABLE_MESSAGES;

        $rows = $this->db->get_results(
            "SELECT c.id, c.session_id, c.lang, c.page_url, c.started_at, m.role, m.content, m.created_at as msg_time
             FROM $conv c
             LEFT JOIN $msg m ON m.conversation_id = c.id
             ORDER BY c.id ASC, m.id ASC",
            ARRAY_A
        );

        $lines   = [ implode( ',', [ 'conversation_id', 'lang', 'page_url', 'started_at', 'role', 'content', 'msg_time' ] ) ];
        foreach ( (array) $rows as $row ) {
            $lines[] = implode( ',', array_map( fn( $v ) => '"' . str_replace( '"', '""', (string) $v ) . '"', [
                $row['id'], $row['lang'], $row['page_url'], $row['started_at'],
                $row['role'], $row['content'], $row['msg_time'],
            ] ) );
        }
        return implode( "\n", $lines );
    }

    // ─── Content index ────────────────────────────────────────────────────────

    public function upsert_content( array $data ): bool {
        $idx    = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        $result = $this->db->replace( $idx, [
            'post_id'    => $data['post_id'],
            'post_type'  => $data['post_type'],
            'lang'       => $data['lang'],
            'title'      => $data['title'],
            'content'    => $data['content'],
            'url'        => $data['url'],
            'indexed_at' => current_time( 'mysql' ),
        ] );
        return $result !== false;
    }

    public function get_last_db_error(): string {
        return $this->db->last_error ?? '';
    }

    /**
     * Keyword search over indexed content.
     * Uses LIKE on individual words (MySQL FULLTEXT does not tokenise Hebrew),
     * ranking results by how many query words they match in the title/content.
     */
    // Common words that add noise to keyword search (match almost any page).
    private const STOPWORDS = [
        // Hebrew
        'של','על','את','זה','זו','יש','מה','מי','איך','איפה','היכן','מתי','למה','האם',
        'אתם','אתה','אתן','אני','אנחנו','הוא','היא','הם','הן','כל','גם','אבל','או','כי',
        'אם','לא','כן','עם','אל','לי','לך','לו','לה','לנו','להם','כמה','אצל','עוד','רק',
        'יותר','כמו','בין','אחרי','לפני','תחת','מעל','בתוך','שלי','שלך','שלכם','האתר',
        // English
        'the','a','an','is','are','do','does','what','where','when','how','why','who',
        'you','your','we','our','they','it','of','to','in','on','at','and','or','for',
        'me','my','can','i','this','that','about',
    ];

    /**
     * Strip leading Hebrew one-letter prefixes (ו/ה/ב/כ/ל/מ/ש) from a word so
     * substring search matches morphological variants. Only strips while the
     * remaining stem stays >= 3 chars, and never touches non-Hebrew words.
     */
    private function strip_hebrew_prefix( string $word ): string {
        // Strip ONE leading attached letter: ו(and) ה(the) ב(in) ל(to) מ(from).
        // ('ש','כ' excluded — they begin core words like שיקום/שירות/כתובת/כללי.)
        $prefixes = [ 'ו', 'ה', 'ב', 'ל', 'מ' ];
        $first    = mb_substr( $word, 0, 1 );
        if ( in_array( $first, $prefixes, true ) && mb_strlen( $word ) >= 4 ) {
            return mb_substr( $word, 1 );
        }
        return $word;
    }

    public function search_content( string $query, string $lang = 'he', int $limit = 5 ): array {
        $idx = $this->db->prefix . self::TABLE_CONTENT_INDEX;

        // Split, drop very short tokens AND stopwords so only meaningful words rank
        $words = array_filter(
            preg_split( '/\s+/u', trim( mb_strtolower( $query ) ) ),
            fn( $w ) => mb_strlen( $w ) >= 2 && ! in_array( $w, self::STOPWORDS, true )
        );
        // If everything was a stopword, fall back to all non-trivial tokens
        if ( empty( $words ) ) {
            $words = array_filter(
                preg_split( '/\s+/u', trim( $query ) ),
                fn( $w ) => mb_strlen( $w ) >= 2
            );
        }
        if ( empty( $words ) ) {
            $words = [ trim( $query ) ];
        }

        // Keep each meaningful word AND its prefix-stripped stem. The exact word
        // scores when present; the stem catches Hebrew variants ("הרופא"→"רופא",
        // "במחלקת"→"מחלקת") without losing precision on the original.
        $expanded = [];
        foreach ( $words as $w ) {
            $expanded[] = $w;
            $stem = $this->strip_hebrew_prefix( $w );
            if ( $stem !== $w && mb_strlen( $stem ) >= 3 ) {
                $expanded[] = $stem;
            }
        }
        $words = array_slice( array_values( array_unique( array_filter( $expanded ) ) ), 0, 14 );

        // Build placeholders. Args must follow the textual order of %s in the
        // final SQL: SELECT score first, then WHERE lang, then WHERE conditions.
        $score_parts = [];
        $where_parts = [];
        $score_args  = [];
        $where_args  = [];

        foreach ( $words as $w ) {
            $like = '%' . $this->db->esc_like( $w ) . '%';
            // Title matches weigh more than content matches
            $score_parts[] = '(CASE WHEN title LIKE %s THEN 3 ELSE 0 END) + (CASE WHEN content LIKE %s THEN 1 ELSE 0 END)';
            $score_args[]  = $like; $score_args[] = $like;
            $where_parts[] = '(title LIKE %s OR content LIKE %s)';
            $where_args[]  = $like; $where_args[] = $like;
        }

        // Authoritative content (menu list, pages, custom types) outranks blog
        // posts/news so structured info wins over an article that merely mentions it.
        $type_boost = "(CASE post_type
            WHEN 'contact' THEN 7
            WHEN 'menu' THEN 6
            WHEN 'page' THEN 3
            WHEN 'department' THEN 4
            WHEN 'team_member' THEN 4
            WHEN 'faq' THEN 3
            WHEN 'manual_text' THEN 3
            WHEN 'post' THEN 0
            ELSE 1 END)";

        $score = '(' . implode( ' + ', $score_parts ) . ') + ' . $type_boost;
        $where = implode( ' OR ', $where_parts );

        $sql = "SELECT post_id, post_type, title, content, url, ($score) AS relevance
                FROM $idx
                WHERE lang = %s AND ($where)
                ORDER BY relevance DESC, indexed_at DESC
                LIMIT %d";

        $args = array_merge( $score_args, [ $lang ], $where_args, [ $limit ] );

        return (array) $this->db->get_results( $this->db->prepare( $sql, ...$args ), ARRAY_A );
    }

    public function get_content_count(): int {
        $idx = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        return (int) $this->db->get_var( "SELECT COUNT(*) FROM $idx" );
    }

    public function get_indexed_list( string $search = '', int $page = 1, int $per_page = 25 ): array {
        $idx    = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        $offset = ( $page - 1 ) * $per_page;
        $where  = '1=1';
        $args   = [];

        if ( $search ) {
            $like    = '%' . $this->db->esc_like( $search ) . '%';
            $where  .= ' AND (title LIKE %s OR url LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        $data_sql  = "SELECT post_id, post_type, lang, title, url, indexed_at,
                             CHAR_LENGTH(content) AS char_count
                      FROM $idx WHERE $where ORDER BY indexed_at DESC LIMIT %d OFFSET %d";
        $count_sql = "SELECT COUNT(*) FROM $idx WHERE $where";

        $data_args  = array_merge( $args, [ $per_page, $offset ] );
        $rows  = $this->db->get_results( $this->db->prepare( $data_sql,  ...$data_args ), ARRAY_A );
        $total = (int) ( $args
            ? $this->db->get_var( $this->db->prepare( $count_sql, ...$args ) )
            : $this->db->get_var( $count_sql ) );

        return [ 'rows' => (array) $rows, 'total' => $total ];
    }

    public function delete_indexed_item( int $post_id, string $lang ): void {
        $idx = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        $this->db->delete( $idx, [ 'post_id' => $post_id, 'lang' => $lang ] );
    }

    public function clear_content_index(): void {
        $idx = $this->db->prefix . self::TABLE_CONTENT_INDEX;
        $this->db->query( "TRUNCATE TABLE $idx" );
    }
}
