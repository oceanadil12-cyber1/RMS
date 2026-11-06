<?php
/**
 * Delivery: where the restaurant delivers and what it costs there.
 * Country, delivery zones (postal codes, distance or everywhere), fees, minimums,
 * free-delivery amounts and delivery times, the restaurant's location for distance
 * zones, and an address checker. The rules live in api/delivery.php.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once dirname(__DIR__, 3) . '/api/menu-overrides.php';
require_once dirname(__DIR__, 3) . '/api/delivery.php';

function admin_page_delivery(): void
{
    $test = null;
    if (is_post()) $test = admin_delivery_post();
    $edit = get_str('edit', 12);
    if ($edit !== '') admin_delivery_zone_form($edit);
    admin_delivery_overview($test);
}

function admin_delivery_find(array $s, string $id): array
{
    foreach ($s['zones'] as $i => $z) if (($z['id'] ?? '') === $id) return [$z, $i];
    return [null, null];
}

/** The delivery master switch as saved (Store settings, or the menu's own setting before it was ever changed). */
function admin_delivery_on(): bool
{
    $rs = read_store_settings();
    return array_key_exists('deliveryEnabled', $rs) ? !empty($rs['deliveryEnabled']) : !empty(delivery_old_store_settings()['deliveryEnabled']);
}

function admin_delivery_cents(string $field): int
{
    return max(0, (int) (dollars_to_cents((string) ($_POST[$field] ?? '')) ?? 0));
}

// ---------- saving ----------

