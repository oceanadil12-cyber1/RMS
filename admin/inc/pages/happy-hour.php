<?php
/**
 * Happy Hour: the menu, its time, and the scrolling ticker across the site.
 * Saved in api/data/happy-hour.json (see api/happy-hour.php), which the menu page,
 * the ticker and the chat assistant all read.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** "$6.99" / "6" → "6.99" / "6" (digits and one dot); '' otherwise. */
function hh_clean_price(string $s): string
{
    $s = preg_replace('/[^0-9.]/', '', $s);
    return preg_match('/^\d+(\.\d{1,2})?$/', $s) ? $s : '';
}

/** "16:00" kept; invalid → fallback. */
function hh_clean_time(string $s, string $fallback): string
{
    return preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m) && (int) $m[1] < 24 && (int) $m[2] < 60
        ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : $fallback;
}

/** Price-history key for a Happy Hour item or side ('item' / 'side'), by name. */
function hh_price_key(string $kind, string $name): string
{
    return 'hh:' . $kind . ':' . mb_strtolower(trim($name));
}

/** "6.99" → 699; '' → null. */
function hh_cents(string $price): ?int
{
    return $price === '' ? null : (int) round(((float) $price) * 100);
}

/** "6.99" → "$6.99" for the change lists. */
function hh_money(string $price): string
{
    return $price === '' ? 'no price' : '$' . number_format((float) $price, 2);
}

/** What differs between the old and new Happy Hour, as readable lines ("Wings (5): price $6.99 → $7.49"). */
function happy_hour_changes(array $old, array $new): array
{
    $out = [];
    if (!empty($old['enabled']) !== !empty($new['enabled'])) $out[] = 'Happy Hour ' . (!empty($new['enabled']) ? 'shown on the website' : 'hidden from the website');
    if (!empty($old['tickerEnabled']) !== !empty($new['tickerEnabled'])) $out[] = 'Scrolling ticker ' . (!empty($new['tickerEnabled']) ? 'on' : 'off');
    if ($old['start'] !== $new['start'] || $old['end'] !== $new['end']) $out[] = 'Time ' . hh_fmt($old['start']) . '–' . hh_fmt($old['end']) . ' → ' . hh_fmt($new['start']) . '–' . hh_fmt($new['end']);
    if (days_label($old['days'] ?? []) !== days_label($new['days'] ?? [])) $out[] = 'Days ' . days_label($old['days'] ?? []) . ' → ' . days_label($new['days'] ?? []);
    if ($old['note'] !== $new['note']) $out[] = 'Intro line changed';
    foreach (['items' => 'item', 'sides' => 'side'] as $list => $kind) {
        $before = [];
        foreach ($old[$list] as $it) $before[mb_strtolower($it['name'])] = $it;
        $after = [];
        foreach ($new[$list] as $it) $after[mb_strtolower($it['name'])] = $it;
        foreach ($after as $key => $it) {
            if (!isset($before[$key])) { $out[] = $it['name'] . ': added as a ' . $kind . ' at ' . hh_money((string) $it['price']); continue; }
            $was = $before[$key];
            if (hh_cents((string) $was['price']) !== hh_cents((string) $it['price'])) $out[] = $it['name'] . ': price ' . hh_money((string) $was['price']) . ' → ' . hh_money((string) $it['price']);
            if (($was['desc'] ?? '') !== ($it['desc'] ?? '')) $out[] = $it['name'] . ': description changed';
        }
        foreach ($before as $key => $it) if (!isset($after[$key])) $out[] = $it['name'] . ': removed from ' . $list;
    }
    return $out;
}

