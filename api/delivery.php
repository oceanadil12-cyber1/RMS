<?php
/**
 * Delivery zones: where the restaurant delivers, and what it costs there.
 * Set up in the admin (admin/?page=delivery) and used by checkout, the cart and the chat.
 *
 * Settings (database setting 'delivery_settings'):
 *   country     ISO code (api/countries.php): postcode label, distance unit, address lookups
 *   unit        'mi' or 'km'
 *   origin      ['lat' => .., 'lng' => ..]  the restaurant, for distance zones
 *   geocoder    'osm' (OpenStreetMap, free) or 'google' (with googleKey)
 *   zones       in priority order; the FIRST active zone that matches an address is used:
 *     ['id', 'name', 'type' => 'postal'|'radius'|'polygon'|'any', 'postcodes' => ['33305', '333*', '33300-33399'],
 *      'radius' => 3.5, 'polygon' => [[lat, lng], ...] (an area drawn on the map), 'fee' => cents, 'minimum' => cents, 'freeOver' => cents (0 = never),
 *      'eta' => travel minutes added to the kitchen's prep time, 'active' => bool]
 *
 * Delivery itself is switched on and off with the store setting 'deliveryEnabled'.
 */
require_once __DIR__ . '/lib.php';

const DELIVERY_ZONE_TYPES = ['postal' => 'Postal codes', 'radius' => 'Distance', 'polygon' => 'Drawn area', 'any' => 'Everywhere else'];
const DELIVERY_MILES_COUNTRIES = ['US', 'GB', 'LR', 'MM', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM'];
const DELIVERY_NUMERIC_POSTCODES = ['US', 'PR', 'GU', 'VI', 'AS', 'MP', 'FM', 'MH', 'PW', 'UM', 'IN', 'DE', 'FR', 'IT', 'ES', 'MX', 'AU', 'PH', 'TH', 'ID', 'MY', 'PK', 'TR', 'SA', 'EG', 'NG', 'KE', 'ZA', 'BE', 'AT', 'CH', 'DK', 'NO', 'FI', 'GR'];

/** All countries: code => [name, postcode label or null], sorted by name. */
function delivery_countries(): array
{
    static $list = null;
    if ($list === null) {
        $list = (array) require __DIR__ . '/countries.php';
        uasort($list, fn($a, $b) => strcoll($a[0], $b[0]));
    }
    return $list;
}

function delivery_country(string $code): array
{
    $c = delivery_countries()[$code] ?? ['United States', 'ZIP code'];
    return ['code' => isset(delivery_countries()[$code]) ? $code : 'US', 'name' => $c[0], 'postalLabel' => $c[1]];
}

function delivery_zone_defaults(): array
{
    return ['id' => '', 'name' => '', 'type' => 'postal', 'postcodes' => [], 'radius' => 0, 'polygon' => [], 'fee' => 0, 'minimum' => 0, 'freeOver' => 0, 'eta' => 20, 'active' => true];
}

/** The saved settings, or (first time) settings built from the old Store settings: one zone with the ZIP list, fee and minimum. */
function delivery_settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $saved = null;
    try {
        require_once __DIR__ . '/db.php';
        $saved = db_setting('delivery_settings');
    } catch (Throwable $e) {
        $saved = null;
    }
    $base = ['country' => 'US', 'unit' => 'mi', 'origin' => ['lat' => null, 'lng' => null], 'geocoder' => 'osm', 'googleKey' => '', 'zones' => [], 'saved' => false];
    if (is_array($saved)) {
        $s = $saved + $base;
        $s['zones'] = array_values(array_map(fn($z) => (array) $z + delivery_zone_defaults(), (array) $s['zones']));
        $s['saved'] = true;
    } else {
        $s = $base;
        $old = delivery_old_store_settings();
        $zips = array_values(array_filter(array_map('strval', (array) ($old['deliveryZips'] ?? []))));
        $s['zones'][] = [
            'id' => 'z' . substr(sha1('first'), 0, 7), 'name' => $zips ? 'Local area' : 'Everywhere',
            'type' => $zips ? 'postal' : 'any', 'postcodes' => $zips,
            'fee' => (int) ($old['deliveryFee'] ?? 0), 'minimum' => (int) ($old['deliveryMinimum'] ?? 0),
        ] + delivery_zone_defaults();
    }
    return $cache = $s;
}

/** The delivery ZIP list, fee and minimum from before this page existed. */
function delivery_old_store_settings(): array
{
    $s = function_exists('read_store_settings') ? read_store_settings() : [];
    if (!isset($s['deliveryZips'])) {
        $snap = json_decode((string) @file_get_contents(dirname(__DIR__) . '/data/menu.json'), true);
        $s += (array) ($snap['store'] ?? []);
    }
    return $s;
}

