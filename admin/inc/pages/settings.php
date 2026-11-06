<?php
/**
 * Store settings: hours, delivery area and fees for the website's ordering.
 * Saved in api/data/store-settings.json (see api/menu-overrides.php) and applied
 * on top of the menu, so checkout, the menu page and the chat all follow them.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__, 3) . '/api/site-features.php'; // SITE_FEATURES: the pages that can be switched off

/** "11:00" → "11:00:00"; invalid → null. */
function hms(string $t): ?string
{
    $t = trim($t);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
    return null;
}

/** What differs between the old and new store settings, as readable lines ("Delivery fee: $3.00 → $4.00"). */
function store_settings_changes(array $old, array $new): array
{
    $money = fn($c) => '$' . number_format(((int) $c) / 100, 2);
    $hours = function ($h) {
        $parts = [];
        foreach (['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'] as $d) {
            $parts[] = ucfirst(strtolower($d)) . ' ' . (isset($h[$d][0]) ? substr($h[$d][0]['open'], 0, 5) . '–' . substr($h[$d][0]['close'], 0, 5) : 'closed');
        }
        return $parts;
    };
    $labels = [
        'name' => ['Name', null], 'address' => ['Address', null], 'phone' => ['Phone', null],
        'prepMinutes' => ['Prep time', fn($v) => (int) $v . ' min'],
        'pickupMinimum' => ['Pickup minimum', $money],
        'pickupEnabled' => ['Takeaway', fn($v) => $v ? 'on' : 'off'],
        'pickupInstructions' => ['Pickup instructions', fn($v) => 'changed'],
        'notificationsEnabled' => ['Notifications', fn($v) => $v ? 'on' : 'off'],
        'alertOrderNotStarted' => ['Alert: order not started', fn($v) => (int) $v . ' min'],
        'alertOrderLatePrep' => ['Alert: prep overtime', fn($v) => (int) $v . ' min'],
        'alertOrderReadyIdle' => ['Alert: order ready idle', fn($v) => (int) $v . ' min'],
        'alertEventSoon' => ['Alert: event starting soon', fn($v) => (int) $v . ' min'],
    ];
    foreach (SITE_FEATURES as [$key, $label]) $labels[$key] = [$label . ' page', fn($v) => $v ? 'on' : 'off'];
    $out = [];
    foreach ($labels as $k => [$label, $fmt]) {
        $a = $old[$k] ?? null;
        $b = $new[$k] ?? null;
        if (is_bool($b)) $a = !empty($a);
        if ($a == $b) continue;
        if ($k === 'pickupInstructions') { $out[] = 'Pickup instructions changed'; continue; }
        $out[] = $label . ': ' . ($fmt ? $fmt($a) : (string) $a) . ' → ' . ($fmt ? $fmt($b) : (string) $b);
    }
    foreach (['pickupHours' => 'Pickup hours', 'deliveryHours' => 'Delivery hours'] as $k => $label) {
        $a = $hours($old[$k] ?? []);
        $b = $hours($new[$k] ?? []);
        foreach ($b as $i => $day) if ($a[$i] !== $day) $out[] = $label . ': ' . $a[$i] . ' → ' . substr($day, 4);
    }
    return $out;
}

