<?php
defined( 'ABSPATH' ) || exit;

// ── Process form submission (standard WP form POST — no AJAX, works with all security plugins) ──
$saved   = false;
$save_error = '';

if ( isset( $_POST['m360_settings_nonce'] ) ) {
    if ( ! check_admin_referer( 'm360_save_settings', 'm360_settings_nonce' ) ) {
        $save_error = 'שגיאת אבטחה — נסה שוב.';
    } elseif ( ! current_user_can( 'manage_options' ) ) {
        $save_error = 'אין הרשאה.';
    } else {
        $allowed     = [
            'ai_provider', 'ai_api_key', 'ai_model',
            'openai_api_key', 'openai_model',
            'primary_color', 'secondary_color', 'font_family', 'bubble_position',
            'greeting_he', 'greeting_en', 'greeting_ru',
            'widget_title_he', 'widget_title_en', 'widget_title_ru',
            'phone', 'map_url', 'max_context_docs', 'conversation_ttl_days',
            'voice_enabled', 'voice_provider', 'tts_model', 'tts_voice', 'stt_model',
            'realtime_enabled', 'realtime_model', 'voice_text_mode',
            'proactive_enabled', 'proactive_delay', 'proactive_msg_he', 'proactive_msg_en', 'proactive_msg_ru',
            'consent_required', 'consent_version', 'consent_text_he', 'consent_text_en', 'consent_text_ru',
            'emergency_msg_he', 'emergency_msg_en', 'emergency_msg_ru',
            'emergency_phone', 'emergency_eran_url', 'emergency_sahar_url',
        ];
        // Checkboxes don't POST when unchecked — normalise to 0/1
        $_POST['voice_enabled']     = isset( $_POST['voice_enabled'] )     ? '1' : '0';
        $_POST['realtime_enabled']  = isset( $_POST['realtime_enabled'] )  ? '1' : '0';
        $_POST['proactive_enabled'] = isset( $_POST['proactive_enabled'] ) ? '1' : '0';
        $_POST['consent_required']  = isset( $_POST['consent_required'] )  ? '1' : '0';
        $secret_keys = [ 'ai_api_key', 'openai_api_key' ];
        $db          = M360_Database::get_instance();

        foreach ( $allowed as $key ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $val = sanitize_text_field( (string) $_POST[ $key ] );
            // Skip empty secret fields — don't wipe stored key
            if ( in_array( $key, $secret_keys, true ) && empty( $val ) ) continue;
            $db->set_setting( $key, $val );
        }
        $saved = true;
    }
}

$db       = M360_Database::get_instance();
$settings = $db->get_all_settings();
$api_key_he = ! empty( $settings['openai_api_key'] ) ? '✅ מפתח מוגדר — הכנס מפתח חדש להחלפה' : 'sk-...';
$api_key_claude = ! empty( $settings['ai_api_key'] ) ? '✅ מפתח מוגדר — הכנס מפתח חדש להחלפה' : 'sk-ant-...';