function delivery_save(array $s): bool
{
    unset($s['saved']);
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('delivery_settings', $s);
        delivery_settings(true);
        return true;
    } catch (Throwable $e) {
        error_log('delivery_save: ' . $e->getMessage());
        return false;
    }
}

function delivery_active_zones(array $s): array
{
    return array_values(array_filter($s['zones'], fn($z) => !empty($z['active'])));
}

// ---------- matching ----------

/** "sw1a 1aa" → "SW1A1AA". */
function delivery_postal_norm(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/**
 * Does $postcode fall in the zone's list? Each entry is an exact code ("33305", "SW1A 1AA"),
 * a prefix ending in * ("333*", "SW1*") or a numeric range ("33300-33399").
 * An exact entry also matches the first part of a longer code: "33305" covers "33305-1234"
 * and "SW1A" covers "SW1A 1AA".
 */
function delivery_postal_matches(string $postcode, array $patterns): bool
{
    $code = delivery_postal_norm($postcode);
    if ($code === '') return false;
    $first = delivery_postal_norm((string) preg_split('/[\s-]+/', trim($postcode))[0]);
    foreach ($patterns as $p) {
        $p = trim((string) $p);
        if ($p === '') continue;
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $p, $m)) {
            $digits = preg_replace('/\D/', '', $first !== '' ? $first : $code);
            if ($digits !== '' && strlen($digits) === strlen($m[1]) && (int) $digits >= (int) $m[1] && (int) $digits <= (int) $m[2]) return true;
            continue;
        }
        if (substr($p, -1) === '*') {
            $prefix = delivery_postal_norm(substr($p, 0, -1));
            if ($prefix === '' || strpos($code, $prefix) === 0) return true;
            continue;
        }
        $np = delivery_postal_norm($p);
        if ($np === $code || $np === $first) return true;
    }
    return false;
}

/** Straight-line distance between two points, in the settings' unit. */
function delivery_distance(float $lat1, float $lng1, float $lat2, float $lng2, string $unit): float
{
    $r = $unit === 'km' ? 6371.0 : 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

function delivery_has_origin(array $s): bool
{
    return is_numeric($s['origin']['lat'] ?? null) && is_numeric($s['origin']['lng'] ?? null);
}

/** One line for an address lookup: "2266 Wilton Drive, Wilton Manors, 33305, United States". */
function delivery_address_query(array $a, array $s): string
{
    $parts = [trim((string) ($a['line1'] ?? '')), trim((string) ($a['city'] ?? '')), trim((string) ($a['zip'] ?? '')), delivery_country($s['country'])['name']];
    return implode(', ', array_filter($parts, fn($p) => $p !== ''));
}

/**
 * Which zone delivers to this address.
 * Returns ['zone' => array|null, 'distance' => float|null, 'pending' => bool, 'reason' => ''|'outside'|'lookup'|'nozones'].
 * $complete = false while the guest is still typing: zones that need more of the address
 * than has been entered make the answer 'pending' instead of 'outside'.
 */
function delivery_match(array $a, array $s, bool $complete = true): array
{
    $zones = delivery_active_zones($s);
    $out = ['zone' => null, 'distance' => null, 'point' => null, 'pending' => false, 'reason' => ''];
    if (!$zones) return ['reason' => 'nozones'] + $out;

    $postcode = trim((string) ($a['zip'] ?? ''));
    $hasStreet = trim((string) ($a['line1'] ?? '')) !== '' && trim((string) ($a['city'] ?? '')) !== '';
    $point = false; // not looked up yet
    $lookupFailed = false;

    foreach ($zones as $z) {
        if ($z['type'] === 'postal') {
            if ($postcode === '') { if (!$complete) $out['pending'] = true; continue; }
            if (delivery_postal_matches($postcode, (array) $z['postcodes'])) return ['zone' => $z, 'pending' => false] + $out;
        } elseif ($z['type'] === 'radius' || $z['type'] === 'polygon') {
            if ($z['type'] === 'radius' && (!delivery_has_origin($s) || (float) $z['radius'] <= 0)) continue;
            if ($z['type'] === 'polygon' && count((array) $z['polygon']) < 3) continue;
            if (!$hasStreet) { if (!$complete) $out['pending'] = true; continue; }
            if ($point === false) {
                $point = delivery_geocode(delivery_address_query($a, $s), $s);
                $out['point'] = $point;
                if ($point && delivery_has_origin($s)) $out['distance'] = round(delivery_distance((float) $s['origin']['lat'], (float) $s['origin']['lng'], $point['lat'], $point['lng'], $s['unit']), 2);
            }
            if ($point === null) { $lookupFailed = true; continue; }
            $inside = $z['type'] === 'radius'
                ? $out['distance'] !== null && $out['distance'] <= (float) $z['radius']
                : delivery_point_in_polygon($point['lat'], $point['lng'], (array) $z['polygon']);
            if ($inside) return ['zone' => $z, 'pending' => false] + $out;
        } else { // 'any'
            if ($out['pending']) return $out; // a closer zone may still match once the address is complete
            return ['zone' => $z] + $out;
        }
    }
    if ($out['pending']) return $out;
    return ['reason' => $lookupFailed ? 'lookup' : 'outside'] + $out;
}

/** Is the point inside the area drawn on the map? $polygon = [[lat, lng], ...] (ray casting). */
function delivery_point_in_polygon(float $lat, float $lng, array $polygon): bool
{
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = [(float) $polygon[$i][0], (float) $polygon[$i][1]];
        [$yj, $xj] = [(float) $polygon[$j][0], (float) $polygon[$j][1]];
        if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) $inside = !$inside;
    }
    return $inside;
}