/** Save the Store settings form to store-settings.json; returns what changed. */
function save_store_settings(): array
{
    $menu = load_menu();
    // The values the form showed, so untouched fields don't count as changes.
    $old = ($menu ? ($menu['store'] ?? []) : []) + [
        'prepMinutes' => 25, 'pickupMinimum' => 0, 'deliveryFee' => 0, 'deliveryMinimum' => 0, 'deliveryZips' => [],
        'pickupInstructions' => '', 'alertOrderNotStarted' => 5, 'alertOrderLatePrep' => 0, 'alertOrderReadyIdle' => 5, 'alertEventSoon' => 30,
    ] + array_fill_keys(array_column(SITE_FEATURES, 0), true);
    $days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    $readHours = function (string $prefix) use ($days) {
        $out = [];
        foreach ($days as $d) {
            $o = hms((string) ($_POST[$prefix . '_open'][$d] ?? ''));
            $c = hms((string) ($_POST[$prefix . '_close'][$d] ?? ''));
            if ($o !== null && $c !== null) $out[$d] = [['open' => $o, 'close' => $c]];
        }
        return $out;
    };
    // Delivery on/off, zones, fees and minimums are set on the Delivery page now: keep what is saved.
    $raw = read_store_settings();
    $new = [
        'name'               => trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 80)),
        'address'            => trim(mb_substr((string) ($_POST['address'] ?? ''), 0, 160)),
        'phone'              => trim(mb_substr((string) ($_POST['phone'] ?? ''), 0, 40)),
        'prepMinutes'        => min(600, max(0, (int) ($_POST['prep'] ?? 25))),
        'pickupMinimum'      => max(0, (int) (dollars_to_cents((string) ($_POST['pickupMin'] ?? '')) ?? 0)),
        'pickupEnabled'      => !empty($_POST['pickupEnabled']),
        'pickupInstructions' => trim(mb_substr((string) ($_POST['pickupInstructions'] ?? ''), 0, 300)),
        'pickupHours'        => $readHours('pickup'),
        'deliveryHours'      => $readHours('delivery'),
        'notificationsEnabled'    => !empty($_POST['notificationsEnabled']),
        'alertOrderNotStarted'    => min(120, max(0, (int) ($_POST['alertOrderNotStarted'] ?? 5))),
        'alertOrderLatePrep'      => min(120, max(0, (int) ($_POST['alertOrderLatePrep'] ?? 0))),
        'alertOrderReadyIdle'     => min(120, max(0, (int) ($_POST['alertOrderReadyIdle'] ?? 5))),
        'alertEventSoon'          => min(120, max(0, (int) ($_POST['alertEventSoon'] ?? 30))),
    ];
    // Website sections (see api/site-features.php): off hides the page and every link to it.
    foreach (SITE_FEATURES as [$key]) $new[$key] = !empty($_POST[$key]);
    foreach (['deliveryEnabled', 'deliveryFee', 'deliveryMinimum', 'deliveryZips'] as $k) if (array_key_exists($k, $raw)) $new[$k] = $raw[$k];
    write_store_settings($new);
    return store_settings_changes($old, $new);
}

