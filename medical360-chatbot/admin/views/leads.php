<?php
defined( 'ABSPATH' ) || exit;

// Save the notification email setting
$notice = '';
if ( isset( $_POST['m360_leadmail_nonce'] ) && check_admin_referer( 'm360_save_leadmail', 'm360_leadmail_nonce' ) && current_user_can( 'manage_options' ) ) {
    $em = sanitize_email( $_POST['lead_email'] ?? '' );
    update_option( 'm360_lead_email', $em );
    $notice = '<div class="notice notice-success is-dismissible"><p>✅ כתובת התראות הלידים נשמרה.</p></div>';
}

// Delete a lead
if ( isset( $_POST['m360_dellead_nonce'] ) && check_admin_referer( 'm360_del_lead', 'm360_dellead_nonce' ) && current_user_can( 'manage_options' ) ) {
    $idx   = (int) ( $_POST['lead_idx'] ?? -1 );
    $leads = get_option( 'm360_leads', [] );
    if ( isset( $leads[ $idx ] ) ) {
        array_splice( $leads, $idx, 1 );
        update_option( 'm360_leads', $leads, false );
        $notice = '<div class="notice notice-success is-dismissible"><p>🗑️ הליד נמחק.</p></div>';
    }
}

$leads      = array_reverse( (array) get_option( 'm360_leads', [] ) ); // newest first
$total      = count( $leads );
$lead_email = get_option( 'm360_lead_email' ) ?: get_option( 'admin_email' );
$base       = admin_url( 'admin.php?page=medical360-leads' );