/** The zone's fee for this subtotal (free above its free-delivery amount). */
function delivery_zone_fee(array $z, int $subtotal): int
{
    return ((int) $z['freeOver'] > 0 && $subtotal >= (int) $z['freeOver']) ? 0 : (int) $z['fee'];
}

// ---------- address lookups (for distance zones) ----------

/**
 * Latitude/longitude of an address: ['lat' => .., 'lng' => ..] or null.
 * OpenStreetMap (free; at most one lookup a second, as its usage policy asks) or
 * Google (with the key from the Delivery page). Answers are cached for 90 days.
 */
function delivery_geocode(string $query, array $s, bool $useLimit = true): ?array
{
    $query = trim($query);
    if ($query === '') return null;
    $provider = ($s['geocoder'] ?? 'osm') === 'google' && trim((string) ($s['googleKey'] ?? '')) !== '' ? 'google' : 'osm';
    $key = sha1($provider . '|' . mb_strtolower($query));
    $file = CACHE_DIR . '/geocode.json';
    $cache = json_decode((string) @file_get_contents($file), true) ?: [];
    if (isset($cache[$key]) && time() - (int) $cache[$key]['t'] < ($cache[$key]['lat'] === null ? 3600 : 90 * 86400)) {
        return $cache[$key]['lat'] === null ? null : ['lat' => (float) $cache[$key]['lat'], 'lng' => (float) $cache[$key]['lng']];
    }
    if ($useLimit && rate_limited('geocode', 40, 900)) return null;

    $cc = strtolower(delivery_country($s['country'])['code']);
    $point = null;
    if ($provider === 'google') {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(['address' => $query, 'region' => $cc, 'key' => trim((string) $s['googleKey'])]);
        $res = delivery_http_json($url);
        $loc = $res['results'][0]['geometry']['location'] ?? null;
        if ($loc) $point = ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
    } else {
        $stamp = CACHE_DIR . '/geocode-last';
        $wait = 1.1 - (microtime(true) - (float) @file_get_contents($stamp));
        if ($wait > 0) usleep((int) ($wait * 1e6));
        @file_put_contents($stamp, (string) microtime(true));
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query(['format' => 'jsonv2', 'limit' => 1, 'countrycodes' => $cc, 'q' => $query]);
        $res = delivery_http_json($url, ['Accept-Language: en']);
        if (isset($res[0]['lat'], $res[0]['lon'])) $point = ['lat' => (float) $res[0]['lat'], 'lng' => (float) $res[0]['lon']];
    }

    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $cache[$key] = ['t' => time(), 'lat' => $point['lat'] ?? null, 'lng' => $point['lng'] ?? null];
    if (count($cache) > 2000) $cache = array_slice($cache, -1500, null, true);
    @file_put_contents($file, json_encode($cache), LOCK_EX);
    return $point;
}

