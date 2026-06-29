<?php
defined( 'ABSPATH' ) || exit;

$db          = M360_Database::get_instance();
$reindex_msg = '';

// ── Handle ALL form POSTs (no AJAX — reliable on all servers) ─────────────
if ( isset( $_POST['m360_reindex_nonce'] ) ) {
    if ( ! check_admin_referer( 'm360_do_reindex', 'm360_reindex_nonce' ) ) {
        $reindex_msg = '<div class="notice notice-error"><p>שגיאת אבטחה.</p></div>';
    } elseif ( ! current_user_can( 'manage_options' ) ) {
        $reindex_msg = '<div class="notice notice-error"><p>אין הרשאה.</p></div>';
    } else {
        $result  = M360_Content_Indexer::get_instance()->full_reindex();
        $indexed = (int) $result['indexed'];
        $failed  = (int) ( $result['failed'] ?? 0 );
        $dberr   = $result['db_error'] ?? '';

        if ( $indexed > 0 ) {
            $extra = $failed ? " ($failed נכשלו)" : '';
            $reindex_msg = '<div class="notice notice-success is-dismissible"><p>✅ אונדקסו <strong>' . $indexed . '</strong> פריטים בהצלחה' . $extra . '.</p></div>';
        } else {
            $err_detail  = $dberr ? '<br><code>' . esc_html( $dberr ) . '</code>' : ' — נסה להשבית ולהפעיל מחדש את הפלאגין כדי ליצור את הטבלאות.';
            $reindex_msg = '<div class="notice notice-error"><p>❌ אינדוקס נכשל — 0 פריטים נכתבו לבסיס הנתונים.' . $err_detail . '</p></div>';
        }
    }
}

// ── Clear-all form POST ─────────────────────────────────────────────────────
if ( isset( $_POST['m360_clearall_nonce'] ) ) {
    if ( check_admin_referer( 'm360_clear_all', 'm360_clearall_nonce' ) && current_user_can( 'manage_options' ) ) {
        $db->clear_content_index();
        $reindex_msg = '<div class="notice notice-success is-dismissible"><p>🗑️ כל התוכן המאונדקס נמחק. ה-Knowledge Base ריק כעת.</p></div>';
    }
}

// ── Sitemap form POST ──────────────────────────────────────────────────────
if ( isset( $_POST['m360_sitemap_nonce'] ) ) {
    if ( check_admin_referer( 'm360_add_sitemap', 'm360_sitemap_nonce' ) && current_user_can( 'manage_options' ) ) {
        $url    = esc_url_raw( $_POST['sitemap_url'] ?? '' );
        $result = M360_Content_Indexer::get_instance()->index_sitemap( $url );
        if ( $result['success'] ) {
            $capped = ! empty( $result['capped'] ) ? ' (הוגבל ל-' . (int)$result['total'] . ' עמודים בריצה אחת — הרץ שוב להמשך)' : '';
            $reindex_msg = '<div class="notice notice-success is-dismissible"><p>✅ Sitemap: אונדקסו <strong>' . (int)$result['indexed'] . '</strong> עמודים' . ( $result['errors'] ? ', ' . (int)$result['errors'] . ' שגיאות' : '' ) . $capped . '.</p></div>';
        } else {
            $reindex_msg = '<div class="notice notice-error"><p>❌ שגיאת Sitemap: ' . esc_html( $result['error'] ?? '' ) . '</p></div>';
        }
    }
}

// ── Single URL form POST ───────────────────────────────────────────────────
if ( isset( $_POST['m360_url_nonce'] ) ) {
    if ( check_admin_referer( 'm360_add_url', 'm360_url_nonce' ) && current_user_can( 'manage_options' ) ) {
        $url    = esc_url_raw( $_POST['single_url'] ?? '' );
        $lang   = sanitize_text_field( $_POST['url_lang'] ?? 'he' );
        $result = M360_Content_Indexer::get_instance()->index_url( $url, $lang );
        if ( $result['success'] ) {
            $reindex_msg = '<div class="notice notice-success is-dismissible"><p>✅ URL אונדקס: <strong>' . esc_html( $result['title'] ?? $url ) . '</strong> (' . number_format( (int)($result['chars']??0) ) . ' תווים).</p></div>';
        } else {
            $reindex_msg = '<div class="notice notice-error"><p>❌ שגיאת URL: ' . esc_html( $result['error'] ?? '' ) . '</p></div>';
        }
    }
}