/** Handles every form on the page. Returns the address-check result (that form re-shows the page), otherwise redirects. */
function admin_delivery_post(): ?array
{
    require_csrf();
    $s = delivery_settings(true);
    $id = preg_match('/^z[a-f0-9]{7}$/', (string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : '';
    [$zone, $pos] = admin_delivery_find($s, $id);
    $back = admin_url('delivery');

    switch ($_POST['action'] ?? '') {
        case 'general':
            $store = read_store_settings();
            $wasOn = admin_delivery_on();
            $store['deliveryEnabled'] = !empty($_POST['deliveryEnabled']);
            write_store_settings($store);
            $country = strtoupper(post_str('country', 2));
            $changes = [];
            if (isset(delivery_countries()[$country]) && $country !== $s['country']) {
                $changes[] = 'Country: ' . delivery_country($s['country'])['name'] . ' → ' . delivery_country($country)['name'];
                $s['country'] = $country;
            }
            $unit = post_str('unit', 2) === 'km' ? 'km' : 'mi';
            if ($unit !== $s['unit']) { $changes[] = 'Distance unit: ' . $s['unit'] . ' → ' . $unit; $s['unit'] = $unit; }
            if ($wasOn !== $store['deliveryEnabled']) $changes[] = 'Delivery: ' . ($store['deliveryEnabled'] ? 'on' : 'off');
            delivery_save($s);
            if ($changes) admin_log('delivery.save', '', $changes);
            flash('Saved. The website follows the new delivery settings straight away.');
            break;

        case 'location':
            $lat = trim((string) ($_POST['lat'] ?? ''));
            $lng = trim((string) ($_POST['lng'] ?? ''));
            if (($lat !== '' || $lng !== '') && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
                flash('Latitude must be between -90 and 90 and longitude between -180 and 180, e.g. 26.1567 and -80.1378.', 'err');
                break;
            }
            $s['origin'] = $lat === '' ? ['lat' => null, 'lng' => null] : ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6)];
            $s['geocoder'] = post_str('geocoder', 10) === 'google' ? 'google' : 'osm';
            $key = post_str('googleKey', 120);
            if ($key !== '' && $key !== str_repeat('•', 8)) $s['googleKey'] = $key;
            if (!empty($_POST['clearKey'])) $s['googleKey'] = '';
            delivery_save($s);
            admin_log('delivery.save', 'Restaurant location and address lookups');
            flash('Saved the restaurant location.');
            break;

        case 'locate':
            $store = load_menu()['store'] ?? [];
            $address = trim((string) ($store['address'] ?? ''));
            if ($address === '') { flash('Add the restaurant address in Store settings first.', 'err'); break; }
            $point = delivery_geocode($address . ', ' . delivery_country($s['country'])['name'], $s, false);
            if (!$point) { flash('That address couldn\'t be found on the map. Enter the latitude and longitude yourself (right-click the spot on Google Maps to copy them).', 'err'); break; }
            $s['origin'] = ['lat' => round($point['lat'], 6), 'lng' => round($point['lng'], 6)];
            delivery_save($s);
            admin_log('delivery.save', 'Restaurant location found from the address');
            flash('Found the restaurant at ' . $s['origin']['lat'] . ', ' . $s['origin']['lng'] . '. Check it on the map link below.');
            break;

        case 'save':
            if ($id !== '' && !$zone) { flash('That zone wasn\'t found.', 'err'); break; }
            [$rec, $errors] = admin_delivery_zone_from_post($zone, $s);
            if ($errors) admin_delivery_zone_form($id !== '' ? $id : 'new', $rec, $errors);
            if ($zone) $s['zones'][$pos] = $rec; else $s['zones'][] = $rec;
            delivery_save($s);
            admin_log('delivery.zone', $rec['name'] . ': ' . delivery_zone_area($rec, $s) . ' · ' . delivery_zone_terms($rec) . (empty($rec['active']) ? ' (paused)' : ''));
            flash('Saved "' . $rec['name'] . '". The website uses it straight away.');
            break;

        case 'delete':
            if ($zone) {
                array_splice($s['zones'], $pos, 1);
                delivery_save($s);
                admin_log('delivery.zone', $zone['name'] . ' deleted');
                flash('Deleted "' . $zone['name'] . '".');
            }
            break;

        case 'duplicate':
            if ($zone) {
                $copy = ['id' => substr('z' . bin2hex(random_bytes(4)), 0, 8), 'name' => $zone['name'] . ' (copy)', 'active' => false] + $zone;
                array_splice($s['zones'], $pos + 1, 0, [$copy]);
                delivery_save($s);
                flash('Duplicated. Change the details, then turn it on.');
                $back = admin_url('delivery', ['edit' => $copy['id']]);
            }
            break;

        case 'toggle':
            if ($zone) {
                $s['zones'][$pos]['active'] = empty($zone['active']);
                delivery_save($s);
                admin_log('delivery.zone', $zone['name'] . (empty($zone['active']) ? ' turned on' : ' paused'));
                flash('"' . $zone['name'] . '" ' . (empty($zone['active']) ? 'is on.' : 'is paused.'));
            }
            break;

        case 'move':
            $dir = (int) ($_POST['dir'] ?? 0);
            if ($zone && $dir !== 0 && $pos + $dir >= 0 && $pos + $dir < count($s['zones'])) {
                $item = array_splice($s['zones'], $pos, 1);
                array_splice($s['zones'], $pos + $dir, 0, $item);
                delivery_save($s);
                flash('Moved "' . $zone['name'] . '". The first zone that matches an address is used.');
            }
            break;

        case 'test':
            $a = ['line1' => post_str('line1', 120), 'city' => post_str('city', 80), 'zip' => post_str('zip', 20)];
            $subtotal = admin_delivery_cents('subtotal');
            return ['address' => $a, 'subtotal' => $subtotal, 'match' => delivery_match($a, $s, true)];
    }
    redirect($back);
    return null;
}

