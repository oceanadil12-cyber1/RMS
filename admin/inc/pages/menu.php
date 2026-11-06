<?php
/**
 * Menu & food: adjust the website's menu without touching Square. Hide an item,
 * change a price, limit it to pickup or delivery, add a delivery surcharge or a
 * longer prep time, edit its description and options, or add dishes of your own.
 *
 * Everything is saved as "local overrides" (api/data/menu-overrides.json, see
 * api/menu-overrides.php) that the website applies on top of the menu. Orders are
 * cash on delivery, so the price set here is exactly what the guest pays.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

// ---------- saving ----------

/** "$15.99" for the change lists. */
function food_money(?int $cents): string
{
    return $cents === null ? 'none' : '$' . number_format($cents / 100, 2);
}

/** What differs between an item's old and new overrides, as readable lines ("Wings: turned off"). */
function food_changes(string $name, array $old, array $new, array $variationNames, array $basePrices): array
{
    $out = [];
    if (!empty($old['hidden']) !== !empty($new['hidden'])) $out[] = $name . ': ' . (!empty($new['hidden']) ? 'turned off (hidden from the website)' : 'turned on');
    foreach ($basePrices as $vid => $b) {
        $was = $old['prices'][$vid] ?? $b;
        $now = $new['prices'][$vid] ?? $b;
        if ($was !== $now) {
            $v = $variationNames[$vid] ?? 'Regular';
            $out[] = $name . ($v !== 'Regular' ? ' (' . $v . ')' : '') . ': price ' . food_money($was) . ' → ' . food_money($now);
        }
    }
    if (!empty($old['pickupOnly']) !== !empty($new['pickupOnly'])) $out[] = $name . ': pickup only ' . (!empty($new['pickupOnly']) ? 'on' : 'off');
    if (!empty($old['deliveryOnly']) !== !empty($new['deliveryOnly'])) $out[] = $name . ': delivery only ' . (!empty($new['deliveryOnly']) ? 'on' : 'off');
    if ((int) ($old['deliveryFee'] ?? 0) !== (int) ($new['deliveryFee'] ?? 0)) $out[] = $name . ': delivery surcharge ' . food_money((int) ($old['deliveryFee'] ?? 0)) . ' → ' . food_money((int) ($new['deliveryFee'] ?? 0));
    if ((int) ($old['prepMinutes'] ?? 0) !== (int) ($new['prepMinutes'] ?? 0)) {
        $mins = fn($m) => $m ? $m . ' min' : 'store default';
        $out[] = $name . ': prep time ' . $mins((int) ($old['prepMinutes'] ?? 0)) . ' → ' . $mins((int) ($new['prepMinutes'] ?? 0));
    }
    return $out;
}