// ── URL list form POST ─────────────────────────────────────────────────────
if ( isset( $_POST['m360_urllist_nonce'] ) ) {
    if ( check_admin_referer( 'm360_add_urllist', 'm360_urllist_nonce' ) && current_user_can( 'manage_options' ) ) {
        $list   = sanitize_textarea_field( $_POST['url_list'] ?? '' );
        $lang   = sanitize_text_field( $_POST['urllist_lang'] ?? 'he' );
        $result = M360_Content_Indexer::get_instance()->index_url_list( $list, $lang );
        $reindex_msg = '<div class="notice notice-success is-dismissible"><p>✅ אונדקסו <strong>' . (int)$result['indexed'] . '</strong> URLs' . ( count($result['errors']??[]) ? ', ' . count($result['errors']) . ' שגיאות' : '' ) . '.</p></div>';
    }
}

// ── Manual text form POST ──────────────────────────────────────────────────
if ( isset( $_POST['m360_text_nonce'] ) ) {
    if ( check_admin_referer( 'm360_add_text', 'm360_text_nonce' ) && current_user_can( 'manage_options' ) ) {
        $title   = sanitize_text_field( $_POST['text_title'] ?? '' );
        $content = sanitize_textarea_field( $_POST['text_content'] ?? '' );
        $lang    = sanitize_text_field( $_POST['text_lang'] ?? 'he' );
        $result  = M360_Content_Indexer::get_instance()->index_text( $title, $content, $lang );
        if ( $result['success'] ) {
            $reindex_msg = '<div class="notice notice-success is-dismissible"><p>✅ טקסט נוסף בהצלחה (' . number_format( (int)($result['chars']??0) ) . ' תווים).</p></div>';
        } else {
            $reindex_msg = '<div class="notice notice-error"><p>❌ שגיאה: ' . esc_html( $result['error'] ?? '' ) . '</p></div>';
        }
    }
}

// ── Bot diagnostic test (form POST) ─────────────────────────────────────────
$test_question = '';
$test_result   = null;
if ( isset( $_POST['m360_test_nonce'] ) ) {
    if ( check_admin_referer( 'm360_test_bot', 'm360_test_nonce' ) && current_user_can( 'manage_options' ) ) {
        $test_question = sanitize_text_field( $_POST['test_question'] ?? '' );
        $test_lang     = sanitize_text_field( $_POST['test_lang'] ?? 'he' );
        if ( $test_question ) {
            $test_result = M360_AI_Handler::get_instance()->diagnose( $test_question, $test_lang );
        }
    }
}

$search  = sanitize_text_field( $_GET['search'] ?? '' );
$page    = max( 1, (int) ( $_GET['kb_page'] ?? 1 ) );
$data    = $db->get_indexed_list( $search, $page, 25 );
$rows    = $data['rows'];
$total   = $data['total'];
$pages   = max( 1, (int) ceil( $total / 25 ) );
$base    = admin_url( 'admin.php?page=medical360-index' );