function m360_v( string $key, mixed $default = '' ): string {
    global $settings;
    return esc_attr( $settings[ $key ] ?? $default );
}
function m360_sel( string $key, string $val ): string {
    global $settings;
    return ( ( $settings[ $key ] ?? '' ) === $val ) ? 'selected' : '';
}
?>
<div class="wrap m360-admin" dir="rtl">
    <h1>הגדרות Medical360 Chatbot</h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>✅ ההגדרות נשמרו בהצלחה.</p></div>
    <?php elseif ( $save_error ) : ?>
        <div class="notice notice-error"><p>❌ <?php echo esc_html( $save_error ); ?></p></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:12px 0 20px">
        <button type="button" class="button button-secondary" id="m360-syscheck">🔧 בדוק מערכת (למה זה לא עובד?)</button>
        <span style="color:#666;margin-inline-start:10px">בדיקה מהירה: שמירת הגדרות, מפתח OpenAI, צ׳אט ודיבור.</span>
        <div id="m360-syscheck-results" style="margin-top:12px"></div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'm360_save_settings', 'm360_settings_nonce' ); ?>

        <h2>🤖 ספק AI</h2>
        <table class="form-table">
            <tr>
                <th>ספק</th>
                <td>
                    <select name="ai_provider" id="m360-ai-provider">
                        <option value="openai" <?php echo m360_sel('ai_provider','openai'); ?>>OpenAI (GPT-4o) — מומלץ</option>
                        <option value="claude" <?php echo m360_sel('ai_provider','claude'); ?>>Claude (Anthropic)</option>
                    </select>
                </td>
            </tr>
            <tr id="m360-openai-row">
                <th>OpenAI API Key</th>
                <td>
                    <input type="password" name="openai_api_key" class="regular-text"
                           placeholder="<?php echo esc_attr( $api_key_he ); ?>" autocomplete="new-password">
                    <p class="description">API key מ-platform.openai.com</p>
                </td>
            </tr>
            <tr id="m360-openai-model-row">
                <th>OpenAI Model</th>
                <td>
                    <select name="openai_model">
                        <option value="gpt-4o" <?php echo m360_sel('openai_model','gpt-4o'); ?>>gpt-4o</option>
                        <option value="gpt-4o-mini" <?php echo m360_sel('openai_model','gpt-4o-mini'); ?>>gpt-4o-mini</option>
                    </select>
                </td>
            </tr>
            <tr id="m360-claude-row" style="display:none">
                <th>Anthropic API Key</th>
                <td>
                    <input type="password" name="ai_api_key" class="regular-text"
                           placeholder="<?php echo esc_attr( $api_key_claude ); ?>" autocomplete="new-password">
                    <p class="description">Claude API key מ-console.anthropic.com</p>
                </td>
            </tr>
            <tr id="m360-claude-model-row" style="display:none">
                <th>Claude Model</th>
                <td>
                    <select name="ai_model">
                        <option value="claude-sonnet-4-6" <?php echo m360_sel('ai_model','claude-sonnet-4-6'); ?>>claude-sonnet-4-6</option>
                        <option value="claude-haiku-4-5-20251001" <?php echo m360_sel('ai_model','claude-haiku-4-5-20251001'); ?>>claude-haiku-4-5</option>
                        <option value="claude-opus-4-8" <?php echo m360_sel('ai_model','claude-opus-4-8'); ?>>claude-opus-4-8</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>מסמכי הקשר מקסימום</th>
                <td>
                    <input type="number" name="max_context_docs" min="1" max="10"
                           value="<?php echo m360_v('max_context_docs','5'); ?>" class="small-text">
                    <p class="description">כמה קטעי תוכן מהאתר לשלוח ל-AI בכל שאלה</p>
                </td>
            </tr>
        </table>

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
                        <option value="openai" <?php echo m360_sel('voice_provider','openai'); ?>>OpenAI — Whisper + קול טבעי (מומלץ)</option>
                        <option value="browser" <?php echo m360_sel('voice_provider','browser'); ?>>דפדפן — חינמי (איכות בינונית)</option>
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
                        <option value="none" <?php echo m360_sel('voice_text_mode','none'); ?>>ללא טקסט — שיחת קול טהורה</option>
                        <option value="end"  <?php echo m360_sel('voice_text_mode','end'); ?>>שמירת תמלול בסיום — בתום השיחה כל מה שנאמר נכתב בצ׳אט (מומלץ)</option>
                        <option value="live" <?php echo m360_sel('voice_text_mode','live'); ?>>טקסט חי — הטקסט של שני הצדדים נכתב בזמן אמת לאורך השיחה</option>
                    </select>
                    <p class="description">קובע אם ואיך הדברים שנאמרו בשיחת הקול מוצגים כטקסט בחלון הצ׳אט.</p>
                </td>
            </tr>
            <tr>
                <th>קול הבוט</th>
                <td>
                    <select name="tts_voice" id="m360-tts-voice">
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
                            <option value="<?php echo esc_attr( $val ); ?>" <?php echo m360_sel('tts_voice',$val); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="m360-voice-preview" style="margin-inline-start:8px">🔊 השמע דוגמה</button>
                    <span id="m360-voice-preview-status" style="margin-inline-start:8px;color:#666"></span>
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
                        <option value="gpt-4o-mini-tts" <?php echo m360_sel('tts_model','gpt-4o-mini-tts'); ?>>gpt-4o-mini-tts — טבעי וזול (מומלץ)</option>
                        <option value="tts-1" <?php echo m360_sel('tts_model','tts-1'); ?>>tts-1 — מהיר</option>
                        <option value="tts-1-hd" <?php echo m360_sel('tts_model','tts-1-hd'); ?>>tts-1-hd — איכות גבוהה</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>מודל האזנה (STT)</th>
                <td>
                    <select name="stt_model">
                        <option value="whisper-1" <?php echo m360_sel('stt_model','whisper-1'); ?>>whisper-1 — מצוין בעברית (מומלץ)</option>
                        <option value="gpt-4o-mini-transcribe" <?php echo m360_sel('stt_model','gpt-4o-mini-transcribe'); ?>>gpt-4o-mini-transcribe — זול</option>
                        <option value="gpt-4o-transcribe" <?php echo m360_sel('stt_model','gpt-4o-transcribe'); ?>>gpt-4o-transcribe — מדויק ביותר</option>
                    </select>
                    <p class="description">המנוע שמתמלל את מה שהמשתמש אומר. Whisper מצטיין במונחים רפואיים ובמבטאים.</p>
                </td>
            </tr>
        </table>

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
                           value="<?php echo m360_v('proactive_delay','5'); ?>" class="small-text"> שניות
                    <p class="description">רלוונטי רק לדפים קצרים שאי אפשר לגלול בהם לאמצע.</p>
                </td>
            </tr>
            <tr>
                <th>הודעה — עברית</th>
                <td>
                    <input type="text" name="proactive_msg_he" class="large-text" value="<?php echo m360_v('proactive_msg_he'); ?>">
                    <p class="description">השתמשו ב-<code>{subject}</code> כדי לשלב אוטומטית את שם הדף (לדוגמה: "סדנת חוסן" / "מחלקת שיקום").</p>
                </td>
            </tr>
            <tr>
                <th>הודעה — English</th>
                <td><input type="text" name="proactive_msg_en" class="large-text" value="<?php echo m360_v('proactive_msg_en'); ?>"></td>
            </tr>
            <tr>
                <th>הודעה — Русский</th>
                <td><input type="text" name="proactive_msg_ru" class="large-text" value="<?php echo m360_v('proactive_msg_ru'); ?>"></td>
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
                    <input type="text" name="consent_version" value="<?php echo m360_v('consent_version','1.0'); ?>" class="small-text">
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
                <td><input type="text" name="emergency_phone" value="<?php echo m360_v('emergency_phone','101'); ?>" class="small-text">
                    <p class="description">מופיע ככפתור חיוג בתגובת חירום.</p></td>
            </tr>
            <tr>
                <th>קישור ער"ן</th>
                <td><input type="url" name="emergency_eran_url" value="<?php echo m360_v('emergency_eran_url','https://www.eran.org.il/'); ?>" class="regular-text" dir="ltr"></td>
            </tr>
            <tr>
                <th>קישור סהר</th>
                <td><input type="url" name="emergency_sahar_url" value="<?php echo m360_v('emergency_sahar_url','https://sahar.org.il/'); ?>" class="regular-text" dir="ltr">
                    <p class="description">קווי סיוע נפשי שיוצגו ככפתורים בתגובת חירום.</p></td>
            </tr>
        </table>

        <h2>🎨 עיצוב Widget</h2>
        <table class="form-table">
            <tr>
                <th>צבע ראשי</th>
                <td><input type="color" name="primary_color" value="<?php echo m360_v('primary_color','#00A3A3'); ?>"></td>
            </tr>
            <tr>
                <th>צבע משני</th>
                <td><input type="color" name="secondary_color" value="<?php echo m360_v('secondary_color','#007878'); ?>"></td>
            </tr>
            <tr>
                <th>מיקום הבועה</th>
                <td>
                    <select name="bubble_position">
                        <option value="bottom-right" <?php echo m360_sel('bubble_position','bottom-right'); ?>>ימין-תחתון (מומלץ)</option>
                        <option value="bottom-left"  <?php echo m360_sel('bubble_position','bottom-left'); ?>>שמאל-תחתון</option>
                    </select>
                </td>
            </tr>
        </table>

        <h2>💬 הודעות פתיחה</h2>
        <table class="form-table">
            <tr>
                <th>כותרת Widget — עברית</th>
                <td><input type="text" name="widget_title_he" class="regular-text" value="<?php echo m360_v('widget_title_he'); ?>"></td>
            </tr>
            <tr>
                <th>כותרת Widget — English</th>
                <td><input type="text" name="widget_title_en" class="regular-text" value="<?php echo m360_v('widget_title_en'); ?>"></td>
            </tr>
            <tr>
                <th>כותרת Widget — Русский</th>
                <td><input type="text" name="widget_title_ru" class="regular-text" value="<?php echo m360_v('widget_title_ru'); ?>"></td>
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
                <td><input type="text" name="phone" class="regular-text" value="<?php echo m360_v('phone'); ?>" placeholder="+972-X-XXXXXXX"></td>
            </tr>
            <tr>
                <th>קישור למפה</th>
                <td><input type="url" name="map_url" class="regular-text" value="<?php echo m360_v('map_url'); ?>" placeholder="https://maps.google.com/..."></td>
            </tr>
        </table>

        <h2>🗑️ שמירת נתונים</h2>
        <table class="form-table">
            <tr>
                <th>שמירת שיחות (ימים)</th>
                <td>
                    <input type="number" name="conversation_ttl_days" min="7" max="365"
                           value="<?php echo m360_v('conversation_ttl_days','60'); ?>" class="small-text">
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary button-large">שמור הגדרות</button>
        </p>
    </form>