/** Save the submitted Food form to the overrides file (only non-default values); returns what changed. */
function save_food_overrides(array $base): array
{
    $changes = [];
    $basePrice = [];
    foreach ($base['items'] as $id => $it) {
        foreach ($it['variations'] as $v) $basePrice[$id][$v['id']] = (int) $v['price'];
    }
    $existing = read_menu_overrides();
    $existingItems = $existing['items'] ?? [];
    $history = read_price_history();
    $out = ['items' => [], 'custom' => $existing['custom'] ?? []]; // keep custom items
    foreach (array_map('strval', (array) ($_POST['ids'] ?? [])) as $id) {
        if (!isset($base['items'][$id])) continue;
        $o = [];
        // Preserve per-item option/description/schedule edits made on the item editor page.
        foreach (['description', 'hideGroups', 'hideChoices', 'choicePrices', 'schedule'] as $k) {
            if (isset($existingItems[$id][$k])) $o[$k] = $existingItems[$id][$k];
        }
        if (!isset($_POST['avail'][$id])) $o['hidden'] = true;
        if (!empty($_POST['pickup'][$id])) $o['pickupOnly'] = true;
        if (!empty($_POST['delonly'][$id])) $o['deliveryOnly'] = true;
        $dfee = dollars_to_cents((string) ($_POST['dfee'][$id] ?? ''));
        if ($dfee) $o['deliveryFee'] = $dfee;
        $prep = (int) ($_POST['prep'][$id] ?? 0);
        if ($prep > 0) $o['prepMinutes'] = min(600, $prep);
        foreach ((array) ($_POST['price'][$id] ?? []) as $vid => $dollars) {
            $c = dollars_to_cents((string) $dollars);
            $b = $basePrice[$id][(string) $vid] ?? null;
            if ($c !== null && $b !== null && $c !== $b) $o['prices'][(string) $vid] = $c;
            // A blank price goes back to the menu's own price.
            price_history_note($history, "menu:$id:$vid", $existingItems[$id]['prices'][(string) $vid] ?? $b, $c ?? $b);
        }
        $vnames = [];
        foreach ($base['items'][$id]['variations'] as $v) $vnames[$v['id']] = $v['name'];
        array_push($changes, ...food_changes($base['items'][$id]['name'], $existingItems[$id] ?? [], $o, $vnames, $basePrice[$id] ?? []));
        if ($o) $out['items'][$id] = $o;
    }
    write_menu_overrides($out);
    write_price_history($history);
    return $changes;
}

/** Category id → name, from the base menu. */
function food_categories(array $base): array
{
    $cats = [];
    foreach ($base['categories'] as $c) $cats[$c['id']] = $c['name'];
    return $cats;
}

/** Add a brand-new custom item from the "Add item" form; returns its name, or '' if nothing was added. */
function add_custom_item(array $base, ?array $schedule = null): string
{
    $name = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80));
    $price = dollars_to_cents((string) ($_POST['price'] ?? ''));
    if ($name === '' || $price === null) return ''; // need at least a name and price
    $cats = food_categories($base);
    $catId = (string) ($_POST['category'] ?? '');
    $ov = read_menu_overrides();
    $ov['custom'] = $ov['custom'] ?? [];
    $ov['custom'][] = [
        'id'           => 'local-' . bin2hex(random_bytes(4)),
        'name'         => $name,
        'description'  => trim(mb_substr((string) ($_POST['description'] ?? ''), 0, 300)),
        'price'        => max(0, $price),
        'category'     => $catId,
        'categoryName' => $cats[$catId] ?? 'More',
        'pickupOnly'   => !empty($_POST['pickup']),
        'deliveryOnly' => !empty($_POST['delonly']),
        'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['dfee'] ?? '')) ?? 0)),
        'prepMinutes'  => min(600, max(0, (int) ($_POST['prep'] ?? 0))),
    ] + ($schedule ? ['schedule' => $schedule] : []);
    write_menu_overrides($ov);
    return $name;
}