// Source type icons + labels
$source_types = [
    'sitemap'  => [ 'icon' => '🗺️', 'label' => 'Sitemap' ],
    'url'      => [ 'icon' => '🔗', 'label' => 'URL' ],
    'url_list' => [ 'icon' => '📋', 'label' => 'רשימת URLs' ],
    'text'     => [ 'icon' => '✏️', 'label' => 'טקסט ידני' ],
    'wp'       => [ 'icon' => '📝', 'label' => 'WordPress' ],
];
?>
<style>
.m360-kb { direction:rtl; font-family:"Open Sans Hebrew",Arial,sans-serif; }
.m360-kb h1 { margin:0; font-size:24px; }
.m360-source-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.m360-source-tab {
    display:flex; align-items:center; gap:6px; padding:9px 16px;
    border-radius:8px; border:1.5px solid #e2e8f0; background:#fff;
    font-size:13px; font-weight:600; cursor:pointer; color:#555;
    transition:all .15s;
}
.m360-source-tab:hover { border-color:#00A3A3; color:#00A3A3; }
.m360-source-tab.active { background:#00A3A3; color:#fff; border-color:#00A3A3; }
.m360-source-panel { display:none; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:20px; }
.m360-source-panel.active { display:block; }
.m360-source-panel label { font-weight:600; font-size:13px; display:block; margin-bottom:5px; }
.m360-source-panel input[type=text],
.m360-source-panel input[type=url],
.m360-source-panel select,
.m360-source-panel textarea { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:14px; direction:rtl; font-family:inherit; margin-bottom:12px; }
.m360-source-panel textarea { min-height:120px; resize:vertical; }
.m360-source-panel input:focus, .m360-source-panel textarea:focus, .m360-source-panel select:focus {
    border-color:#00A3A3; outline:none; box-shadow:0 0 0 3px rgba(0,163,163,.1);
}
.m360-btn-primary { background:#00A3A3; color:#fff; border:none; padding:10px 22px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; }
.m360-btn-primary:hover { background:#007878; }
.m360-btn-primary:disabled { background:#ccc; }
.m360-stat-bar { display:flex; gap:0; background:#fff; border:1px solid #e5e5e5; border-radius:8px; margin-bottom:20px; overflow:hidden; }
.m360-stat-item { flex:1; padding:14px 18px; text-align:center; border-left:1px solid #e5e5e5; }
.m360-stat-item:last-child { border-left:none; }
.m360-stat-num { font-size:24px; font-weight:700; }
.m360-stat-lbl { font-size:12px; color:#777; margin-top:2px; }
.m360-kb-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e5e5; border-radius:8px; overflow:hidden; }
.m360-kb-table th { background:#f8fafc; padding:10px 14px; font-size:12px; color:#64748b; font-weight:600; text-align:right; border-bottom:1px solid #e5e5e5; }
.m360-kb-table td { padding:12px 14px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:middle; }
.m360-kb-table tr:last-child td { border-bottom:none; }
.m360-kb-table tr:hover td { background:#f8fafc; }
.m360-status-ok { background:#dcfce7; color:#15803d; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; }
.m360-type-badge { background:#f1f5f9; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:600; color:#475569; }
.m360-del { background:none; border:none; cursor:pointer; color:#dc2626; font-size:16px; padding:4px 8px; border-radius:6px; }
.m360-del:hover { background:#fee2e2; }
.m360-source-result { margin-top:10px; font-size:13px; font-weight:600; }
</style>

<div class="wrap m360-kb">

    <?php echo $reindex_msg; ?>

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
        <div>
            <h1>Knowledge Base</h1>
            <p style="color:#888;margin:4px 0 0;font-size:13px">תוכן שהבוט לומד ממנו. הוסף מקורות כדי לשפר את תשובות הבוט.</p>
        </div>
    </div>

    <!-- Add source section -->
    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px">
        <h2 style="margin:0 0 14px;font-size:16px">+ הוסף מקור נתונים</h2>

        <div class="m360-source-tabs">
            <button class="m360-source-tab active" data-panel="panel-wp">📝 WordPress</button>
            <button class="m360-source-tab" data-panel="panel-sitemap">🗺️ Sitemap</button>
            <button class="m360-source-tab" data-panel="panel-url">🔗 URL בודד</button>
            <button class="m360-source-tab" data-panel="panel-url-list">📋 רשימת URLs</button>
            <button class="m360-source-tab" data-panel="panel-text">✏️ טקסט ידני</button>
        </div>

        <!-- WordPress panel — standard form POST, no AJAX -->
        <div id="panel-wp" class="m360-source-panel active">
            <p style="color:#555;font-size:13px;margin:0 0 12px">מאנדקס אוטומטית את כל הפוסטים, עמודים, מחלקות, פודקאסטים וסדנאות מהאתר.</p>
            <form method="post" action="" style="display:inline">
                <?php wp_nonce_field( 'm360_do_reindex', 'm360_reindex_nonce' ); ?>
                <button type="submit" class="m360-btn-primary">↺ אנדקס תוכן WordPress עכשיו</button>
            </form>
        </div>

        <!-- Sitemap panel -->
        <div id="panel-sitemap" class="m360-source-panel">
            <p style="color:#555;font-size:13px;margin:0 0 12px">
                ה-Sitemap שלך: <a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank" style="color:#00A3A3"><?php echo esc_url(home_url('/sitemap.xml')); ?> ↗</a>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('m360_add_sitemap','m360_sitemap_nonce'); ?>
                <label>כתובת Sitemap</label>
                <input type="url" name="sitemap_url" value="<?php echo esc_attr(home_url('/sitemap.xml')); ?>" required dir="ltr">
                <button type="submit" class="m360-btn-primary" style="margin-top:4px">אנדקס Sitemap</button>
            </form>
        </div>

        <!-- Single URL panel -->
        <div id="panel-url" class="m360-source-panel">
            <form method="post" action="">
                <?php wp_nonce_field('m360_add_url','m360_url_nonce'); ?>
                <label>כתובת URL</label>
                <input type="url" name="single_url" placeholder="https://medical360.org/about" required dir="ltr">
                <label style="margin-top:4px">שפה</label>
                <select name="url_lang"><option value="he">עברית</option><option value="en">English</option><option value="ru">Русский</option></select>
                <button type="submit" class="m360-btn-primary" style="margin-top:4px">אנדקס URL</button>
            </form>
        </div>

        <!-- URL list panel -->
        <div id="panel-url-list" class="m360-source-panel">
            <form method="post" action="">
                <?php wp_nonce_field('m360_add_urllist','m360_urllist_nonce'); ?>
                <label>רשימת URLs (אחד בשורה)</label>
                <textarea name="url_list" placeholder="https://medical360.org/page1&#10;https://medical360.org/page2" dir="ltr"></textarea>
                <label style="margin-top:4px">שפה</label>
                <select name="urllist_lang"><option value="he">עברית</option><option value="en">English</option><option value="ru">Русский</option></select>
                <button type="submit" class="m360-btn-primary" style="margin-top:4px">אנדקס רשימה</button>
            </form>
        </div>

        <!-- Manual text panel -->
        <div id="panel-text" class="m360-source-panel">
            <form method="post" action="">
                <?php wp_nonce_field('m360_add_text','m360_text_nonce'); ?>
                <label>כותרת</label>
                <input type="text" name="text_title" placeholder="למשל: שאלות נפוצות על קבלה" required>
                <label style="margin-top:4px">תוכן</label>
                <textarea name="text_content" placeholder="כתוב כאן את התוכן שהבוט ילמד..."></textarea>
                <label style="margin-top:4px">שפה</label>
                <select name="text_lang"><option value="he">עברית</option><option value="en">English</option><option value="ru">Русский</option></select>
                <button type="submit" class="m360-btn-primary" style="margin-top:4px">הוסף טקסט</button>
            </form>
        </div>
    </div>

    <!-- Bot diagnostic -->
    <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:24px">
        <h2 style="margin:0 0 6px;font-size:16px">🔍 בדוק מה הבוט עונה</h2>
        <p style="color:#777;font-size:13px;margin:0 0 12px">הקלד שאלה כמו שגולש ישאל — ותראה אילו דפים הבוט שלף, כמה תוכן יש בכל אחד, ומה הוא ענה.</p>
        <form method="post" action="">
            <?php wp_nonce_field( 'm360_test_bot', 'm360_test_nonce' ); ?>
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <input type="text" name="test_question" value="<?php echo esc_attr( $test_question ); ?>"
                       placeholder="למשל: מי הרופא במחלקת שיקום כללי?" required
                       style="flex:1;min-width:280px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;direction:rtl;font-size:14px">
                <select name="test_lang" style="padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px">
                    <option value="he">עברית</option><option value="en">English</option><option value="ru">Русский</option>
                </select>
                <button type="submit" class="m360-btn-primary">בדוק</button>
            </div>
        </form>

        <?php if ( $test_result ) : ?>
            <div style="margin-top:16px;border-top:1px solid #eef2f7;padding-top:14px">
                <div style="font-size:12px;color:#888;margin-bottom:8px">
                    מילות חיפוש בפועל: <code style="background:#f1f5f9;padding:1px 6px;border-radius:4px;direction:rtl"><?php echo esc_html( $test_result['search_query'] ); ?></code>
                </div>

                <strong style="font-size:13px">📄 דפים שנשלפו (<?php echo count( $test_result['docs'] ); ?>):</strong>
                <?php if ( empty( $test_result['docs'] ) ) : ?>
                    <div style="color:#dc2626;padding:8px;background:#fef2f2;border-radius:6px;margin-top:6px">
                        ❌ שום דף לא נשלף — הבוט אין לו מאיפה לענות. בדוק שהדף הרלוונטי באינדקס למטה.
                    </div>
                <?php else : ?>
                    <table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:13px">
                        <?php foreach ( $test_result['docs'] as $d ) : ?>
                            <tr style="border-bottom:1px solid #f1f5f9">
                                <td style="padding:6px 4px"><?php echo esc_html( $d['title'] ); ?></td>
                                <td style="padding:6px 4px;text-align:left;color:<?php echo $d['chars'] < 50 ? '#dc2626' : '#16a34a'; ?>">
                                    <?php echo number_format( (int) $d['chars'] ); ?> תווים
                                    <?php echo $d['chars'] < 50 ? '⚠️ ריק!' : '✓'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <strong style="font-size:13px;display:block;margin-top:14px">💬 תשובת הבוט:</strong>
                <div style="margin-top:6px;padding:12px 14px;background:#f0fafa;border:1px solid #d0ecec;border-radius:8px;direction:rtl;line-height:1.6;white-space:pre-wrap"><?php echo esc_html( $test_result['reply'] ); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Stats bar -->
    <div class="m360-stat-bar">
        <div class="m360-stat-item">
            <div class="m360-stat-num"><?php echo (int) $total; ?></div>
            <div class="m360-stat-lbl">מקורות</div>
        </div>
        <div class="m360-stat-item">
            <div class="m360-stat-num" style="color:#22c55e"><?php echo (int) $total; ?></div>
            <div class="m360-stat-lbl">● מעובדים</div>
        </div>
        <div class="m360-stat-item">
            <div class="m360-stat-num" style="color:#f59e0b">0</div>
            <div class="m360-stat-lbl">● ממתינים</div>
        </div>
    </div>

    <!-- Search + Delete All -->
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap">
        <form method="get" style="margin:0">
            <input type="hidden" name="page" value="medical360-index">
            <div style="display:flex;gap:8px">
                <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
                       placeholder="חפש לפי כותרת או URL..." class="regular-text" style="direction:rtl;max-width:340px">
                <button type="submit" class="button">חפש</button>
                <?php if ($search) : ?><a href="<?php echo esc_url($base); ?>" class="button">נקה</a><?php endif; ?>
            </div>
        </form>

        <?php if ( $total > 0 ) : ?>
        <form method="post" style="margin:0"
              onsubmit="return confirm('למחוק את כל <?php echo (int)$total; ?> הפריטים המאונדקסים? פעולה זו אינה הפיכה.');">
            <?php wp_nonce_field( 'm360_clear_all', 'm360_clearall_nonce' ); ?>
            <button type="submit" class="button" style="color:#dc2626;border-color:#dc2626">
                🗑️ מחק את כל האינדקס (<?php echo (int)$total; ?>)
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Table -->
    <?php if (empty($rows)) : ?>
        <div style="text-align:center;padding:48px;color:#888;background:#fff;border:1px solid #e5e5e5;border-radius:8px">
            <?php echo $search ? 'לא נמצאו תוצאות.' : 'האינדקס ריק — הוסף מקור נתונים למעלה.'; ?>
        </div>
    <?php else : ?>
    <table class="m360-kb-table">
        <thead><tr>
            <th style="width:42%">עמוד / מקור</th>
            <th style="width:10%;text-align:center">תווים</th>
            <th style="width:9%;text-align:center">שפה</th>
            <th style="width:9%;text-align:center">סוג</th>
            <th style="width:12%;text-align:center">סטטוס</th>
            <th style="width:12%;text-align:center">תאריך</th>
            <th style="width:6%;text-align:center"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr id="m360-row-<?php echo (int)$row['post_id']; ?>-<?php echo esc_attr($row['lang']); ?>">
                <td>
                    <div style="font-weight:600;margin-bottom:3px"><?php echo esc_html($row['title']); ?></div>
                    <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"
                       style="font-size:12px;color:#2563eb;word-break:break-all"><?php echo esc_html($row['url']); ?></a>
                </td>
                <td style="text-align:center;color:#555"><?php echo number_format((int)$row['char_count']); ?></td>
                <td style="text-align:center"><span class="m360-type-badge"><?php echo esc_html(strtoupper($row['lang'])); ?></span></td>
                <td style="text-align:center">
                    <span class="m360-type-badge">
                        <?php
                        $type_labels = [
                            'post' => 'Post', 'page' => 'Page', 'external_url' => 'URL',
                            'manual_text' => 'Text', 'department' => 'מחלקה',
                            'podcast' => 'Podcast', 'workshop' => 'סדנה', 'faq' => 'FAQ',
                        ];
                        echo esc_html($type_labels[$row['post_type']] ?? $row['post_type']);
                        ?>
                    </span>
                </td>
                <td style="text-align:center"><span class="m360-status-ok">✓ Processed</span></td>
                <td style="text-align:center;color:#777;font-size:12px">
                    <?php echo esc_html(date_i18n('j M Y', strtotime($row['indexed_at']))); ?>
                </td>
                <td style="text-align:center">
                    <button class="m360-del m360-del-btn"
                            data-post-id="<?php echo (int)$row['post_id']; ?>"
                            data-lang="<?php echo esc_attr($row['lang']); ?>"
                            title="הסר">🗑</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
    <div style="display:flex;gap:6px;align-items:center;margin-top:14px;direction:rtl">
        <?php if ($page > 1) : ?>
            <a href="<?php echo esc_url(add_query_arg(['kb_page'=>$page-1,'search'=>$search],$base)); ?>" class="button">« הקודם</a>
        <?php endif; ?>
        <span style="color:#555;font-size:13px">עמוד <?php echo $page; ?> מתוך <?php echo $pages; ?> (<?php echo $total; ?> פריטים)</span>
        <?php if ($page < $pages) : ?>
            <a href="<?php echo esc_url(add_query_arg(['kb_page'=>$page+1,'search'=>$search],$base)); ?>" class="button">הבא »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<script>
(function() {
    const nonce   = (window.M360Admin || {}).ajaxNonce || '';
    const ajax    = window.ajaxurl || '/wp-admin/admin-ajax.php';

    // ── Tab switching ─────────────────────────────────────────────────
    document.querySelectorAll('.m360-source-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.m360-source-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.m360-source-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(this.dataset.panel).classList.add('active');
        });
    });

    // ── Add source (URL, Sitemap, Text) via AJAX ─────────────────────
    document.querySelectorAll('.m360-add-source-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const type   = this.dataset.type;
            const result = document.getElementById('result-' + type.replace('_','-'));
            const fd     = new FormData();
            fd.append('action', 'm360_add_source');
            fd.append('nonce', nonce);
            fd.append('source_type', type);

            if (type === 'sitemap') {
                const url = document.getElementById('sitemap-url').value.trim();
                if (!url) { result.textContent='⚠️ הכנס כתובת Sitemap'; result.style.color='#f59e0b'; return; }
                fd.append('sitemap_url', url);
            } else if (type === 'url') {
                const url = document.getElementById('single-url').value.trim();
                if (!url) { result.textContent='⚠️ הכנס URL'; result.style.color='#f59e0b'; return; }
                fd.append('url', url);
                fd.append('lang', document.getElementById('url-lang').value);
            } else if (type === 'url_list') {
                const list = document.getElementById('url-list-input').value.trim();
                if (!list) { result.textContent='⚠️ הכנס רשימת URLs'; result.style.color='#f59e0b'; return; }
                fd.append('url_list', list);
                fd.append('lang', document.getElementById('url-list-lang').value);
            } else if (type === 'text') {
                const title = document.getElementById('text-title').value.trim();
                const content = document.getElementById('text-content').value.trim();
                if (!title || !content) { result.textContent='⚠️ מלא כותרת ותוכן'; result.style.color='#f59e0b'; return; }
                fd.append('text_title', title);
                fd.append('text_content', content);
                fd.append('lang', document.getElementById('text-lang').value);
            }

            this.disabled = true;
            result.textContent = '⏳ מעבד...'; result.style.color='#555';

            try {
                const res  = await fetch(ajax, {method:'POST', body:fd});
                const json = await res.json();
                if (json.success) {
                    const d = json.data || {};
                    const msg = d.indexed != null
                        ? '✅ ' + d.indexed + ' פריטים נוספו' + (d.errors ? ' (' + d.errors + ' שגיאות)' : '')
                        : '✅ נוסף בהצלחה (' + (d.chars||0).toLocaleString() + ' תווים)';
                    result.textContent = msg; result.style.color='#15803d';
                    setTimeout(() => location.reload(), 1800);
                } else {
                    result.textContent = '❌ ' + (json.data || 'שגיאה');
                    result.style.color='#dc2626';
                }
            } catch(e) { result.textContent='❌ '+e.message; result.style.color='#dc2626'; }
            this.disabled = false;
        });
    });

    // ── Delete item ───────────────────────────────────────────────────
    document.querySelectorAll('.m360-del-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!confirm('הסר פריט זה מהאינדקס?')) return;
            this.disabled = true;
            const fd = new FormData();
            fd.append('action','m360_delete_indexed'); fd.append('nonce',nonce);
            fd.append('post_id', this.dataset.postId); fd.append('lang', this.dataset.lang);
            const res  = await fetch(ajax, {method:'POST', body:fd});
            const json = await res.json();
            if (json.success) {
                document.getElementById('m360-row-'+this.dataset.postId+'-'+this.dataset.lang)?.remove();
            } else {
                this.disabled = false;
                alert('שגיאה במחיקה');
            }
        });
    });
})();
</script>
