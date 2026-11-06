<?php
/**
 * Small helpers for building the admin's pages: escaping, formatting, icons,
 * badges, tabs, paging, the date-range picker and the charts.
 *
 * Text is always escaped with h(). The helpers that take HTML (a card's body, a
 * page's sub-heading) say so; everything a guest typed goes through h() first.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- links, redirects, requests ----------

/** A link inside the admin, e.g. admin_url('orders', ['id' => 'AB12CD']) = "?page=orders&id=AB12CD". Escape with h() in HTML. */
function admin_url(string $page = '', array $params = []): string
{
    $q = [];
    if ($page !== '' && $page !== 'dashboard') $q['page'] = $page;
    foreach ($params as $k => $v) {
        if ($v !== null && $v !== '' && $v !== false) $q[$k] = $v;
    }
    return '?' . http_build_query($q);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/** A submitted text field, trimmed and cut to $max characters. */
function post_str(string $key, int $max = 300): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim(mb_substr($v, 0, $max)) : '';
}

/** A query-string value: a string within $allowed, else $default. */
function get_choice(string $key, array $allowed, string $default): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
}

function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim(mb_substr($v, 0, $max)) : '';
}

function hidden_fields(array $params): string
{
    $out = '';
    foreach ($params as $k => $v) {
        if ($v !== null && $v !== '' && $v !== false) $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $out;
}

// ---------- one-time messages ("Saved.") shown after a redirect ----------

/** Queue a message for the next page: $type 'ok' or 'err'. */
function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type === 'err' ? 'err' : 'ok', $message];
}

/** The queued messages as HTML, then forget them. */
function flash_html(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $message]) {
        $out .= '<div class="flash ' . $type . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">' . icon($type === 'err' ? 'alert' : 'check', 16) . '<span>' . h($message) . '</span></div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

// ---------- formatting ----------

function fmt_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/** "15.99" or "$15.99" → 1599 cents; '' or non-numeric → null. */
function dollars_to_cents(string $s): ?int
{
    $s = trim(str_replace(['$', ','], '', $s));
    if ($s === '' || !is_numeric($s)) return null;
    return (int) round(((float) $s) * 100);
}

/** Day checkboxes, a time window and a date range for a menu item's schedule (fields sched_*). */
function schedule_fields(?array $s): string
{
    $s = $s ?? [];
    $days = '';
    foreach (WEEK_DAYS as $k => $label) {
        $days .= '<label class="check"><input type="checkbox" name="sched_days[]" value="' . $k . '" ' . (in_array($k, $s['days'] ?? [], true) ? 'checked' : '') . '> ' . $label . '</label>';
    }
    return '<div class="sched-days" role="group" aria-label="Days">' . $days . '</div>'
        . '<div class="form-grid">'
        . '<label class="field sm"><span>From</span><input type="time" name="sched_from" value="' . h($s['from'] ?? '') . '"></label>'
        . '<label class="field sm"><span>Until</span><input type="time" name="sched_to" value="' . h($s['to'] ?? '') . '"></label>'
        . '<label class="field sm"><span>Starting on</span><input type="date" name="sched_start" value="' . h($s['start'] ?? '') . '"></label>'
        . '<label class="field sm"><span>Ending on</span><input type="date" name="sched_end" value="' . h($s['end'] ?? '') . '"></label>'
        . '</div>';
}

/** Read schedule_fields() from the form: [schedule, or null for "always", and an error message or '']. */
function schedule_from_post(): array
{
    $days = array_values(array_intersect(array_keys(WEEK_DAYS), array_map('strval', (array) ($_POST['sched_days'] ?? []))));
    if (count($days) === 7) $days = []; // every day = no day limit
    $time = fn($k) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v = trim((string) ($_POST[$k] ?? ''))) ? $v : '';
    $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v = trim((string) ($_POST[$k] ?? ''))) && strtotime($v) ? $v : '';
    $s = array_filter(['days' => $days, 'from' => $time('sched_from'), 'to' => $time('sched_to'), 'start' => $date('sched_start'), 'end' => $date('sched_end')]);
    if (isset($s['from']) !== isset($s['to'])) return [null, 'Give the schedule both a "From" and an "Until" time, or leave both blank.'];
    if (isset($s['from']) && $s['from'] === $s['to']) return [null, 'The schedule\'s "From" and "Until" times are the same.'];
    if (isset($s['start'], $s['end']) && $s['start'] > $s['end']) return [null, 'The schedule ends before it starts. Check the dates.'];
    return [$s ?: null, ''];
}

