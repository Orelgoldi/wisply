<?php
defined( 'ABSPATH' ) || exit;

// ── Analytics (section 22) ──
$m360_db    = M360_Database::get_instance();
$conv_total = $m360_db->get_conversations_count();
$leads      = (array) get_option( 'm360_leads', [] );
$leads_n    = count( $leads );
$opt_n      = 0;
$depts      = [];
foreach ( $leads as $l ) {
    if ( ! empty( $l['marketing_consent'] ) ) $opt_n++;
    $d = trim( (string) ( $l['department'] ?? '' ) );
    if ( $d !== '' ) $depts[ $d ] = ( $depts[ $d ] ?? 0 ) + 1;
}
arsort( $depts );
$opt_rate  = $leads_n  ? round( $opt_n / $leads_n * 100 )    : 0;
$conv_rate = $conv_total ? round( $leads_n / $conv_total * 100 ) : 0;
$abandoned = max( 0, $conv_total - $leads_n );
$top_q     = $m360_db->get_top_user_questions( 8 );
?>
<div class="wrap m360-admin" dir="rtl">
    <h1>
        <span class="m360-logo">💬</span>
        Medical360 AI Chatbot — לוח בקרה
    </h1>

    <h2 style="margin-top:18px">📊 אנליטיקה</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:8px">
        <?php
        $cards = [
            [ 'מספר שיחות', $conv_total, '#00A3A3' ],
            [ 'מספר לידים', $leads_n, '#007878' ],
            [ 'יחס המרה (ליד/שיחה)', $conv_rate . '%', '#0a8f3c' ],
            [ 'שיעור Opt-In', $opt_rate . '% (' . $opt_n . ')', '#0a8f3c' ],
            [ 'שיחות שננטשו (ללא ליד)', $abandoned, '#b08900' ],
        ];
        foreach ( $cards as $c ) : ?>
            <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px">
                <div style="font-size:30px;font-weight:800;color:<?php echo esc_attr( $c[2] ); ?>"><?php echo esc_html( $c[1] ); ?></div>
                <div style="color:#666;font-size:13px;margin-top:4px"><?php echo esc_html( $c[0] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:12px 0 4px">
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px">
            <strong>🔝 שאלות נפוצות</strong>
            <?php if ( empty( $top_q ) ) : ?>
                <p style="color:#999;margin:8px 0 0">אין נתונים עדיין.</p>
            <?php else : ?>
                <ol style="margin:8px 18px 0;padding:0;line-height:1.9">
                    <?php foreach ( $top_q as $q ) : ?>
                        <li><?php echo esc_html( $q['content'] ); ?> <span style="color:#999">(<?php echo (int) $q['cnt']; ?>)</span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px">
            <strong>🏥 מחלקות מבוקשות (לפי לידים)</strong>
            <?php if ( empty( $depts ) ) : ?>
                <p style="color:#999;margin:8px 0 0">אין נתונים עדיין.</p>
            <?php else : ?>
                <ol style="margin:8px 18px 0;padding:0;line-height:1.9">
                    <?php foreach ( array_slice( $depts, 0, 8, true ) as $d => $n ) : ?>
                        <li><?php echo esc_html( $d ); ?> <span style="color:#999">(<?php echo (int) $n; ?>)</span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <div class="m360-stats-grid">
        <div class="m360-stat-card">
            <span class="m360-stat-number" id="m360-stat-unanswered">—</span>
            <span class="m360-stat-label">שאלות שלא נענו</span>
        </div>
        <div class="m360-stat-card">
            <span class="m360-stat-number" id="m360-stat-indexed">—</span>
            <span class="m360-stat-label">דפים מאונדקסים</span>
        </div>
        <div class="m360-stat-card m360-stat-card--action">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=medical360-conversations' ) ); ?>" class="button button-primary">
                צפה בכל השיחות
            </a>
        </div>
        <div class="m360-stat-card m360-stat-card--action">
            <a href="<?php echo esc_url( rest_url( 'medical360/v1/admin/export' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>"
               class="button">
                ייצוא CSV
            </a>
        </div>
    </div>

    <div class="m360-status-row">
        <h2>סטטוס מערכת</h2>
        <table class="widefat m360-status-table">
            <tbody>
            <tr>
                <td><strong>ספק AI</strong></td>
                <td id="m360-provider">—</td>
            </tr>
            <tr>
                <td><strong>מודל</strong></td>
                <td id="m360-model">—</td>
            </tr>
            <tr>
                <td><strong>API Key</strong></td>
                <td id="m360-apikey-status">—</td>
            </tr>
            <tr>
                <td><strong>מספר דפים באינדקס</strong></td>
                <td id="m360-index-count">—</td>
            </tr>
            </tbody>
        </table>

        <p>
            <button id="m360-test-btn" class="button button-secondary">בדוק חיבור ל-AI</button>
            <span id="m360-test-result" style="margin-right:10px;"></span>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cfg = window.M360Admin || {};
    const s   = cfg.settings || {};

    document.getElementById('m360-stat-unanswered').textContent = cfg.unansweredCount ?? '—';
    document.getElementById('m360-stat-indexed').textContent    = cfg.contentCount ?? '—';
    document.getElementById('m360-provider').textContent        = s.ai_provider || '—';
    document.getElementById('m360-model').textContent           = s.ai_provider === 'openai' ? s.openai_model : s.ai_model;
    // Check the active provider's key — not always ai_api_key (Claude)
    const activeKey = s.ai_provider === 'openai' ? s.openai_api_key : s.ai_api_key;
    document.getElementById('m360-apikey-status').textContent = activeKey ? '✅ מוגדר' : '❌ חסר';
    document.getElementById('m360-index-count').textContent     = cfg.contentCount ?? '—';

    document.getElementById('m360-test-btn').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        const el = document.getElementById('m360-test-result');
        el.textContent = 'בודק...';
        try {
            const fd = new FormData();
            fd.append('action', 'm360_test_ai');
            fd.append('nonce',  cfg.ajaxNonce);
            const ajaxUrl = window.ajaxurl || cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
            const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await res.json();
            // wp_send_json wraps in {success, data} — but test_ai uses wp_send_json directly
            const payload = data.data ?? data;
            el.textContent = payload.success ? '✅ ' + payload.reply : '❌ ' + payload.reply;
        } catch(e) {
            el.textContent = '❌ שגיאת רשת';
        }
        btn.disabled = false;
    });
});
</script>
