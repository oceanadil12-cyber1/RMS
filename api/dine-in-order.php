<?php
/**
 * POST api/dine-in-order.php   (JSON body, public — no auth)
 *
 * Customer-facing dine-in order placement.
 *
 *   { "table": 14, "name": "Alex",
 *     "cart": [ { "itemId":"...", "variationId":"...", "qty":2,
 *                 "modifiers":["choiceId",...], "note":"..." }, ... ],
 *     "notes": "..." }
 *
 * Prices are always computed server-side from the menu data.
 * The order goes into the same `orders` table the KDS polls, so it
 * appears on the kitchen display automatically.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/menu.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
require_same_site();
// Guests on the venue Wi-Fi all share one address, so the tight limit is per table.
rate_limit('dinein', 80, 600);

$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

// --- validate table ---
$table = (int) ($req['table'] ?? 0);
if ($table < 1 || $table > 999) fail('Invalid table number.');
rate_limit('dinein-table-' . $table, 8, 600);

// --- validate customer name ---
$name = trim(mb_substr((string) ($req['name'] ?? ''), 0, 30));
if ($name === '' || !preg_match('/^[A-Za-z][A-Za-z ]*$/', $name) || mb_strlen($name) < 2) {
    fail('Please enter a valid name (letters only, at least 2 characters).');
}

// --- load and validate menu ---
$menu = load_menu();
if (!$menu) fail('The menu is temporarily unavailable. Please try again shortly.', 503);

// --- validate cart ---
$cart = $req['cart'] ?? [];
if (!is_array($cart) || count($cart) === 0) fail('Your order is empty.');
if (count($cart) > 50) fail('Too many items in the order.');

$orderNotes = trim(mb_substr((string) ($req['notes'] ?? ''), 0, 500));

$itemLines = [];
$subtotal = 0;

foreach ($cart as $line) {
    $itemId = (string) ($line['itemId'] ?? '');
    $item = $menu['items'][$itemId] ?? null;
    if (!$item) fail('An item in your order is no longer on the menu. Please remove it and try again.');
    if (!empty($item['soldOut'])) fail("{$item['name']} is sold out.");

    $variationId = (string) ($line['variationId'] ?? '');
    $variation = null;
    foreach ($item['variations'] as $v) {
        if ($v['id'] === $variationId) { $variation = $v; break; }
    }
    if (!$variation) fail("Please choose an option for {$item['name']}.");
    if (!empty($variation['soldOut'])) fail("{$item['name']} ({$variation['name']}) is sold out.");

    $qty = (int) ($line['qty'] ?? 0);
    if ($qty < 1 || $qty > 50) fail("Please choose a quantity between 1 and 50 for {$item['name']}.");

    $chosen = array_values(array_unique(array_map('strval', $line['modifiers'] ?? [])));
    $modNames = [];
    $unit = $variation['price'];

    foreach ($item['modifiers'] as $group) {
        $picked = [];
        foreach ($group['choices'] as $c) {
            if (in_array($c['id'], $chosen, true)) {
                if (!empty($c['soldOut'])) fail("{$c['name']} is not available right now.");
                $picked[] = $c;
            }
        }
        $count = count($picked);
        if ($count < $group['min']) fail("Please choose {$group['name']} for {$item['name']}.");
        if ($group['max'] > 0 && $count > $group['max']) fail("Too many choices for {$group['name']} on {$item['name']}.");
        foreach ($picked as $c) {
            $modNames[] = $c['name'];
            $unit += $c['price'];
        }
    }

    if (count($modNames) !== count($chosen)) {
        fail("Some options for {$item['name']} are no longer available. Please re-add it.");
    }

    $note = trim(mb_substr((string) ($line['note'] ?? ''), 0, 200));
    $varLabel = ($variation['name'] !== '' && $variation['name'] !== 'Regular') ? $variation['name'] : '';

    $label = $qty . ' x ' . $item['name']
        . ($varLabel !== '' ? ' (' . $varLabel . ')' : '')
        . (count($modNames) ? ' [' . implode(', ', $modNames) . ']' : '')
        . ($note !== '' ? ' - note: ' . $note : '');
    if ($unit > 0) {
        $label .= ' = $' . number_format($unit * $qty / 100, 2);
    }

    $itemLines[] = $label;
    $subtotal += $unit * $qty;
}

if (count($itemLines) === 0) fail('No valid items in the order.');

// --- save order ---
$number = strtoupper(bin2hex(random_bytes(3)));

require_once __DIR__ . '/db.php';
$saved = save_row('orders', [
    'number'       => $number,
    'payment'      => 'dine-in',
    'order_type'   => 'dine-in',
    'table_number' => $table,
    'name'         => $name,
    'items'        => json_encode($itemLines, JSON_UNESCAPED_UNICODE),
    'order_notes'  => $orderNotes,
    'subtotal'     => $subtotal,
    'delivery_fee' => 0,
    'total'        => $subtotal,
    'ip'           => client_ip(),
]);
if (!$saved) fail('Unable to save your order. Please try again.', 500);

// --- log and notify ---
define('KDS', true);
require_once __DIR__ . '/../admin/inc/store.php';

admin_log('order.dine-in', '#' . $number . ' table ' . $table . ' ' . $name . ' (' . count($itemLines) . ' items)');

notify("Dine-in order #$number (Table $table)", [
    'Order'    => "#$number",
    'Placed'   => date('l, M j, Y g:i A'),
    'Type'     => 'DINE-IN',
    'Table'    => $table,
    'Guest'    => $name,
    'Items'    => implode("\n", $itemLines),
    'Notes'    => $orderNotes ?: '(none)',
    'Subtotal' => '$' . number_format($subtotal / 100, 2),
]);

send_json([
    'ok'          => true,
    'orderNumber' => $number,
    'table'       => $table,
    'items'       => count($itemLines),
    'total'       => $subtotal,
]);