/** "Last price $14.99" under a price box, from read_price_history(); '' when the price never changed here. */
function last_price_html(array $history, string $key): string
{
    if (!isset($history[$key]['prev'])) return '';
    $when = strtotime((string) ($history[$key]['changed'] ?? ''));
    return '<small class="lastprice"' . ($when ? ' title="Changed ' . h(date('M j, Y g:i A', $when)) . '"' : '') . '>Last price $'
        . number_format((int) $history[$key]['prev'] / 100, 2) . '</small>';
}

/** A dollar amount box ("$ [ 12.00 ]"). */
function money_input(string $name, string $value, string $placeholder = '', bool $required = false): string
{
    return '<span class="money">$<input name="' . h($name) . '" inputmode="decimal" value="' . h($value) . '"' . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '') . ($required ? ' required' : '') . '></span>';
}

/** A minutes box ("[ 25 ] min"). */
function minutes_input(string $name, string $value, string $placeholder = ''): string
{
    return '<span class="mins"><input name="' . h($name) . '" inputmode="numeric" value="' . h($value) . '"' . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '') . '> min</span>';
}

/** "Sep 29, 2:03 PM" (with the year for other years). Takes a timestamp or a date string. */
function fmt_when($when): string
{
    $t = is_int($when) ? $when : (int) strtotime((string) $when);
    if ($t <= 0) return '';
    return date(date('Y', $t) === date('Y') ? 'M j, g:i A' : 'M j, Y, g:i A', $t);
}

/** "Tue, Sep 29" from a Y-m-d date (with the year for other years). */
function fmt_date(string $ymd): string
{
    $t = strtotime($ymd . ' 12:00:00');
    if (!$t) return $ymd;
    return date(date('Y', $t) === date('Y') ? 'D, M j' : 'D, M j, Y', $t);
}

/** "19:30" → "7:30 PM". */
function fmt_time12(string $hhmm): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) return $hhmm;
    $h = (int) $m[1];
    return sprintf('%d:%02d %s', $h % 12 ?: 12, (int) $m[2], $h >= 12 ? 'PM' : 'AM');
}

function fmt_ago(int $ts): string
{
    if ($ts <= 0) return '';
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 86400 * 7) return floor($d / 86400) . ' d ago';
    return date('M j', $ts);
}

function plural(int $n, string $one, ?string $many = null): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

function initial(string $s): string
{
    $s = trim($s);
    return $s === '' ? '?' : mb_strtoupper(mb_substr($s, 0, 1));
}

/** Text cut to $n characters with an ellipsis, on one line. */
function snip(string $s, int $n = 90): string
{
    return mb_strimwidth(preg_replace('/\s+/', ' ', trim($s)), 0, $n, '…');
}

/** The number of days a preset button covers, as its label. */
function period_label(int $days): string
{
    return $days === 1 ? 'Today' : $days . ' days';
}

/** A guest's phone number as a tap-to-call link (escaped). */
function tel_link(string $phone): string
{
    $digits = preg_replace('/[^\d+]/', '', $phone);
    return $digits === '' ? h($phone) : '<a href="tel:' . h($digits) . '">' . h($phone) . '</a>';
}

