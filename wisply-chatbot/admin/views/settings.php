<?php
defined( 'ABSPATH' ) || exit;

// ── Process form submission (standard WP form POST — no AJAX, works with all security plugins) ──
$saved   = false;
$save_error = '';
$lang_notice = '';

if ( isset( $_POST['wisply_settings_nonce'] ) ) {
    if ( ! check_admin_referer( 'wisply_save_settings', 'wisply_settings_nonce' ) ) {
        $save_error = 'שגיאת אבטחה — נסה שוב.';
    } elseif ( ! current_user_can( 'manage_options' ) ) {
        $save_error = 'אין הרשאה.';
    } else {
        $allowed     = [
            'license_key',
            // White-label persona
            'bot_name', 'business_name', 'business_type', 'business_description', 'action_buttons',
            'suggested_questions_he', 'suggested_questions_en', 'suggested_questions_ru', 'suggested_questions_ar',

            // AI is platform-managed (2.16.0): provider API keys no longer exist
            // in this plugin — every call runs through the Wisply proxy with the
            // licence key. Only the model preference and custom rules remain.
            'ai_custom_rules', 'openai_model',
            'primary_color', 'secondary_color', 'font_family', 'bubble_position', 'bot_avatar', 'bot_avatar_url',
            'greeting_he', 'greeting_en', 'greeting_ru', 'greeting_ar',
            'widget_title_he', 'widget_title_en', 'widget_title_ru', 'widget_title_ar',
            'phone', 'map_url', 'max_context_docs', 'conversation_ttl_days',
            'voice_enabled', 'voice_provider', 'tts_model', 'tts_voice', 'stt_model',
            'realtime_enabled', 'realtime_model', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay', 'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru', 'proactive_msg_ar',
            'desktop_autoopen_enabled', 'desktop_autoopen_delay',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru', 'desktop_autoopen_msg_ar',
            'consent_required', 'consent_version', 'consent_text_he', 'consent_text_en', 'consent_text_ru', 'consent_text_ar',
            'emergency_msg_he', 'emergency_msg_en', 'emergency_msg_ru', 'emergency_msg_ar',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
            'report_recipients', 'report_daily', 'report_weekly',
            'woo_enabled', 'woo_max_products', 'woo_show_stock', 'woo_visual_search', 'woo_bundle_enabled', 'woo_order_status_enabled',
            'lead_field_name', 'lead_field_phone', 'lead_field_email',
            'conversation_end_action', 'max_messages', 'wrapup_margin',
        ];
        // Multi-line fields must keep their newlines
        $textarea_keys = [
            'business_description', 'ai_custom_rules', 'greeting_he', 'greeting_en', 'greeting_ru', 'greeting_ar',
            'suggested_questions_he', 'suggested_questions_en', 'suggested_questions_ru', 'suggested_questions_ar',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru', 'desktop_autoopen_msg_ar',
            'report_recipients',
        ];
        // Checkboxes don't POST when unchecked — normalise to 0/1
        $_POST['voice_enabled']     = isset( $_POST['voice_enabled'] )     ? '1' : '0';
        $_POST['realtime_enabled']  = isset( $_POST['realtime_enabled'] )  ? '1' : '0';
        $_POST['proactive_enabled'] = isset( $_POST['proactive_enabled'] ) ? '1' : '0';
        $_POST['desktop_autoopen_enabled'] = isset( $_POST['desktop_autoopen_enabled'] ) ? '1' : '0';
        $_POST['consent_required']  = isset( $_POST['consent_required'] )  ? '1' : '0';
        $_POST['report_daily']      = isset( $_POST['report_daily'] )      ? '1' : '0';
        $_POST['report_weekly']     = isset( $_POST['report_weekly'] )     ? '1' : '0';
        $_POST['woo_enabled']        = isset( $_POST['woo_enabled'] )        ? '1' : '0';
        $_POST['woo_show_stock']     = isset( $_POST['woo_show_stock'] )     ? '1' : '0';
        $_POST['woo_visual_search']  = isset( $_POST['woo_visual_search'] )  ? '1' : '0';
        $_POST['woo_bundle_enabled'] = isset( $_POST['woo_bundle_enabled'] ) ? '1' : '0';
        $_POST['woo_order_status_enabled'] = isset( $_POST['woo_order_status_enabled'] ) ? '1' : '0';
        $secret_keys = [ 'ai_api_key', 'openai_api_key' ];
        $db          = Wisply_Database::get_instance();
        // Remember the licence key we had, so a NEW one can be activated after saving
        $license_before = strtoupper( trim( (string) $db->get_setting( 'license_key', '' ) ) );

        foreach ( $allowed as $key ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            // CTA buttons arrive as a JSON string — validate, then re-encode cleanly
            if ( $key === 'action_buttons' ) {
                $db->set_setting( 'action_buttons', wisply_sanitize_action_buttons( (string) wp_unslash( $_POST['action_buttons'] ) ) );
                continue;
            }
            // Licence keys are always uppercase — "wsp-…" pasted by a customer still works
            if ( $key === 'license_key' ) {
                $db->set_setting( 'license_key', strtoupper( trim( sanitize_text_field( (string) wp_unslash( $_POST['license_key'] ) ) ) ) );
                continue;
            }
            $val = in_array( $key, $textarea_keys, true )
                ? sanitize_textarea_field( (string) wp_unslash( $_POST[ $key ] ) )
                : sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
            // Skip empty secret fields — don't wipe stored key
            if ( in_array( $key, $secret_keys, true ) && empty( $val ) ) continue;
            $db->set_setting( $key, $val );
        }

        // Re-activate on EVERY save with a non-empty key — not only when it changed.
        // The admin notice tells a locked-out owner to "check the key in settings", so
        // Save has to actually be the retry button that promise implies. Gating this on
        // a changed key made it a no-op precisely for the person whose key is already
        // correct and whose bot is down. license_activate is idempotent for a site it
        // knows (on conflict → touch last_seen_at), so the extra round-trip is free.
        // Runs BEFORE the language clamp so a fresh plan upgrade's max_langs is live.
        $license_after = strtoupper( trim( (string) $db->get_setting( 'license_key', '' ) ) );
        if ( $license_after !== '' && class_exists( 'Wisply_License' ) ) {
            Wisply_License::get_instance()->activate( $license_after );
        }
        unset( $license_before );

        // ── Languages (plan-gated) ──────────────────────────────────────────
        // Checkboxes POST as enabled_langs[]; the plan caps how many may be on.
        // The default language is kept first so the clamp can never drop it.
        $supported_langs = [ 'he', 'en', 'ru', 'ar' ];
        $max_langs = class_exists( 'Wisply_License' ) ? Wisply_License::get_instance()->max_langs() : 1;
        $picked = ( isset( $_POST['enabled_langs'] ) && is_array( $_POST['enabled_langs'] ) )
            ? array_values( array_intersect( $supported_langs, array_map( 'sanitize_key', wp_unslash( $_POST['enabled_langs'] ) ) ) )
            : [];
        $default_lang = sanitize_key( (string) ( $_POST['default_lang'] ?? '' ) );
        if ( ! in_array( $default_lang, $supported_langs, true ) ) $default_lang = '';
        if ( empty( $picked ) ) $picked = [ $default_lang !== '' ? $default_lang : 'he' ];
        if ( $default_lang !== '' && in_array( $default_lang, $picked, true ) ) {
            $picked = array_merge( [ $default_lang ], array_values( array_diff( $picked, [ $default_lang ] ) ) );
        }
        $over   = count( $picked ) > $max_langs;
        $picked = array_slice( $picked, 0, max( 1, $max_langs ) );
        if ( $default_lang === '' || ! in_array( $default_lang, $picked, true ) ) $default_lang = $picked[0];
        $db->set_setting( 'enabled_langs', implode( ',', $picked ) );
        $db->set_setting( 'default_lang', $default_lang );
        if ( $over ) {
            $lang_notice = sprintf(
                'התכנית הנוכחית מאפשרת עד %d שפות פעילות, ולכן נשמרו רק %d. לשפות נוספות יש לשדרג תכנית.',
                $max_langs, count( $picked )
            );
        }

        $saved = true;
    }
}

$db       = Wisply_Database::get_instance();
$settings = $db->get_all_settings();

