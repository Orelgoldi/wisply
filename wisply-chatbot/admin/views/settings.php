<?php
defined( 'ABSPATH' ) || exit;

// ── Process form submission (standard WP form POST — no AJAX, works with all security plugins) ──
$saved   = false;
$save_error = '';

if ( isset( $_POST['wisply_settings_nonce'] ) ) {
    if ( ! check_admin_referer( 'wisply_save_settings', 'wisply_settings_nonce' ) ) {
        $save_error = 'שגיאת אבטחה — נסה שוב.';
    } elseif ( ! current_user_can( 'manage_options' ) ) {
        $save_error = 'אין הרשאה.';
    } else {
        $allowed     = [
            // White-label persona
            'bot_name', 'business_name', 'business_type', 'business_description', 'action_buttons',
            'suggested_questions_he', 'suggested_questions_en', 'suggested_questions_ru',

            'ai_provider', 'ai_api_key', 'ai_model',
            'openai_api_key', 'openai_model',
            'primary_color', 'secondary_color', 'font_family', 'bubble_position',
            'greeting_he', 'greeting_en', 'greeting_ru',
            'widget_title_he', 'widget_title_en', 'widget_title_ru',
            'phone', 'map_url', 'max_context_docs', 'conversation_ttl_days',
            'voice_enabled', 'voice_provider', 'tts_model', 'tts_voice', 'stt_model',
            'realtime_enabled', 'realtime_model', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay', 'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru',
            'desktop_autoopen_enabled', 'desktop_autoopen_delay',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru',
            'consent_required', 'consent_version', 'consent_text_he', 'consent_text_en', 'consent_text_ru',
            'emergency_msg_he', 'emergency_msg_en', 'emergency_msg_ru',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
            'report_recipients', 'report_daily', 'report_weekly',
        ];
        // Multi-line fields must keep their newlines
        $textarea_keys = [
            'business_description', 'greeting_he', 'greeting_en', 'greeting_ru',
            'suggested_questions_he', 'suggested_questions_en', 'suggested_questions_ru',
            'desktop_autoopen_msg_he', 'desktop_autoopen_msg_en', 'desktop_autoopen_msg_ru',
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
        $secret_keys = [ 'ai_api_key', 'openai_api_key' ];
        $db          = Wisply_Database::get_instance();

        foreach ( $allowed as $key ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            // CTA buttons arrive as a JSON string — validate, then re-encode cleanly
            if ( $key === 'action_buttons' ) {
                $db->set_setting( 'action_buttons', wisply_sanitize_action_buttons( (string) wp_unslash( $_POST['action_buttons'] ) ) );
                continue;
            }
            $val = in_array( $key, $textarea_keys, true )
                ? sanitize_textarea_field( (string) wp_unslash( $_POST[ $key ] ) )
                : sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
            // Skip empty secret fields — don't wipe stored key
            if ( in_array( $key, $secret_keys, true ) && empty( $val ) ) continue;
            $db->set_setting( $key, $val );
        }
        $saved = true;
    }
}

$db       = Wisply_Database::get_instance();
$settings = $db->get_all_settings();
$api_key_he = ! empty( $settings['openai_api_key'] ) ? '✅ מפתח מוגדר — הכנס מפתח חדש להחלפה' : 'sk-...';
$api_key_claude = ! empty( $settings['ai_api_key'] ) ? '✅ מפתח מוגדר — הכנס מפתח חדש להחלפה' : 'sk-ant-...';

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
        ];
    }
    return $out ? wp_json_encode( $out, JSON_UNESCAPED_UNICODE ) : '';
}
$product_name = defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : ( $settings['product_name'] ?? 'Wisply' );
?>
<div class="wrap wisply-admin" dir="rtl">
    <h1><?php echo esc_html( $product_name ); ?> — הגדרות</h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ ההגדרות נשמרו בהצלחה.</p></div>
    <?php elseif ( $save_error ) : ?>
        <div class="notice notice-error"><p>❌ <?php echo esc_html( $save_error ); ?></p></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0 20px">
        <button type="button" class="button button-secondary" id="wisply-syscheck">🔧 בדוק מערכת (למה זה לא עובד?)</button>
        <span style="color:#666;margin-inline-start:10px">בדיקה מהירה: שמירת הגדרות, מפתח OpenAI, צ׳אט ודיבור.</span>
        <div id="wisply-syscheck-results" style="margin-top:12px"></div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'wisply_save_settings', 'wisply_settings_nonce' ); ?>

        <div class="wisply-tabs" role="tablist">
            <button type="button" class="wisply-tab-btn active" data-target="brand">🏷️ מיתוג</button>
            <button type="button" class="wisply-tab-btn" data-target="ai">🤖 AI</button>
            <button type="button" class="wisply-tab-btn" data-target="voice">🎙️ קול</button>
            <button type="button" class="wisply-tab-btn" data-target="proactive">🔔 בועית יזומה</button>
            <button type="button" class="wisply-tab-btn" data-target="leads">📥 לידים</button>
            <button type="button" class="wisply-tab-btn" data-target="design">🎨 עיצוב ותוכן</button>
            <button type="button" class="wisply-tab-btn" data-target="advanced">⚙️ מתקדם</button>
        </div>

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
        </table>

        <h2>🔘 כפתורי פעולה (CTA)</h2>
        <p class="description" style="margin:0 0 8px">כפתורים שהבוט יציע כשרלוונטי (למשל "הזמנת תור", "לחנות"). ה-AI מזהה כוונה ומוסיף את הכפתור אוטומטית.</p>
        <table class="widefat striped" id="wisply-cta-table" style="max-width:980px">
            <thead><tr>
                <th style="width:120px">מזהה (key)</th><th>תווית (עברית)</th><th>Label (EN)</th><th>Label (RU)</th><th>קישור (URL)</th><th style="width:40px"></th>
            </tr></thead>
            <tbody id="wisply-cta-rows"></tbody>
        </table>
        <p><button type="button" class="button" id="wisply-cta-add">➕ הוספת כפתור</button></p>
        <input type="hidden" name="action_buttons" id="wisply-cta-json" value="<?php echo esc_attr( $settings['action_buttons'] ?? '' ); ?>">

        </div><div class="wisply-pane" data-pane="ai">
        <h2>🤖 ספק AI</h2>
        <table class="form-table">
            <tr>
                <th>ספק</th>
                <td>
                    <select name="ai_provider" id="wisply-ai-provider">
                        <option value="openai" <?php echo wisply_sel('ai_provider','openai'); ?>>OpenAI (GPT-4o) — מומלץ</option>
                        <option value="claude" <?php echo wisply_sel('ai_provider','claude'); ?>>Claude (Anthropic)</option>
                    </select>
                </td>
            </tr>
            <tr id="wisply-openai-row">
                <th>OpenAI API Key</th>
                <td>
                    <input type="password" name="openai_api_key" class="regular-text"
                           placeholder="<?php echo esc_attr( $api_key_he ); ?>" autocomplete="new-password">
                    <p class="description">API key מ-platform.openai.com</p>
                </td>
            </tr>
            <tr id="wisply-openai-model-row">
                <th>OpenAI Model</th>
                <td>
                    <select name="openai_model">
                        <option value="gpt-4o" <?php echo wisply_sel('openai_model','gpt-4o'); ?>>gpt-4o</option>
                        <option value="gpt-4o-mini" <?php echo wisply_sel('openai_model','gpt-4o-mini'); ?>>gpt-4o-mini</option>
                    </select>
                </td>
            </tr>
            <tr id="wisply-claude-row" style="display:none">
                <th>Anthropic API Key</th>
                <td>
                    <input type="password" name="ai_api_key" class="regular-text"
                           placeholder="<?php echo esc_attr( $api_key_claude ); ?>" autocomplete="new-password">
                    <p class="description">Claude API key מ-console.anthropic.com</p>
                </td>
            </tr>
            <tr id="wisply-claude-model-row" style="display:none">
                <th>Claude Model</th>
                <td>
                    <select name="ai_model">
                        <option value="claude-sonnet-4-6" <?php echo wisply_sel('ai_model','claude-sonnet-4-6'); ?>>claude-sonnet-4-6</option>
                        <option value="claude-haiku-4-5-20251001" <?php echo wisply_sel('ai_model','claude-haiku-4-5-20251001'); ?>>claude-haiku-4-5</option>
                        <option value="claude-opus-4-8" <?php echo wisply_sel('ai_model','claude-opus-4-8'); ?>>claude-opus-4-8</option>
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
        </table>

        </div><div class="wisply-pane" data-pane="leads">
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

        </div><div class="wisply-pane" data-pane="design">
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

        <p class="submit">
            <button type="submit" class="button button-primary button-large">שמור הגדרות</button>
        </p>
    </form>