function admin_delivery_zone_from_post(?array $existing, array $s): array
{
    $errors = [];
    $type = post_str('type', 10);
    if (!isset(DELIVERY_ZONE_TYPES[$type])) $type = 'postal';
    $codes = [];
    foreach (preg_split('/[,;\n]+/', (string) ($_POST['postcodes'] ?? '')) as $c) {
        $c = strtoupper(trim(mb_substr($c, 0, 20)));
        if ($c !== '' && !in_array($c, $codes, true)) $codes[] = $c;
    }
    $radius = (float) str_replace(',', '.', (string) ($_POST['radius'] ?? '0'));
    // The drawn area arrives as JSON from the map: [[lat, lng], ...].
    $polygon = [];
    foreach ((array) json_decode((string) ($_POST['polygon'] ?? '[]'), true) as $p) {
        if (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1]) && abs((float) $p[0]) <= 90 && abs((float) $p[1]) <= 180) {
            $polygon[] = [round((float) $p[0], 6), round((float) $p[1], 6)];
        }
    }
    $rec = [
        'id'        => $existing['id'] ?? substr('z' . bin2hex(random_bytes(4)), 0, 8),
        'name'      => post_str('name', 60),
        'type'      => $type,
        'postcodes' => $type === 'postal' ? array_slice($codes, 0, 500) : [],
        'radius'    => $type === 'radius' ? round(max(0, min(500, $radius)), 2) : 0,
        'polygon'   => $type === 'polygon' ? array_slice($polygon, 0, 300) : [],
        'fee'       => admin_delivery_cents('fee'),
        'minimum'   => admin_delivery_cents('minimum'),
        'freeOver'  => admin_delivery_cents('freeOver'),
        'eta'       => min(600, max(0, (int) ($_POST['eta'] ?? 20))),
        'active'    => !empty($_POST['active']),
    ];
    if ($rec['name'] === '') $errors[] = 'Give the zone a name, e.g. "Downtown" or "Within 3 miles".';
    if ($type === 'postal' && !$codes) $errors[] = 'List at least one ' . delivery_label_lc(delivery_country($s['country'])['postalLabel'] ?? 'postal code') . ' for this zone.';
    if ($type === 'postal' && delivery_country($s['country'])['postalLabel'] === null) $errors[] = delivery_country($s['country'])['name'] . ' has no postal codes: use a distance zone instead.';
    if ($type === 'radius' && $rec['radius'] <= 0) $errors[] = 'Enter how far this zone reaches, e.g. 3.';
    if ($type === 'polygon' && count($polygon) < 3) $errors[] = 'Draw the area on the map: click at least 3 points around it.';
    foreach ($codes as $c) {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $c, $m) && (strlen($m[1]) !== strlen($m[2]) || (int) $m[1] > (int) $m[2])) $errors[] = 'The range ' . $c . ' should run from low to high with the same number of digits, e.g. 33300-33399.';
    }
    return [$rec, $errors];
}

// ---------- the map ----------

const DELIVERY_MAP_COLORS = ['#c8a84e', '#4ea3c8', '#c84e7a', '#5cb85c', '#a26ad1', '#e0874a', '#3fb8a8', '#d65c5c'];

/**
 * What the map draws (read by admin/assets/delivery.js): the restaurant, where to centre,
 * and each zone with its colour. Distance zones are sent in metres.
 */
function admin_delivery_map_data(array $s, string $store_address): array
{
    $origin = delivery_has_origin($s) ? [(float) $s['origin']['lat'], (float) $s['origin']['lng']] : null;
    $center = $origin;
    if (!$center && $store_address !== '') {
        $p = delivery_geocode($store_address . ', ' . delivery_country($s['country'])['name'], $s, false);
        if ($p) $center = [$p['lat'], $p['lng']];
    }
    $zones = [];
    foreach ($s['zones'] as $n => $z) {
        $zones[] = [
            'id'      => $z['id'],
            'name'    => ($n + 1) . '. ' . $z['name'],
            'type'    => $z['type'],
            'radius'  => $z['type'] === 'radius' ? (float) $z['radius'] * ($s['unit'] === 'km' ? 1000 : 1609.344) : 0,
            'polygon' => $z['type'] === 'polygon' ? array_values((array) $z['polygon']) : [],
            'active'  => !empty($z['active']),
            'color'   => DELIVERY_MAP_COLORS[$n % count(DELIVERY_MAP_COLORS)],
            'terms'   => delivery_zone_area($z, $s) . ' · ' . delivery_zone_terms($z),
            'url'     => admin_url('delivery', ['edit' => $z['id']]),
        ];
    }
    return ['origin' => $origin, 'center' => $center, 'zones' => $zones, 'metresPerUnit' => $s['unit'] === 'km' ? 1000 : 1609.344, 'unit' => $s['unit']];
}

function admin_delivery_map_head(): string
{
    return '<link rel="stylesheet" href="' . admin_asset('vendor/leaflet/leaflet.css') . '">';
}

// ---------- the page ----------