/** mailto: address for a guest's email (special characters percent-encoded; escape with h() in HTML). */
function mailto_href(string $email, string $subject = '', string $body = ''): string
{
    $href = 'mailto:' . str_replace('%40', '@', rawurlencode($email));
    $q = [];
    if ($subject !== '') $q[] = 'subject=' . rawurlencode($subject);
    if ($body !== '') $q[] = 'body=' . rawurlencode($body);
    return $href . ($q ? '?' . implode('&', $q) : '');
}

/** "jane.doe@example.com" → "j***@example.com": enough to recognise in the activity log without keeping the address. */
function mask_email(string $email): string
{
    $at = strrpos($email, '@');
    return mb_substr($email, 0, 1) . '***' . ($at === false ? '' : substr($email, $at));
}

function mail_link(string $email): string
{
    return $email === '' ? '' : '<a href="' . h(mailto_href($email)) . '">' . h($email) . '</a>';
}

function map_link(string $address): string
{
    if (trim($address) === '') return '';
    return '<a href="' . h('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address)) . '" target="_blank" rel="noopener noreferrer">' . h($address) . '</a>';
}

// ---------- icons and badges ----------

/** A small inline icon (24px grid, drawn with the current text colour). */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home'     => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'bag'      => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'mail'     => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><path d="M22 6l-10 7L2 6"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chat'     => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'chart'    => '<path d="M18 20V10M12 20V4M6 20v-6"/>',
        'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'glass'    => '<path d="M3 3h18l-9 11z"/><path d="M12 14v7M8 21h8"/>',
        'sliders'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
        'print'    => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'phone'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'pin'      => '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'trash'    => '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'check'    => '<path d="M20 6L9 17l-5-5"/>',
        'x'        => '<path d="M18 6L6 18M6 6l12 12"/>',
        'alert'    => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'flag'     => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
        'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'edit'     => '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'refresh'  => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'left'     => '<path d="M15 18l-6-6 6-6"/>',
        'right'    => '<path d="M9 18l6-6-6-6"/>',
        'menu'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'truck'    => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'dollar'   => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'star'     => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'music'    => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'qr'       => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4h-4"/>',
        'gift'     => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    ];
    return '<svg class="i" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** A round "i" next to a label that shows $text on hover, tap or keyboard focus (admin.js). */
function info_tip(string $text): string
{
    return '<button type="button" class="itip" data-info="' . h($text) . '" aria-label="' . h('More info: ' . $text) . '" aria-expanded="false">i</button>';
}

/** The little symbol in front of a status label, so meaning never rests on colour alone. */
function tone_icon(string $tone): string
{
    static $shapes = [
        'info'  => '<circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>',
        'warn'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'good'  => '<path d="M20 6L9 17l-5-5"/>',
        'crit'  => '<path d="M18 6L6 18M6 6l12 12"/>',
        'muted' => '<circle cx="12" cy="12" r="8"/>',
        'gold'  => '<circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>',
    ];
    return '<svg class="ti" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($shapes[$tone] ?? $shapes['muted']) . '</svg>';
}

/** A status pill: symbol + label, tone one of info, warn, good, crit, muted, gold. */
function badge(string $label, string $tone = 'muted'): string
{
    return '<span class="badge tone-' . h($tone) . '">' . tone_icon($tone) . h($label) . '</span>';
}

/** The badge for a record's status, from one of the *_STATUSES tables in store.php. */
function status_badge(array $statuses, string $key): string
{
    return isset($statuses[$key]) ? badge($statuses[$key][0], $statuses[$key][1]) : badge($key);
}

// ---------- building blocks ----------

