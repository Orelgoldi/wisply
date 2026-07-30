<?php
/**
 * First-run setup wizard view (rendered by Wisply_Setup_Wizard::render()).
 * $this here is the Wisply_Setup_Wizard instance; $this->db is the database.
 *
 * Single-page, 4-step wizard. Steps are shown/hidden by the inline JS below;
 * each step posts to admin-ajax via the shared WisplyAdmin nonce.
 */
defined( 'ABSPATH' ) || exit;

$product       = defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply';
$business_name = (string) $this->db->get_setting( 'business_name', '' );
$bot_name      = (string) $this->db->get_setting( 'bot_name', '' );
$business_type = (string) $this->db->get_setting( 'business_type', '' );
$faq_he        = (string) $this->db->get_setting( 'suggested_questions_he', '' );
$content_count = (int) $this->db->get_content_count();

// Existing primary CTA (if any) so the field is prefilled on re-entry.
$cta_label = '';
$cta_url   = '';
$buttons   = json_decode( (string) $this->db->get_setting( 'action_buttons', '' ), true );
if ( is_array( $buttons ) && ! empty( $buttons[0] ) ) {
    $cta_label = (string) ( $buttons[0]['label_he'] ?? $buttons[0]['label'] ?? '' );
    $cta_url   = (string) ( $buttons[0]['url'] ?? '' );
}
?>
<div class="wrap" dir="rtl" style="max-width:760px">
    <h1 style="display:flex;align-items:center;gap:10px">
        <span class="dashicons dashicons-format-chat" style="font-size:32px;width:32px;height:32px"></span>
        הגדרת <?php echo esc_html( $product ); ?>
    </h1>

    <!-- Progress -->
    <ol id="wisply-steps" style="display:flex;gap:6px;list-style:none;padding:0;margin:16px 0 24px">
        <?php
        $labels = [ '1. סריקת האתר', '2. אופי הבוט', '3. שאלות ו-CTA', '4. סיום' ];
        foreach ( $labels as $i => $label ) :
            ?>
            <li class="wisply-step-pill" data-pill="<?php echo (int) ( $i + 1 ); ?>"
                style="flex:1;text-align:center;padding:8px 6px;border-radius:8px;background:#f0f0f1;font-size:13px;color:#555">
                <?php echo esc_html( $label ); ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <div style="background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:24px">

        <!-- STEP 1: site scan -->
        <section class="wisply-step" data-step="1">
            <h2>נסרוק את האתר כדי לבנות את בסיס הידע</h2>
            <p>נעבור על העמודים, הפוסטים והתפריטים שפורסמו באתר ונבנה מהם את הידע שהבוט יענה לפיו. אפשר להוסיף כתובת Sitemap כדי להעשיר את הסריקה (לא חובה).</p>
            <p>
                <label>כתובת Sitemap (רשות):<br>
                    <input type="url" id="wisply-sitemap" class="regular-text" placeholder="https://example.com/sitemap.xml">
                </label>
            </p>
            <p>
                <button type="button" class="button button-primary" id="wisply-scan-btn">סרוק את האתר עכשיו</button>
                <span id="wisply-scan-status" style="margin-inline-start:10px;color:#555"><?php
                    echo $content_count > 0 ? esc_html( sprintf( 'כרגע מאונדקסים %d פריטים.', $content_count ) ) : '';
                ?></span>
            </p>
            <p style="text-align:left">
                <button type="button" class="button button-primary wisply-next" data-next="2" disabled id="wisply-step1-next">המשך</button>
            </p>
        </section>

        <!-- STEP 2: persona -->
        <section class="wisply-step" data-step="2" style="display:none">
            <h2>אופי הבוט והעסק</h2>
            <p>
                <label>שם העסק:<br>
                    <input type="text" id="wisply-business-name" class="regular-text" value="<?php echo esc_attr( $business_name ); ?>">
                </label>
            </p>
            <p>
                <label>שם הבוט (איך הוא יציג את עצמו):<br>
                    <input type="text" id="wisply-bot-name" class="regular-text" value="<?php echo esc_attr( $bot_name ); ?>">
                </label>
            </p>
            <p>
                <label>סוג העסק (לדוגמה: חנות תכשיטים, מרפאה, משרד עו״ד):<br>
                    <input type="text" id="wisply-business-type" class="regular-text" value="<?php echo esc_attr( $business_type ); ?>">
                </label>
            </p>
            <p>
                <label>טון הדיבור:<br>
                    <select id="wisply-tone" class="regular-text">
                        <option value="חם ואישי">חם ואישי</option>
                        <option value="מקצועי ורשמי">מקצועי ורשמי</option>
                        <option value="ידידותי וקליל">ידידותי וקליל</option>
                        <option value="ענייני וקצר">ענייני וקצר</option>
                    </select>
                </label>
            </p>
            <p>
                <label>מה לאסוף בליד (מה חשוב לדעת על הפונה):<br>
                    <input type="text" id="wisply-collect" class="regular-text" placeholder="שם, טלפון, ובמה מתעניין">
                </label>
            </p>
            <fieldset style="margin-top:8px">
                <legend><strong>שדות טופס יצירת קשר</strong></legend>
                <?php
                $fields = [
                    'name'  => [ 'label' => 'שם',    'default' => 'required' ],
                    'phone' => [ 'label' => 'טלפון', 'default' => 'required' ],
                    'email' => [ 'label' => 'אימייל', 'default' => 'optional' ],
                ];
                foreach ( $fields as $key => $f ) :
                    $cur = (string) $this->db->get_setting( 'lead_field_' . $key, $f['default'] );
                    ?>
                    <label style="display:inline-block;margin-inline-end:16px">
                        <?php echo esc_html( $f['label'] ); ?>:
                        <select data-leadfield="<?php echo esc_attr( $key ); ?>">
                            <option value="required" <?php selected( $cur, 'required' ); ?>>חובה</option>
                            <option value="optional" <?php selected( $cur, 'optional' ); ?>>רשות</option>
                            <option value="hidden"   <?php selected( $cur, 'hidden' ); ?>>מוסתר</option>
                        </select>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p style="text-align:left;margin-top:16px">
                <button type="button" class="button wisply-prev" data-prev="1">חזרה</button>
                <button type="button" class="button button-primary wisply-save-next" data-next="3">שמור והמשך</button>
            </p>
        </section>

        <!-- STEP 3: FAQ + CTA -->
        <section class="wisply-step" data-step="3" style="display:none">
            <h2>שאלות מוצעות וכפתור פעולה</h2>
            <p>אלה השאלות שיוצעו לגולש בפתיחת הצ׳אט. ערכו, הוסיפו או מחקו (שורה לכל שאלה):</p>
            <p>
                <textarea id="wisply-faq" rows="5" class="large-text"><?php echo esc_textarea( $faq_he ); ?></textarea>
            </p>
            <p><strong>כפתור פעולה ראשי (CTA)</strong> — למשל "קביעת תור" או "לחנות":</p>
            <p>
                <label>טקסט הכפתור:<br>
                    <input type="text" id="wisply-cta-label" class="regular-text" value="<?php echo esc_attr( $cta_label ); ?>">
                </label>
            </p>
            <p>
                <label>קישור הכפתור:<br>
                    <input type="url" id="wisply-cta-url" class="regular-text" value="<?php echo esc_attr( $cta_url ); ?>">
                </label>
            </p>
            <p style="text-align:left;margin-top:16px">
                <button type="button" class="button wisply-prev" data-prev="2">חזרה</button>
                <button type="button" class="button button-primary wisply-save-next" data-next="4">שמור והמשך</button>
            </p>
        </section>

        <!-- STEP 4: finish -->
        <section class="wisply-step" data-step="4" style="display:none">
            <h2>הכול מוכן 🎉</h2>
            <p>הבוט הוגדר ומתחיל תקופת ניסיון חינם של <strong><?php echo (int) Wisply_Trial::TRIAL_DAYS; ?> ימים</strong> או עד <strong><?php echo (int) Wisply_Trial::TRIAL_CONVO_CAP; ?> שיחות</strong> (המוקדם מביניהם). בזמן הניסיון הבוט רץ על מודל חסכוני; בסיום הוא יושהה בעדינות עם הודעת שדרוג, וכל ההגדרות יישמרו.</p>
            <p style="text-align:left;margin-top:16px">
                <button type="button" class="button wisply-prev" data-prev="3">חזרה</button>
                <button type="button" class="button button-primary" id="wisply-finish-btn">סיום והפעלת הבוט</button>
                <span id="wisply-finish-status" style="margin-inline-start:10px;color:#555"></span>
            </p>
        </section>

    </div>
