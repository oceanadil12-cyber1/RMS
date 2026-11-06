<?php
/**
 * GET api/happy-hour.php  →  the Happy Hour settings as JSON.
 *
 * Edited from the admin (admin/?page=happy-hour) and stored in
 * api/data/happy-hour.json. The menu page + sitewide ticker read this (js/main.js)
 * and the chat assistant reads it too (api/chat.php), so Happy Hour lives in one
 * place. Falls back to the seeded defaults below until it's first edited.
 */
require_once __DIR__ . '/lib.php';

/** The current Happy Hour content, as shown on the site before any edits. */
const HAPPY_HOUR_DEFAULTS = [
    'enabled'       => true,
    'tickerEnabled' => true,
    'start'         => '16:00', // 4:00 PM
    'end'           => '18:45', // 6:45 PM
    'days'          => [],      // e.g. ['MON', 'TUE', 'WED', 'THU', 'FRI']; empty = every day
    'note'          => 'Some of our most popular items, made and specially priced.',
    'items'         => [
        ['name' => 'Mini Nachos',            'desc' => 'Add chicken +$4 · add beef +$6', 'price' => '6.99'],
        ['name' => 'Mexican Street Tacos',   'desc' => 'Chicken or pork',                'price' => '6'],
        ['name' => 'Chicken Quesadilla',     'desc' => '',                               'price' => '11.99'],
        ['name' => 'BBQ Pork Slider & Fries','desc' => 'One slider with fries',          'price' => '6.99'],
        ['name' => 'Champ Slider & Fries',   'desc' => 'One slider with fries',          'price' => '6.99'],
        ['name' => '4 oz. Burger & Fries',   'desc' => '',                               'price' => '6.99'],
        ['name' => 'Bacon Cheese Dog & Fries','desc' => '',                              'price' => '6.99'],
        ['name' => 'Wings (5)',              'desc' => 'Mild, hot or BBQ, with bleu cheese or ranch', 'price' => '6.99'],
    ],
    'sides'         => [
        ['name' => 'Side Fries',       'price' => '3'],
        ['name' => 'Side Onion Rings', 'price' => '4'],
        ['name' => 'Side Salad',       'price' => '3'],
    ],
];

function write_happy_hour(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('happy_hour', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_happy_hour: ' . $e->getMessage());
        return false;
    }
}

/** Stored Happy Hour settings merged over the defaults. */
function happy_hour_data(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $saved = db_setting('happy_hour');
    } catch (Throwable $e) {
        $saved = null;
    }
    if (!is_array($saved)) return HAPPY_HOUR_DEFAULTS;
    $d = array_merge(HAPPY_HOUR_DEFAULTS, $saved);
    if (!isset($saved['items']) || !is_array($saved['items'])) $d['items'] = HAPPY_HOUR_DEFAULTS['items'];
    if (!isset($saved['sides']) || !is_array($saved['sides'])) $d['sides'] = HAPPY_HOUR_DEFAULTS['sides'];
    return $d;
}

function hh_min(string $hhmm): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) return 0;
    return min(1439, max(0, (int) $m[1] * 60 + (int) $m[2]));
}

/** "16:00" → "4 PM"; "18:45" → "6:45 PM". */
function hh_fmt(string $hhmm): string
{
    $mins = hh_min($hhmm);
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    $ap = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12 ?: 12;
    return $m ? sprintf('%d:%02d %s', $h12, $m, $ap) : "$h12 $ap";
}

/** Public shape for the endpoint and the front-end: adds derived labels + minutes. */
function happy_hour_public(): array
{
    $d = happy_hour_data();
    $start = hh_fmt($d['start']);
    $end = hh_fmt($d['end']);
    $days = array_values(array_intersect(array_keys(WEEK_DAYS), (array) ($d['days'] ?? [])));
    $every = !$days || count($days) === 7;
    return [
        'enabled'       => (bool) $d['enabled'],
        'tickerEnabled' => (bool) $d['tickerEnabled'],
        'startMin'      => hh_min($d['start']),
        'endMin'        => hh_min($d['end']),
        'days'          => $every ? [] : $days, // empty = every day
        'timeLabel'     => ($every ? 'Every Day' : days_label($days)) . " · $start – $end",
        'tickerLabel'   => 'Happy Hour · ' . ($every ? 'Daily' : days_label($days)) . " $start – $end",
        'untilLabel'    => "until $end",
        'note'          => (string) $d['note'],
        'items'         => array_values(array_map(fn($i) => [
            'name'  => (string) ($i['name'] ?? ''),
            'desc'  => (string) ($i['desc'] ?? ''),
            'price' => (string) ($i['price'] ?? ''),
        ], $d['items'])),
        'sides'         => array_values(array_map(fn($s) => [
            'name'  => (string) ($s['name'] ?? ''),
            'price' => (string) ($s['price'] ?? ''),
        ], $d['sides'])),
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    send_json(happy_hour_public());
}