/** Save edits and deletions to existing custom items; returns [edited, deleted, changes]. */
function save_custom_items(array $base): array
{
    $changes = [];
    $cats = food_categories($base);
    $ov = read_menu_overrides();
    $prev = [];
    foreach ($ov['custom'] ?? [] as $c) if (!empty($c['id'])) $prev[(string) $c['id']] = $c;
    $history = read_price_history();
    $out = [];
    $deleted = 0;
    foreach (array_map('strval', (array) ($_POST['cid'] ?? [])) as $id) {
        if (!isset($prev[$id])) continue;
        $was = $prev[$id];
        if (!empty($_POST['cdelete'][$id])) { $deleted++; unset($history["custom:$id"]); $changes[] = ($was['name'] ?? 'Item') . ': deleted'; continue; }
        $name = trim(mb_substr((string) ($_POST['cname'][$id] ?? ''), 0, 80));
        if ($name === '') { $changes[] = ($was['name'] ?? 'Item') . ': removed (name left blank)'; continue; }
        $catId = (string) ($_POST['ccat'][$id] ?? '');
        $price = max(0, (int) (dollars_to_cents((string) ($_POST['cprice'][$id] ?? '')) ?? 0));
        price_history_note($history, "custom:$id", (int) ($prev[$id]['price'] ?? 0), $price);
        $now = [
            'pickupOnly'   => !empty($_POST['cpickup'][$id]),
            'deliveryOnly' => !empty($_POST['cdelonly'][$id]),
            'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['cdfee'][$id] ?? '')) ?? 0)),
            'prepMinutes'  => min(600, max(0, (int) ($_POST['cprep'][$id] ?? 0))),
            'prices'       => ['v' => $price],
        ];
        if ($name !== ($was['name'] ?? '')) $changes[] = ($was['name'] ?? '') . ': renamed to ' . $name;
        if ($catId !== (string) ($was['category'] ?? '')) $changes[] = $name . ': moved to ' . ($cats[$catId] ?? 'More');
        array_push($changes, ...food_changes($name, ['prices' => ['v' => (int) ($was['price'] ?? 0)]] + $was, $now, [], ['v' => (int) ($was['price'] ?? 0)]));
        $out[] = [
            'id'           => $id,
            'name'         => $name,
            'description'  => trim(mb_substr((string) ($_POST['cdesc'][$id] ?? ($prev[$id]['description'] ?? '')), 0, 300)),
            'price'        => $price,
            'category'     => $catId,
            'categoryName' => $cats[$catId] ?? 'More',
            'pickupOnly'   => !empty($_POST['cpickup'][$id]),
            'deliveryOnly' => !empty($_POST['cdelonly'][$id]),
            'deliveryFee'  => max(0, (int) (dollars_to_cents((string) ($_POST['cdfee'][$id] ?? '')) ?? 0)),
            'prepMinutes'  => min(600, max(0, (int) ($_POST['cprep'][$id] ?? 0))),
        ] + (isset($was['schedule']) ? ['schedule' => $was['schedule']] : []); // set on the item's own page
    }
    $ov['custom'] = $out;
    write_menu_overrides($ov);
    write_price_history($history);
    return [count($out), $deleted, $changes];
}

/** Save description, schedule and option-group edits for one item (keeps its price/pickup/etc.); returns what changed. */
function save_item_options(array $base, ?array $schedule): array
{
    $id = (string) ($_POST['item'] ?? '');
    if (!isset($base['items'][$id])) return [];
    $baseItem = $base['items'][$id];
    $basePrice = [];
    foreach ($baseItem['modifiers'] as $g) foreach ($g['choices'] as $c) $basePrice[(string) $c['id']] = (int) $c['price'];

    $ov = read_menu_overrides();
    $ov['items'] = $ov['items'] ?? [];
    $o = $ov['items'][$id] ?? []; // keep existing basic fields (price/pickup/fee/prep)
    $was = $o;

    $desc = trim(mb_substr((string) ($_POST['description'] ?? ''), 0, 300));
    if ($desc !== '' && $desc !== (string) ($baseItem['description'] ?? '')) $o['description'] = $desc;
    else unset($o['description']);

    $hideG = array_values(array_map('strval', (array) ($_POST['hidegroup'] ?? [])));
    $hideC = array_values(array_map('strval', (array) ($_POST['hidechoice'] ?? [])));
    if ($hideG) $o['hideGroups'] = $hideG; else unset($o['hideGroups']);
    if ($hideC) $o['hideChoices'] = $hideC; else unset($o['hideChoices']);

    $history = read_price_history();
    $cp = [];
    foreach ((array) ($_POST['chprice'] ?? []) as $cid => $val) {
        $cid = (string) $cid;
        $c = dollars_to_cents((string) $val);
        if ($c !== null && isset($basePrice[$cid]) && $c !== $basePrice[$cid]) $cp[$cid] = $c;
        if (isset($basePrice[$cid])) price_history_note($history, "choice:$id:$cid", $o['choicePrices'][$cid] ?? $basePrice[$cid], $c ?? $basePrice[$cid]);
    }
    if ($cp) $o['choicePrices'] = $cp; else unset($o['choicePrices']);
    if ($schedule) $o['schedule'] = $schedule; else unset($o['schedule']);

    if ($o) $ov['items'][$id] = $o; else unset($ov['items'][$id]);
    write_menu_overrides($ov);
    write_price_history($history);

    // What changed, for the activity log and email alert.
    $name = $baseItem['name'];
    $changes = [];
    if (($was['description'] ?? '') !== ($o['description'] ?? '')) $changes[] = $name . ': description changed';
    if ($line = schedule_change($name, $was['schedule'] ?? null, $schedule)) $changes[] = $line;
    foreach ($baseItem['modifiers'] as $g) {
        $gHid = in_array((string) $g['id'], $o['hideGroups'] ?? [], true);
        if (in_array((string) $g['id'], $was['hideGroups'] ?? [], true) !== $gHid) $changes[] = $name . ': option group "' . $g['name'] . '" ' . ($gHid ? 'hidden' : 'shown');
        foreach ($g['choices'] as $c) {
            $cid = (string) $c['id'];
            $cHid = in_array($cid, $o['hideChoices'] ?? [], true);
            if (in_array($cid, $was['hideChoices'] ?? [], true) !== $cHid) $changes[] = $name . ': option "' . $c['name'] . '" ' . ($cHid ? 'hidden' : 'shown');
            $before = $was['choicePrices'][$cid] ?? (int) $c['price'];
            $after = $o['choicePrices'][$cid] ?? (int) $c['price'];
            if ($before !== $after) $changes[] = $name . ': option "' . $c['name'] . '" price ' . food_money($before) . ' → ' . food_money($after);
        }
    }
    return $changes;
}

