<?php
/**
 * QR codes: a printable card for each table, and one takeaway card. A table's code
 * opens the table ordering page for that table (dine-in/table/N, see dine-in/index.php)
 * to order or call a waiter; the takeaway code opens the website's online ordering
 * (menu.html, where pickup is the default). The codes are made here (admin/inc/qr.php),
 * with no outside service.
 *
 *   ?page=table-qr&from=1&to=20&size=4    cards for tables 1–20, four to a page
 *   ?page=table-qr&view=takeaway&copies=4 four takeaway cards
 *   ?page=table-qr&svg=5                  table 5's code as an SVG file, for a print shop
 *   ?page=table-qr&svg=takeaway           the takeaway code as an SVG file
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../qr.php';

const TABLE_QR_MAX = 999;    // the table ordering page takes tables 1–999
const TABLE_QR_PER_PRINT = 100;

/** The address the codes point at: the saved one, else this site's own (https://host/folder). */
function table_qr_site(): string
{
    $saved = (string) db_setting('table-qr-site', '');
    if ($saved !== '') return $saved;
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . rtrim(SITE_BASE, '/');
}

/** "https://example.com/" or "example.com" → "https://example.com"; '' if it isn't a web address. */
function table_qr_clean_site(string $s): string
{
    $s = trim($s);
    if ($s !== '' && !preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $s = rtrim($s, '/');
    $p = parse_url($s);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['query']) || isset($p['fragment'])) return '';
    return $s;
}

function table_qr_link(string $site, int $table): string
{
    return $site . '/dine-in/table/' . $table;
}

/** Online ordering, scrolled to the food menu. */
function table_qr_takeaway_link(string $site): string
{
    return $site . '/menu.html#order-menu';
}

/** True for an address only this computer or network can open (localhost, 127.x, 192.168.x…). */
function table_qr_is_local(string $site): bool
{
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    return $host === 'localhost' || preg_match('/\.(localhost|test|local)$/', $host)
        || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
}

/** True for a short-lived sharing tunnel (Cloudflare quick tunnel, ngrok…) that stops working when it's closed. */
function table_qr_is_tunnel(string $site): bool
{
    return (bool) preg_match('/(^|\.)(trycloudflare\.com|ngrok-free\.app|ngrok-free\.dev|ngrok\.io|ngrok\.app|loca\.lt)$/', strtolower((string) parse_url($site, PHP_URL_HOST)));
}