</div>

<style>
.wisply-tabs { display:flex; flex-wrap:wrap; gap:6px; border-bottom:2px solid #e2e8f0; margin:8px 0 20px; }
.wisply-tab-btn { background:transparent; border:0; border-bottom:3px solid transparent; padding:10px 16px; font-size:14px; font-weight:600; color:#556; cursor:pointer; border-radius:6px 6px 0 0; margin-bottom:-2px; }
.wisply-tab-btn:hover { background:#f1f5f9; color:#00A3A3; }
.wisply-tab-btn.active { color:#00A3A3; border-bottom-color:#00A3A3; background:#f0fafa; }
.wisply-pane { display:none; }
.wisply-pane.active { display:block; animation:wisplyFade .18s ease; }
@keyframes wisplyFade { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
</style>
<script>
(function() {
    const btns  = document.querySelectorAll('.wisply-tab-btn');
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
(function() {
    const providerSel = document.getElementById('wisply-ai-provider');
    function toggleProvider() {
        const isClaude = providerSel.value === 'claude';
        document.getElementById('wisply-claude-row').style.display       = isClaude ? '' : 'none';
        document.getElementById('wisply-claude-model-row').style.display = isClaude ? '' : 'none';
        document.getElementById('wisply-openai-row').style.display       = isClaude ? 'none' : '';
        document.getElementById('wisply-openai-model-row').style.display = isClaude ? 'none' : '';
    }
    providerSel.addEventListener('change', toggleProvider);
    toggleProvider();

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
                html += row('מפתח OpenAI', d.openai_key);
                html += row('צ׳אט OpenAI (תשובות)', d.openai_chat);
                html += row('דיבור OpenAI (TTS)', d.openai_tts);
                if (d.realtime) html += row('Real-Time (דיבור מיידי)', d.realtime);
                html += '</table>';
                const v = d.values || {};
                html += '<p style="margin-top:10px;color:#555">ערכים פעילים כעת: ספק=' + v.ai_provider + ' · מודל=' + v.openai_model +
                    ' · Real-Time=' + v.realtime_enabled + ' · קול=' + v.tts_voice + ' · TTS=' + v.tts_model + ' · STT=' + v.stt_model + '</p>';
                if (!d.openai_chat.ok || !d.openai_tts.ok) {
                    html += '<p style="margin-top:8px;color:#b32d2e;font-weight:600">⬅ זו הבעיה: OpenAI מחזיר שגיאה. בדוק את ההודעה למעלה — בדרך כלל מפתח לא תקין או נגמרו קרדיטים/מכסה בחשבון OpenAI.</p>';
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
                label_ru: tr.querySelector('.cta-ru').value.trim() });
        });
        hidden.value = rows.length ? JSON.stringify(rows) : '';
    });
})();
</script>