/** "Taco Tuesday: schedule set to Tue only · 11 AM–3 PM"; '' when unchanged. */
function schedule_change(string $name, ?array $was, ?array $now): string
{
    $a = schedule_text($was);
    $b = schedule_text($now);
    if ($a === $b) return '';
    return $name . ': ' . ($b === '' ? 'schedule removed (always on the menu)' : 'schedule set to ' . $b);
}

/** One of your own added items, by id, or null. */
function custom_item_by_id(string $id): ?array
{
    foreach (read_menu_overrides()['custom'] ?? [] as $c) if ((string) ($c['id'] ?? '') === $id) return $c;
    return null;
}

/** Save the schedule of an item you added; returns what changed. */
function save_custom_schedule(string $id, ?array $schedule): array
{
    $ov = read_menu_overrides();
    $changes = [];
    foreach ($ov['custom'] ?? [] as $k => $c) {
        if ((string) ($c['id'] ?? '') !== $id) continue;
        if ($line = schedule_change((string) $c['name'], $c['schedule'] ?? null, $schedule)) $changes[] = $line;
        if ($schedule) $ov['custom'][$k]['schedule'] = $schedule; else unset($ov['custom'][$k]['schedule']);
    }
    write_menu_overrides($ov);
    return $changes;
}

// ---------- pages ----------