</div>

<script>
(function() {
    const providerSel = document.getElementById('m360-ai-provider');
    function toggleProvider() {
        const isClaude = providerSel.value === 'claude';
        document.getElementById('m360-claude-row').style.display       = isClaude ? '' : 'none';
        document.getElementById('m360-claude-model-row').style.display = isClaude ? '' : 'none';
        document.getElementById('m360-openai-row').style.display       = isClaude ? 'none' : '';
        document.getElementById('m360-openai-model-row').style.display = isClaude ? 'none' : '';
    }
    providerSel.addEventListener('change', toggleProvider);
    toggleProvider();

    // ── Voice preview: hear the selected voice immediately (no save needed) ──
    // AJAX URL + nonce are injected straight from PHP so the button works even
    // though the localized admin script loads later in the page footer.
    const AJAX_URL    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    const VOICE_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'm360_admin_nonce' ) ); ?>;

    // ── System check: pinpoint exactly what's broken ──
    const sysBtn = document.getElementById('m360-syscheck');
    const sysOut = document.getElementById('m360-syscheck-results');
    if (sysBtn) {
        sysBtn.addEventListener('click', async function () {
            sysBtn.disabled = true;
            sysOut.innerHTML = '<p>⏳ בודק... (כולל קריאת בדיקה ל-OpenAI, ~5 שניות)</p>';
            try {
                const body = new FormData();
                body.append('action', 'm360_system_check');
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
    const previewBtn  = document.getElementById('m360-voice-preview');
    const voiceSel    = document.getElementById('m360-tts-voice');
    const statusEl    = document.getElementById('m360-voice-preview-status');
    let previewAudio  = null;
    if (previewBtn && voiceSel) {
        previewBtn.addEventListener('click', async function () {
            if (previewAudio) { try { previewAudio.pause(); } catch (e) {} }
            previewBtn.disabled = true;
            statusEl.textContent = '⏳ מפיק קול...';
            try {
                const body = new FormData();
                body.append('action', 'm360_test_voice');
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
