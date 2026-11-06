<?php
/**
 * GET api/menu.php  →  the online-ordering menu as JSON.
 *
 * Reads the restaurant's live Square Online store (same items, sizes, options
 * and prices as your-store.square.site), caches it for a few
 * minutes, and falls back to data/menu.json if Square can't be reached.
 *
 * CLI:  php api/menu.php --snapshot   rebuilds data/menu.json
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/menu-overrides.php';

const SNAPSHOT = __DIR__ . '/../data/menu.json';

// Website categories, in display order, merged from Square's overlapping
// category trees (the "FOOD" group and the upper-case categories).
const MENU_CATEGORIES = [
    ['specials',   'Specials',         ['JY75BR3XZHMN4I3OBHRVMIB6', 'NEVD6NNKJOSGUHJTHL5DSCMD']],
    ['appetizers', 'Appetizers',       ['3FVBPKXVI27FRUOCAPCE75SJ', 'MBWO5HFDNYBDHYD3ZR4UKINV']],
    ['wings',      'Wings',            ['V6R4VSZ6HSX27VMTTZPDJM3H', 'UQWCCJOHBRV3AZDNKAZBOJ33']],
    ['salads',     'Salads',           ['IUEEXXKQOSFP3WCXW3LAMK5D']],
    ['burgers',    'Burgers',          ['VIOY473AYVTW27J67DWEPXWI', '7DKCZNPSKDZCKF3NA7ETYM7V']],
    ['flatbreads', 'Flatbread Pizza',  ['544IX2ZM5YLWZ4ENFAHLAXIO', 'JAIIIPSUSAC5ADMGGYVB34XI']],
    ['hot-sandwiches',  'Hot Sandwiches',  ['P7HOKQNHSGNDDCGMABNQYUDN']],
    ['cold-sandwiches', 'Cold Sandwiches & Wraps', ['HLDNWC44532V7QDBGYIMDZ3P', 'BEF2RZAXK5GFT5WSZ77YMKG7']],
    ['asian',      'Asian',            ['OLWKQNZPQJEG36X3S6FYE3Y4', 'YBBPGPBKMT327ZZQ45ZICUTR']],
    ['mexican',    'Mexican',          ['BLYNBEC4KLSQZPVG57V63ZFL', '6Z7CARCTIJ2TQF6VFFTF5RKD']],
    ['sides',      'Sides',            ['G3RS4DP2F4A4OUJRHFTC2IJZ', 'FFUX3PZZOY4YGY6B4Y7VL5FW']],
    ['desserts',   'Desserts',         ['EHBEN3J4ALEJWKID6BQC66TN']],
    ['happy-hour', 'Happy Hour Daily', ['DE4PABSJPQRNHJIIIHBIL4K3']],
    ['sauces',     'Dressings & Sauces', ['I5CCM4GARRCJB3PIJ6F6POIP']],
    ['soda',       'Soda',             ['EW4U7USK2NXON5CIO7O5LN5F', 'P26VFH5AMOMOL5BLUGTE5VSH']],
];

// Event-only items that live in the same categories on Square.
const EXCLUDE_NAME_PATTERN = '/\bFOR TAP\b/i';

// Tidy the POS-style names Square uses ("SPC 3 …", "SEM …", "Cole Slaw $").
function clean_name(string $name): string
{
    $name = preg_replace('/^(SPC\s*\d+[A-Z]?|SEM)\s+/i', '', trim($name));
    $name = preg_replace('/\s*\$+\s*(\(\d+\))?$/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $fixes = [
        'Mozzeralla' => 'Mozzarella', 'Casear' => 'Caesar', 'Ceasar' => 'Caesar', 'Vegatables' => 'Vegetables',
        'Grill Cheese' => 'Grilled Cheese', 'Pull Pork' => 'Pulled Pork', 'Cole Slaw' => 'Coleslaw',
        'Taco - Taco' => 'Tacos', 'Tso Chicken' => 'Tso\'s Chicken', 'GYRO CHICKEN' => 'Chicken Gyro',
        'Sriacha' => 'Sriracha', 'Guacomole' => 'Guacamole', 'Totts' => 'Tots',
        'WINGS ( 4 ) BBQ' => 'Happy Hour Wings (4) BBQ', 'Or Chicken' => 'or Chicken',
    ];
    $name = strtr($name, $fixes);
    // Title-case names typed in all caps.
    if ($name === strtoupper($name)) $name = ucwords(strtolower($name));
    return $name;
}

function clean_choice(string $name): string
{
    $name = preg_replace('/\s*\$+\s*$/', '', trim($name));
    $name = preg_replace('/^\$\s*/', '', $name);
    return strtr(preg_replace('/\s+/', ' ', $name), ['Sriacha' => 'Sriracha', 'Ceasar' => 'Caesar', 'Totts' => 'Tots', 'Vegatables' => 'Vegetables']);
}