function admin_page_menu(): void
{
    $base = load_menu_base();
    $itemId = get_str('item', 60);

    // The options editor for one item (?item=ID).
    if ($itemId !== '') {
        if (is_post()) {
            require_csrf();
            $pid = (string) ($_POST['item'] ?? '');
            if ($base && ($_POST['op'] ?? '') === 'item' && (isset($base['items'][$pid]) || custom_item_by_id($pid))) {
                [$schedule, $error] = schedule_from_post();
                if ($error !== '') {
                    flash($error . ' Nothing was saved.', 'err');
                } else {
                    $changes = isset($base['items'][$pid]) ? save_item_options($base, $schedule) : save_custom_schedule($pid, $schedule);
                    if ($changes) admin_log('menu.item', '', $changes);
                    flash($changes ? 'Saved. The website menu is updated.' : 'Saved. Nothing had changed.');
                }
            }
            redirect(admin_url('menu', ['item' => $itemId]));
        }
        admin_menu_item_page($base, $itemId); // ends the request
    }

    if (is_post()) {
        require_csrf();
        if ($base) {
            $op = $_POST['op'] ?? 'items';
            if ($op === 'add') {
                [$schedule, $error] = schedule_from_post();
                $name = $error === '' ? add_custom_item($base, $schedule) : '';
                if ($error !== '') {
                    flash($error . ' The item wasn\'t added.', 'err');
                } elseif ($name !== '') {
                    $custom = read_menu_overrides()['custom'] ?? [];
                    $added = end($custom) ?: [];
                    admin_log('menu.add', $name . ' — ' . food_money((int) ($added['price'] ?? 0)) . ' in ' . ($added['categoryName'] ?? 'More') . ($schedule ? ' · ' . schedule_text($schedule) : ''));
                    flash('Added ' . $name . ($schedule ? '. It shows on the website menu ' . schedule_text($schedule) . '.' : '. It is on the website menu now.'));
                }
                else flash('Give the new item a name and a price.', 'err');
            } elseif ($op === 'custom') {
                [$edited, $deleted, $changes] = save_custom_items($base);
                if ($changes) admin_log('menu.custom', '', $changes);
                flash('Saved your added items.' . ($deleted ? ' ' . plural($deleted, 'item') . ' deleted.' : ''));
            } elseif (empty($_POST['end'])) {
                // The server drops form fields past its max_input_vars limit; saving a cut-off form would lose settings.
                flash('Nothing was saved: the menu form is bigger than this server accepts (max_input_vars is ' . ini_get('max_input_vars') . '). Ask your web host to raise it.', 'err');
            } else {
                $changes = save_food_overrides($base);
                if ($changes) admin_log('menu.items', '', $changes);
                flash($changes ? 'Saved. The website menu is updated.' : 'Saved. Nothing had changed.');
            }
        }
        redirect(admin_url('menu'));
    }

    if (!$base) {
        admin_render('Menu & food', page_head('Menu & food') . empty_state('The menu is temporarily unavailable', 'Please try again in a moment.', 'list'), 'menu');
    }
    admin_menu_list_page($base);
}