function admin_delivery_overview(?array $test): void
{
    $s = delivery_settings(true);
    $store = load_menu()['store'] ?? [];
    $on = admin_delivery_on();
    $country = delivery_country($s['country']);
    $active = delivery_active_zones($s);
    $needsOrigin = (bool) array_filter($active, fn($z) => $z['type'] === 'radius') && !delivery_has_origin($s);
    $i = fn(string $text) => info_tip($text);

    $alerts = '';
    if (!$on) $alerts .= '<div class="callout gold">' . icon('alert', 18) . '<div><b>Delivery is switched off.</b><p class="muted">Guests can only order pickup. Turn it on under Delivery service below.</p></div></div>';
    if (!$active) $alerts .= '<div class="callout red">' . icon('alert', 18) . '<div><b>No delivery zones are on.</b><p class="muted">Add a zone (or turn one on) so guests can order delivery.</p></div></div>';
    if ($needsOrigin) $alerts .= '<div class="callout red">' . icon('alert', 18) . '<div><b>Distance zones need the restaurant\'s location.</b><p class="muted">Set it under Restaurant location below. Until then, distance zones match no address.</p></div></div>';
    if (!$s['saved']) $alerts .= '<div class="callout">' . icon('info', 18) . '<div><b>Your earlier delivery setup was brought over as the first zone.</b><p class="muted">Its ZIP codes, fee and minimum came from Store settings. Nothing changes for guests until you edit it.</p></div></div>';

    // Delivery service
    $countryOptions = '';
    foreach (delivery_countries() as $code => [$name]) {
        $countryOptions .= '<option value="' . h($code) . '"' . ($code === $country['code'] ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    $general = form_open(admin_url('delivery')) . '<input type="hidden" name="action" value="general">'
        . '<div class="form-grid">'
        . '<label class="check"><input type="checkbox" name="deliveryEnabled" value="1"' . ($on ? ' checked' : '') . '> Delivery enabled' . $i('Master switch for delivery on the website. When off, guests can only order pickup.') . '</label>'
        . '<label class="field"><span>Country' . $i('Sets what guests are asked for at checkout (ZIP code, postcode, PIN code…), whether it is required, and where addresses are looked up.') . '</span><select name="country" data-country>' . $countryOptions . '</select></label>'
        . '<label class="field sm"><span>Distance in' . $i('Used for distance zones, e.g. "within 3 miles".') . '</span><select name="unit"><option value="mi"' . ($s['unit'] === 'mi' ? ' selected' : '') . '>Miles</option><option value="km"' . ($s['unit'] === 'km' ? ' selected' : '') . '>Kilometres</option></select></label>'
        . '</div>'
        . '<p class="muted small">Guests are asked for: street address, city' . ($country['postalLabel'] !== null ? ' and ' . h(delivery_label_lc($country['postalLabel'])) : ' (' . h($country['name']) . ' has no postal codes, so delivery works by distance)') . '. '
        . 'Delivery hours are set in <a href="' . h(admin_url('settings')) . '">Store settings</a> &rarr; Hours.</p>'
        . '<div class="btns">' . btn_submit('Save', 'primary', '', '', 'check') . '</div></form>';

    // Zones
    $rows = '';
    $total = count($s['zones']);
    foreach ($s['zones'] as $n => $z) {
        $off = empty($z['active']);
        $idField = '<input type="hidden" name="id" value="' . h($z['id']) . '">';
        $move = fn($dir, $arrow) => form_open(admin_url('delivery'), 'inline') . $idField . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="' . $dir . '">' . btn_submit($arrow, 'sm ghost') . '</form>';
        $rows .= '<tr class="' . ($off ? 'is-off' : '') . '">'
            . '<td class="num muted">' . ($n + 1) . '</td>'
            . '<td><a class="row-link" href="' . h(admin_url('delivery', ['edit' => $z['id']])) . '"><b>' . h($z['name']) . '</b></a><div class="cell-sub">' . h(delivery_zone_area($z, $s)) . '</div></td>'
            . '<td>' . h(DELIVERY_ZONE_TYPES[$z['type']] ?? $z['type']) . '</td>'
            . '<td class="num">' . ((int) $z['fee'] ? h(fmt_money((int) $z['fee'])) : 'Free') . '</td>'
            . '<td class="num">' . ((int) $z['minimum'] ? h(fmt_money((int) $z['minimum'])) : '—') . '</td>'
            . '<td class="num">' . ((int) $z['freeOver'] ? h(fmt_money((int) $z['freeOver'])) : '—') . '</td>'
            . '<td class="num">' . (int) $z['eta'] . ' min</td>'
            . '<td>' . ($off ? badge('Paused', 'muted') : badge('On', 'good')) . '</td>'
            . '<td class="act nowrap">' . ($n > 0 ? $move(-1, "\xE2\x86\x91") : '') . ($n < $total - 1 ? $move(1, "\xE2\x86\x93") : '') . '</td>'
            . '<td class="act">' . form_open(admin_url('delivery'), 'inline') . $idField . '<input type="hidden" name="action" value="toggle">' . btn_submit($off ? 'Turn on' : 'Pause', 'sm ghost') . '</form></td></tr>';
    }
    $zones = $rows === ''
        ? empty_state('No delivery zones yet', 'Add a zone to say where you deliver and what it costs.', 'truck')
        : '<div class="table"><table class="tbl"><thead><tr><th>#</th><th style="min-width:190px">Zone</th><th>Type</th><th class="num">Fee</th><th class="num">Minimum</th><th class="num">Free over</th><th class="num">Travel time</th><th>Status</th><th>Order</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="muted small">An address gets the <b>first</b> zone in this list that matches it, so put smaller or closer zones above wider ones (and "Everywhere else" last). Addresses that match no zone can\'t order delivery.</p>';

    // Restaurant location
    $lat = $s['origin']['lat'];
    $lng = $s['origin']['lng'];
    $hasKey = trim((string) $s['googleKey']) !== '';
    $location = form_open(admin_url('delivery')) . '<input type="hidden" name="action" value="location">'
        . '<div class="form-grid">'
        . '<label class="field sm"><span>Latitude</span><input name="lat" inputmode="decimal" value="' . h($lat === null ? '' : (string) $lat) . '" placeholder="e.g. 26.1567"></label>'
        . '<label class="field sm"><span>Longitude</span><input name="lng" inputmode="decimal" value="' . h($lng === null ? '' : (string) $lng) . '" placeholder="e.g. -80.1378"></label>'
        . '<label class="field"><span>Address lookups' . $i('Distance zones look up where each guest\'s address is. OpenStreetMap is free; Google is more precise in some areas and needs a Geocoding API key from Google Cloud.') . '</span><select name="geocoder" data-geocoder>'
        .   '<option value="osm"' . ($s['geocoder'] !== 'google' ? ' selected' : '') . '>OpenStreetMap (free)</option>'
        .   '<option value="google"' . ($s['geocoder'] === 'google' ? ' selected' : '') . '>Google Maps (API key)</option></select></label>'
        . '<label class="field" data-google-key' . ($s['geocoder'] === 'google' ? '' : ' hidden') . '><span>Google API key</span><input name="googleKey" autocomplete="off" value="' . ($hasKey ? str_repeat('•', 8) : '') . '" placeholder="AIza…">'
        .   ($hasKey ? '<label class="check small"><input type="checkbox" name="clearKey" value="1"> Remove the saved key</label>' : '') . '</label>'
        . '</div>'
        . ($lat !== null ? '<p class="small"><a href="https://www.openstreetmap.org/?mlat=' . h((string) $lat) . '&amp;mlon=' . h((string) $lng) . '#map=17/' . h((string) $lat) . '/' . h((string) $lng) . '" target="_blank" rel="noopener">' . icon('pin', 14) . ' Check this spot on the map</a></p>' : '')
        . '<div class="btns">' . btn_submit('Save location', 'primary', '', '', 'check') . '</div></form>'
        . form_open(admin_url('delivery')) . '<input type="hidden" name="action" value="locate">'
        . '<p class="muted small">Only needed for distance zones. Store address: ' . h((string) ($store['address'] ?? 'not set')) . ' (<a href="' . h(admin_url('settings')) . '">change</a>).</p>'
        . '<div class="btns">' . btn_submit('Find from the store address', 'sm', '', '', 'search') . '</div></form>';

    // Address checker
    $ta = $test['address'] ?? ['line1' => '', 'city' => '', 'zip' => ''];
    $result = '';
    if ($test) {
        $m = $test['match'];
        if ($m['zone']) {
            $z = $m['zone'];
            $fee = delivery_zone_fee($z, $test['subtotal']);
            $short = $test['subtotal'] < (int) $z['minimum'];
            $result = '<div class="callout green">' . icon('check', 18) . '<div><b>Delivers: ' . h($z['name']) . '</b><p class="muted">'
                . h(delivery_zone_area($z, $s)) . ($m['distance'] !== null ? ' · ' . h((string) $m['distance']) . ' ' . h($s['unit']) . ' away' : '')
                . '<br>Fee for a ' . h(fmt_money($test['subtotal'])) . ' order: ' . ($fee ? h(fmt_money($fee)) : 'free')
                . ($short ? ' · <b>below the ' . h(fmt_money((int) $z['minimum'])) . ' minimum</b>' : '')
                . ' · arrives about ' . ((int) ($store['prepMinutes'] ?? 25) + (int) $z['eta']) . ' min after ordering</p></div></div>';
        } else {
            $why = ['nozones' => 'No delivery zones are on.', 'lookup' => 'The address couldn\'t be found on the map, so distance zones and drawn areas couldn\'t be checked. Check the spelling.', 'outside' => 'It isn\'t in any zone.'][$m['reason']] ?? 'It isn\'t in any zone.';
            $result = '<div class="callout red">' . icon('x', 18) . '<div><b>No delivery to this address</b><p class="muted">' . h($why) . ($m['distance'] !== null ? ' It is ' . h((string) $m['distance']) . ' ' . h($s['unit']) . ' away.' : '') . '</p></div></div>';
        }
    }
    $checker = form_open(admin_url('delivery') . '#check') . '<input type="hidden" name="action" value="test">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Street address</span><input name="line1" value="' . h($ta['line1']) . '" placeholder="e.g. 2300 Wilton Drive"></label>'
        . '<label class="field sm"><span>City</span><input name="city" value="' . h($ta['city']) . '"></label>'
        . ($country['postalLabel'] !== null ? '<label class="field sm"><span>' . h($country['postalLabel']) . '</span><input name="zip" value="' . h($ta['zip']) . '"></label>' : '')
        . '<label class="field sm"><span>Order subtotal</span>' . money_input('subtotal', number_format(($test['subtotal'] ?? 2500) / 100, 2, '.', '')) . '</label>'
        . '</div><div class="btns">' . btn_submit('Check address', 'primary', '', '', 'search') . '</div></form>' . $result;

    // The map: restaurant pin (drag it to move the restaurant), distance circles, drawn areas, the checked address.
    $mapData = admin_delivery_map_data($s, (string) ($store['address'] ?? ''));
    if ($test && !empty($test['match']['point'])) {
        $mapData['check'] = ['lat' => $test['match']['point']['lat'], 'lng' => $test['match']['point']['lng'], 'label' => $test['address']['line1'] . ($test['match']['zone'] ? ' → ' . $test['match']['zone']['name'] : ' → no delivery')];
    }
    $postalZones = array_filter($s['zones'], fn($z) => $z['type'] === 'postal' || $z['type'] === 'any');
    $map = '<div class="dmap" data-delivery-map="' . h(json_encode($mapData)) . '"></div>'
        . '<div class="dmap-legend" data-map-legend></div>'
        . '<p class="muted small" data-origin-hint>' . ($mapData['origin'] ? 'Drag the restaurant pin to move it, then press Save location below.' : 'The restaurant\'s location isn\'t set yet: click "Find from the store address" below, or drag the pin once it appears.') . '</p>'
        . ($postalZones ? '<p class="muted small">Not drawn on the map: ' . h(implode(', ', array_map(fn($z) => $z['name'] . ' (' . delivery_zone_area($z, $s) . ')', $postalZones))) . '.</p>' : '');

    $body = admin_delivery_map_head()
        . page_head('Delivery', 'Where you deliver, what it costs, and how long it takes. Changes apply to the website straight away.',
            btn_link('Add zone', admin_url('delivery', ['edit' => 'new']), 'primary', 'plus'))
        . $alerts
        . card('Delivery service', $general)
        . card('Delivery zones', $zones)
        . card('Map', $map)
        . '<div id="check">' . card('Check an address', '<p class="muted small">See which zone, fee and minimum an address gets, exactly as checkout will. Addresses that are looked up show as a pin on the map.</p>' . $checker) . '</div>'
        . card('Restaurant location', $location);
    admin_render('Delivery', $body, 'delivery', ['vendor/leaflet/leaflet.js', 'delivery.js']);
}

// ---------- the zone form ----------

function admin_delivery_zone_form(string $edit, ?array $posted = null, array $errors = []): void
{
    $s = delivery_settings(true);
    $isNew = $edit === 'new';
    [$saved] = $isNew ? [null] : admin_delivery_find($s, $edit);
    if (!$isNew && !$saved) {
        admin_render('Zone', '<p class="back"><a href="' . h(admin_url('delivery')) . '">' . icon('left', 15) . ' Delivery</a></p>' . empty_state('Zone not found', 'It may have been deleted.', 'truck'), 'delivery');
    }
    $z = $posted ?? $saved ?? delivery_zone_defaults();
    $country = delivery_country($s['country']);
    $postalLabel = $country['postalLabel'] ?? 'Postal code';
    $money = fn($c) => (int) $c ? number_format(((int) $c) / 100, 2, '.', '') : '';
    $i = fn(string $text) => info_tip($text);

    $errorHtml = '';
    foreach ($errors as $msg) $errorHtml .= '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($msg) . '</span></div>';

    $types = '';
    foreach (DELIVERY_ZONE_TYPES as $t => $label) {
        $hint = ['postal' => 'A list of ' . delivery_label_lc($postalLabel) . 's', 'radius' => 'Within a distance of the restaurant', 'polygon' => 'An area you draw on the map', 'any' => 'Any address (put it last)'][$t];
        $disabled = $t === 'postal' && $country['postalLabel'] === null;
        $types .= '<label class="check"><input type="radio" name="type" value="' . $t . '"' . ($z['type'] === $t ? ' checked' : '') . ($disabled ? ' disabled' : '') . ' data-zone-type> <b>' . h($label) . '</b> <span class="muted small">' . h($hint) . ($disabled ? ' (not used in ' . h($country['name']) . ')' : '') . '</span></label>';
    }

    $form = '<form class="eventform" method="post" action="' . h(admin_url('delivery')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . h($isNew ? '' : (string) $z['id']) . '">'
        . '<div class="split wideleft"><div class="stack">'
        .   card('Area', '<label class="field wide"><span>Zone name' . $i('Shown to guests at checkout, e.g. "Downtown".') . '</span><input name="name" value="' . h($z['name']) . '" maxlength="60" required placeholder="e.g. Downtown"></label>'
                . '<div class="field wide"><span>Who it covers</span><div class="stack tight">' . $types . '</div></div>'
                . '<label class="field wide" data-for-type="postal"><span>' . h($postalLabel) . 's' . $i('One per line or separated by commas. Exact: 33305 or SW1A 1AA. Whole area: 333* or SW1*. Range: 33300-33399.') . '</span>'
                .   '<textarea name="postcodes" rows="4" placeholder="33305, 33306&#10;333*&#10;33300-33399">' . h(implode("\n", (array) $z['postcodes'])) . '</textarea>'
                .   '<small class="muted">Exact codes (33305), whole areas with * (333*, SW1*) or number ranges (33300-33399). An exact code also covers longer forms of it, e.g. 33305 covers 33305-1234.</small></label>'
                . '<label class="field sm" data-for-type="radius"><span>Reaches up to' . $i('Straight-line distance from the restaurant\'s location (set on the Delivery page).') . '</span>'
                .   '<span class="mins"><input name="radius" inputmode="decimal" value="' . h($z['radius'] ? (string) $z['radius'] : '') . '" placeholder="3"><b>' . h($s['unit']) . '</b></span></label>'
                . (!delivery_has_origin($s) ? '<p class="flash err" data-for-type="radius">' . icon('alert', 16) . '<span>Set the restaurant\'s location on the Delivery page first, or this zone matches no address.</span></p>' : '')
                . '<div class="field wide" data-for-type="radius polygon"><span data-for-type="polygon">Draw the area' . $i('Click the map to add corners around the area. Drag a corner to move it; click a corner to remove it. The area closes by itself.') . '</span>'
                .   '<div class="dmap dmap-edit" data-zone-map="' . h(json_encode(['zone' => ['id' => (string) $z['id'], 'type' => $z['type'], 'polygon' => array_values((array) $z['polygon']), 'color' => '#c8a84e']] + admin_delivery_map_data($s, (string) (load_menu()['store']['address'] ?? '')))) . '"></div>'
                .   '<input type="hidden" name="polygon" value="' . h(json_encode(array_values((array) $z['polygon']))) . '" data-polygon>'
                .   '<div class="btns" data-for-type="polygon"><button type="button" class="btn sm" data-poly-undo>' . icon('left', 15) . '<span>Undo last corner</span></button>'
                .   '<button type="button" class="btn sm ghost" data-poly-clear>' . icon('trash', 15) . '<span>Clear</span></button>'
                .   '<span class="muted small" data-poly-count></span></div>'
                .   '<small class="muted" data-for-type="radius">The gold circle shows how far this zone reaches. Other zones are shown faintly.</small></div>')
        . '</div><div class="stack">'
        .   card('Price and time', '<div class="form-grid">'
                . '<label class="field sm"><span>Delivery fee' . $i('Charged on every delivery order in this zone. Leave empty for free delivery. Per-item delivery surcharges from Menu & food are added on top.') . '</span>' . money_input('fee', $money($z['fee']), '0.00') . '</label>'
                . '<label class="field sm"><span>Minimum order' . $i('The smallest subtotal guests in this zone can order for delivery.') . '</span>' . money_input('minimum', $money($z['minimum']), '0.00') . '</label>'
                . '<label class="field sm"><span>Free delivery over' . $i('Orders at or above this subtotal get free delivery. Leave empty to always charge the fee.') . '</span>' . money_input('freeOver', $money($z['freeOver']), 'never') . '</label>'
                . '<label class="field sm"><span>Travel time' . $i('Added to the kitchen\'s prep time to tell guests when to expect their food.') . '</span>' . minutes_input('eta', (string) (int) $z['eta']) . '</label>'
                . '</div>')
        .   card('On the website', '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($z['active']) ? ' checked' : '') . '> Deliver to this zone</label><p class="muted small">Untick to pause it without losing the details.</p>')
        . '</div></div>'
        . '<div class="savebar"><a class="btn ghost" href="' . h(admin_url('delivery')) . '">Cancel</a><span class="grow"></span>'
        . '<button class="btn primary" type="submit">' . icon('check', 16) . '<span>Save zone</span></button></div></form>';

    $extra = '';
    if (!$isNew) {
        $idField = '<input type="hidden" name="id" value="' . h((string) $z['id']) . '">';
        $extra = card('More', '<div class="btns">'
            . form_open(admin_url('delivery'), 'inline') . $idField . '<input type="hidden" name="action" value="duplicate">' . btn_submit('Duplicate', 'sm', '', '', 'plus') . '</form>'
            . form_open(admin_url('delivery'), 'inline') . $idField . '<input type="hidden" name="action" value="delete">'
            . btn_submit('Delete zone', 'sm danger', '', '', 'trash', 'Delete "' . $z['name'] . '"? Addresses in it can no longer order delivery unless another zone covers them.') . '</form></div>');
    }

    $body = admin_delivery_map_head()
        . '<p class="back"><a href="' . h(admin_url('delivery')) . '">' . icon('left', 15) . ' Delivery</a></p>'
        . page_head($isNew ? 'Add a delivery zone' : 'Edit zone', $isNew ? 'Say which addresses it covers and what delivery costs there.' : h($z['name']))
        . $errorHtml . $form . $extra;
    admin_render($isNew ? 'Add a delivery zone' : 'Edit zone', $body, 'delivery', ['vendor/leaflet/leaflet.js', 'delivery.js']);
}