function admin_page_settings(): void
{
    if (is_post()) {
        require_csrf();
        $changes = save_store_settings();
        if ($changes) admin_log('settings.save', '', $changes);
        flash($changes ? 'Saved. The website follows the new settings straight away.' : 'Saved. Nothing had changed.');
        redirect(admin_url('settings'));
    }

    $menu = load_menu();
    $store = $menu ? ($menu['store'] ?? []) : [];
    $days = ['MON' => 'Monday', 'TUE' => 'Tuesday', 'WED' => 'Wednesday', 'THU' => 'Thursday', 'FRI' => 'Friday', 'SAT' => 'Saturday', 'SUN' => 'Sunday'];
    $money = fn($c) => number_format(((int) $c) / 100, 2, '.', '');
    $timeVal = fn(array $hours, string $d, string $which) => isset($hours[$d][0][$which]) ? substr((string) $hours[$d][0][$which], 0, 5) : '';
    $hoursTable = function (string $prefix, array $hours) use ($days, $timeVal) {
        $rows = '';
        foreach ($days as $k => $name) {
            $rows .= '<tr><td>' . $name . '</td>'
                . '<td><input type="time" name="' . $prefix . '_open[' . $k . ']" value="' . h($timeVal($hours, $k, 'open')) . '" aria-label="' . $name . ' opens"></td>'
                . '<td><input type="time" name="' . $prefix . '_close[' . $k . ']" value="' . h($timeVal($hours, $k, 'close')) . '" aria-label="' . $name . ' closes"></td></tr>';
        }
        return '<div class="table flush"><table class="tbl hours"><thead><tr><th>Day</th><th>Open</th><th>Close</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    };

    $i = fn(string $text) => info_tip($text);

    // One row per switchable website section: name, what it does, and an on/off switch.
    $sections = '';
    foreach (SITE_FEATURES as $page => [$key, $label, $desc]) {
        $on = !array_key_exists($key, $store) || !empty($store[$key]);
        $sections .= '<div class="section-switch">'
            . '<div><b>' . h($label) . '</b><small class="muted">' . h($desc) . ' <a href="' . h(SITE_BASE . $page . '.html') . '" target="_blank" rel="noopener">View page</a></small></div>'
            . '<span class="section-switch-state" data-state>' . ($on ? 'Enabled' : 'Disabled') . '</span>'
            . '<label class="switch"><input type="checkbox" name="' . $key . '" value="1"' . ($on ? ' checked' : '') . ' aria-label="' . h($label) . ' page enabled"><span></span></label>'
            . '</div>';
    }

    $body = page_head('Store settings', 'Business details, website sections, ordering and hours. Leave a day\'s times blank to mark it closed.')
        . '<form method="post" action="' . h(admin_url('settings')) . '">' . csrf_field()
        . card('Business', '<div class="form-grid">'
            . '<label class="field"><span>Name' . $i('Your restaurant name. Shown on the website, receipts and order confirmations.') . '</span><input name="name" value="' . h($store['name'] ?? '') . '" maxlength="80"></label>'
            . '<label class="field"><span>Address' . $i('Your physical address. Shown on the website, receipts and pickup confirmation pages.') . '</span><input name="address" value="' . h($store['address'] ?? '') . '" maxlength="160"></label>'
            . '<label class="field sm"><span>Phone' . $i('Your business phone number. Shown on the website and receipts.') . '</span><input name="phone" value="' . h($store['phone'] ?? '') . '" maxlength="40"></label></div>')
        . card('Website sections',
            '<div class="section-switches">' . $sections . '</div>',
            $i('Switch a page off to take it off the website: it disappears from the menu, the mobile menu and the footer, every button that links to it is hidden, and visiting its address shows "page not found". Its content stays saved in the admin, so switching it back on restores it.'))
        . card('Ordering & delivery', '<div class="form-grid">'
            . '<label class="field sm"><span>Prep time' . $i('Default kitchen preparation time for all items. Individual items can override this on the Menu page.') . '</span>' . minutes_input('prep', (string) (int) ($store['prepMinutes'] ?? 25)) . '</label>'
            . '<label class="field sm"><span>Pickup minimum' . $i('Minimum order total required before a customer can place a pickup order. Set to $0 for no minimum.') . '</span>' . money_input('pickupMin', $money($store['pickupMinimum'] ?? 0)) . '</label>'
            . '<label class="check"><input type="checkbox" name="pickupEnabled" value="1" ' . (($store['pickupEnabled'] ?? true) ? 'checked' : '') . '> Take Away enabled' . $i('Master switch for takeaway (pickup) orders on the website. When off, guests can only order delivery. With both off, the website stops taking online orders.') . '</label>'
            . '<p class="field wide muted small">' . icon('truck', 15) . ' Delivery on/off, country, delivery zones, fees and minimums are on the <a href="' . h(admin_url('delivery')) . '">Delivery</a> page.</p>'
            . '<label class="field wide"><span>Pickup instructions' . $i('Shown to customers after they place a pickup order. For example: "Enter through the side door" or "Park in the back lot."') . '</span><input name="pickupInstructions" value="' . h($store['pickupInstructions'] ?? '') . '" maxlength="300"></label></div>')
        . card('Hours', '<div class="hours-wrap"><div><h3>Pickup' . $i('When the website accepts pickup orders. Leave both times blank to close that day.') . '</h3>' . $hoursTable('pickup', $store['pickupHours'] ?? []) . '</div>'
            . '<div><h3>Delivery' . $i('When the website accepts delivery orders. Leave both times blank to close that day.') . '</h3>' . $hoursTable('delivery', $store['deliveryHours'] ?? []) . '</div></div>')
        . card('Notifications', '<div class="form-grid">'
            . '<label class="check"><input type="checkbox" name="notificationsEnabled" value="1" ' . (!empty($store['notificationsEnabled']) ? 'checked' : '') . '> Notifications enabled' . $i('Master switch. When on, the bell icon in the top bar shows real-time alerts for orders, events and more.') . '</label>'
            . '<label class="field sm"><span>Order not started' . $i('Alert when a new order sits without being started for this many minutes. Set to 0 to disable.') . '</span>' . minutes_input('alertOrderNotStarted', (string) (int) ($store['alertOrderNotStarted'] ?? 5)) . '</label>'
            . '<label class="field sm"><span>Order prep overtime' . $i('Alert when kitchen prep takes longer than expected. Set to 0 to use the item\'s own prep time as the threshold.') . '</span>' . minutes_input('alertOrderLatePrep', (string) (int) ($store['alertOrderLatePrep'] ?? 0)) . '</label>'
            . '<label class="field sm"><span>Order ready idle' . $i('Alert when food is marked ready but the order hasn\'t been completed (picked up, delivered, or served). Set to 0 to disable.') . '</span>' . minutes_input('alertOrderReadyIdle', (string) (int) ($store['alertOrderReadyIdle'] ?? 5)) . '</label>'
            . '<label class="field sm"><span>Event starting soon' . $i('Alert this many minutes before a scheduled event begins today. Set to 0 to disable.') . '</span>' . minutes_input('alertEventSoon', (string) (int) ($store['alertEventSoon'] ?? 30)) . '</label>'
            . '</div>')
        . '<div class="savebar"><span class="muted">Changes go live as soon as you save.</span>' . btn_submit('Save settings', 'primary', '', '', 'check') . '</div></form>';
    admin_render('Store settings', $body, 'settings');
}