function admin_menu_list_page(array $base): void
{
    $over = read_menu_overrides();
    $ov = $over['items'] ?? [];
    $history = read_price_history();
    $storePrep = (int) ($base['store']['prepMinutes'] ?? 25);
    $csrf = csrf_field();

    // Category options for the forms.
    $cats = food_categories($base);
    $catOptions = fn($sel) => implode('', array_map(fn($id) => '<option value="' . h($id) . '"' . ((string) $id === $sel ? ' selected' : '') . '>' . h($cats[$id]) . '</option>', array_keys($cats)));

    // The menu, category by category.
    $rows = '';
    $count = 0;
    $hidden = 0;
    foreach ($base['categories'] as $cat) {
        $items = '';
        foreach ($cat['itemIds'] as $id) {
            $it = $base['items'][$id] ?? null;
            if (!$it) continue;
            $count++;
            $o = $ov[$id] ?? [];
            $off = !empty($o['hidden']);
            if ($off) $hidden++;
            $prices = '';
            foreach ($it['variations'] as $v) {
                $cur = $o['prices'][$v['id']] ?? $v['price'];
                $prices .= '<label class="fp"><span class="fp-lbl">' . ($v['name'] !== 'Regular' ? h($v['name']) : 'Price') . '</span>'
                    . money_input('price[' . $id . '][' . $v['id'] . ']', number_format($cur / 100, 2, '.', ''))
                    . last_price_html($history, 'menu:' . $id . ':' . $v['id']) . '</label>';
            }
            $items .= '<tr class="' . ($off ? 'is-off' : '') . '" data-name="' . h(mb_strtolower($it['name'])) . '">'
                . '<td><label class="switch"><input type="checkbox" name="avail[' . h($id) . ']" value="1" ' . ($off ? '' : 'checked') . ' aria-label="Show ' . h($it['name']) . ' on the website"><span></span></label>'
                . '<input type="hidden" name="ids[]" value="' . h($id) . '"></td>'
                . '<td class="fname"><a href="' . h(admin_url('menu', ['item' => $id])) . '" title="Edit description and options">' . h($it['name']) . '</a>'
                . (!empty($it['soldOut']) ? ' <span class="badge tone-crit">sold out</span>' : '')
                . (($it['modifiers'] ?? []) ? ' <span class="chip">' . plural(count($it['modifiers']), 'option') . '</span>' : '')
                . schedule_tag($o['schedule'] ?? null, $id) . '</td>'
                . '<td><div class="fprices">' . $prices . '</div></td>'
                . '<td><label class="check"><input type="checkbox" name="pickup[' . h($id) . ']" value="1" ' . (!empty($o['pickupOnly']) ? 'checked' : '') . '> Pickup only</label>'
                . '<label class="check"><input type="checkbox" name="delonly[' . h($id) . ']" value="1" ' . (!empty($o['deliveryOnly']) ? 'checked' : '') . '> Delivery only</label></td>'
                . '<td>' . money_input('dfee[' . $id . ']', !empty($o['deliveryFee']) ? number_format($o['deliveryFee'] / 100, 2, '.', '') : '', '0.00') . '</td>'
                . '<td>' . minutes_input('prep[' . $id . ']', !empty($o['prepMinutes']) ? (string) (int) $o['prepMinutes'] : '', (string) $storePrep) . '</td></tr>';
        }
        if ($items !== '') $rows .= '<tr class="cat"><td colspan="6">' . h($cat['name']) . '</td></tr>' . $items;
    }

    // "Add a new item".
    $add = '<details class="card addcard"' . (get_str('add', 1) === '1' ? ' open' : '') . '><summary><span>' . icon('plus', 16) . ' Add a new item</span><small>Your own dishes, orderable on the website</small></summary><div class="card-body">'
        . '<form method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="add">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Name</span><input name="name" maxlength="80" required></label>'
        . '<label class="field sm"><span>Price</span>' . money_input('price', '', '', true) . '</label>'
        . '<label class="field sm"><span>Category</span><select name="category">' . $catOptions('') . '</select></label>'
        . '<label class="field sm"><span>Delivery surcharge</span>' . money_input('dfee', '', '0.00') . '</label>'
        . '<label class="field sm"><span>Prep time</span>' . minutes_input('prep', '', (string) $storePrep) . '</label>'
        . '<label class="field wide"><span>Description (optional)</span><input name="description" maxlength="300"></label>'
        . '<label class="check"><input type="checkbox" name="pickup" value="1"> Pickup only</label>'
        . '<label class="check"><input type="checkbox" name="delonly" value="1"> Delivery only</label>'
        . '</div><details class="sched-add"><summary>Schedule (optional): only on certain days, times or dates</summary>' . schedule_fields(null) . '</details>'
        . '<div class="bar">' . btn_submit('Add item', 'primary', '', '', 'plus') . '</div></form></div></details>';

    // Items you added.
    $customRows = '';
    foreach ($over['custom'] ?? [] as $c) {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') continue;
        $customRows .= '<tr><td><input name="cname[' . h($id) . ']" value="' . h($c['name'] ?? '') . '" maxlength="80" aria-label="Name">'
            . '<input type="hidden" name="cid[]" value="' . h($id) . '"><input type="hidden" name="cdesc[' . h($id) . ']" value="' . h($c['description'] ?? '') . '">'
            . (schedule_tag($c['schedule'] ?? null, $id) ?: ' <a class="sched-link" href="' . h(admin_url('menu', ['item' => $id])) . '">Set a schedule</a>') . '</td>'
            . '<td><div class="fp">' . money_input('cprice[' . $id . ']', number_format((int) ($c['price'] ?? 0) / 100, 2, '.', '')) . last_price_html($history, 'custom:' . $id) . '</div></td>'
            . '<td><select name="ccat[' . h($id) . ']" aria-label="Category">' . $catOptions((string) ($c['category'] ?? '')) . '</select></td>'
            . '<td><label class="check"><input type="checkbox" name="cpickup[' . h($id) . ']" value="1" ' . (!empty($c['pickupOnly']) ? 'checked' : '') . '> Pickup only</label>'
            . '<label class="check"><input type="checkbox" name="cdelonly[' . h($id) . ']" value="1" ' . (!empty($c['deliveryOnly']) ? 'checked' : '') . '> Delivery only</label></td>'
            . '<td>' . money_input('cdfee[' . $id . ']', !empty($c['deliveryFee']) ? number_format($c['deliveryFee'] / 100, 2, '.', '') : '', '0.00') . '</td>'
            . '<td>' . minutes_input('cprep[' . $id . ']', !empty($c['prepMinutes']) ? (string) (int) $c['prepMinutes'] : '', (string) $storePrep) . '</td>'
            . '<td><label class="check danger"><input type="checkbox" name="cdelete[' . h($id) . ']" value="1"> Delete</label></td></tr>';
    }
    $custom = $customRows === '' ? '' : card('Items you added',
        '<form method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="custom">'
        . '<div class="table flush"><table class="tbl ftable"><thead><tr><th>Name</th><th>Price</th><th>Category</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th><th></th></tr></thead><tbody>' . $customRows . '</tbody></table></div>'
        . '<div class="bar">' . btn_submit('Save added items', 'primary') . '</div></form>');

    $body = page_head('Menu & food', 'Your menu for the website. Orders are cash on delivery, so the price you set is what the guest pays. Turn an item off to hide it; a blank price goes back to the original menu price; "Last price" shows what an item cost before its latest change; a blank prep time uses the store default (' . $storePrep . ' min).',
            btn_link('View menu page', SITE_BASE . 'menu.html', 'sm', 'external', ' target="_blank" rel="noopener"'))
        . $add . $custom
        . '<form class="menuform" method="post" action="' . h(admin_url('menu')) . '">' . $csrf . '<input type="hidden" name="op" value="items">'
        . '<div class="toolbar">' . '<label class="search">' . icon('search', 16) . '<input type="search" data-filter="#food-table" placeholder="Filter items…" aria-label="Filter items"></label>'
        . '<span class="grow"></span><span class="muted" data-filter-count="#food-table">' . plural($count, 'item') . ($hidden ? ', ' . $hidden . ' hidden' : '') . '</span></div>'
        . '<div class="table"><table class="tbl ftable" id="food-table"><thead><tr><th>On</th><th>Item</th><th>Price</th><th>Fulfillment</th><th>Delivery surcharge</th><th>Prep time</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<input type="hidden" name="end" value="1">'
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save changes', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Menu & food', $body, 'menu');
}

