<?php
defined( 'ABSPATH' ) || exit;

$db = M360_Database::get_instance();

// Filters from query string
$flt = [
    'lang'       => sanitize_text_field( $_GET['lang'] ?? '' ),
    'unanswered' => ! empty( $_GET['unanswered'] ),
    'date_from'  => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'    => sanitize_text_field( $_GET['date_to'] ?? '' ),
];
$page = max( 1, (int) ( $_GET['cpage'] ?? 1 ) );
$data = $db->get_conversations_paginated( $page, 20, $flt );
$rows = $data['rows'];
$total = $data['total'];
$pages = max( 1, (int) ceil( $total / 20 ) );
$base  = admin_url( 'admin.php?page=medical360-conversations' );

// If a single conversation is opened
$open_id = (int) ( $_GET['view'] ?? 0 );
$open_msgs = $open_id ? $db->get_conversation_full( $open_id ) : [];

$lang_label = fn( $l ) => [ 'he' => '🇮🇱 עברית', 'en' => '🇺🇸 English', 'ru' => '🇷🇺 Русский' ][ $l ] ?? $l;
?>
<div class="wrap m360-admin" dir="rtl" style="font-family:'Open Sans Hebrew',Arial,sans-serif">
    <h1 style="color:#00A3A3">שיחות</h1>

    <?php if ( $open_id && $open_msgs ) : ?>
        <!-- Single conversation view -->
        <p><a href="<?php echo esc_url( $base ); ?>" class="button">« חזרה לכל השיחות</a></p>
        <div style="background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px;max-width:680px">
            <h2 style="margin-top:0">שיחה #<?php echo $open_id; ?></h2>
            <div style="display:flex;flex-direction:column;gap:10px">
                <?php foreach ( $open_msgs as $m ) :
                    $is_user = $m['role'] === 'user';
                    ?>
                    <div style="max-width:80%;<?php echo $is_user ? 'align-self:flex-start' : 'align-self:flex-end'; ?>">
                        <div style="padding:10px 14px;border-radius:12px;line-height:1.6;
                            <?php echo $is_user
                                ? 'background:#00A3A3;color:#fff'
                                : 'background:#f0fafa;color:#1a1a1a;border:1px solid #d0ecec'; ?>">
                            <?php echo nl2br( esc_html( $m['content'] ) ); ?>
                        </div>
                        <div style="font-size:11px;color:#999;margin-top:3px">
                            <?php echo $is_user ? '👤 גולש' : '🤖 בוט'; ?> ·
                            <?php echo esc_html( $m['created_at'] ); ?>
                            <?php echo $m['unanswered'] ? ' · <span style="color:#dc2626">לא נענה</span>' : ''; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else : ?>
        <!-- Filters -->
        <form method="get" style="background:#f9f9f9;padding:14px 16px;border-radius:8px;margin:16px 0;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="page" value="medical360-conversations">
            <label>שפה:
                <select name="lang">
                    <option value="">הכל</option>
                    <option value="he" <?php selected( $flt['lang'], 'he' ); ?>>עברית</option>
                    <option value="en" <?php selected( $flt['lang'], 'en' ); ?>>English</option>
                    <option value="ru" <?php selected( $flt['lang'], 'ru' ); ?>>Русский</option>
                </select>
            </label>
            <label><input type="checkbox" name="unanswered" value="1" <?php checked( $flt['unanswered'] ); ?>> שאלות שלא נענו בלבד</label>
            <label>מתאריך: <input type="date" name="date_from" value="<?php echo esc_attr( $flt['date_from'] ); ?>"></label>
            <label>עד: <input type="date" name="date_to" value="<?php echo esc_attr( $flt['date_to'] ); ?>"></label>
            <button type="submit" class="button">סנן</button>
            <a class="button" href="<?php echo esc_url( rest_url( 'medical360/v1/admin/export' ) . '?_wpnonce=' . wp_create_nonce( 'wp_rest' ) ); ?>">ייצוא CSV</a>
        </form>

        <p style="color:#666"><?php echo (int) $total; ?> שיחות סה״כ</p>

        <?php if ( empty( $rows ) ) : ?>
            <div style="text-align:center;padding:48px;color:#888;background:#fff;border:1px solid #e5e5e5;border-radius:8px">
                עדיין אין שיחות. ברגע שגולשים ידברו עם הבוט, השיחות יופיעו כאן.
            </div>
        <?php else : ?>
            <table class="widefat striped" style="border-radius:8px;overflow:hidden">
                <thead><tr>
                    <th>#</th><th>שפה</th><th>עמוד</th>
                    <th>התחלה</th><th>הודעות</th><th>שאלות פתוחות</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo (int) $r['id']; ?></td>
                        <td><?php echo esc_html( $lang_label( $r['lang'] ) ); ?></td>
                        <td><small><?php echo esc_html( mb_substr( (string) $r['page_url'], 0, 46 ) ); ?></small></td>
                        <td><?php echo esc_html( $r['started_at'] ); ?></td>
                        <td><?php echo (int) $r['message_count']; ?></td>
                        <td>
                            <?php echo $r['unanswered_count'] > 0
                                ? '<span style="background:#dc2626;color:#fff;border-radius:12px;padding:2px 8px;font-size:12px">' . (int) $r['unanswered_count'] . '</span>'
                                : '—'; ?>
                        </td>
                        <td><a class="button button-small" href="<?php echo esc_url( add_query_arg( 'view', (int) $r['id'], $base ) ); ?>">צפה</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
                <div style="margin-top:14px;display:flex;gap:6px;align-items:center">
                    <?php if ( $page > 1 ) : ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'cpage' => $page - 1 ] ), $base ) ); ?>">« הקודם</a>
                    <?php endif; ?>
                    <span style="color:#555">עמוד <?php echo $page; ?> מתוך <?php echo $pages; ?></span>
                    <?php if ( $page < $pages ) : ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'cpage' => $page + 1 ] ), $base ) ); ?>">הבא »</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