</div>

<script>
(function () {
    var A = window.WisplyAdmin || {};
    var ajaxUrl = A.ajaxUrl, nonce = A.ajaxNonce;

    function post(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    function show(step) {
        document.querySelectorAll('.wisply-step').forEach(function (s) {
            s.style.display = (s.getAttribute('data-step') == step) ? '' : 'none';
        });
        document.querySelectorAll('.wisply-step-pill').forEach(function (p) {
            var on = (+p.getAttribute('data-pill') <= +step);
            p.style.background = on ? '#00A3A3' : '#f0f0f1';
            p.style.color = on ? '#fff' : '#555';
        });
    }

    function collectPersona() {
        var lead = {};
        document.querySelectorAll('[data-leadfield]').forEach(function (el) {
            lead['lead_field_' + el.getAttribute('data-leadfield')] = el.value;
        });
        return Object.assign({
            business_name: (document.getElementById('wisply-business-name') || {}).value || '',
            bot_name:      (document.getElementById('wisply-bot-name') || {}).value || '',
            business_type: (document.getElementById('wisply-business-type') || {}).value || '',
            tone:          (document.getElementById('wisply-tone') || {}).value || '',
            collect:       (document.getElementById('wisply-collect') || {}).value || ''
        }, lead);
    }

    function collectFaqCta() {
        return {
            faq_he:    (document.getElementById('wisply-faq') || {}).value || '',
            cta_label: (document.getElementById('wisply-cta-label') || {}).value || '',
            cta_url:   (document.getElementById('wisply-cta-url') || {}).value || ''
        };
    }

    // Step 1: scan
    var scanBtn = document.getElementById('wisply-scan-btn');
    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            var status = document.getElementById('wisply-scan-status');
            scanBtn.disabled = true;
            status.textContent = 'סורק…';
            post('wisply_setup_scan', { sitemap_url: (document.getElementById('wisply-sitemap') || {}).value || '' })
                .then(function (res) {
                    scanBtn.disabled = false;
                    if (res && res.success) {
                        var d = res.data || {};
                        status.textContent = 'הסריקה הושלמה — ' + (d.content_count || d.indexed || 0) + ' פריטים באינדקס.';
                        var next = document.getElementById('wisply-step1-next');
                        if (next) next.disabled = false;
                        var bn = document.getElementById('wisply-business-name');
                        if (bn && !bn.value && d.business_name) bn.value = d.business_name;
                    } else {
                        status.textContent = 'הסריקה נכשלה. אפשר להמשיך ולהגדיר ידנית.';
                        var n2 = document.getElementById('wisply-step1-next');
                        if (n2) n2.disabled = false;
                    }
                })
                .catch(function () {
                    scanBtn.disabled = false;
                    status.textContent = 'שגיאה בסריקה. אפשר להמשיך ולהגדיר ידנית.';
                    var n3 = document.getElementById('wisply-step1-next');
                    if (n3) n3.disabled = false;
                });
        });
    }

    // Plain next / prev
    document.querySelectorAll('.wisply-next').forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-next')); });
    });
    document.querySelectorAll('.wisply-prev').forEach(function (b) {
        b.addEventListener('click', function () { show(b.getAttribute('data-prev')); });
    });

    // Save-and-next (steps 2 & 3)
    document.querySelectorAll('.wisply-save-next').forEach(function (b) {
        b.addEventListener('click', function () {
            var next = b.getAttribute('data-next');
            var data = (next === '3') ? collectPersona() : collectFaqCta();
            b.disabled = true;
            post('wisply_setup_save', data).then(function () {
                b.disabled = false;
                show(next);
            }).catch(function () { b.disabled = false; show(next); });
        });
    });

    // Finish
    var finishBtn = document.getElementById('wisply-finish-btn');
    if (finishBtn) {
        finishBtn.addEventListener('click', function () {
            var status = document.getElementById('wisply-finish-status');
            finishBtn.disabled = true;
            status.textContent = 'מפעיל…';
            post('wisply_setup_finish', {}).then(function (res) {
                if (res && res.success && res.data && res.data.redirect) {
                    window.location.href = res.data.redirect;
                } else {
                    status.textContent = 'הופעל. מעביר…';
                    window.location.reload();
                }
            }).catch(function () {
                finishBtn.disabled = false;
                status.textContent = 'שגיאה. נסו שוב.';
            });
        });
    }

    show(1);
})();
</script>