/** The per-item editor: description + each option group's choices (re-price / hide). Ends the request. */
function admin_menu_item_page(?array $base, string $id): void
{
    $listLink = '<p class="back"><a href="' . h(admin_url('menu')) . '">' . icon('left', 15) . ' Menu &amp; food</a></p>';
    $open = '<form method="post" action="' . h(admin_url('menu', ['item' => $id])) . '">' . csrf_field() . '<input type="hidden" name="op" value="item"><input type="hidden" name="item" value="' . h($id) . '">';
    $save = '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save item', 'primary', '', '', 'check') . '</div></form>';

    // Items you added yourself: everything else is edited in the list, so this page is just the schedule.
    $custom = $base ? custom_item_by_id($id) : null;
    if ($custom) {
        admin_render((string) $custom['name'], $listLink . page_head((string) $custom['name'], 'Choose when this item is on the menu. Its name, price and other details are edited on the Menu &amp; food page.')
            . $open . schedule_card($custom['schedule'] ?? null) . $save, 'menu');
    }
    if (!$base || !isset($base['items'][$id])) {
        admin_render('Menu item', $listLink . empty_state('Item not found', 'It may have been removed from the menu.', 'list'), 'menu');
    }
    $item = $base['items'][$id];
    $o = (read_menu_overrides()['items'] ?? [])[$id] ?? [];
    $hideG = array_flip($o['hideGroups'] ?? []);
    $hideC = array_flip($o['hideChoices'] ?? []);
    $cp = $o['choicePrices'] ?? [];
    $history = read_price_history();
    $desc = $o['description'] ?? ($item['description'] ?? '');

    $groups = '';
    foreach ($item['modifiers'] as $g) {
        $choices = '';
        foreach ($g['choices'] as $c) {
            $price = $cp[$c['id']] ?? $c['price'];
            $choices .= '<tr><td>' . h($c['name']) . '</td>'
                . '<td>' . money_input('chprice[' . $c['id'] . ']', number_format((int) $price / 100, 2, '.', '')) . ' ' . last_price_html($history, 'choice:' . $id . ':' . $c['id']) . '</td>'
                . '<td><label class="check"><input type="checkbox" name="hidechoice[]" value="' . h($c['id']) . '" ' . (isset($hideC[$c['id']]) ? 'checked' : '') . '> Hide</label></td></tr>';
        }
        $groups .= '<section class="fgrp"><div class="fgrp-head"><strong>' . h($g['name']) . '</strong>'
            . '<label class="check danger"><input type="checkbox" name="hidegroup[]" value="' . h($g['id']) . '" ' . (isset($hideG[$g['id']]) ? 'checked' : '') . '> Hide whole group</label></div>'
            . '<div class="table flush"><table class="tbl"><tbody>' . $choices . '</tbody></table></div></section>';
    }
    if ($groups === '') $groups = '<p class="muted">This item has no options to edit.</p>';

    $body = $listLink . page_head($item['name'], 'Change the description guests see, choose when the item is on the menu, and re-price or hide individual options.')
        . $open
        . card('Description', '<label class="field wide"><span class="sr">Description</span><textarea name="description" maxlength="300" rows="3">' . h($desc) . '</textarea></label>')
        . schedule_card($o['schedule'] ?? null)
        . '<h2 class="sect">Options</h2><p class="muted">Re-price or hide individual choices, or hide a whole group.</p>' . $groups
        . $save;
    admin_render($item['name'], $body, 'menu');
}

