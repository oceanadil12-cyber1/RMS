<?php
/**
 * Staff order creation: dine-in, takeaway or delivery (included from
 * admin/index.php when api=dine-in).
 *
 * Expects POST with JSON body:
 *   { "type": "dine-in" | "pickup" | "delivery",   (takeaway is stored as "pickup")
 *     "table": 12,                                  (dine-in only)
 *     "phone": "...", "address": "...",             (delivery only)
 *     "items": [ { "name":"...", "qty":2, "price":899,
 *     "variation":"...", "mods":"...", "note":"..." }, ... ], "notes":"..." }
 */

$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

$type = in_array($req['type'] ?? 'dine-in', ['dine-in', 'pickup', 'delivery'], true) ? (string) ($req['type'] ?? 'dine-in') : 'dine-in';
$typeLabel = ['dine-in' => 'Dine-in', 'pickup' => 'Takeaway', 'delivery' => 'Delivery'][$type];

$table = null;
if ($type === 'dine-in') {
    $table = (int) ($req['table'] ?? 0);
    if ($table < 1 || $table > 999) fail('Please select a table number.');
}

$phone = $address = '';
if ($type === 'delivery') {
    $phone = trim(mb_substr((string) ($req['phone'] ?? ''), 0, 30));
    $address = trim(mb_substr((string) ($req['address'] ?? ''), 0, 300));
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) fail('Please enter a phone number for the driver.');
    if ($address === '') fail('Please enter the delivery address.');
}

$items = $req['items'] ?? [];
if (!is_array($items) || count($items) === 0) fail('Please add at least one item.');
if (count($items) > 50) fail('Too many items.');

$guest = trim(mb_substr((string) ($req['guest'] ?? ''), 0, 80));
$orderNotes = trim(mb_substr((string) ($req['notes'] ?? ''), 0, 500));

$itemLines = [];
$subtotal = 0;
foreach ($items as $item) {
    $name = trim(mb_substr((string) ($item['name'] ?? ''), 0, 200));
    $qty = max(1, min(99, (int) ($item['qty'] ?? 1)));
    $price = (int) ($item['price'] ?? 0);
    $variation = trim(mb_substr((string) ($item['variation'] ?? ''), 0, 100));
    $mods = trim(mb_substr((string) ($item['mods'] ?? ''), 0, 300));
    $note = trim(mb_substr((string) ($item['note'] ?? ''), 0, 200));

    if ($name === '') continue;

    $label = $qty . ' x ' . $name
        . ($variation !== '' && $variation !== 'Regular' ? ' (' . $variation . ')' : '')
        . ($mods !== '' ? ' [' . $mods . ']' : '')
        . ($note !== '' ? ' - note: ' . $note : '');
    if ($price > 0) {
        $label .= ' = $' . number_format($price * $qty / 100, 2);
    }
    $itemLines[] = $label;
    $subtotal += $price * $qty;
}

if (count($itemLines) === 0) fail('No valid items in the order.');

$number = strtoupper(bin2hex(random_bytes(3)));

require_once __DIR__ . '/../../api/db.php';
$saved = save_row('orders', [
    'number'       => $number,
    // Takeaway and delivery are paid when the guest gets the food, like the website's cash orders.
    'payment'      => $type === 'dine-in' ? 'dine-in' : 'cash on delivery',
    'order_type'   => $type,
    'table_number' => $table,
    'name'         => $guest,
    'phone'        => $phone,
    'address'      => $address,
    'items'        => json_encode($itemLines, JSON_UNESCAPED_UNICODE),
    'order_notes'  => $orderNotes,
    'subtotal'     => $subtotal,
    'delivery_fee' => 0,
    'total'        => $subtotal,
    'ip'           => client_ip(),
]);
if (!$saved) fail('The order could not be saved. Please try again, or write it down for the kitchen.', 500);

$where = $type === 'dine-in' ? "table $table" : strtolower($typeLabel);
admin_log('order.' . ($type === 'pickup' ? 'takeaway' : $type), '#' . $number . ' ' . $where . ' (' . count($itemLines) . ' items)');

notify("$typeLabel order #$number" . ($type === 'dine-in' ? " (Table $table)" : ''), array_filter([
    'Order'    => "#$number",
    'Placed'   => date('l, M j, Y g:i A'),
    'Type'     => strtoupper($typeLabel),
    'Table'    => $table,
    'Guest'    => $guest ?: '(not provided)',
    'Phone'    => $phone,
    'Address'  => $address,
    'Items'    => implode("\n", $itemLines),
    'Notes'    => $orderNotes ?: '(none)',
    'Subtotal' => '$' . number_format($subtotal / 100, 2),
], fn($v) => $v !== null && $v !== ''));

send_json([
    'ok'          => true,
    'orderNumber' => $number,
    'type'        => $type,
    'table'       => $table,
    'items'       => count($itemLines),
]);