function delivery_http_json(string $url, array $headers = []): ?array
{
    $ch = curl_handle($url, [
        CURLOPT_TIMEOUT    => 8,
        CURLOPT_USERAGENT  => 'RMSWebsite/1.0 (restaurant delivery zones)',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}

// ---------- describing zones ----------

/** A label for use mid-sentence: "Postcode" → "postcode", but "ZIP code", "PIN code" and "CEP" stay as they are. */
function delivery_label_lc(string $label): string
{
    return preg_match('/^[A-Z][a-z]/', $label) ? lcfirst($label) : $label;
}

function delivery_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/** Where a zone delivers, in words: "ZIP code 33305, 33306", "Within 3 mi", "Everywhere else". */
function delivery_zone_area(array $z, array $s): string
{
    if ($z['type'] === 'radius') return 'Within ' . rtrim(rtrim(number_format((float) $z['radius'], 1), '0'), '.') . ' ' . $s['unit'];
    if ($z['type'] === 'any') return 'Everywhere else';
    if ($z['type'] === 'polygon') return 'The ' . $z['name'] . ' area on our map';
    $label = delivery_label_lc(delivery_country($s['country'])['postalLabel'] ?? 'Postal code');
    $codes = array_map(fn($c) => preg_replace('/\*$/', '…', (string) $c), (array) $z['postcodes']); // "SW1*" → "SW1…"
    return $label . ' ' . (count($codes) > 6 ? implode(', ', array_slice($codes, 0, 6)) . ' +' . (count($codes) - 6) . ' more' : implode(', ', $codes));
}

/** "$3.00 fee · $15.00 minimum · free over $40.00". */
function delivery_zone_terms(array $z): string
{
    $parts = [(int) $z['fee'] ? delivery_money((int) $z['fee']) . ' fee' : 'free delivery'];
    if ((int) $z['minimum']) $parts[] = delivery_money((int) $z['minimum']) . ' minimum';
    if ((int) $z['freeOver'] && (int) $z['fee']) $parts[] = 'free over ' . delivery_money((int) $z['freeOver']);
    return implode(' · ', $parts);
}

/** What the website and chat need to know (no keys or locations). */
function delivery_public(array $s): array
{
    $zones = delivery_active_zones($s);
    $country = delivery_country($s['country']);
    $fees = array_map(fn($z) => (int) $z['fee'], $zones);
    $mins = array_map(fn($z) => (int) $z['minimum'], $zones);
    $areas = array_map(fn($z) => ['name' => (string) $z['name'], 'area' => delivery_zone_area($z, $s), 'terms' => delivery_zone_terms($z)], $zones);
    $feeText = !$fees ? '' : (min($fees) === max($fees) ? (max($fees) ? delivery_money(max($fees)) . ' fee' : 'free delivery') : 'fees ' . delivery_money(min($fees)) . '–' . delivery_money(max($fees)));
    if (count($zones) === 1) {
        $z = $zones[0];
        $summary = $z['type'] === 'any' ? 'Delivery to your door'
            : ($z['type'] === 'radius' ? 'Delivery ' . lcfirst(delivery_zone_area($z, $s)) : 'Delivery to ' . delivery_zone_area($z, $s));
        $note = $summary . ' · ' . delivery_zone_terms($z);
    } else {
        $summary = 'Delivery in ' . count($zones) . ' areas';
        $note = $summary . ($feeText ? ' · ' . $feeText : '') . ($mins && max($mins) ? ' · minimum from ' . delivery_money(min($mins)) : '') . ' · exact fee at checkout';
    }
    return [
        'country'        => $country['code'],
        'countryName'    => $country['name'],
        'postalLabel'    => $country['postalLabel'] ?? 'Postal code',
        'postalUsed'     => $country['postalLabel'] !== null,
        'postalRequired' => $country['postalLabel'] !== null && (bool) array_filter($zones, fn($z) => $z['type'] === 'postal'),
        'postalNumeric'  => in_array($country['code'], DELIVERY_NUMERIC_POSTCODES, true),
        'unit'           => $s['unit'],
        'areas'          => $areas,
        'summary'        => $zones ? $summary : 'Delivery is not set up',
        'note'           => $zones ? $note : '',
        'feeText'        => $feeText,
        'minimumFrom'    => $mins ? min($mins) : 0,
    ];
}

/**
 * Adds the delivery zones to the store block the website reads (api/menu.php), and keeps the
 * older single-value fields (deliveryFee = lowest fee, deliveryMinimum = lowest minimum,
 * deliveryZips = every postal code listed) for code that still reads them.
 */
function delivery_apply_store(array $store): array
{
    $s = delivery_settings();
    $zones = delivery_active_zones($s);
    $store['delivery'] = delivery_public($s);
    $store['deliveryEnabled'] = !empty($store['deliveryEnabled']) && (bool) $zones;
    $store['deliveryFee'] = $zones ? min(array_map(fn($z) => (int) $z['fee'], $zones)) : 0;
    $store['deliveryMinimum'] = $store['delivery']['minimumFrom'];
    $zips = [];
    foreach ($zones as $z) if ($z['type'] === 'postal') $zips = array_merge($zips, (array) $z['postcodes']);
    $store['deliveryZips'] = array_values(array_unique($zips));
    return $store;
}