/** Page heading. $subHtml and $actionsHtml are HTML (escape text yourself). */
function page_head(string $title, string $subHtml = '', string $actionsHtml = ''): string
{
    return '<div class="page-head"><div><h1>' . h($title) . '</h1>' . ($subHtml !== '' ? '<p class="sub">' . $subHtml . '</p>' : '') . '</div>'
        . ($actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}

/** A titled card. $bodyHtml and $headHtml (right side of the title row) are HTML. */
function card(string $title, string $bodyHtml, string $headHtml = '', string $class = ''): string
{
    return '<section class="card' . ($class !== '' ? ' ' . $class : '') . '">'
        . ($title !== '' || $headHtml !== '' ? '<header class="card-head"><h2>' . h($title) . '</h2>' . $headHtml . '</header>' : '')
        . '<div class="card-body">' . $bodyHtml . '</div></section>';
}

/** A headline number that links to where you act on it. $subHtml is HTML. */
function stat_tile(string $label, string $value, string $href, string $subHtml = '', string $icon = '', string $tone = ''): string
{
    return '<a class="stat' . ($tone !== '' ? ' is-' . $tone : '') . '" href="' . h($href) . '">'
        . ($icon !== '' ? '<span class="stat-ico">' . icon($icon, 18) . '</span>' : '')
        . '<span class="stat-val">' . h($value) . '</span><span class="stat-lab">' . h($label) . '</span>'
        . ($subHtml !== '' ? '<span class="stat-sub">' . $subHtml . '</span>' : '') . '</a>';
}

/** A round initial plus a name and email, for the first column of a list. $extraHtml goes under the email. */
function guest_cell(string $name, string $email = '', string $extraHtml = ''): string
{
    $label = $name !== '' ? $name : $email;
    return '<div class="gcell"><span class="avatar' . ($label === '' ? ' empty' : '') . '">' . h(initial($label)) . '</span><span class="ginfo">'
        . ($name !== '' ? '<b>' . h($name) . '</b>' : '')
        . ($email !== '' ? '<span class="cell-sub">' . h($email) . '</span>' : '')
        . $extraHtml . '</span></div>';
}

/** Label / value rows. Values are HTML; a row with an empty value is left out. */
function kv(array $pairs): string
{
    $out = '<dl class="kv">';
    foreach ($pairs as [$label, $html]) {
        if ($html !== '' && $html !== null) $out .= '<div><dt>' . h($label) . '</dt><dd>' . $html . '</dd></div>';
    }
    return $out . '</dl>';
}

function empty_state(string $title, string $hint = '', string $icon = 'inbox'): string
{
    return '<div class="empty">' . icon($icon, 28) . '<b>' . h($title) . '</b>' . ($hint !== '' ? '<p>' . $hint . '</p>' : '') . '</div>';
}

function btn_link(string $label, string $href, string $class = '', string $icon = '', string $attrs = ''): string
{
    return '<a class="btn' . ($class !== '' ? ' ' . $class : '') . '" href="' . h($href) . '"' . $attrs . '>' . ($icon !== '' ? icon($icon, 16) : '') . '<span>' . h($label) . '</span></a>';
}

/** A submit button; $confirm asks "Are you sure?" first (admin.js). */
function btn_submit(string $label, string $class = '', string $name = '', string $value = '', string $icon = '', string $confirm = ''): string
{
    return '<button class="btn' . ($class !== '' ? ' ' . $class : '') . '" type="submit"'
        . ($name !== '' ? ' name="' . h($name) . '" value="' . h($value) . '"' : '')
        . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . ($icon !== '' ? icon($icon, 16) : '') . '<span>' . h($label) . '</span></button>';
}

/** Opening tag of a POST form with its CSRF token (close it with </form>). */
function form_open(string $action = '', string $class = ''): string
{
    return '<form method="post" action="' . h($action) . '"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . csrf_field();
}

/** Pill tabs: [['label', 'href', 'on' => bool, 'count' => int|null], ...]. */
function tabs_html(array $items): string
{
    $out = '<nav class="tabs">';
    foreach ($items as $t) {
        $out .= '<a href="' . h($t['href']) . '"' . (!empty($t['on']) ? ' class="on" aria-current="page"' : '') . '>' . h($t['label'])
            . (isset($t['count']) && $t['count'] !== null ? '<span class="count">' . (int) $t['count'] . '</span>' : '') . '</a>';
    }
    return $out . '</nav>';
}

function search_form(string $page, array $keep, string $q, string $placeholder = 'Search'): string
{
    return '<form class="search" method="get" role="search"><input type="hidden" name="page" value="' . h($page) . '">' . hidden_fields($keep)
        . icon('search', 16) . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . h($placeholder) . '" aria-label="' . h($placeholder) . '"></form>';
}

/** One page of $rows for ?pg=N: [rows to show, pager HTML ('' when there's only one page)]. $params = filters to keep in the links. */
function paginate(array $rows, int $per, string $page, array $params = []): array
{
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $per));
    $pg = min($pages, max(1, (int) ($_GET['pg'] ?? 1)));
    $slice = array_slice($rows, ($pg - 1) * $per, $per);
    if ($pages === 1) return [$slice, ''];

    $link = fn($n, $inner, $cls = '') => '<a class="pg ' . $cls . '" href="' . h(admin_url($page, $params + ['pg' => $n])) . '">' . $inner . '</a>';
    $nums = '';
    $last = 0;
    foreach (range(1, $pages) as $n) {
        if ($n !== 1 && $n !== $pages && abs($n - $pg) > 2) continue;
        if ($last && $n - $last > 1) $nums .= '<span class="pg gap">…</span>';
        $nums .= $n === $pg ? '<span class="pg on" aria-current="page">' . $n . '</span>' : $link($n, $n);
        $last = $n;
    }
    $html = '<nav class="pager" aria-label="Pages"><span class="muted">' . (($pg - 1) * $per + 1) . '–' . min($total, $pg * $per) . ' of ' . number_format($total) . '</span><span class="pgs">'
        . ($pg > 1 ? $link($pg - 1, icon('left', 16) . '<span class="sr">Previous</span>') : '')
        . $nums
        . ($pg < $pages ? $link($pg + 1, icon('right', 16) . '<span class="sr">Next</span>') : '')
        . '</span></nav>';
    return [$slice, $html];
}