// Square option-group names are written for staff; show customers plain labels.
function group_label(string $group, string $itemName): string
{
    $known = [
        '/temps$/i'                 => 'Temperature',
        '/^choice of sides?$/i'     => 'Choice of Side',
        '/^burgers n sliders \d*$/i' => 'Customize',
        '/^covid hot sandwiches$/i' => 'Extras',
        '/^add protein salads$/i'   => 'Add Protein',
        '/^subs for chicken$/i'     => 'Swap the Chicken',
        '/^flatbread pizza$/i'      => 'Customize',
        '/^happy hour wings$/i'     => 'Customize',
        '/^wings$/i'                => 'Wing Extras',
    ];
    foreach ($known as $pattern => $label) {
        if (preg_match($pattern, trim($group))) return $label;
    }
    // Groups named after the dish itself ("Mucho Nachos", "Classic Rueben") hold its customizations.
    $g = strtolower(trim($group));
    $i = strtolower($itemName);
    if (strpos($i, $g) !== false || strtok($g, ' ') === strtok($i, ' ')) return 'Customize';
    return $group === strtoupper($group) ? ucwords(strtolower($group)) : $group;
}

function clean_text(?string $html): string
{
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $text));
}

function cents($dollars): int
{
    return (int) round(((float) $dollars) * 100);
}

function build_menu(): ?array
{
    $L = '/store-locations/' . STORE_LOCATION;
    $v = 'cache-version=' . date('Y-m-d');

    // 1. Products in each Square category (plus store settings).
    $urls = ['store' => STORE_FEED . "/store-locations?page=1&per_page=10&include=address&lang=en&valid=1&$v"];
    foreach (MENU_CATEGORIES as [$slug, , $ids]) {
        foreach ($ids as $cid) {
            $urls["cat:$cid"] = STORE_FEED . "$L/products?page=1&per_page=200&categories[]=$cid&include=images&$v";
        }
    }
    $lists = feed_get_many($urls);
    $store = $lists['store']['data'][0] ?? null;
    if (!$store) return null;

    $categories = [];
    $seen = [];
    foreach (MENU_CATEGORIES as [$slug, $label, $ids]) {
        $itemIds = [];
        foreach ($ids as $cid) {
            foreach ($lists["cat:$cid"]['data'] ?? [] as $p) {
                $pid = (string) $p['site_product_id'];
                if (isset($seen[$pid]) || preg_match(EXCLUDE_NAME_PATTERN, $p['name'])) continue;
                if (($p['visibility'] ?? 'visible') !== 'visible' || empty($p['fulfillable'])) continue;
                $seen[$pid] = $p;
                $itemIds[] = $pid;
            }
        }
        if ($itemIds) $categories[] = ['id' => $slug, 'name' => $label, 'itemIds' => $itemIds];
    }
    if (!$categories) return null;

    // 2. Full details (options + sizes) for every item, fetched in parallel.
    $urls = [];
    foreach ($seen as $pid => $p) {
        $urls["p:$pid"] = STORE_FEED . "$L/products/$pid?include=images,modifiers&$v";
        $urls["s:$pid"] = STORE_FEED . "$L/products/$pid/skus?page=1&per_page=100&$v";
    }
    $details = feed_get_many($urls);

    $items = [];
    foreach ($seen as $pid => $p) {
        $d = $details["p:$pid"]['data'] ?? null;
        $skus = $details["s:$pid"]['data'] ?? null;
        if (!$d || !$skus) continue;

        $variations = [];
        foreach ($skus as $s) {
            if (empty($s['sellable']) && isset($s['sellable'])) continue;
            $variations[] = [
                'id'      => $s['square_id'],
                'name'    => clean_name((string) ($s['name'] ?: 'Regular')),
                'price'   => (int) ($s['price']['current_subunits'] ?? cents($s['price']['current'] ?? 0)),
                'soldOut' => !empty($s['sold_out']),
            ];
        }
        if (!$variations) continue;

        $groups = [];
        foreach ($d['modifiers']['data'] ?? [] as $g) {
            if (!empty($g['hidden_from_customer'])) continue;
            $choices = [];
            foreach ($g['choices'] ?? [] as $c) {
                if (!empty($c['hidden'])) continue;
                $choices[] = [
                    'id'      => $c['square_id'],
                    'name'    => clean_choice((string) $c['name']),
                    'price'   => cents($c['price'] ?? 0),
                    'default' => !empty($c['selected_by_default']),
                    'soldOut' => !empty($c['sold_out']),
                ];
            }
            if (!$choices) continue;
            $groups[] = [
                'id'      => $g['square_id'],
                'name'    => group_label(clean_choice((string) $g['name']), clean_name($d['name'])),
                'min'     => max(0, (int) $g['min_selected']),
                'max'     => max(0, (int) $g['max_selected']),   // 0 = no limit
                'choices' => $choices,
            ];
        }

        $image = $d['images']['data'][0]['absolute_url'] ?? ($d['images']['data'][0]['url'] ?? null);
        $items[$pid] = [
            'id'          => $pid,
            'squareId'    => $d['square_id'],
            'name'        => clean_name($d['name']),
            'description' => clean_text($d['short_description'] ?? ''),
            'image'       => $image,
            'soldOut'     => !empty($d['badges']['out_of_stock']),
            'maxQty'      => (int) ($d['per_order_max'] ?? 0),
            'variations'  => $variations,
            'modifiers'   => $groups,
        ];
    }

    // A partial read (e.g. rate-limited) must not replace a good menu.
    if (count($items) < 0.9 * count($seen)) return null;

    foreach ($categories as &$c) {
        $c['itemIds'] = array_values(array_filter($c['itemIds'], fn($id) => isset($items[$id])));
    }
    unset($c);

    $zips = [];
    foreach ($store['delivery_areas'] ?? [] as $a) {
        if (($a['type'] ?? '') === 'zipcode' && !empty($a['zipcode'])) $zips[] = (string) $a['zipcode'];
    }

    return [
        'updated'    => gmdate('c'),
        'store' => [
            'name'               => "RMS",
            'address'            => '2266 Wilton Drive, Wilton Manors, FL 33305',
            'phone'              => '(954) 565-2526',
            'timezone'           => $store['pickup_timezone'] ?? 'America/New_York',
            'prepMinutes'        => (int) ($store['order_prep_time'] ?? 25),
            'pickupHours'        => $store['pickup_hours'] ?? [],
            'pickupInstructions' => trim((string) ($store['pickup_instructions'] ?? '')),
            'pickupMinimum'      => cents($store['pickup_order_subtotal_minimum'] ?? 0),
            'deliveryEnabled'    => ($store['delivery_settings']['enablement_status'] ?? '') === 'ENABLED',
            'deliveryHours'      => $store['delivery_hours'] ?? [],
            'deliveryZips'       => $zips,
            'deliveryMinimum'    => cents($store['delivery_order_subtotal_minimum'] ?? 0),
            'deliveryFee'        => cents($store['delivery_fee'] ?? 0),
        ],
        'categories' => array_values(array_filter($categories, fn($c) => $c['itemIds'])),
        'items'      => $items,
    ];
}