/** A tag in the Menu & food list for a scheduled item ("Tue only · 11 AM–3 PM", "not showing now"), linking to its page; '' if none. */
function schedule_tag(?array $schedule, string $id): string
{
    if (!$schedule) return '';
    $live = schedule_active($schedule);
    return ' <a class="chip sched-chip" href="' . h(admin_url('menu', ['item' => $id])) . '" title="Change the schedule">' . icon('clock', 12) . ' ' . h(schedule_text($schedule)) . '</a>'
        . ($live ? '' : ' <span class="badge tone-warn">not showing now</span>');
}

/** The "Schedule" card on an item's page: which days, times and dates it is on the menu. */
function schedule_card(?array $schedule): string
{
    $now = $schedule ? (schedule_active($schedule) ? 'On the menu right now.' : 'Not on the menu right now — it shows again at its next scheduled time.') : '';
    return card('Schedule',
        '<p class="muted">Leave everything blank to keep the item on the menu all the time. Tick days to show it only on those days, add times to show it only between them, '
        . 'or dates for a limited run (e.g. a holiday special). Outside its schedule the item is hidden from the website, online ordering, table ordering, staff orders and the chat assistant.</p>'
        . schedule_fields($schedule)
        . ($schedule ? '<p class="sched-now"><b>' . h(schedule_text($schedule)) . '</b> · ' . h($now) . '</p>' : ''));
}