// CSV export
if ( isset( $_GET['export'] ) && current_user_can( 'manage_options' ) ) {
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="medical360-leads-' . date( 'Y-m-d' ) . '.csv"' );
    echo "\xEF\xBB\xBF";
    echo "שם,טלפון,אימייל,התעניינות,סיכום שיחה,מחלקה,מקור,קמפיין,דף נחיתה,הסכמה שיווקית,גרסת הסכמה,זמן הסכמה,סטטוס,Logicare,אורך שיחה,הקשר מלא,שפה,תאריך,עמוד\n";
    foreach ( $leads as $l ) {
        echo '"' . implode( '","', array_map( fn( $v ) => str_replace( '"', '""', (string) ( $v ?? '' ) ), [
            $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '',
            $l['interest'] ?? '', $l['summary'] ?? '', $l['department'] ?? '',
            $l['source'] ?? '', $l['campaign'] ?? '', $l['landing_page'] ?? '',
            ! empty( $l['marketing_consent'] ) ? 'כן' : 'לא',
            $l['consent_version'] ?? '', $l['consent_time'] ?? '',
            $l['lead_status'] ?? '', $l['logicare'] ?? '',
            $l['conversation_length'] ?? '',
            $l['context'] ?? '', $l['lang'] ?? '', $l['time'] ?? '', $l['page'] ?? '',
        ] ) ) . "\"\n";
    }
    exit;
}
?>
<div class="wrap m360-admin" dir="rtl" style="font-family:'Open Sans Hebrew',Arial,sans-serif">
    <h1 style="color:#00A3A3">לידים מהעוזר החכם</h1>
    <?php echo $notice; ?>

    <!-- Notification email setting -->
    <form method="post" style="background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;margin:16px 0;max-width:560px">
        <?php wp_nonce_field( 'm360_save_leadmail', 'm360_leadmail_nonce' ); ?>
        <label style="font-weight:600;display:block;margin-bottom:6px">📧 כתובת לקבלת התראות על לידים חדשים</label>
        <div style="display:flex;gap:8px">
            <input type="email" name="lead_email" value="<?php echo esc_attr( $lead_email ); ?>"
                   class="regular-text" placeholder="name@example.com" style="flex:1" dir="ltr">
            <button type="submit" class="button button-primary">שמור</button>
        </div>
        <p class="description" style="margin-top:6px">בכל ליד חדש יישלח מייל עם הכותרת "ליד חדש מהעוזר החכם" לכתובת זו.</p>
    </form>

    <?php
    $consented = 0;
    foreach ( $leads as $l ) { if ( ! empty( $l['marketing_consent'] ) ) $consented++; }
    $opt_rate = $total ? round( $consented / $total * 100 ) : 0;
    ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap">
        <strong><?php echo $total; ?> לידים</strong>
        <?php if ( $total ) : ?>
            <span style="background:#e9f8ef;color:#0a8f3c;padding:4px 12px;border-radius:999px;font-weight:600">Opt-In: <?php echo $opt_rate; ?>% (<?php echo $consented; ?>)</span>
            <a href="<?php echo esc_url( add_query_arg( 'export', 1, $base ) ); ?>" class="button">⬇ ייצוא CSV</a>
        <?php endif; ?>
    </div>

    <?php if ( ! $total ) : ?>
        <div style="text-align:center;padding:48px;color:#888;background:#fff;border:1px solid #e5e5e5;border-radius:8px">
            עדיין אין לידים. כשגולש ישאיר פרטים בצ׳אט, הם יופיעו כאן (וגם יישלחו למייל).
        </div>
    <?php else : ?>
        <table class="widefat striped" style="border-radius:8px;overflow:hidden">
            <thead><tr>
                <th>תאריך</th><th>שם</th><th>טלפון</th><th>אימייל</th><th>התעניינות / סיכום / הקשר</th><th>מקור</th><th>Opt-In</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $leads as $i => $l ) :
                $orig_idx = $total - 1 - $i; // index in the non-reversed array
                $has_consent = ! empty( $l['marketing_consent'] );
                ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html( $l['time'] ?? '' ); ?></td>
                    <td><strong><?php echo esc_html( $l['name'] ?? '' ); ?></strong></td>
                    <td><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $l['phone'] ?? '' ) ); ?>"><?php echo esc_html( $l['phone'] ?? '' ); ?></a></td>
                    <td><?php echo $l['email'] ? '<a href="mailto:' . esc_attr( $l['email'] ) . '">' . esc_html( $l['email'] ) . '</a>' : '—'; ?></td>
                    <td style="max-width:420px">
                        <?php if ( ! empty( $l['interest'] ) ) : ?>
                            <span style="background:#E0F5F5;color:#007878;padding:2px 8px;border-radius:6px;font-weight:600;display:inline-block;margin-bottom:4px"><?php echo esc_html( $l['interest'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $l['summary'] ) ) : ?>
                            <div style="font-size:13px;color:#333;margin:2px 0 4px"><?php echo esc_html( $l['summary'] ); ?></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $l['department'] ) ) : ?>
                            <div style="font-size:12px;color:#777">מחלקה: <?php echo esc_html( $l['department'] ); ?></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $l['context'] ) ) : ?>
                            <details style="margin-top:2px">
                                <summary style="cursor:pointer;color:#007878;font-size:12px">צפייה בשיחה המלאה</summary>
                                <div style="white-space:pre-line;background:#f7fafa;border:1px solid #e0eeee;border-radius:8px;padding:8px 10px;margin-top:6px;font-size:13px;line-height:1.6;max-height:220px;overflow:auto"><?php echo esc_html( $l['context'] ); ?></div>
                            </details>
                        <?php elseif ( ! empty( $l['message'] ) ) : ?>
                            <?php echo esc_html( $l['message'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#555">
                        <?php echo esc_html( $l['source'] ?? '—' ); ?>
                        <?php if ( ! empty( $l['campaign'] ) ) : ?><br><span style="color:#999"><?php echo esc_html( $l['campaign'] ); ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $has_consent ) : ?>
                            <span title="גרסה <?php echo esc_attr( $l['consent_version'] ?? '' ); ?> · <?php echo esc_attr( $l['consent_time'] ?? '' ); ?>" style="color:#0a8f3c;font-weight:700">✓</span>
                        <?php else : ?>
                            <span title="ללא הסכמה — אסור לפנות שיווקית" style="color:#b32d2e;font-weight:700">✗</span>
                        <?php endif; ?>
                        <?php
                        $crm = $l['logicare'] ?? '';
                        if ( $crm === 'sent' ) : ?>
                            <br><span title="נשלח ל-Logicare" style="color:#0a8f3c;font-size:11px">CRM ✓</span>
                        <?php elseif ( $crm !== '' && $crm !== 'disabled' && $crm !== 'not_configured' ) : ?>
                            <br><span title="<?php echo esc_attr( $crm ); ?>" style="color:#b32d2e;font-size:11px">CRM ✗</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('למחוק את הליד?');" style="margin:0">
                            <?php wp_nonce_field( 'm360_del_lead', 'm360_dellead_nonce' ); ?>
                            <input type="hidden" name="lead_idx" value="<?php echo $orig_idx; ?>">
                            <button type="submit" class="button button-small" style="color:#dc2626">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