/** Save the Happy Hour form to happy-hour.json; returns what changed. */
function save_happy_hour(): array
{
    // Prices before this save, for "Last price".
    $old = happy_hour_data();
    $was = [];
    foreach ($old['items'] as $it) $was[hh_price_key('item', $it['name'])] = hh_cents((string) $it['price']);
    foreach ($old['sides'] as $s) $was[hh_price_key('side', $s['name'])] = hh_cents((string) $s['price']);
    $history = read_price_history();

    $items = [];
    foreach ((array) ($_POST['hhname'] ?? []) as $k => $n) {
        $n = trim(mb_substr((string) $n, 0, 60));
        if ($n === '') continue;
        $price = hh_clean_price((string) ($_POST['hhprice'][$k] ?? ''));
        price_history_note($history, hh_price_key('item', $n), $was[hh_price_key('item', $n)] ?? null, hh_cents($price));
        $items[] = ['name' => $n, 'desc' => trim(mb_substr((string) ($_POST['hhdesc'][$k] ?? ''), 0, 90)), 'price' => $price];
    }
    $sides = [];
    foreach ((array) ($_POST['sidename'] ?? []) as $k => $n) {
        $n = trim(mb_substr((string) $n, 0, 60));
        if ($n === '') continue;
        $price = hh_clean_price((string) ($_POST['sideprice'][$k] ?? ''));
        price_history_note($history, hh_price_key('side', $n), $was[hh_price_key('side', $n)] ?? null, hh_cents($price));
        $sides[] = ['name' => $n, 'price' => $price];
    }
    write_price_history($history);
    $new = [
        'enabled'       => !empty($_POST['enabled']),
        'tickerEnabled' => !empty($_POST['tickerEnabled']),
        'start'         => hh_clean_time((string) ($_POST['start'] ?? ''), '16:00'),
        'end'           => hh_clean_time((string) ($_POST['end'] ?? ''), '18:45'),
        'days'          => ($days = array_values(array_intersect(array_keys(WEEK_DAYS), array_map('strval', (array) ($_POST['hhdays'] ?? []))))) && count($days) < 7 ? $days : [],
        'note'          => trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 200)),
        'items'         => $items,
        'sides'         => $sides,
    ];
    write_happy_hour($new);
    return happy_hour_changes($old, $new);
}

/** The "Add a new item" form: append one item (or side) to the Happy Hour menu. Returns its name, or '' if incomplete. */
function add_happy_hour_item(): string
{
    $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 60));
    $price = hh_clean_price((string) ($_POST['price'] ?? ''));
    if ($name === '' || $price === '') return '';
    $hh = happy_hour_data();
    if (($_POST['section'] ?? 'items') === 'sides') {
        $hh['sides'][] = ['name' => $name, 'price' => $price];
    } else {
        $hh['items'][] = ['name' => $name, 'desc' => trim(mb_substr((string) ($_POST['desc'] ?? ''), 0, 90)), 'price' => $price];
    }
    return write_happy_hour($hh) ? $name : '';
}