// This view is require()'d inside a method, so read settings from the DB (a
// `global $settings` would not reach the method-local copy above).
function wisply_settings_store(): array {
    static $s = null;
    if ( $s === null ) { $s = Wisply_Database::get_instance()->get_all_settings(); }
    return $s;
}
function wisply_v( string $key, mixed $default = '' ): string {
    $s = wisply_settings_store();
    return esc_attr( $s[ $key ] ?? $default );
}
function wisply_sel( string $key, string $val ): string {
    $s = wisply_settings_store();
    return ( ( $s[ $key ] ?? '' ) === $val ) ? 'selected' : '';
}
/** Validate the CTA-buttons JSON from the form → clean JSON array (or ''). */
function wisply_sanitize_action_buttons( string $json ): string {
    $data = json_decode( trim( $json ), true );
    if ( ! is_array( $data ) ) return '';
    $out = [];
    foreach ( $data as $row ) {
        if ( ! is_array( $row ) ) continue;
        $key = sanitize_key( (string) ( $row['key'] ?? '' ) );
        $url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
        if ( $key === '' || $url === '' ) continue;
        $out[] = [
            'key'      => $key,
            'url'      => $url,
            'label_he' => sanitize_text_field( (string) ( $row['label_he'] ?? '' ) ),
            'label_en' => sanitize_text_field( (string) ( $row['label_en'] ?? '' ) ),
            'label_ru' => sanitize_text_field( (string) ( $row['label_ru'] ?? '' ) ),
            'label_ar' => sanitize_text_field( (string) ( $row['label_ar'] ?? '' ) ),
        ];
    }
    return $out ? wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) : '';
}
$product_name = defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : ( $settings['product_name'] ?? 'Wisply' );
?>
<div class="wrap wisply-admin" dir="rtl">
    <h1 class="wisply-title"><span class="wisply-title-dot"></span><?php echo esc_html( $product_name ); ?> <span class="wisply-title-sub">הגדרות</span></h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ ההגדרות נשמרו בהצלחה.</p></div>
    <?php elseif ( $save_error ) : ?>
        <div class="notice notice-error"><p>❌ <?php echo esc_html( $save_error ); ?></p></div>
    <?php endif; ?>

    <?php
    $wisply_db      = Wisply_Database::get_instance();
    $wisply_indexed = (int) $wisply_db->get_content_count();
    $wisply_lic_ok  = class_exists( 'Wisply_License' ) && Wisply_License::get_instance()->is_valid();
    // The licence IS the AI credential now — a valid licence means the Wisply
    // proxy will serve AI with the customer's platform-managed key.
    $wisply_key_set = class_exists( 'Wisply_License' )
                   && Wisply_License::get_instance()->get_key() !== '';
    $wisply_ready   = $wisply_key_set && $wisply_indexed > 0 && $wisply_lic_ok;
    $wisply_steps   = [
        [ 'ok' => $wisply_lic_ok,        'label' => 'רישיון פעיל',        'tab' => 'advanced', 'hint' => 'הזן מפתח רישיון' ],
        [ 'ok' => $wisply_key_set,       'label' => 'מנוע AI מחובר',      'tab' => 'advanced', 'hint' => 'מופעל אוטומטית עם מפתח הרישיון' ],
        [ 'ok' => $wisply_indexed > 0,   'label' => 'תוכן האתר אונדקס',   'tab' => 'design',   'hint' => 'הרץ אינדוקס לתוכן' ],
    ];
    $wisply_done = count( array_filter( $wisply_steps, static fn( $s ) => $s['ok'] ) );
    ?>
    <div class="wisply-hero <?php echo $wisply_ready ? 'is-ready' : 'is-setup'; ?>">
        <div class="wisply-hero-ico"><?php echo $wisply_ready ? '✓' : '⚡'; ?></div>
        <div class="wisply-hero-main">
            <h2><?php echo $wisply_ready ? 'העוזר החכם שלך מוכן ופעיל' : 'כמעט שם, עוד כמה צעדים'; ?></h2>
            <p>
                <?php if ( $wisply_ready ) : ?>
                    <?php echo esc_html( sprintf( 'אונדקסו %d פריטי תוכן. הבוט עונה לגולשים על סמך תוכן האתר שלך.', $wisply_indexed ) ); ?>
                <?php else : ?>
                    השלמת <?php echo (int) $wisply_done; ?> מתוך <?php echo count( $wisply_steps ); ?> צעדים כדי להפעיל את הבוט.
                <?php endif; ?>
            </p>
            <div class="wisply-onb">
                <?php foreach ( $wisply_steps as $wisply_s ) : ?>
                    <button type="button" class="wisply-onb-step <?php echo $wisply_s['ok'] ? 'is-done' : ''; ?>" data-goto="<?php echo esc_attr( $wisply_s['tab'] ); ?>">
                        <span class="wisply-onb-check"><?php echo $wisply_s['ok'] ? '✓' : ''; ?></span>
                        <span><?php echo esc_html( $wisply_s['label'] ); ?></span>
                        <?php if ( ! $wisply_s['ok'] ) : ?><em><?php echo esc_html( $wisply_s['hint'] ); ?></em><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="wisply-hero-stat">
            <b><?php echo (int) $wisply_indexed; ?></b>
            <span>פריטים באינדקס</span>
        </div>
    </div>
    <?php if ( $lang_notice ) : ?>
        <div class="notice notice-warning is-dismissible"><p>⚠️ <?php echo esc_html( $lang_notice ); ?></p></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0 20px">
        <button type="button" class="button button-secondary" id="wisply-syscheck">🔧 בדוק מערכת (למה זה לא עובד?)</button>
        <span style="color:#666;margin-inline-start:10px">בדיקה מהירה: שמירת הגדרות, מפתח OpenAI, צ׳אט ודיבור.</span>
        <div id="wisply-syscheck-results" style="margin-top:12px"></div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'wisply_save_settings', 'wisply_settings_nonce' ); ?>

        <div class="wisply-layout">
        <nav class="wisply-nav" role="tablist" aria-label="הגדרות">
            <button type="button" class="wisply-nav-btn active" data-target="brand"    role="tab"><span class="wisply-nav-ico">🏷️</span><span class="wisply-nav-txt"><b>מיתוג וזהות</b><em>שם, פרסונה, לוגו</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="ai"       role="tab"><span class="wisply-nav-ico">🤖</span><span class="wisply-nav-txt"><b>מנוע ה-AI</b><em>ספק, מפתח, חוקים</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="voice"    role="tab"><span class="wisply-nav-ico">🎙️</span><span class="wisply-nav-txt"><b>קול ודיבור</b><em>הקלטה והקראה</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="proactive" role="tab"><span class="wisply-nav-ico">🔔</span><span class="wisply-nav-txt"><b>בועית יזומה</b><em>פתיחה אוטומטית</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="leads"    role="tab"><span class="wisply-nav-ico">📥</span><span class="wisply-nav-txt"><b>לידים ודוחות</b><em>טפסים ומעקב</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="shop"     role="tab"><span class="wisply-nav-ico">🛒</span><span class="wisply-nav-txt"><b>חנות ומסחר</b><em>מוצרים והזמנות</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="design"   role="tab"><span class="wisply-nav-ico">🎨</span><span class="wisply-nav-txt"><b>עיצוב ותוכן</b><em>צבע, אוואטר, שפות</em></span></button>
            <button type="button" class="wisply-nav-btn"        data-target="advanced" role="tab"><span class="wisply-nav-ico">⚙️</span><span class="wisply-nav-txt"><b>מתקדם</b><em>רישיון ומערכת</em></span></button>
        </nav>

        <div class="wisply-content">
        <div class="wisply-pane active" data-pane="brand">
        <h2>🏷️ זהות ומיתוג (White-Label)</h2>
        <table class="form-table">
            <tr>
                <th>שם הבוט</th>
                <td>
                    <input type="text" name="bot_name" class="regular-text" value="<?php echo wisply_v('bot_name'); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                    <p class="description">השם שבו הבוט מציג את עצמו (למשל "דנה", "העוזר של האתר"). נטען אוטומטית משם האתר.</p>
                </td>
            </tr>
            <tr>
                <th>שם העסק / האתר</th>
                <td>
                    <input type="text" name="business_name" class="regular-text" value="<?php echo wisply_v('business_name'); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                    <p class="description">שם העסק כפי שהבוט יתייחס אליו בתשובות.</p>
                </td>
            </tr>
            <tr>
                <th>סוג העסק</th>
                <td>
                    <input type="text" name="business_type" class="regular-text" value="<?php echo wisply_v('business_type'); ?>" placeholder="חנות אונליין / משרד עו״ד / קליניקה / מסעדה...">
                    <p class="description">תיאור קצר בשורה אחת. עוזר לבוט להבין את ההקשר.</p>
                </td>
            </tr>
            <tr>
                <th>תיאור / פרסונה</th>
                <td>
                    <textarea name="business_description" rows="4" class="large-text" placeholder="ספר לבוט על העסק: מה אתם מציעים, מה הטון הרצוי, מה מותר ומה אסור לומר..."><?php echo esc_textarea( $settings['business_description'] ?? '' ); ?></textarea>
                    <p class="description">טקסט חופשי שמוזרק ישירות להנחיות ה-AI. כאן אפשר לכוונן את האישיות והגבולות.</p>
                </td>
            </tr>
        </table>

        <h2>💡 שאלות מוצעות (Chips)</h2>
        <p class="description" style="margin:0 0 8px">שאלה אחת בכל שורה. מוצגות ככפתורים מהירים בפתיחת הצ׳אט. השאר ריק לברירת המחדל.</p>
        <table class="form-table">
            <tr><th>עברית</th><td><textarea name="suggested_questions_he" rows="4" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['suggested_questions_he'] ?? '' ); ?></textarea></td></tr>
            <tr><th>English</th><td><textarea name="suggested_questions_en" rows="4" class="large-text" dir="ltr"><?php echo esc_textarea( $settings['suggested_questions_en'] ?? '' ); ?></textarea></td></tr>
            <tr><th>Русский</th><td><textarea name="suggested_questions_ru" rows="4" class="large-text" dir="ltr"><?php echo esc_textarea( $settings['suggested_questions_ru'] ?? '' ); ?></textarea></td></tr>
            <tr><th>العربية</th><td><textarea name="suggested_questions_ar" rows="4" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['suggested_questions_ar'] ?? '' ); ?></textarea></td></tr>
        </table>

        <h2>🔘 כפתורי פעולה (CTA)</h2>
        <p class="description" style="margin:0 0 8px">כפתורים שהבוט יציע כשרלוונטי (למשל "הזמנת תור", "לחנות"). ה-AI מזהה כוונה ומוסיף את הכפתור אוטומטית.</p>
        <table class="widefat striped" id="wisply-cta-table" style="max-width:980px">
            <thead><tr>
                <th style="width:120px">מזהה (key)</th><th>תווית (עברית)</th><th>Label (EN)</th><th>Label (RU)</th><th>التسمية (AR)</th><th>קישור (URL)</th><th style="width:40px"></th>
            </tr></thead>
            <tbody id="wisply-cta-rows"></tbody>
        </table>
        <p><button type="button" class="button" id="wisply-cta-add">➕ הוספת כפתור</button></p>
        <input type="hidden" name="action_buttons" id="wisply-cta-json" value="<?php echo esc_attr( $settings['action_buttons'] ?? '' ); ?>">

        </div><div class="wisply-pane" data-pane="ai">
        <h2>🤖 מנוע AI</h2>
        <div style="background:#f0fafa;border:1px solid #cfeaea;border-radius:10px;padding:14px 16px;margin:12px 0;max-width:640px">
            <strong>מנוע ה-AI מנוהל על ידי Wisply.</strong>
            <p style="margin:6px 0 0;color:#555">
                לא צריך להזין מפתח API — הבוט מתחבר אוטומטית באמצעות מפתח הרישיון,
                והשימוש נמדד ומנוהל לפי המסלול שלך. אם הצ׳אט לא עונה, ודא שמפתח
                הרישיון פעיל בלשונית "מתקדם".
            </p>
        </div>
        <table class="form-table">
            <tr id="wisply-openai-model-row">
                <th>מודל</th>
                <td>
                    <select name="openai_model">
                        <option value="gpt-4o" <?php echo wisply_sel('openai_model','gpt-4o'); ?>>איכות מרבית (gpt-4o)</option>
                        <option value="gpt-4o-mini" <?php echo wisply_sel('openai_model','gpt-4o-mini'); ?>>חסכוני (gpt-4o-mini)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>מסמכי הקשר מקסימום</th>
                <td>
                    <input type="number" name="max_context_docs" min="1" max="10"
                           value="<?php echo wisply_v('max_context_docs','5'); ?>" class="small-text">
                    <p class="description">כמה קטעי תוכן מהאתר לשלוח ל-AI בכל שאלה</p>
                </td>
            </tr>
        </table>

        <h2>🧠 חוקים והתאמה חכמה</h2>
        <table class="form-table">
            <tr>
                <th>הנחיות מותאמות לבוט</th>
                <td>
                    <textarea name="ai_custom_rules" rows="9" class="large-text" dir="rtl" placeholder="לדוגמה:
אנחנו מותג תכשיטים בעבודת יד. הטון עדין, חם ואישי.
התאמת מוצר לפי סיטואציה/רגש:
- מתנה ליום נישואין או אירוע מיוחד -> שרשרת או תליון אלגנטי מזהב.
- רוצים לפנק או לשמח -> פריט צבעוני וכיפי (עגילים/צמיד).
- מתנה קלאסית ובטוחה -> טבעת או שרשרת עדינה.
- תקציב מוגבל -> הצע קודם את הפריטים המשתלמים.
תמיד תסביר בקצרה למה הפריט מתאים למה שהלקוח תיאר."><?php echo esc_textarea( $settings['ai_custom_rules'] ?? '' ); ?></textarea>
                    <p class="description">כללים, ידע וטון שהבוט יזכור ויפעל לפיהם, <strong>ספציפי לעסק שלך בלבד</strong> (נשמר בהגדרות ההתקנה הזו, לא בקוד התוסף). כאן מגדירים איזה מוצר מתאים לאיזו סיטואציה או רגש, על מה מתמחים, מה להדגיש ומה להימנע. הבוט משתמש בזה כדי להבין מה הלקוח מתאר <strong>ולהתאים מוצר רלוונטי</strong>, לא לשלוף מוצרים אקראיים.</p>
                </td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="voice">
        <h2>🎙️ שיחת קול (Voice)</h2>
        <table class="form-table">
            <tr>
                <th>הפעלת קול</th>
                <td>
                    <label>
                        <input type="checkbox" name="voice_enabled" value="1"
                               <?php checked( ( $settings['voice_enabled'] ?? '1' ), '1' ); ?>>
                        אפשר למשתמשים לדבר עם הבוט ולשמוע תשובות בקול
                    </label>
                </td>
            </tr>
            <tr>
                <th>מנוע קול</th>
                <td>
                    <select name="voice_provider">
                        <option value="openai" <?php echo wisply_sel('voice_provider','openai'); ?>>OpenAI — Whisper + קול טבעי (מומלץ)</option>
                        <option value="browser" <?php echo wisply_sel('voice_provider','browser'); ?>>דפדפן — חינמי (איכות בינונית)</option>
                    </select>
                    <p class="description">OpenAI נותן הבנה מצוינת בעברית וקול טבעי. עלות זניחה (~אגורות לשיחה), משתמש באותו API key.</p>
                </td>
            </tr>
            <tr>
                <th>מצב Real-Time ⚡</th>
                <td>
                    <label>
                        <input type="checkbox" name="realtime_enabled" value="1"
                               <?php checked( ( $settings['realtime_enabled'] ?? '1' ), '1' ); ?>>
                        שיחת קול בזמן אמת כמו ChatGPT (speech-to-speech, תגובה מיידית, אפשר לקטוע באמצע)
                    </label>
                    <p class="description">
                        משתמש ב-OpenAI Realtime API. <strong>חשוב:</strong> דורש גישה ל-Realtime בחשבון ה-OpenAI שלך,
                        ועלותו <strong>גבוהה יותר</strong> מצ׳אט רגיל (אודיו בזמן אמת). יש מגבלות הגנה מובנות
                        (עד 5 דק׳ לשיחה, מקס׳ 6 שיחות ל-10 דק׳ לכל מבקר). אם תכבה — הקול יעבוד במצב Whisper+TTS הרגיל.
                    </p>
                </td>
            </tr>
            <tr>
                <th>טקסט בשיחת קול</th>
                <td>
                    <select name="voice_text_mode">
                        <option value="none" <?php echo wisply_sel('voice_text_mode','none'); ?>>ללא טקסט — שיחת קול טהורה</option>
                        <option value="end"  <?php echo wisply_sel('voice_text_mode','end'); ?>>שמירת תמלול בסיום — בתום השיחה כל מה שנאמר נכתב בצ׳אט (מומלץ)</option>
                        <option value="live" <?php echo wisply_sel('voice_text_mode','live'); ?>>טקסט חי — הטקסט של שני הצדדים נכתב בזמן אמת לאורך השיחה</option>
                    </select>
                    <p class="description">קובע אם ואיך הדברים שנאמרו בשיחת הקול מוצגים כטקסט בחלון הצ׳אט.</p>
                </td>
            </tr>
            <tr>
                <th>קול הבוט</th>
                <td>
                    <select name="tts_voice" id="wisply-tts-voice">
                        <?php foreach ( [
                            'shimmer' => 'Shimmer — נשי וחם (מומלץ)',
                            'coral'   => 'Coral — נשי ורגוע',
                            'sage'    => 'Sage — נשי ושקט',
                            'alloy'   => 'Alloy — ניטרלי',
                            'ballad'  => 'Ballad — רך ומלודי',
                            'ash'     => 'Ash — גברי וברור',
                            'echo'    => 'Echo — גברי',
                            'verse'   => 'Verse — גברי ומלא',
                        ] as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php echo wisply_sel('tts_voice',$val); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="wisply-voice-preview" style="margin-inline-start:8px">🔊 השמע דוגמה</button>
                    <span id="wisply-voice-preview-status" style="margin-inline-start:8px;color:#666"></span>
                    <p class="description">
                        הקול שבו הבוט מדבר — עובד גם במצב Real-Time וגם במצב הרגיל.
                        לחץ "השמע דוגמה" כדי לשמוע את הקול הנבחר מיד (אין צורך לשמור קודם).
                    </p>
                </td>
            </tr>
            <tr>
                <th>מודל דיבור (TTS)</th>
                <td>
                    <select name="tts_model">
                        <option value="gpt-4o-mini-tts" <?php echo wisply_sel('tts_model','gpt-4o-mini-tts'); ?>>gpt-4o-mini-tts — טבעי וזול (מומלץ)</option>
                        <option value="tts-1" <?php echo wisply_sel('tts_model','tts-1'); ?>>tts-1 — מהיר</option>
                        <option value="tts-1-hd" <?php echo wisply_sel('tts_model','tts-1-hd'); ?>>tts-1-hd — איכות גבוהה</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>מודל האזנה (STT)</th>
                <td>
                    <select name="stt_model">
                        <option value="whisper-1" <?php echo wisply_sel('stt_model','whisper-1'); ?>>whisper-1 — מצוין בעברית (מומלץ)</option>
                        <option value="gpt-4o-mini-transcribe" <?php echo wisply_sel('stt_model','gpt-4o-mini-transcribe'); ?>>gpt-4o-mini-transcribe — זול</option>
                        <option value="gpt-4o-transcribe" <?php echo wisply_sel('stt_model','gpt-4o-transcribe'); ?>>gpt-4o-transcribe — מדויק ביותר</option>
                    </select>
                    <p class="description">המנוע שמתמלל את מה שהמשתמש אומר. Whisper מצטיין במונחים רפואיים ובמבטאים.</p>
                </td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="proactive">
        <h2>🔔 התראה יזומה (בועית פנייה לפי דף)</h2>
        <table class="form-table">
            <tr>
                <th>הפעלה</th>
                <td>
                    <label>
                        <input type="checkbox" name="proactive_enabled" value="1"
                               <?php checked( ( $settings['proactive_enabled'] ?? '1' ), '1' ); ?>>
                        הצג בועית פנייה יזומה בדפים ספציפיים (מחלקה, סדנה, מאמר…)
                    </label>
                    <p class="description">הבועית קופצת בכל טעינת עמוד כשהגולש מגיע ל<strong>אמצע העמוד</strong> בגלילה (בדפים קצרים — אחרי ההשהיה למטה).</p>
                </td>
            </tr>
            <tr>
                <th>השהיה בדפים קצרים</th>
                <td>
                    <input type="number" name="proactive_delay" min="1" max="120"
                           value="<?php echo wisply_v('proactive_delay','5'); ?>" class="small-text"> שניות
                    <p class="description">רלוונטי רק לדפים קצרים שאי אפשר לגלול בהם לאמצע.</p>
                </td>
            </tr>
            <tr>
                <th>הודעה — עברית</th>
                <td>
                    <input type="text" name="proactive_msg_he" class="large-text" value="<?php echo wisply_v('proactive_msg_he'); ?>">
                    <p class="description">השתמשו ב-<code>{subject}</code> כדי לשלב אוטומטית את שם הדף (לדוגמה: שם המוצר או השירות בעמוד).</p>
                </td>
            </tr>
            <tr>
                <th>הודעה — English</th>
                <td><input type="text" name="proactive_msg_en" class="large-text" value="<?php echo wisply_v('proactive_msg_en'); ?>"></td>
            </tr>
            <tr>
                <th>הודעה — Русский</th>
                <td><input type="text" name="proactive_msg_ru" class="large-text" value="<?php echo wisply_v('proactive_msg_ru'); ?>"></td>
            </tr>
            <tr>
                <th>הודעה — العربية</th>
                <td><input type="text" name="proactive_msg_ar" class="large-text" dir="rtl" value="<?php echo wisply_v('proactive_msg_ar'); ?>"></td>
            </tr>
        </table>

        <h2>🖥️ פתיחה אוטומטית בדסקטופ</h2>
        <table class="form-table">
            <tr>
                <th>הפעלה</th>
                <td>
                    <label>
                        <input type="checkbox" name="desktop_autoopen_enabled" value="1"
                               <?php checked( ( $settings['desktop_autoopen_enabled'] ?? '0' ), '1' ); ?>>
                        פתח את חלון הצ׳אט אוטומטית במחשב (דסקטופ) כדי להניע לפעולה
                    </label>
                    <p class="description">רק במסך רחב (דסקטופ, לא בנייד), פעם אחת לכל גלישה — אם הגולש סוגר, זה לא ייפתח שוב באותה גלישה.</p>
                </td>
            </tr>
            <tr>
                <th>השהיה לפני פתיחה</th>
                <td>
                    <input type="number" name="desktop_autoopen_delay" min="0" max="120"
                           value="<?php echo wisply_v('desktop_autoopen_delay','3'); ?>" class="small-text"> שניות
                </td>
            </tr>
            <tr>
                <th>הודעת פתיחה — עברית</th>
                <td><input type="text" name="desktop_autoopen_msg_he" class="large-text" value="<?php echo wisply_v('desktop_autoopen_msg_he'); ?>"></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — English</th>
                <td><input type="text" name="desktop_autoopen_msg_en" class="large-text" value="<?php echo wisply_v('desktop_autoopen_msg_en'); ?>"></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — Русский</th>
                <td><input type="text" name="desktop_autoopen_msg_ru" class="large-text" value="<?php echo wisply_v('desktop_autoopen_msg_ru'); ?>"></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — العربية</th>
                <td><input type="text" name="desktop_autoopen_msg_ar" class="large-text" dir="rtl" value="<?php echo wisply_v('desktop_autoopen_msg_ar'); ?>"></td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="leads">
        <h2>📝 טופס לידים וסיום שיחה</h2>
        <table class="form-table">
            <tr>
                <th>שדה: שם</th>
                <td>
                    <select name="lead_field_name">
                        <option value="required" <?php echo wisply_sel('lead_field_name','required'); ?>>חובה</option>
                        <option value="optional" <?php echo wisply_sel('lead_field_name','optional'); ?>>רשות</option>
                        <option value="hidden"   <?php echo wisply_sel('lead_field_name','hidden'); ?>>מוסתר</option>
                    </select>
                    <p class="description">מה מוצג בטופס השארת הפרטים ומה חובה למלא.</p>
                </td>
            </tr>
            <tr>
                <th>שדה: טלפון</th>
                <td>
                    <select name="lead_field_phone">
                        <option value="required" <?php echo wisply_sel('lead_field_phone','required'); ?>>חובה</option>
                        <option value="optional" <?php echo wisply_sel('lead_field_phone','optional'); ?>>רשות</option>
                        <option value="hidden"   <?php echo wisply_sel('lead_field_phone','hidden'); ?>>מוסתר</option>
                    </select>
                    <p class="description">מה מוצג בטופס השארת הפרטים ומה חובה למלא.</p>
                </td>
            </tr>
            <tr>
                <th>שדה: אימייל</th>
                <td>
                    <select name="lead_field_email">
                        <option value="required" <?php echo wisply_sel('lead_field_email','required'); ?>>חובה</option>
                        <option value="optional" <?php echo wisply_sel('lead_field_email','optional'); ?>>רשות</option>
                        <option value="hidden"   <?php echo wisply_sel('lead_field_email','hidden'); ?>>מוסתר</option>
                    </select>
                    <p class="description">מה מוצג בטופס השארת הפרטים ומה חובה למלא.</p>
                </td>
            </tr>
            <tr>
                <th>בסיום שיחה</th>
                <td>
                    <select name="conversation_end_action">
                        <option value="lead" <?php echo wisply_sel('conversation_end_action','lead'); ?>>טופס השארת פרטים</option>
                        <option value="call" <?php echo wisply_sel('conversation_end_action','call'); ?>>כפתור התקשרות</option>
                        <option value="both" <?php echo wisply_sel('conversation_end_action','both'); ?>>גם וגם</option>
                        <option value="none" <?php echo wisply_sel('conversation_end_action','none'); ?>>כלום</option>
                    </select>
                    <p class="description">מה יוצג לגולש כשהשיחה מסתיימת.</p>
                </td>
            </tr>
            <tr>
                <th>מקסימום הודעות בשיחה</th>
                <td>
                    <input type="number" name="max_messages" min="0" max="50"
                           value="<?php echo wisply_v('max_messages','0'); ?>" class="small-text">
                    <p class="description">כמה הודעות מהגולש מותרות בשיחה. 0 = ללא הגבלה.</p>
                </td>
            </tr>
            <tr>
                <th>התחלת התכנסות (הודעות לפני הסוף)</th>
                <td>
                    <input type="number" name="wrapup_margin" min="0" max="10"
                           value="<?php echo wisply_v('wrapup_margin','2'); ?>" class="small-text">
                    <p class="description">כמה הודעות לפני המקסימום הבוט מתחיל לסכם ולחתור להשארת פרטים.</p>
                </td>
            </tr>
        </table>

        <h2>📋 הסכמה שיווקית (Opt-In) — חובה משפטית</h2>
        <table class="form-table">
            <tr>
                <th>דרישת הסכמה</th>
                <td>
                    <label>
                        <input type="checkbox" name="consent_required" value="1"
                               <?php checked( ( $settings['consent_required'] ?? '1' ), '1' ); ?>>
                        חובה לסמן הסכמה כדי לשלוח את הטופס (תואם חוק הספאם)
                    </label>
                    <p class="description">ללא סימון — לא יישמר ליד שיווקי. מומלץ להשאיר דלוק.</p>
                </td>
            </tr>
            <tr>
                <th>גרסת נוסח ההסכמה</th>
                <td>
                    <input type="text" name="consent_version" value="<?php echo wisply_v('consent_version','1.0'); ?>" class="small-text">
                    <p class="description">העלו את המספר בכל שינוי נוסח (לתיעוד משפטי לכל ליד).</p>
                </td>
            </tr>
            <tr>
                <th>נוסח ההסכמה — עברית</th>
                <td><textarea name="consent_text_he" rows="3" class="large-text"><?php echo esc_textarea( $settings['consent_text_he'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>נוסח ההסכמה — English</th>
                <td><textarea name="consent_text_en" rows="3" class="large-text"><?php echo esc_textarea( $settings['consent_text_en'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>נוסח ההסכמה — Русский</th>
                <td><textarea name="consent_text_ru" rows="3" class="large-text"><?php echo esc_textarea( $settings['consent_text_ru'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>נוסח ההסכמה — العربية</th>
                <td><textarea name="consent_text_ar" rows="3" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['consent_text_ar'] ?? '' ); ?></textarea></td>
            </tr>
        </table>

        <h2>🚨 הודעת חירום (Escalation)</h2>
        <table class="form-table">
            <tr>
                <th>הודעה — עברית</th>
                <td><textarea name="emergency_msg_he" rows="2" class="large-text"><?php echo esc_textarea( $settings['emergency_msg_he'] ?? '' ); ?></textarea>
                    <p class="description">כשהבוט מזהה מצב חירום רפואי — הוא יעצור ויציג הודעה זו.</p></td>
            </tr>
            <tr>
                <th>הודעה — English</th>
                <td><textarea name="emergency_msg_en" rows="2" class="large-text"><?php echo esc_textarea( $settings['emergency_msg_en'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>הודעה — Русский</th>
                <td><textarea name="emergency_msg_ru" rows="2" class="large-text"><?php echo esc_textarea( $settings['emergency_msg_ru'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>הודעה — العربية</th>
                <td><textarea name="emergency_msg_ar" rows="2" class="large-text" dir="rtl"><?php echo esc_textarea( $settings['emergency_msg_ar'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>טלפון חירום</th>
                <td><input type="text" name="emergency_phone" value="<?php echo wisply_v('emergency_phone','101'); ?>" class="small-text">
                    <p class="description">מופיע ככפתור חיוג בתגובת חירום.</p></td>
            </tr>
            <tr>
                <th>קישור ער"ן</th>
                <td><input type="url" name="emergency_eran_url" value="<?php echo wisply_v('emergency_eran_url','https://www.eran.org.il/'); ?>" class="regular-text" dir="ltr"></td>
            </tr>
            <tr>
                <th>קישור סהר</th>
                <td><input type="url" name="emergency_sahar_url" value="<?php echo wisply_v('emergency_sahar_url','https://sahar.org.il/'); ?>" class="regular-text" dir="ltr">
                    <p class="description">קווי סיוע נפשי שיוצגו ככפתורים בתגובת חירום.</p></td>
            </tr>
        </table>

        <h2>📧 דוחות לידים אוטומטיים</h2>
        <table class="form-table">
            <tr>
                <th>נמעני הדוח</th>
                <td>
                    <textarea name="report_recipients" rows="3" class="large-text" dir="ltr" placeholder="name@example.com, manager@example.com"><?php echo esc_textarea( $settings['report_recipients'] ?? '' ); ?></textarea>
                    <p class="description">מיילים לקבלת דוחות הלידים (מופרדים בפסיק / רווח / שורה). השאירו ריק כדי לא לשלוח.</p>
                </td>
            </tr>
            <tr>
                <th>תדירות</th>
                <td>
                    <label><input type="checkbox" name="report_daily" value="1" <?php checked( ( $settings['report_daily'] ?? '1' ), '1' ); ?>> דוח יומי (כל בוקר)</label><br>
                    <label><input type="checkbox" name="report_weekly" value="1" <?php checked( ( $settings['report_weekly'] ?? '1' ), '1' ); ?>> דוח שבועי (יום ב׳ בבוקר)</label>
                    <p class="description">כל דוח כולל את הלידים החדשים מהתקופה, מחולקים ל🎯 שיווקי / 💼 דרושים, עם קובץ CSV מצורף.</p>
                </td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="shop">

        <h2>🛒 מודול חנות (WooCommerce)</h2>
        <?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
            <p class="description" style="color:#b32d2e">WooCommerce לא מותקן באתר — המודול לא יפעל.</p>
        <?php endif; ?>
        <table class="form-table">
            <tr>
                <th>הפעלה</th>
                <td>
                    <label>
                        <input type="checkbox" name="woo_enabled" value="1"
                               <?php checked( ( $settings['woo_enabled'] ?? '0' ), '1' ); ?>>
                        הפעל מענה על מוצרים מהחנות
                    </label>
                    <p class="description">הבוט יענה על מוצרים, מחירים, וריאציות ומלאי — נתונים חיים מ-WooCommerce. כולל שאילתות חכמות: "מה יש עד 300 שח", "הכי זול", "הכי נמכר".</p>
                </td>
            </tr>
            <tr>
                <th>מספר מוצרים בתשובה</th>
                <td>
                    <input type="number" name="woo_max_products" min="1" max="12"
                           value="<?php echo wisply_v('woo_max_products','8'); ?>" class="small-text">
                    <p class="description">כמה כרטיסי מוצר יוצגו לכל היותר בתשובה אחת.</p>
                </td>
            </tr>
            <tr>
                <th>הצגת מלאי</th>
                <td>
                    <label>
                        <input type="checkbox" name="woo_show_stock" value="1"
                               <?php checked( ( $settings['woo_show_stock'] ?? '1' ), '1' ); ?>>
                        הצג תגית מלאי בכרטיס המוצר
                    </label>
                    <p class="description">הצג תגית "במלאי / אזל" בכרטיס המוצר.</p>
                </td>
            </tr>
            <tr>
                <th>חיפוש לפי תמונה</th>
                <td>
                    <label>
                        <input type="checkbox" name="woo_visual_search" value="1"
                               <?php checked( ( $settings['woo_visual_search'] ?? '0' ), '1' ); ?>>
                        אפשר חיפוש מוצרים לפי תמונה
                    </label>
                    <p class="description">הגולש מעלה תמונה והבוט מוצא מוצרים דומים (דורש מפתח OpenAI).</p>
                </td>
            </tr>
            <tr>
                <th>התאמת מוצר משלים</th>
                <td>
                    <label>
                        <input type="checkbox" name="woo_bundle_enabled" value="1"
                               <?php checked( ( $settings['woo_bundle_enabled'] ?? '1' ), '1' ); ?>>
                        הצע לגולש התאמת מוצר משלים (Cross-sell)
                    </label>
                    <p class="description">אחרי הצגת מוצרים, הבוט יציע כפתור "שאתאים לך מוצר משלים?". בלחיצה הוא מתשאל בקצרה (למי, לאיזה אירוע, סגנון) וממליץ על פריט משלים אמיתי מהחנות.</p>
                </td>
            </tr>
            <tr>
                <th>בדיקת סטטוס הזמנה</th>
                <td>
                    <label>
                        <input type="checkbox" name="woo_order_status_enabled" value="1"
                               <?php checked( ( $settings['woo_order_status_enabled'] ?? '1' ), '1' ); ?>>
                        אפשר ללקוח לבדוק סטטוס הזמנה בצ׳אט
                    </label>
                    <p class="description">הלקוח מזין מספר הזמנה + המייל שאיתו הזמין, ומקבל את הסטטוס (בהכנה / נשלחה), הפריטים, הסכום ומספר מעקב אם קיים. שני הפרטים חייבים להתאים, כך שמייל בלבד לא חושף הזמנות של אף אחד.</p>
                </td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="design">

        <div class="wisply-preview" id="wisply-preview">
            <div class="wisply-preview-label">תצוגה מקדימה חיה</div>
            <div class="wp-chat">
                <div class="wp-head">
                    <div class="wp-av" id="wp-av"></div>
                    <div class="wp-name" id="wp-name"><?php echo esc_html( $settings['widget_title_he'] ?? ( $product_name ) ); ?></div>
                </div>
                <div class="wp-body">
                    <div class="wp-bub" id="wp-greet"><?php echo esc_html( mb_substr( (string) ( $settings['greeting_he'] ?? 'שלום, איך אפשר לעזור?' ), 0, 120 ) ); ?></div>
                </div>
            </div>
            <div class="wp-fab"><span id="wp-fab-ico"><svg viewBox="0 0 24 24" fill="#fff"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2z"/></svg></span></div>
        </div>

        <?php
        $wisply_lang_names = [ 'he' => 'עברית', 'en' => 'English', 'ru' => 'Русский', 'ar' => 'العربية' ];
        $wisply_cur_enabled = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $settings['enabled_langs'] ?? '' ) ) ) ) );
        $wisply_cur_enabled = array_values( array_intersect( array_keys( $wisply_lang_names ), $wisply_cur_enabled ) );
        if ( empty( $wisply_cur_enabled ) ) {
            $wisply_site_lang = substr( (string) determine_locale(), 0, 2 );
            $wisply_cur_enabled = [ isset( $wisply_lang_names[ $wisply_site_lang ] ) ? $wisply_site_lang : 'he' ];
        }
        $wisply_cur_default = (string) ( $settings['default_lang'] ?? '' );
        if ( ! in_array( $wisply_cur_default, $wisply_cur_enabled, true ) ) $wisply_cur_default = $wisply_cur_enabled[0];
        $wisply_max_langs = class_exists( 'Wisply_License' ) ? Wisply_License::get_instance()->max_langs() : 1;
        ?>
        <h2>🌐 שפות</h2>
        <p class="description" style="margin:0 0 8px">בחרו באילו שפות הבוט ייתן מענה. אפשר שפה אחת, כמה, או את כולן. הבוט יציג בורר שפה לגולש רק כשמסומנת יותר משפה אחת.
            <br>התכנית הנוכחית מאפשרת עד <strong id="wisply-max-langs" data-max="<?php echo (int) $wisply_max_langs; ?>"><?php echo (int) $wisply_max_langs; ?></strong> שפות פעילות.
            <?php if ( $wisply_max_langs < count( $wisply_lang_names ) ) : ?>לשפות נוספות יש לשדרג תכנית.<?php endif; ?></p>
        <table class="form-table">
            <tr>
                <th>שפות פעילות</th>
                <td id="wisply-langs-box">
                    <?php foreach ( $wisply_lang_names as $code => $label ) :
                        $on = in_array( $code, $wisply_cur_enabled, true ); ?>
                        <label style="display:inline-flex;align-items:center;gap:6px;margin:0 0 6px 18px">
                            <input type="checkbox" class="wisply-lang-cb" name="enabled_langs[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $on ); ?>>
                            <?php echo esc_html( $label ); ?> <code style="opacity:.6"><?php echo esc_html( $code ); ?></code>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th>שפת ברירת מחדל</th>
                <td>
                    <select name="default_lang" id="wisply-default-lang">
                        <?php foreach ( $wisply_lang_names as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $wisply_cur_default, $code ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">השפה שבה הצ׳אט נפתח (חייבת להיות מסומנת כשפה פעילה).</p>
                </td>
            </tr>
        </table>

        <h2>🎨 עיצוב Widget</h2>
        <table class="form-table">
            <tr>
                <th>צבע ראשי</th>
                <td><input type="color" name="primary_color" value="<?php echo wisply_v('primary_color','#00A3A3'); ?>"></td>
            </tr>
            <tr>
                <th>צבע משני</th>
                <td><input type="color" name="secondary_color" value="<?php echo wisply_v('secondary_color','#007878'); ?>"></td>
            </tr>
            <tr>
                <th>מיקום הבועה</th>
                <td>
                    <select name="bubble_position">
                        <option value="bottom-right" <?php echo wisply_sel('bubble_position','bottom-right'); ?>>ימין-תחתון (מומלץ)</option>
                        <option value="bottom-left"  <?php echo wisply_sel('bubble_position','bottom-left'); ?>>שמאל-תחתון</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>אייקון הבוט (אוואטר)</th>
                <td>
                    <?php
                    wp_enqueue_media();
                    $wisply_avatars = [
                        'robot'   => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M13 2.4a1.4 1.4 0 10-2 1.25V5.2H8.2A3.7 3.7 0 004.5 8.9v5.4a3.7 3.7 0 003.7 3.7h7.6a3.7 3.7 0 003.7-3.7V8.9a3.7 3.7 0 00-3.7-3.7H13V3.65A1.4 1.4 0 0013 2.4zM9.6 10.4a1.7 1.7 0 100 3.4 1.7 1.7 0 000-3.4zm4.8 0a1.7 1.7 0 100 3.4 1.7 1.7 0 000-3.4z"/><path d="M2.6 10.5a1 1 0 011 1v2a1 1 0 11-2 0v-2a1 1 0 011-1zm18.8 0a1 1 0 011 1v2a1 1 0 11-2 0v-2a1 1 0 011-1z"/></svg>',
                        'sparkle' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.5 2.3c.5 3.7 2.2 5.4 5.9 5.9-3.7.5-5.4 2.2-5.9 5.9-.5-3.7-2.2-5.4-5.9-5.9 3.7-.5 5.4-2.2 5.9-5.9z"/><path d="M18 13c.25 1.9 1.1 2.75 3 3-1.9.25-2.75 1.1-3 3-.25-1.9-1.1-2.75-3-3 1.9-.25 2.75-1.1 3-3z"/></svg>',
                        'chat'    => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M6.5 3.5A2.75 2.75 0 003.75 6.25v8A2.75 2.75 0 006.5 17H8v3.1a1 1 0 001.64.77L14 17h3.5a2.75 2.75 0 002.75-2.75v-8A2.75 2.75 0 0017.5 3.5h-11zM9 8.4a1.3 1.3 0 100 2.6 1.3 1.3 0 000-2.6zm6 0a1.3 1.3 0 100 2.6 1.3 1.3 0 000-2.6zm-6.2 4.3a1 1 0 00-1.6 1.15 5.5 5.5 0 009.6 0 1 1 0 10-1.7-1.05 3.5 3.5 0 01-6.3 0z"/></svg>',
                        'headset' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a8.5 8.5 0 00-8.5 8.5v.5a1 1 0 001 1H6v-1.5a6 6 0 0112 0V17a2.5 2.5 0 01-2.5 2.5h-2.1a1.4 1.4 0 100 1.5H15.5A4 4 0 0019.4 17.6 1.5 1.5 0 0020.5 12v-.5A8.5 8.5 0 0012 3z"/><rect x="3" y="11.6" width="4" height="6.4" rx="2"/><rect x="17" y="11.6" width="4" height="6.4" rx="2"/></svg>',
                        'person'  => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M12 2.4a9.6 9.6 0 100 19.2 9.6 9.6 0 000-19.2zM8.7 9a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9zm6.6 0a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9zM7.9 14.1a1 1 0 00-1.65 1.15 7 7 0 0011.5 0A1 1 0 1016.1 14.1a5 5 0 01-8.2 0z"/></svg>',
                        'heart'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.2S3.3 15.6 3.3 9.6C3.3 6.4 5.8 4.2 8.6 4.2c1.8 0 3.1.9 3.4 2.1.3-1.2 1.6-2.1 3.4-2.1 2.8 0 5.3 2.2 5.3 5.4 0 6-8.7 11.6-8.7 11.6z"/></svg>',
                        'store'   => '<svg viewBox="0 0 24 24" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><path d="M8 6a4 4 0 118 0h2.2a1.5 1.5 0 011.49 1.33l1.2 11A1.5 1.5 0 0119.4 20H4.6a1.5 1.5 0 01-1.49-1.67l1.2-11A1.5 1.5 0 015.8 6H8zm2 0h4a2 2 0 10-4 0zm-.5 4.5a1 1 0 10-2 0 4.5 4.5 0 009 0 1 1 0 10-2 0 2.5 2.5 0 01-5 0z"/></svg>',
                        'spark'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.7 2.2a.6.6 0 00-1.08-.14L5.3 12.4a.7.7 0 00.57 1.1H10l-1.6 8a.6.6 0 001.08.46l7.3-10.3a.7.7 0 00-.57-1.1H12l1.7-8.36z"/></svg>',
                    ];
                    $wisply_cur_av  = $settings['bot_avatar'] ?? 'robot';
                    $wisply_av_url  = $settings['bot_avatar_url'] ?? '';
                    ?>
                    <?php
                    // Illustrated character avatars bundled with the plugin (DiceBear, CC0).
                    $wisply_av_base = WISPLY_PLUGIN_URL . 'public/avatars/';
                    $wisply_chars   = [
                        'av-1', 'av-2', 'av-3', 'av-4', 'av-5', 'av-6',
                        'av-7', 'av-8', 'av-9', 'av-10', 'av-11', 'av-12',
                    ];
                    // Back-compat: if the saved value is a legacy icon key, keep it selectable.
                    $wisply_is_legacy = isset( $wisply_avatars[ $wisply_cur_av ] );
                    ?>
                    <div class="wisply-avatars">
                        <?php foreach ( $wisply_chars as $wisply_k ) : ?>
                        <label class="wisply-av wisply-av--char">
                            <input type="radio" name="bot_avatar" value="<?php echo esc_attr( $wisply_k ); ?>" <?php checked( $wisply_cur_av, $wisply_k ); ?>>
                            <span class="wisply-av-badge"><img src="<?php echo esc_url( $wisply_av_base . $wisply_k . '.png?ver=' . WISPLY_VERSION ); ?>" alt=""></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if ( $wisply_is_legacy ) : // preserve a previously chosen icon preset ?>
                        <label class="wisply-av">
                            <input type="radio" name="bot_avatar" value="<?php echo esc_attr( $wisply_cur_av ); ?>" checked>
                            <span class="wisply-av-badge"><?php echo $wisply_avatars[ $wisply_cur_av ]; // phpcs:ignore ?></span>
                        </label>
                        <?php endif; ?>
                        <label class="wisply-av wisply-av--custom">
                            <input type="radio" name="bot_avatar" value="custom" <?php checked( $wisply_cur_av, 'custom' ); ?>>
                            <span class="wisply-av-badge" id="wisply-av-preview"><?php
                                if ( $wisply_av_url !== '' ) { echo '<img src="' . esc_url( $wisply_av_url ) . '" alt="">'; }
                                else { echo '<span class="wisply-av-plus">+</span>'; }
                            ?></span>
                        </label>
                    </div>
                    <p style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <input type="text" name="bot_avatar_url" id="wisply-av-url" class="regular-text" dir="ltr"
                               value="<?php echo esc_attr( $wisply_av_url ); ?>" placeholder="https://… כתובת תמונת אוואטר" style="max-width:340px">
                        <button type="button" class="button" id="wisply-av-upload">העלאת תמונה</button>
                    </p>
                    <p class="description">בחר אייקון מוכן לבוט, או העלה/הדבק תמונה משלך (לוגו/פרצוף). האוואטר מוצג בכותרת הצ׳אט ובבועית ההזמנה.</p>
                </td>
            </tr>
        </table>

        <h2>💬 הודעות פתיחה</h2>
        <table class="form-table">
            <tr>
                <th>כותרת Widget — עברית</th>
                <td><input type="text" name="widget_title_he" class="regular-text" value="<?php echo wisply_v('widget_title_he'); ?>"></td>
            </tr>
            <tr>
                <th>כותרת Widget — English</th>
                <td><input type="text" name="widget_title_en" class="regular-text" value="<?php echo wisply_v('widget_title_en'); ?>"></td>
            </tr>
            <tr>
                <th>כותרת Widget — Русский</th>
                <td><input type="text" name="widget_title_ru" class="regular-text" value="<?php echo wisply_v('widget_title_ru'); ?>"></td>
            </tr>
            <tr>
                <th>כותרת Widget — العربية</th>
                <td><input type="text" name="widget_title_ar" class="regular-text" dir="rtl" value="<?php echo wisply_v('widget_title_ar'); ?>"></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — עברית</th>
                <td><textarea name="greeting_he" rows="2" class="regular-text"><?php echo esc_textarea( $settings['greeting_he'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — English</th>
                <td><textarea name="greeting_en" rows="2" class="regular-text"><?php echo esc_textarea( $settings['greeting_en'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — Русский</th>
                <td><textarea name="greeting_ru" rows="2" class="regular-text"><?php echo esc_textarea( $settings['greeting_ru'] ?? '' ); ?></textarea></td>
            </tr>
            <tr>
                <th>הודעת פתיחה — العربية</th>
                <td><textarea name="greeting_ar" rows="2" class="regular-text" dir="rtl"><?php echo esc_textarea( $settings['greeting_ar'] ?? '' ); ?></textarea></td>
            </tr>
        </table>

        <h2>📞 פרטי קשר ומיקום</h2>
        <table class="form-table">
            <tr>
                <th>טלפון (Click-to-Call)</th>
                <td><input type="text" name="phone" class="regular-text" value="<?php echo wisply_v('phone'); ?>" placeholder="+972-X-XXXXXXX"></td>
            </tr>
            <tr>
                <th>קישור למפה</th>
                <td><input type="url" name="map_url" class="regular-text" value="<?php echo wisply_v('map_url'); ?>" placeholder="https://maps.google.com/..."></td>
            </tr>
        </table>

        </div><div class="wisply-pane" data-pane="advanced">
        <h2>🔑 רישיון</h2>
        <table class="form-table">
            <tr>
                <th>מפתח רישיון</th>
                <td>
                    <input type="text" name="license_key" dir="ltr" class="regular-text"
                           placeholder="WSP-XXXX-XXXX-XXXX-XXXX"
                           value="<?php echo wisply_v('license_key'); ?>">
                    <?php
                    $lic_status = $settings['license_status'] ?? '';
                    $lic_msg    = (string) ( $settings['license_message'] ?? '' );
                    if ( $lic_status === 'active' ) : ?>
                        <p style="color:#0a7a30;font-weight:600;margin:8px 0 0">✅ הרישיון פעיל</p>
                    <?php elseif ( $lic_status === 'invalid' ) : ?>
                        <p style="color:#c0392b;font-weight:600;margin:8px 0 0">❌ <?php echo esc_html( $lic_msg !== '' ? $lic_msg : 'הרישיון לא אומת מול השרת.' ); ?></p>
                    <?php else : ?>
                        <p style="color:#666;margin:8px 0 0">המפתח יאומת מול השרת אחרי שמירה.</p>
                    <?php endif; ?>
                    <p class="description">
                        מפתח הרישיון מפעיל עדכונים אוטומטיים של התוסף ישירות מלוח הבקרה של וורדפרס,
                        והוא צמוד למספר האתרים שנכללים בחבילה שרכשתם. שימוש באותו מפתח ביותר אתרים
                        ממה שהחבילה מאפשרת לא יאושר.
                    </p>
                </td>
            </tr>
        </table>

        <h2>🗑️ שמירת נתונים</h2>
        <table class="form-table">
            <tr>
                <th>שמירת שיחות (ימים)</th>
                <td>
                    <input type="number" name="conversation_ttl_days" min="7" max="365"
                           value="<?php echo wisply_v('conversation_ttl_days','60'); ?>" class="small-text">
                </td>
            </tr>
        </table>
        </div><!-- /.wisply-pane advanced -->
        </div><!-- /.wisply-content -->
        </div><!-- /.wisply-layout -->

        <p class="submit">
            <button type="submit" class="button button-primary button-large">שמור הגדרות</button>
        </p>
    </form>
</div>

<style>
/* ══════════════ Wisply admin — modern refresh ══════════════ */
.wisply-admin {
  --w-ink:#0f1729; --w-mut:#5b6472; --w-line:#e6e9ef; --w-bg:#f6f8fb;
  --w-brand:#00A3A3; --w-brand-d:#007878; --w-card:#fff; --w-radius:14px;
  max-width:1120px;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Open Sans Hebrew",sans-serif;
}
.wisply-admin * { box-sizing:border-box; }
.wisply-title { display:flex; align-items:center; gap:12px; font-size:26px; font-weight:800; color:var(--w-ink); margin:14px 0 20px; letter-spacing:-.4px; }
.wisply-title-dot { width:12px; height:12px; border-radius:50%; background:linear-gradient(135deg,var(--w-brand),var(--w-brand-d)); box-shadow:0 0 0 4px rgba(0,163,163,.16); }
.wisply-title-sub { font-size:15px; font-weight:600; color:var(--w-mut); }

/* Status hero + onboarding */
.wisply-hero {
  display:flex; align-items:center; gap:20px; flex-wrap:wrap;
  background:var(--w-card); border:1px solid var(--w-line); border-radius:var(--w-radius);
  padding:20px 22px; margin:0 0 20px; box-shadow:0 1px 2px rgba(16,24,40,.04);
}
.wisply-hero.is-ready { background:linear-gradient(120deg,#effaf4,#fff 60%); border-color:#bfe8d2; }
.wisply-hero.is-setup { background:linear-gradient(120deg,#fff7ec,#fff 60%); border-color:#f3dcae; }
.wisply-hero-ico {
  flex:none; width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  font-size:24px; color:#fff; font-weight:800;
}
.wisply-hero.is-ready .wisply-hero-ico { background:linear-gradient(135deg,#22b07d,#0f9d64); }
.wisply-hero.is-setup .wisply-hero-ico { background:linear-gradient(135deg,#e6a23c,#d98324); }
.wisply-hero-main { flex:1; min-width:260px; }
.wisply-hero-main h2 { margin:0 0 4px; font-size:18px; font-weight:800; color:var(--w-ink); }
.wisply-hero-main p { margin:0 0 12px; color:var(--w-mut); font-size:13.5px; }
.wisply-onb { display:flex; flex-wrap:wrap; gap:8px; }
.wisply-onb-step {
  display:inline-flex; align-items:center; gap:8px; cursor:pointer;
  background:#fff; border:1px solid var(--w-line); border-radius:999px;
  padding:7px 14px; font-size:12.5px; font-weight:600; color:var(--w-ink);
  transition:border-color .15s, transform .15s, box-shadow .15s;
}
.wisply-onb-step:hover { transform:translateY(-1px); box-shadow:0 3px 8px rgba(16,24,40,.1); border-color:#cfd6e2; }
.wisply-onb-step em { font-style:normal; color:var(--w-mut); font-weight:500; }
.wisply-onb-check {
  width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  font-size:11px; color:#fff; background:#cbd3df;
}
.wisply-onb-step.is-done { border-color:#bfe8d2; background:#f3fbf6; color:#0f9d64; }
.wisply-onb-step.is-done .wisply-onb-check { background:#22b07d; }
.wisply-hero-stat { flex:none; text-align:center; padding-inline-start:20px; border-inline-start:1px solid var(--w-line); }
.wisply-hero-stat b { display:block; font-size:30px; font-weight:800; color:var(--w-brand-d); line-height:1; }
.wisply-hero-stat span { font-size:11.5px; color:var(--w-mut); }
@media (max-width:600px){ .wisply-hero-stat { border:0; padding:0; } }

/* Panels become cards */
.wisply-admin .wisply-pane {
  background:var(--w-card); border:1px solid var(--w-line); border-radius:var(--w-radius);
  padding:8px 24px 22px; box-shadow:0 1px 2px rgba(16,24,40,.04);
}
.wisply-admin .wisply-pane h2 {
  font-size:15px; font-weight:800; color:var(--w-ink); letter-spacing:-.2px;
  margin:26px 0 6px; padding-top:20px; border-top:1px solid var(--w-line);
}
.wisply-admin .wisply-pane > h2:first-of-type,
.wisply-admin .wisply-pane > *:first-child + h2 { border-top:0; padding-top:6px; margin-top:8px; }

/* Form rows: a real 2-column grid (label / control) so controls FILL the row and the
   RTL layout never leaves a dead gap on one side. 8pt rhythm, hairline between rows. */
.wisply-admin .form-table,
.wisply-admin .form-table tbody { display:block; width:100%; margin:0; }
.wisply-admin .form-table tr {
  display:grid; grid-template-columns:186px minmax(0,1fr); gap:4px 32px;
  align-items:start; padding:20px 2px; border-bottom:1px solid var(--w-line);
}
.wisply-admin .form-table tr:last-child { border-bottom:0; }
.wisply-admin .form-table th {
  width:auto; padding:9px 0 0; vertical-align:top;
  font-weight:600; color:var(--w-ink); font-size:13.5px; line-height:1.4;
}
.wisply-admin .form-table td { padding:0; max-width:660px; }
.wisply-admin .description, .wisply-admin .form-table p.description {
  color:var(--w-mut); font-size:12.5px; line-height:1.6; margin-top:8px;
}
@media (max-width:900px){ .wisply-admin .form-table tr { grid-template-columns:1fr; gap:6px; } }

/* Inputs: 40px targets, soft border, teal focus ring, fill the control column. */
.wisply-admin input[type=text], .wisply-admin input[type=url], .wisply-admin input[type=email],
.wisply-admin input[type=password], .wisply-admin input[type=number], .wisply-admin select, .wisply-admin textarea {
  border:1px solid #d7dce6; border-radius:10px; padding:10px 13px; font-size:13.5px; line-height:1.45;
  background:#fff; color:var(--w-ink); box-shadow:none; transition:border-color .15s, box-shadow .15s;
}
.wisply-admin .form-table td input[type=text], .wisply-admin .form-table td input[type=url],
.wisply-admin .form-table td input[type=email], .wisply-admin .form-table td input[type=password],
.wisply-admin .form-table td select, .wisply-admin .form-table td textarea,
.wisply-admin .form-table td .regular-text, .wisply-admin .form-table td .large-text { width:100%; max-width:100%; }
.wisply-admin .form-table td input[type=number] { width:110px; }
.wisply-admin textarea { line-height:1.6; }
.wisply-admin input:focus, .wisply-admin select:focus, .wisply-admin textarea:focus {
  border-color:var(--w-brand); box-shadow:0 0 0 3px rgba(0,163,163,.18); outline:none;
}
.wisply-admin input::placeholder, .wisply-admin textarea::placeholder { color:#9aa4b2; }
.wisply-admin input[type=color] { border-radius:10px; border:1px solid #d7dce6; width:56px; height:40px; padding:3px; cursor:pointer; }

/* Buttons */
.wisply-admin .button, .wisply-admin .button-secondary {
  border-radius:10px; border:1px solid #d7dce6; background:#fff; color:var(--w-ink);
  font-weight:600; padding:8px 16px; height:auto; box-shadow:none; transition:all .15s;
}
.wisply-admin .button:hover { border-color:var(--w-brand); color:var(--w-brand-d); background:#f0fafa; }
.wisply-admin .button-primary, .wisply-admin #submit, .wisply-admin p.submit .button-primary {
  background:linear-gradient(135deg,var(--w-brand),var(--w-brand-d)); border:0; color:#fff;
  border-radius:11px; font-weight:700; padding:12px 30px; font-size:14px;
  box-shadow:0 10px 24px -10px rgba(0,120,120,.75);
}
.wisply-admin .button-primary:hover { transform:translateY(-1px); box-shadow:0 14px 28px -10px rgba(0,120,120,.85); }
.wisply-admin p.submit {
  position:sticky; bottom:0; z-index:5; margin:20px 0 0; padding:16px 0 12px;
  background:linear-gradient(0deg,var(--w-bg) 68%,transparent);
}

/* Section heading: accent bar (visual-hierarchy: weight + colour + position) */
.wisply-admin .wisply-pane h2::before {
  content:""; width:4px; height:17px; border-radius:3px; flex:none;
  background:linear-gradient(var(--w-brand),var(--w-brand-d));
}
.wisply-admin .wisply-pane h2 { display:flex; align-items:center; gap:11px; }

/* Live preview: an in-flow card at the top of the Design tab. It used to be a fixed
   floating card, which overlapped the sticky Save button — now it sits inside the
   settings panel like any other block. */
.wisply-preview {
  width:290px; max-width:100%; margin:4px 0 22px;
  background:var(--w-card); border:1px solid var(--w-line); border-radius:20px; overflow:hidden;
  box-shadow:0 10px 26px -16px rgba(16,24,40,.35); --w-brand:#00A3A3;
}
.wisply-preview-label { font-size:10.5px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--w-mut); padding:13px 16px 0; }
.wp-chat { margin:12px; border:1px solid var(--w-line); border-radius:16px; overflow:hidden; background:#f7f9fb; }
.wp-head { background:var(--w-brand); color:#fff; padding:13px 15px; display:flex; align-items:center; gap:11px; direction:rtl; }
.wp-av { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; overflow:hidden; }
.wp-av svg { width:20px; height:20px; }
.wp-av img { width:100%; height:100%; object-fit:cover; }
.wp-name { font-weight:700; font-size:13.5px; }
.wp-body { padding:15px; min-height:118px; direction:rtl; }
.wp-bub { background:#fff; border:1px solid var(--w-line); border-radius:13px; padding:10px 13px; font-size:12.5px; line-height:1.55; color:var(--w-ink); max-width:90%; box-shadow:0 1px 2px rgba(16,24,40,.05); }
.wp-fab { display:flex; justify-content:flex-start; padding:0 15px 15px; }
.wp-fab span { width:46px; height:46px; border-radius:50%; background:var(--w-brand); display:flex; align-items:center; justify-content:center; color:#fff; box-shadow:0 10px 20px -8px rgba(0,120,120,.85); }
.wp-fab svg { width:23px; height:23px; }

/* Sidebar layout (StoreAgent-style): sticky nav column + content column. */
.wisply-layout { display:grid; grid-template-columns:250px minmax(0,1fr); gap:22px; align-items:start; }
.wisply-content { min-width:0; }
@media (max-width:900px){ .wisply-layout { grid-template-columns:1fr; } }
.wisply-nav {
  position:sticky; top:40px; display:flex; flex-direction:column; gap:3px;
  background:var(--w-card); border:1px solid var(--w-line); border-radius:16px;
  padding:10px; box-shadow:0 1px 2px rgba(16,24,40,.04);
}
@media (max-width:900px){ .wisply-nav { position:static; flex-direction:row; flex-wrap:wrap; } }
.wisply-nav-btn {
  display:flex; align-items:center; gap:12px; text-align:start; cursor:pointer; position:relative;
  background:transparent; border:0; border-radius:12px; padding:11px 12px; width:100%; color:var(--w-ink);
  transition:background .15s, color .15s;
}
.wisply-nav-btn:hover { background:#f4f6fa; }
.wisply-nav-ico {
  flex:none; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center;
  font-size:17px; background:#eef2f7; transition:background .15s;
}
.wisply-nav-txt { display:flex; flex-direction:column; line-height:1.25; min-width:0; }
.wisply-nav-txt b { font-size:13.5px; font-weight:700; letter-spacing:-.2px; }
.wisply-nav-txt em { font-style:normal; font-size:11px; color:var(--w-mut); margin-top:1px; }
.wisply-nav-btn.active { background:linear-gradient(90deg, rgba(0,163,163,.13), rgba(0,163,163,.03)); }
.wisply-nav-btn.active .wisply-nav-ico { background:rgba(0,163,163,.2); }
.wisply-nav-btn.active .wisply-nav-txt b { color:var(--w-brand-d); }
.wisply-nav-btn.active::before {
  content:""; position:absolute; inset-inline-start:-10px; top:15%; bottom:15%; width:3px;
  border-radius:0 3px 3px 0; background:var(--w-brand);
}
.wisply-pane { display:none; }
.wisply-pane.active { display:block; animation:wisplyFade .18s ease; }
@keyframes wisplyFade { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }

/* ── Avatar picker (collect.chat-style swatches) ── */
.wisply-avatars { display:flex; flex-wrap:wrap; gap:12px; }
.wisply-av { cursor:pointer; margin:0; }
.wisply-av input { position:absolute; opacity:0; width:0; height:0; }
.wisply-av-badge {
  display:flex; align-items:center; justify-content:center;
  width:52px; height:52px; border-radius:50%;
  background:#00A3A3; color:#fff; overflow:hidden;
  border:3px solid transparent; box-shadow:0 1px 3px rgba(16,24,40,.12);
  transition:transform .15s ease, border-color .15s ease, box-shadow .15s ease;
}
.wisply-av-badge svg { width:26px; height:26px; }
.wisply-av-badge img { width:100%; height:100%; object-fit:cover; }
.wisply-av:hover .wisply-av-badge { transform:translateY(-2px); box-shadow:0 4px 10px rgba(16,24,40,.18); }
.wisply-av input:checked + .wisply-av-badge { border-color:#0b3b3b; transform:translateY(-2px); box-shadow:0 0 0 3px rgba(0,163,163,.25); }
.wisply-av input:focus-visible + .wisply-av-badge { outline:2px solid #00A3A3; outline-offset:2px; }
.wisply-av--custom .wisply-av-badge { background:#eef2f6; color:#7a8699; border-style:dashed; border-color:#c7d0dc; }
.wisply-av--custom input:checked + .wisply-av-badge { border-style:solid; }
.wisply-av-plus { font-size:26px; font-weight:300; line-height:1; }
</style>
<script>
(function() {
    const btns  = document.querySelectorAll('.wisply-nav-btn');
    const panes = document.querySelectorAll('.wisply-pane');
    function show(target) {
        btns.forEach(b => b.classList.toggle('active', b.dataset.target === target));
        panes.forEach(p => p.classList.toggle('active', p.dataset.pane === target));
        try { localStorage.setItem('wisply_settings_tab', target); } catch (e) {}
    }
    btns.forEach(b => b.addEventListener('click', () => show(b.dataset.target)));
    let saved = 'brand';
    try { saved = localStorage.getItem('wisply_settings_tab') || 'brand'; } catch (e) {}
    if (document.querySelector('.wisply-pane[data-pane="' + saved + '"]')) show(saved);
})();
</script>

<script>
/* Avatar picker: media upload + keep the custom swatch in sync with the URL field. */
(function() {
    const urlField = document.getElementById('wisply-av-url');
    const preview  = document.getElementById('wisply-av-preview');
    const uploadBtn = document.getElementById('wisply-av-upload');
    const customRadio = document.querySelector('.wisply-av--custom input');
    if (!urlField) return;

    function paint(url) {
        if (preview) preview.innerHTML = url
            ? '<img src="' + url + '" alt="">'
            : '<span class="wisply-av-plus">+</span>';
    }
    function selectCustom() { if (customRadio) customRadio.checked = true; }

    urlField.addEventListener('input', function () {
        paint(this.value.trim());
        if (this.value.trim()) selectCustom();
    });

    if (uploadBtn) {
        let frame;
        uploadBtn.addEventListener('click', function (e) {
            e.preventDefault();
            // Check wp.media at CLICK time, not load time — the media scripts are enqueued
            // in the footer and aren't ready when this IIFE first runs. (That timing bug is
            // why the upload button did nothing.)
            if (!(window.wp && window.wp.media)) {
                urlField.focus();
                urlField.placeholder = 'הדביקו כתובת תמונה (מנהל המדיה לא נטען)';
                return;
            }
            if (frame) { frame.open(); return; }
            frame = window.wp.media({ title: 'בחירת אוואטר', button: { text: 'השתמש בתמונה' }, multiple: false });
            frame.on('select', function () {
                const att = frame.state().get('selection').first().toJSON();
                const url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                urlField.value = url;
                paint(url);
                selectCustom();
            });
            frame.open();
        });
    }
})();
</script>

<script>
/* Live widget preview + onboarding step navigation. */
(function() {
    const prev = document.getElementById('wisply-preview');
    const $ = function (id) { return document.getElementById(id); };

    // Onboarding chips jump to their tab.
    document.querySelectorAll('.wisply-onb-step[data-goto]').forEach(function (b) {
        b.addEventListener('click', function () {
            const t = b.dataset.goto;
            const tab = document.querySelector('.wisply-nav-btn[data-target="' + t + '"]');
            if (tab) { tab.click(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
        });
    });

    if (!prev) return;

    // Brand colour → live.
    const color = document.querySelector('input[name="primary_color"]');
    function paintColor() { if (color) prev.style.setProperty('--w-brand', color.value); }
    if (color) { color.addEventListener('input', paintColor); paintColor(); }

    // Avatar → mirror the selected swatch into the preview head.
    const avEl = $('wp-av');
    function paintAvatar() {
        const checked = document.querySelector('.wisply-av input:checked');
        if (!checked || !avEl) return;
        const badge = checked.parentElement.querySelector('.wisply-av-badge');
        if (badge) avEl.innerHTML = badge.innerHTML.replace('<span class="wisply-av-plus">+</span>', '');
    }
    document.querySelectorAll('.wisply-av input').forEach(function (r) { r.addEventListener('change', paintAvatar); });
    const avUrl = $('wisply-av-url');
    if (avUrl) avUrl.addEventListener('input', function () { setTimeout(paintAvatar, 30); });
    paintAvatar();

    // Title + greeting → live.
    const title = document.querySelector('input[name="widget_title_he"]');
    if (title) title.addEventListener('input', function () { const n = $('wp-name'); if (n) n.textContent = this.value || 'Wisply'; });
    const greet = document.querySelector('textarea[name="greeting_he"], input[name="greeting_he"]');
    if (greet) greet.addEventListener('input', function () { const g = $('wp-greet'); if (g) g.textContent = (this.value || '').slice(0, 120); });
})();
</script>

<script>
(function() {
    // (Provider/key selection removed in 2.16.0 — AI runs through the Wisply
    // proxy with the licence key, so there is nothing to toggle here.)

    // ── Voice preview: hear the selected voice immediately (no save needed) ──
    // AJAX URL + nonce are injected straight from PHP so the button works even
    // though the localized admin script loads later in the page footer.
    const AJAX_URL    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const VOICE_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wisply_admin_nonce' ) ); ?>;

    // ── System check: pinpoint exactly what's broken ──
    const sysBtn = document.getElementById('wisply-syscheck');
    const sysOut = document.getElementById('wisply-syscheck-results');
    if (sysBtn) {
        sysBtn.addEventListener('click', async function () {
            sysBtn.disabled = true;
            sysOut.innerHTML = '<p>⏳ בודק... (כולל קריאת בדיקה ל-OpenAI, ~5 שניות)</p>';
            try {
                const body = new FormData();
                body.append('action', 'wisply_system_check');
                body.append('nonce', VOICE_NONCE);
                const res = await fetch(AJAX_URL, { method: 'POST', body, credentials: 'same-origin' });
                if (!res.ok) { sysOut.innerHTML = '<p style="color:#b32d2e">❌ הבקשה נחסמה (HTTP ' + res.status + '). ככל הנראה תוסף אבטחה/חומת אש חוסם — זו הבעיה.</p>'; sysBtn.disabled = false; return; }
                const data = await res.json();
                if (!data || !data.success) { sysOut.innerHTML = '<p style="color:#b32d2e">❌ ' + ((data && data.data) || 'נכשל') + '</p>'; sysBtn.disabled = false; return; }
                const d = data.data;
                const row = (label, r) => '<tr><td style="padding:6px 10px;font-weight:600">' + label + '</td><td style="padding:6px 10px">' +
                    (r.ok ? '✅' : '❌') + '</td><td style="padding:6px 10px;color:#555">' + (r.detail || '') + '</td></tr>';
                let html = '<table style="border-collapse:collapse;background:#fafafa;border:1px solid #e0e0e0;border-radius:6px;width:100%;max-width:680px">';
                html += row('שמירת הגדרות במסד', d.settings_write);
                html += row('חיבור Wisply AI', d.openai_key);
                html += row('צ׳אט AI (תשובות)', d.openai_chat);
                html += row('דיבור (TTS)', d.openai_tts);
                if (d.realtime) html += row('Real-Time (דיבור מיידי)', d.realtime);
                html += '</table>';
                const v = d.values || {};
                html += '<p style="margin-top:10px;color:#555">ערכים פעילים כעת: מודל=' + v.openai_model +
                    ' · Real-Time=' + v.realtime_enabled + ' · קול=' + v.tts_voice + ' · TTS=' + v.tts_model + ' · STT=' + v.stt_model + '</p>';
                if (!d.openai_chat.ok || !d.openai_tts.ok) {
                    html += '<p style="margin-top:8px;color:#b32d2e;font-weight:600">⬅ זו הבעיה: שירות ה-AI מחזיר שגיאה. בדוק שמפתח הרישיון פעיל, או פנה לתמיכת Wisply.</p>';
                }
                sysOut.innerHTML = html;
            } catch (e) {
                sysOut.innerHTML = '<p style="color:#b32d2e">❌ שגיאת רשת: ' + e.message + '</p>';
            } finally {
                sysBtn.disabled = false;
            }
        });
    }
    const previewBtn  = document.getElementById('wisply-voice-preview');
    const voiceSel    = document.getElementById('wisply-tts-voice');
    const statusEl    = document.getElementById('wisply-voice-preview-status');
    let previewAudio  = null;
    if (previewBtn && voiceSel) {
        previewBtn.addEventListener('click', async function () {
            if (previewAudio) { try { previewAudio.pause(); } catch (e) {} }
            previewBtn.disabled = true;
            statusEl.textContent = '⏳ מפיק קול...';
            try {
                const body = new FormData();
                body.append('action', 'wisply_test_voice');
                body.append('nonce', VOICE_NONCE);
                body.append('voice', voiceSel.value);
                const res = await fetch(AJAX_URL, { method: 'POST', body, credentials: 'same-origin' });
                const data = await res.json();
                if (data && data.success && data.data && data.data.audio) {
                    previewAudio = new Audio('data:' + (data.data.mime || 'audio/mpeg') + ';base64,' + data.data.audio);
                    previewAudio.play();
                    statusEl.textContent = '🔊 ' + voiceSel.options[voiceSel.selectedIndex].text;
                } else {
                    const msg = (data && (data.data && data.data.message || data.data)) || 'שגיאה. ודא שמפתח OpenAI מוגדר ונשמר.';
                    statusEl.textContent = '❌ ' + msg;
                }
            } catch (e) {
                statusEl.textContent = '❌ שגיאת רשת';
            } finally {
                previewBtn.disabled = false;
            }
        });
    }
})();
</script>

<script>
/* ── Custom CTA buttons repeater ── */
(function () {
    const tbody  = document.getElementById('wisply-cta-rows');
    const addBtn = document.getElementById('wisply-cta-add');
    const hidden = document.getElementById('wisply-cta-json');
    if (!tbody || !addBtn || !hidden) return;
    function escAttr(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }
    function addRow(b) {
        b = b || {};
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" class="cta-key" value="' + escAttr(b.key) + '" placeholder="book"></td>' +
            '<td><input type="text" class="cta-he regular-text" value="' + escAttr(b.label_he) + '" placeholder="הזמנת תור"></td>' +
            '<td><input type="text" class="cta-en regular-text" value="' + escAttr(b.label_en) + '" placeholder="Book now" dir="ltr"></td>' +
            '<td><input type="text" class="cta-ru regular-text" value="' + escAttr(b.label_ru) + '" placeholder="Записаться" dir="ltr"></td>' +
            '<td><input type="text" class="cta-ar regular-text" value="' + escAttr(b.label_ar) + '" placeholder="حجز موعد" dir="rtl"></td>' +
            '<td><input type="url"  class="cta-url regular-text" value="' + escAttr(b.url) + '" placeholder="https://..." dir="ltr"></td>' +
            '<td><button type="button" class="button button-link-delete cta-del">✕</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.cta-del').addEventListener('click', () => tr.remove());
    }
    let initial = [];
    try { initial = JSON.parse(hidden.value || '[]'); } catch (e) {}
    if (Array.isArray(initial)) initial.forEach(addRow);
    addBtn.addEventListener('click', () => addRow());
    const form = hidden.closest('form');
    if (form) form.addEventListener('submit', function () {
        const rows = [];
        tbody.querySelectorAll('tr').forEach(tr => {
            const key = tr.querySelector('.cta-key').value.trim();
            const url = tr.querySelector('.cta-url').value.trim();
            if (!key || !url) return;
            rows.push({ key: key, url: url,
                label_he: tr.querySelector('.cta-he').value.trim(),
                label_en: tr.querySelector('.cta-en').value.trim(),
                label_ru: tr.querySelector('.cta-ru').value.trim(),
                label_ar: tr.querySelector('.cta-ar').value.trim() });
        });
        hidden.value = rows.length ? JSON.stringify(rows) : '';
    });
})();
</script>
<script>
/* Languages: enforce the plan cap and keep the default-language list in sync. */
(function () {
    const box = document.getElementById('wisply-langs-box');
    const maxEl = document.getElementById('wisply-max-langs');
    const def = document.getElementById('wisply-default-lang');
    if (!box || !def) return;
    const names = { he:'עברית', en:'English', ru:'Русский', ar:'العربية' };
    const max = Math.max(1, parseInt((maxEl && maxEl.dataset.max) || '1', 10));
    const cbs = () => Array.from(box.querySelectorAll('.wisply-lang-cb'));

    function syncDefault() {
        const checked = cbs().filter(c => c.checked).map(c => c.value);
        const cur = def.value;
        def.innerHTML = '';
        (checked.length ? checked : ['he']).forEach(code => {
            const o = document.createElement('option');
            o.value = code; o.textContent = names[code] || code;
            if (code === cur) o.selected = true;
            def.appendChild(o);
        });
        if (!checked.includes(cur) && checked.length) def.value = checked[0];
    }
    box.addEventListener('change', (e) => {
        const t = e.target;
        if (t && t.classList.contains('wisply-lang-cb')) {
            if (t.checked) {
                // At the cap, checking a new language drops the oldest others (radio-like
                // when max=1). This lets the owner pick WHICH single language is active —
                // any of the four — instead of being locked to the first one (Hebrew).
                let checked = cbs().filter(c => c.checked);
                while (checked.length > max) {
                    const victim = checked.find(c => c !== t);
                    if (!victim) break;
                    victim.checked = false;
                    checked = cbs().filter(c => c.checked);
                }
            } else if (!cbs().some(c => c.checked)) {
                t.checked = true; // never allow zero languages
            }
        }
        syncDefault();
    });
    syncDefault();
})();
</script>
