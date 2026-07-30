<?php
defined( 'ABSPATH' ) || exit;

// ── Analytics (section 22) ──
$wisply_db    = Wisply_Database::get_instance();
$conv_total = $wisply_db->get_conversations_count();
$leads      = (array) get_option( 'wisply_leads', [] );
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
$top_q     = $wisply_db->get_top_user_questions( 8 );
$days      = isset( $_GET['days'] ) ? max( 7, min( 90, (int) $_GET['days'] ) ) : 30;
$by_day    = $wisply_db->get_conversations_by_day( $days );
$heat      = $wisply_db->get_activity_heatmap();
?>
<div class="wrap wisply-admin" dir="rtl">
    <h1>
        <span class="wisply-logo">💬</span>
        <?php echo esc_html( defined( 'WISPLY_PRODUCT_NAME' ) ? WISPLY_PRODUCT_NAME : 'Wisply' ); ?> — לוח בקרה
    </h1>

    <h2>📊 אנליטיקה</h2>
    <div class="wisply-stats-grid">
        <?php
        $cards = [
            [ 'מספר שיחות', $conv_total, '#00A3A3' ],
            [ 'מספר לידים', $leads_n, '#007878' ],
            [ 'יחס המרה (ליד/שיחה)', $conv_rate . '%', '#0f9d64' ],
            [ 'שיעור Opt-In', $opt_rate . '% (' . $opt_n . ')', '#0f9d64' ],
            [ 'שיחות שננטשו (ללא ליד)', $abandoned, '#d98324' ],
        ];
        foreach ( $cards as $c ) : ?>
            <div class="wisply-stat-card" style="--accent:<?php echo esc_attr( $c[2] ); ?>">
                <span class="wisply-stat-number"><?php echo esc_html( $c[1] ); ?></span>
                <span class="wisply-stat-label"><?php echo esc_html( $c[0] ); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="wisply-cols2">
        <div class="wisply-card">
            <strong>🔝 שאלות נפוצות</strong>
            <?php if ( empty( $top_q ) ) : ?>
                <p style="color:var(--w-mut);margin:0">אין נתונים עדיין.</p>
            <?php else : ?>
                <ol><?php foreach ( $top_q as $q ) : ?><li><?php echo esc_html( $q['content'] ); ?> <span style="color:var(--w-mut)">(<?php echo (int) $q['cnt']; ?>)</span></li><?php endforeach; ?></ol>
            <?php endif; ?>
        </div>
        <div class="wisply-card">
            <strong>🏥 מחלקות מבוקשות (לפי לידים)</strong>
            <?php if ( empty( $depts ) ) : ?>
                <p style="color:var(--w-mut);margin:0">אין נתונים עדיין.</p>
            <?php else : ?>
                <ol><?php foreach ( array_slice( $depts, 0, 8, true ) as $d => $n ) : ?><li><?php echo esc_html( $d ); ?> <span style="color:var(--w-mut)">(<?php echo (int) $n; ?>)</span></li><?php endforeach; ?></ol>
            <?php endif; ?>
        </div>
    </div>

    <div class="wisply-card">
        <div class="wisply-card-head">
            <strong>📈 שיחות לאורך זמן</strong>
            <div class="wisply-range">
                <?php foreach ( [ 7, 30, 90 ] as $r ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'days', $r ) ); ?>" class="<?php echo $days === $r ? 'on' : ''; ?>"><?php echo (int) $r; ?> ימים</a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php $bmax = 1; foreach ( $by_day as $v ) { if ( $v > $bmax ) $bmax = $v; } ?>
        <?php if ( array_sum( $by_day ) === 0 ) : ?>
            <div class="wisply-empty">אין עדיין שיחות בטווח הזה.</div>
        <?php else : ?>
            <div class="wisply-bars">
                <?php for ( $i = $days - 1; $i >= 0; $i-- ) :
                    $d = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
                    $c = $by_day[ $d ] ?? 0; ?>
                    <div class="wisply-bar" title="<?php echo esc_attr( $d . ' — ' . $c . ' שיחות' ); ?>"><span style="height:<?php echo (int) round( $c / $bmax * 100 ); ?>%"></span></div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="wisply-card">
        <strong>🔥 מתי הגולשים הכי פעילים</strong>
        <?php
        $hmax = 1; $any = false;
        foreach ( $heat as $row ) { foreach ( $row as $v ) { if ( $v > $hmax ) $hmax = $v; if ( $v > 0 ) $any = true; } }
        ?>
        <?php if ( ! $any ) : ?>
            <div class="wisply-empty">אין עדיין מספיק נתונים לניתוח שעות.</div>
        <?php else :
            $order = [ 6, 0, 1, 2, 3, 4, 5 ]; $labels = [ 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ש' ]; ?>
            <div class="wisply-heat" style="margin-top:14px">
                <?php foreach ( $order as $idx => $wd ) : ?>
                    <div class="wisply-heat-row">
                        <span class="wisply-heat-lbl"><?php echo esc_html( $labels[ $idx ] ); ?></span>
                        <?php for ( $h = 0; $h < 24; $h++ ) : $v = $heat[ $wd ][ $h ] ?? 0; $op = $v ? round( 0.12 + 0.88 * ( $v / $hmax ), 2 ) : 0; ?>
                            <i style="--v:<?php echo esc_attr( $op ); ?>" title="<?php echo esc_attr( $labels[ $idx ] . ' ' . $h . ':00 — ' . $v . ' שיחות' ); ?>"></i>
                        <?php endfor; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="wisply-heat-cap"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span></div>
        <?php endif; ?>
    </div>

    <div class="wisply-stats-grid">
        <div class="wisply-stat-card">
            <span class="wisply-stat-number" id="wisply-stat-unanswered">—</span>
            <span class="wisply-stat-label">שאלות שלא נענו</span>
        </div>
        <div class="wisply-stat-card">
            <span class="wisply-stat-number" id="wisply-stat-indexed">—</span>
            <span class="wisply-stat-label">דפים מאונדקסים</span>
        </div>
        <div class="wisply-stat-card wisply-stat-card--action">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wisply-conversations' ) ); ?>" class="button button-primary">
                צפה בכל השיחות
            </a>
        </div>
        <div class="wisply-stat-card wisply-stat-card--action">
            <a href="<?php echo esc_url( rest_url( 'wisply/v1/admin/export' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>"
               class="button">
                ייצוא CSV
            </a>
        </div>
    </div>

    <div class="wisply-status-row">
        <h2>סטטוס מערכת</h2>
        <table class="widefat wisply-status-table">
            <tbody>
            <tr>
                <td><strong>ספק AI</strong></td>
                <td id="wisply-provider">—</td>
            </tr>
            <tr>
                <td><strong>מודל</strong></td>
                <td id="wisply-model">—</td>
            </tr>
            <tr>
                <td><strong>API Key</strong></td>
                <td id="wisply-apikey-status">—</td>
            </tr>
            <tr>
                <td><strong>מספר דפים באינדקס</strong></td>
                <td id="wisply-index-count">—</td>
            </tr>
            </tbody>
        </table>

        <p>
            <button id="wisply-test-btn" class="button button-secondary">בדוק חיבור ל-AI</button>
            <span id="wisply-test-result" style="margin-right:10px;"></span>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cfg = window.WisplyAdmin || {};
    const s   = cfg.settings || {};

    document.getElementById('wisply-stat-unanswered').textContent = cfg.unansweredCount ?? '—';
    document.getElementById('wisply-stat-indexed').textContent    = cfg.contentCount ?? '—';
    document.getElementById('wisply-provider').textContent        = s.ai_provider || '—';
    document.getElementById('wisply-model').textContent           = s.ai_provider === 'openai' ? s.openai_model : s.ai_model;
    // Check the active provider's key — not always ai_api_key (Claude)
    const activeKey = s.ai_provider === 'openai' ? s.openai_api_key : s.ai_api_key;
    document.getElementById('wisply-apikey-status').textContent = activeKey ? '✅ מוגדר' : '❌ חסר';
    document.getElementById('wisply-index-count').textContent     = cfg.contentCount ?? '—';

    document.getElementById('wisply-test-btn').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        const el = document.getElementById('wisply-test-result');
        el.textContent = 'בודק...';
        try {
            const fd = new FormData();
            fd.append('action', 'wisply_test_ai');
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