function admin_page_happy_hour(): void
{
    if (is_post()) {
        require_csrf();
        if (($_POST['op'] ?? '') === 'add') {
            $name = add_happy_hour_item();
            if ($name !== '') {
                admin_log('happy.add', $name . ' — ' . hh_money(hh_clean_price((string) ($_POST['price'] ?? ''))) . (($_POST['section'] ?? '') === 'sides' ? ' (side)' : ''));
                flash('Added ' . $name . ' to Happy Hour. It is on the website menu now.');
            }
            else flash('Give the new item a name and a price.', 'err');
            redirect(admin_url('happy-hour'));
        }
        $changes = save_happy_hour();
        if ($changes) admin_log('happy.save', '', $changes);
        flash($changes ? 'Saved. The website shows the new Happy Hour on the next page load.' : 'Saved. Nothing had changed.');
        redirect(admin_url('happy-hour'));
    }

    $hh = happy_hour_data();
    $items = $hh['items'];
    $sides = $hh['sides'];
    $history = read_price_history();

    $itemRows = '';
    foreach ($items as $k => $it) {
        $itemRows .= '<tr><td><input name="hhname[' . $k . ']" value="' . h($it['name']) . '" maxlength="60" placeholder="Item name" aria-label="Item name"></td>'
            . '<td><input name="hhdesc[' . $k . ']" value="' . h($it['desc']) . '" maxlength="90" placeholder="Short description (optional)" aria-label="Description"></td>'
            . '<td><div class="fp">' . money_input('hhprice[' . $k . ']', (string) $it['price'], '0') . last_price_html($history, hh_price_key('item', $it['name'])) . '</div></td></tr>';
    }
    $sideRows = '';
    foreach ($sides as $k => $s) {
        $sideRows .= '<tr><td><input name="sidename[' . $k . ']" value="' . h($s['name']) . '" maxlength="60" placeholder="Side name" aria-label="Side name"></td>'
            . '<td><div class="fp">' . money_input('sideprice[' . $k . ']', (string) $s['price'], '0') . last_price_html($history, hh_price_key('side', $s['name'])) . '</div></td></tr>';
    }

    // "Add a new item" (its own form, like Menu & food).
    $add = '<details class="card addcard"><summary><span>' . icon('plus', 16) . ' Add a new item</span><small>Your own Happy Hour dishes and sides</small></summary><div class="card-body">'
        . form_open(admin_url('happy-hour')) . '<input type="hidden" name="op" value="add">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Name</span><input name="name" maxlength="60" required></label>'
        . '<label class="field sm"><span>Price</span>' . money_input('price', '', '', true) . '</label>'
        . '<label class="field sm"><span>Add to</span><select name="section"><option value="items">Items</option><option value="sides">Sides</option></select></label>'
        . '<label class="field wide"><span>Description (optional, items only)</span><input name="desc" maxlength="90"></label>'
        . '</div><div class="bar">' . btn_submit('Add item', 'primary', '', '', 'plus') . '</div></form></div></details>';

    $body = page_head('Happy Hour', 'The Happy Hour menu, its time, and the scrolling ticker across the site. Leave an item\'s name blank to drop it. Prices in dollars.',
            btn_link('View menu page', SITE_BASE . 'menu.html#happy-hour', 'sm', 'external', ' target="_blank" rel="noopener"'))
        . $add
        . '<form method="post" action="' . h(admin_url('happy-hour')) . '">' . csrf_field()
        . card('Settings', '<div class="form-grid">'
            . '<label class="check"><input type="checkbox" name="enabled" value="1" ' . (!empty($hh['enabled']) ? 'checked' : '') . '> Show Happy Hour on the website</label>'
            . '<label class="check"><input type="checkbox" name="tickerEnabled" value="1" ' . (!empty($hh['tickerEnabled']) ? 'checked' : '') . '> Show the scrolling ticker</label>'
            . '<label class="field sm"><span>Starts</span><input type="time" name="start" value="' . h($hh['start']) . '"></label>'
            . '<label class="field sm"><span>Ends</span><input type="time" name="end" value="' . h($hh['end']) . '"></label>'
            . '<div class="field wide"><span>Days</span><div class="sched-days" role="group" aria-label="Happy Hour days">'
            . implode('', array_map(fn($k) => '<label class="check"><input type="checkbox" name="hhdays[]" value="' . $k . '" ' . (empty($hh['days']) || in_array($k, $hh['days'], true) ? 'checked' : '') . '> ' . WEEK_DAYS[$k] . '</label>', array_keys(WEEK_DAYS)))
            . '</div><small class="muted">Untick a day to have no Happy Hour that day. All ticked = every day.</small></div>'
            . '<label class="field wide"><span>Intro line</span><input name="note" value="' . h($hh['note']) . '" maxlength="200"></label></div>')
        . card('Items', '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Description</th><th>Price</th></tr></thead><tbody>' . $itemRows . '</tbody></table></div>')
        . card('Sides', '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Price</th></tr></thead><tbody>' . $sideRows . '</tbody></table></div>')
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save Happy Hour', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Happy Hour', $body, 'happy-hour');
}