// ---------- date range (Today / 7 days / ... / exact dates) ----------

/**
 * The period a list covers: a preset from $presets (?days=N) or an exact range
 * (?from=&to=). Returns ['days', 'from', 'to', 'custom' => bool, 'query' => params for links].
 */
function admin_period(array $presets, int $default): array
{
    $ok = function ($v) {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    };
    $from = $ok($_GET['from'] ?? null) ? $_GET['from'] : null;
    $to   = $ok($_GET['to'] ?? null) ? $_GET['to'] : null;
    if ($from !== null || $to !== null) {
        $from = $from ?? $to;
        $to = $to ?? $from;
        if ($from > $to) [$from, $to] = [$to, $from];
        return ['days' => 0, 'from' => $from, 'to' => $to, 'custom' => true, 'query' => ['from' => $from, 'to' => $to]];
    }
    $days = (int) ($_GET['days'] ?? $default);
    if (!in_array($days, $presets, true)) $days = $default;
    return [
        'days' => $days, 'custom' => false, 'query' => ['days' => $days],
        'from' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days')), 'to' => date('Y-m-d'),
    ];
}

/** The preset buttons and the exact-dates form for a period. $keep = other filters to carry along. */
function period_bar(string $page, array $period, array $presets, array $keep = []): string
{
    $tabs = [];
    foreach ($presets as $d) {
        $tabs[] = ['label' => period_label($d), 'href' => admin_url($page, $keep + ['days' => $d]), 'on' => !$period['custom'] && $period['days'] === $d];
    }
    $today = date('Y-m-d');
    return '<div class="toolbar">' . tabs_html($tabs)
        . '<form class="range" method="get"><input type="hidden" name="page" value="' . h($page) . '">' . hidden_fields($keep)
        . '<label>From <input type="date" name="from" value="' . h($period['from']) . '" max="' . h($today) . '"></label>'
        . '<label>to <input type="date" name="to" value="' . h($period['to']) . '" max="' . h($today) . '"></label>'
        . '<button class="btn sm" type="submit">Apply</button>'
        . ($period['custom'] ? '<a class="btn sm ghost" href="' . h(admin_url($page, $keep)) . '">Clear</a>' : '')
        . '</form></div>';
}