function admin_page_table_qr(): void
{
    if (is_post()) {
        require_csrf();
        $site = table_qr_clean_site(post_str('site', 200));
        if ($site === '') {
            flash('That website address doesn\'t look right. Use one like https://example.com', 'err');
        } else {
            db_setting_save('table-qr-site', $site);
            admin_log('settings.save', "Table QR codes point to $site");
            flash("Saved. The codes now point to $site.");
        }
        redirect(admin_url('table-qr', ($_GET['view'] ?? '') === 'takeaway' ? ['view' => 'takeaway'] : []));
    }

    $site = table_qr_site();
    $svg = get_str('svg', 8);
    if ($svg === 'takeaway' || ctype_digit($svg) && (int) $svg >= 1 && (int) $svg <= TABLE_QR_MAX) {
        $takeaway = $svg === 'takeaway';
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment; filename="rms-' . ($takeaway ? 'takeaway' : 'table-' . (int) $svg) . '-qr.svg"');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . qr_svg($takeaway ? table_qr_takeaway_link($site) : table_qr_link($site, (int) $svg));
        exit;
    }

    $view = get_choice('view', ['tables', 'takeaway'], 'tables');
    $size = get_choice('size', ['4', '1'], '4');
    $sizeField = '<label class="field"><span>Card size' . info_tip('Standard fits four cards on a page, for stickers or small stands. Large is one card per page, for table tents' . ($view === 'takeaway' ? ', posters or the front window.' : '.')) . '</span>'
        . '<select name="size"><option value="4"' . ($size === '4' ? ' selected' : '') . '>Standard: 4 per page</option><option value="1"' . ($size === '1' ? ' selected' : '') . '>Large: 1 per page</option></select></label>';
    $showBtn = '<div class="field sm"><span>&nbsp;</span>' . btn_submit('Show cards', '', '', '', 'refresh') . '</div>';

    // One printable card: $kicker is the small gold line, $big the large word under it.
    $card = fn(string $link, string $kicker, string $big, string $help, string $svgKey, string $alt): string => '<article class="qr-card">'
        . '<img class="qr-logo" src="' . h(SITE_BASE) . 'images/logo.png" alt="RMS">'
        . '<p class="qr-table"><span>' . h($kicker) . '</span><b' . (ctype_digit($big) ? '' : ' class="word"') . '>' . h($big) . '</b></p>'
        . '<div class="qr-code">' . qr_svg($link, $alt) . '</div>'
        . '<p class="qr-cta">Scan to order</p>'
        . '<p class="qr-help">' . h($help) . '</p>'
        . '<p class="qr-url">' . h(preg_replace('#^https?://(www\.)?#', '', $link)) . '</p>'
        . '<p class="qr-actions no-print">'
        .   '<a href="' . h($link) . '" target="_blank" rel="noopener">' . icon('external', 13) . ' Open</a>'
        .   '<a href="' . h(admin_url('table-qr', ['svg' => $svgKey])) . '" download>' . icon('download', 13) . ' SVG</a>'
        . '</p></article>';

    if ($view === 'takeaway') {
        $count = max(1, min(TABLE_QR_PER_PRINT, (int) ($_GET['copies'] ?? 1)));
        $cards = str_repeat($card(table_qr_takeaway_link($site), 'Takeaway', 'Order to go',
            'Order and pay on your phone, then pick it up here when it\'s ready.', 'takeaway', 'QR code for takeaway orders'), $count);
        $form = '<form method="get" class="qr-form"><input type="hidden" name="page" value="table-qr"><input type="hidden" name="view" value="takeaway">'
            . '<label class="field sm"><span>Copies' . info_tip('Every copy is the same code. Print a few for the bar, the front door and the counter.') . '</span><input type="number" name="copies" min="1" max="' . TABLE_QR_PER_PRINT . '" value="' . $count . '" inputmode="numeric"></label>'
            . $sizeField . $showBtn . '</form>';
        $menu = load_menu();
        $note = $menu && empty($menu['store']['pickupEnabled'])
            ? '<div class="callout warn">' . icon('alert', 16) . '<span><b>Take Away is switched off in Store settings</b>, so guests who scan this code can\'t order for pickup. <a href="' . h(admin_url('settings')) . '">Turn it on</a> before putting the code out.</span></div>'
            : '';
        $intro = 'One code for takeaway. Put it at the bar, the front door or on flyers: guests scan it, order and pay on their phone, and pick up when it\'s ready. Orders arrive on the kitchen display and in Orders as pickup.';
    } else {
        $from = max(1, min(TABLE_QR_MAX, (int) ($_GET['from'] ?? 1)));
        $to = max($from, min(TABLE_QR_MAX, (int) ($_GET['to'] ?? 20)));
        $capped = $to - $from + 1 > TABLE_QR_PER_PRINT;
        if ($capped) $to = $from + TABLE_QR_PER_PRINT - 1;
        $count = $to - $from + 1;
        $cards = '';
        for ($t = $from; $t <= $to; $t++) {
            $cards .= $card(table_qr_link($site, $t), 'Table', (string) $t, 'Point your phone camera at the code. Order here or call your server.', (string) $t, "QR code for table $t");
        }
        $form = '<form method="get" class="qr-form"><input type="hidden" name="page" value="table-qr">'
            . '<label class="field sm"><span>From table</span><input type="number" name="from" min="1" max="' . TABLE_QR_MAX . '" value="' . $from . '" inputmode="numeric"></label>'
            . '<label class="field sm"><span>To table</span><input type="number" name="to" min="1" max="' . TABLE_QR_MAX . '" value="' . $to . '" inputmode="numeric"></label>'
            . $sizeField . $showBtn . '</form>';
        $note = $capped ? '<p class="muted small">Showing ' . TABLE_QR_PER_PRINT . ' cards at a time: print these, then show the next tables.</p>' : '';
        $intro = 'Guests scan the code on their table to order from their phone or call a server. Orders arrive on the kitchen display and in Orders as dine-in, with the table number.';
    }

    $local = table_qr_is_local($site);
    $controls = '<section class="card no-print"><div class="card-body qr-controls">'
        . tabs_html([
            ['label' => 'Table codes', 'href' => admin_url('table-qr'), 'on' => $view === 'tables'],
            ['label' => 'Takeaway code', 'href' => admin_url('table-qr', ['view' => 'takeaway']), 'on' => $view === 'takeaway'],
        ])
        . $form
        . form_open(admin_url('table-qr', $view === 'takeaway' ? ['view' => 'takeaway'] : []), 'qr-form')
        . '<label class="field"><span>Website address' . info_tip('The address the codes open, without "/dine-in" or "/menu.html". It\'s normally this website\'s own address; change it only if the site moves to a new domain.') . '</span>'
        .   '<input name="site" value="' . h($site) . '" maxlength="200" inputmode="url" autocapitalize="none" spellcheck="false"></label>'
        . '<div class="field sm"><span>&nbsp;</span>' . btn_submit('Save address', '', '', '', 'check') . '</div>'
        . '</form>'
        . ($local ? '<div class="callout warn">' . icon('alert', 16) . '<span><b>These codes point to ' . h($site) . ', which only this computer can open.</b> Before printing, enter the live address (for example https://example.com) and save it.</span></div>' : '')
        . (table_qr_is_tunnel($site) ? '<div class="callout warn">' . icon('alert', 16) . '<span><b>These codes point to a temporary sharing link (' . h((string) parse_url($site, PHP_URL_HOST)) . ')</b> that stops working when it\'s closed. Before printing, enter the live address (for example https://example.com) and save it.</span></div>' : '')
        . $note
        . '</div></section>';

    $body = page_head('QR codes', $intro,
            '<button class="btn primary" type="button" data-print>' . icon('print', 16) . '<span>Print ' . plural($count, 'card') . '</span></button>')
        . $controls
        . '<div class="qr-sheet size-' . $size . '">' . $cards . '</div>'
        . '<p class="muted small no-print qr-tip">Tip: test one card with your own phone before printing them all. Laminated cards or stands last longest; keep each code at least 3 cm (1.2 in) wide.</p>';
    admin_render('QR codes', $body, 'table-qr');
}