/** Read the frozen local menu (data/menu.json). */
function load_menu_snapshot(): ?array
{
    return is_file(SNAPSHOT) ? json_decode((string) file_get_contents(SNAPSHOT), true) : null;
}

/**
 * The menu with no local overrides. In 'local' mode (the default) this is the
 * frozen snapshot and Square is never contacted. In 'square' mode it pulls live
 * (cache → live → snapshot fallback).
 */
function load_menu_base(bool $forceRefresh = false): ?array
{
    if ((config()['menu_source'] ?? 'local') === 'local') return load_menu_snapshot();

    $ttl = max(1, (int) config()['menu_cache_mins']) * 60;
    if (!$forceRefresh && ($cached = cache_read('menu', $ttl))) return $cached;
    $menu = build_menu();
    if ($menu) {
        cache_write('menu', $menu);
        return $menu;
    }
    if ($cached = cache_read('menu', PHP_INT_MAX)) return $cached;
    return load_menu_snapshot();
}

/** Menu for the rest of the API, with the restaurant's local overrides applied. */
function load_menu(bool $forceRefresh = false): ?array
{
    $menu = load_menu_base($forceRefresh);
    return $menu ? apply_menu_overrides($menu) : null;
}

// Only act as an endpoint when requested directly (checkout.php includes this file).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI === 'cli') {
        $menu = build_menu();
        if (!$menu) { fwrite(STDERR, "Could not load the Square store.\n"); exit(1); }
        if (in_array('--snapshot', $argv, true)) {
            if (!is_dir(dirname(SNAPSHOT))) mkdir(dirname(SNAPSHOT), 0755, true);
            file_put_contents(SNAPSHOT, json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            echo 'Wrote ' . SNAPSHOT . "\n";
        }
        $n = 0; foreach ($menu['categories'] as $c) { echo str_pad($c['name'], 26) . count($c['itemIds']) . " items\n"; $n += count($c['itemIds']); }
        echo "Total $n items\n";
        exit;
    }
    $menu = load_menu();
    if (!$menu) fail('The menu is temporarily unavailable.', 503);
    $menu['codMode'] = cod_mode();
    $menu['paymentsEnabled'] = !cod_mode() && payments_enabled();
    $menu['applicationId'] = $menu['paymentsEnabled'] ? config()['application_id'] : null;
    $menu['locationId'] = config()['location_id'];
    $menu['environment'] = config()['environment'];
    send_json($menu);
}