// ---------- downloads ----------

/** Send a spreadsheet (CSV) and end the request. Values that look like formulas are defused. */
function csv_download(string $name, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9._-]/i', '', $name) . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read accents and emoji
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $row), ',', '"', '');
    }
    exit;
}

// ---------- charts ----------

/**
 * Horizontal bars for a list of counts: one series in the accent colour, the value
 * at the bar's tip, the share in the tooltip. $base = the total the share is out
 * of (0 = no share). Hover text lives in title="" (admin.js turns it into a tooltip).
 */
function bar_list(array $counts, int $base, string $unit = 'questions'): string
{
    $counts = array_filter($counts, fn($n) => $n > 0) ?: [];
    if (!$counts) return '<p class="empty-sm">Nothing yet in this period.</p>';
    $max = max($counts);
    $out = '<ul class="bars">';
    foreach ($counts as $label => $n) {
        $pct = $base ? round($n * 100 / $base) : 0;
        $out .= '<li tabindex="0" title="' . h("$label: $n $unit" . ($base ? " ($pct%)" : '')) . '"><span class="lbl">' . h($label) . '</span>'
            . '<span class="track"><span class="fill" style="width:' . max(2, round($n * 100 / $max)) . '%"></span></span>'
            . '<span class="val">' . $n . '</span></li>';
    }
    return $out . '</ul>';
}

/**
 * A column chart of one measure over time: thin columns from one baseline, hairline
 * grid, exact value on hover or focus, and the same numbers in a table underneath.
 * $points = [['label' => x-axis text, 'tip' => full name of the day, 'value' => int], ...];
 * $unit = singular noun ("order").
 */
function column_chart(string $title, string $sub, array $points, string $unit): string
{
    $max = 0;
    foreach ($points as $p) $max = max($max, (int) $p['value']);
    $top = $max > 10000 ? (int) (ceil($max / 2000) * 2000) : 4;
    foreach ([4, 8, 12, 20, 40, 60, 100, 200, 400, 600, 1000, 2000, 4000, 6000, 10000] as $n) {
        if ($n >= $max) { $top = $n; break; }
    }
    $cols = '';
    $rows = '';
    foreach ($points as $p) {
        $v = (int) $p['value'];
        $tip = $p['tip'] . ': ' . plural($v, $unit);
        $cols .= '<div class="col" tabindex="0" role="img" aria-label="' . h($tip) . '" title="' . h($tip) . '">'
            . '<i style="height:' . ($v > 0 ? max(1.5, round($v * 100 / $top, 1)) : 0) . '%"></i><span class="x">' . h($p['label']) . '</span></div>';
        $rows .= '<tr><td>' . h($p['tip']) . '</td><td class="num">' . number_format($v) . '</td></tr>';
    }
    return '<figure class="chart"><figcaption><b>' . h($title) . '</b><span>' . h($sub) . '</span></figcaption>'
        . '<div class="plot"><div class="yax" aria-hidden="true"><span>' . number_format($top) . '</span><span>' . number_format($top / 2) . '</span><span>0</span></div>'
        . '<div class="cols' . (count($points) > 32 ? ' dense denser' : (count($points) > 16 ? ' dense' : '')) . '">' . $cols . '</div></div>'
        . '<details class="tview"><summary>Table view</summary><table class="mini"><thead><tr><th>Day</th><th class="num">' . h(ucfirst($unit)) . 's</th></tr></thead><tbody>' . $rows . '</tbody></table></details></figure>';
}
