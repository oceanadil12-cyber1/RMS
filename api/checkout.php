<?php
/**
 * POST api/checkout.php   (JSON body)
 *
 *   { "action": "quote", "cart": [...], "fulfillment": {...} }
 *       → validates the cart and returns subtotal, tax, delivery fee and total.
 *
 *   { "action": "pay", "cart": [...], "fulfillment": {...}, "customer": {...},
 *     "tip": 350, "sourceId": "<card token from Square Web Payments SDK>",
 *     "idempotencyKey": "<uuid>" }
 *       → creates the order in Square (it appears in the POS like any online
 *         order) and charges the card.
 *
 * Cart line: { "itemId": "1513", "variationId": "<square id>", "qty": 2,
 *              "modifiers": ["<choice square id>", ...], "note": "..." }
 * Prices always come from the menu data on the server, never from the browser.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/delivery.php'; // delivery zones (admin → Delivery)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
require_same_site();
$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

$menu = load_menu();
if (!$menu) fail('The menu is temporarily unavailable. Please try again shortly.', 503);
$store = $menu['store'];

/** Check the cart against the menu; returns [lines, subtotalCents]. */
function validate_cart(array $cart, array $menu): array
{
    if (!$cart) fail('Your order is empty.');
    if (count($cart) > 50) fail('That order is too large to place online. Please call us.');
    $lines = [];
    $subtotal = 0;
    foreach ($cart as $n => $line) {
        $item = $menu['items'][(string) ($line['itemId'] ?? '')] ?? null;
        if (!$item) fail('An item in your order is no longer on the menu. Please remove it and try again.');
        if (!empty($item['soldOut'])) fail("{$item['name']} is sold out.");

        $variation = null;
        foreach ($item['variations'] as $v) if ($v['id'] === ($line['variationId'] ?? '')) $variation = $v;
        if (!$variation) fail("Please choose an option for {$item['name']}.");
        if (!empty($variation['soldOut'])) fail("{$item['name']} ({$variation['name']}) is sold out.");

        $qty = (int) ($line['qty'] ?? 0);
        $maxQty = $item['maxQty'] ?: 50;
        if ($qty < 1 || $qty > $maxQty) fail("Please choose a quantity between 1 and $maxQty for {$item['name']}.");

        $chosen = array_values(array_unique(array_map('strval', $line['modifiers'] ?? [])));
        $mods = [];
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
                $mods[] = $c;
                $unit += $c['price'];
            }
        }
        if (count($mods) !== count($chosen)) fail("Some options for {$item['name']} are no longer available. Please re-add it.");

        $note = trim(mb_substr((string) ($line['note'] ?? ''), 0, 200));
        $lines[] = ['item' => $item, 'variation' => $variation, 'qty' => $qty, 'mods' => $mods, 'note' => $note, 'unit' => $unit];
        $subtotal += $unit * $qty;
    }
    return [$lines, $subtotal];
}

/** Is the kitchen taking pickup/delivery orders right now (store time zone)? */
function is_open(array $hours, string $tz): bool
{
    $now = new DateTime('now', new DateTimeZone($tz));
    $day = strtoupper($now->format('D'));
    $time = $now->format('H:i:s');
    foreach ($hours[$day] ?? [] as $slot) {
        if ($time >= $slot['open'] && $time < $slot['close']) return true;
    }
    return false;
}

/**
 * $complete = false (price quotes while the customer is still typing) only
 * checks what has been entered so far; the full address is required to pay.
 */
function validate_fulfillment(array $f, array $store, int $subtotal, bool $complete = true): array
{
    $type = ($f['type'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
    $zone = null;
    $distance = null;
    $pending = false;
    if ($type === 'delivery') {
        if (empty($store['deliveryEnabled'])) fail('Delivery is not available right now.');
        $d = delivery_settings();
        $pub = $store['delivery'] ?? delivery_public($d);
        $a = is_array($f['address'] ?? null) ? $f['address'] : [];
        foreach (['line1', 'line2', 'city', 'zip', 'instructions'] as $k) $a[$k] = trim(mb_substr((string) ($a[$k] ?? ''), 0, $k === 'instructions' ? 500 : 120));
        if ($complete) {
            $need = ['line1' => 'street address', 'city' => 'city'];
            if (!empty($pub['postalRequired'])) $need['zip'] = delivery_label_lc($pub['postalLabel']);
            foreach ($need as $k => $label) if ($a[$k] === '') fail("Please enter your $label for delivery.");
        }
        $m = delivery_match($a, $d, $complete);
        if ($m['zone']) {
            $zone = $m['zone'];
            $distance = $m['distance'];
        } elseif ($m['pending']) {
            $pending = true;
        } elseif ($m['reason'] === 'lookup') {
            fail('We couldn\'t find that address on the map. Please check the street, city' . (!empty($pub['postalRequired']) ? ' and ' . delivery_label_lc($pub['postalLabel']) : '') . '.');
        } elseif ($m['reason'] === 'nozones') {
            fail('Delivery is not available right now.');
        } else {
            $where = array_map(fn($x) => $x['area'], array_filter($pub['areas'], fn($x) => $x['area'] !== 'Everywhere else'));
            fail('Sorry, we don\'t deliver to that address' . ($where ? ' (we deliver to: ' . implode('; ', $where) . ')' : '') . '. Please choose pickup instead.');
        }
        $minimum = $zone ? (int) $zone['minimum'] : (int) $store['deliveryMinimum'];
        if ($subtotal < $minimum) {
            fail('Delivery orders' . ($zone ? ' to ' . $zone['name'] : '') . ' need a subtotal of at least ' . delivery_money($minimum) . '.');
        }
        if (!is_open($store['deliveryHours'], $store['timezone'])) fail('We are not delivering right now. Please check our delivery hours.');
        return ['type' => $type, 'address' => $a, 'zone' => $zone, 'distance' => $distance, 'pending' => $pending];
    } else {
        if (empty($store['pickupEnabled'])) fail('Pickup (takeaway) is not available right now.' . (!empty($store['deliveryEnabled']) ? ' Please choose delivery.' : ''));
        if ($subtotal < $store['pickupMinimum']) fail('Your order is below the pickup minimum.');
        if (!is_open($store['pickupHours'], $store['timezone'])) fail('The kitchen is closed for online orders right now. Please check our hours.');
    }
    return ['type' => $type, 'address' => [], 'zone' => null, 'distance' => null, 'pending' => false];
}

/** What the cart shows about the delivery: zone, when it should arrive, and whether the address is still incomplete. */
function delivery_info(array $fulfillment, int $prepMinutes): ?array
{
    if ($fulfillment['type'] !== 'delivery') return null;
    $z = $fulfillment['zone'];
    return [
        'pending'     => $fulfillment['pending'],
        'zone'        => $z ? $z['name'] : null,
        'etaMinutes'  => $z ? $prepMinutes + (int) $z['eta'] : null,
        'freeOver'    => $z ? (int) $z['freeOver'] : 0,
        'minimum'     => $z ? (int) $z['minimum'] : null,
        'distance'    => $fulfillment['distance'],
    ];
}

function money(int $cents): array
{
    return ['amount' => $cents, 'currency' => 'USD'];
}

/** Square Orders API order body. */
function square_order(array $lines, array $fulfillment, array $customer, array $store): array
{
    $lineItems = [];
    foreach ($lines as $l) {
        $li = [
            'catalog_object_id' => $l['variation']['id'],
            'quantity'          => (string) $l['qty'],
            'modifiers'         => array_map(fn($m) => ['catalog_object_id' => $m['id']], $l['mods']),
        ];
        if ($l['note'] !== '') $li['note'] = $l['note'];
        $lineItems[] = $li;
    }

    $recipient = array_filter([
        'display_name'  => $customer['name'] ?? null,
        'phone_number'  => $customer['phone'] ?? null,
        'email_address' => $customer['email'] ?? null,
    ]);
    if ($fulfillment['type'] === 'delivery') {
        $a = $fulfillment['address'];
        $recipient['address'] = array_filter([
            'address_line_1' => trim((string) $a['line1']),
            'address_line_2' => trim((string) ($a['line2'] ?? '')),
            'locality'       => trim((string) $a['city']),
            'administrative_district_level_1' => trim((string) ($a['state'] ?? '')),
            'postal_code'    => trim((string) $a['zip']),
            'country'        => delivery_settings()['country'],
        ]);
        $f = ['type' => 'DELIVERY', 'state' => 'PROPOSED', 'delivery_details' => array_filter([
            'recipient'     => $recipient,
            'schedule_type' => 'ASAP',
            'prep_time_duration' => 'PT' . $store['prepMinutes'] . 'M',
            'note'          => trim(mb_substr((string) ($a['instructions'] ?? ''), 0, 500)) ?: null,
        ])];
    } else {
        $f = ['type' => 'PICKUP', 'state' => 'PROPOSED', 'pickup_details' => [
            'recipient'          => $recipient,
            'schedule_type'      => 'ASAP',
            'prep_time_duration' => 'PT' . $store['prepMinutes'] . 'M',
            'note'               => 'Ordered on the website',
        ]];
    }

    $order = [
        'location_id'     => config()['location_id'],
        'source'          => ['name' => 'RMS Website'],
        'line_items'      => $lineItems,
        'fulfillments'    => [$f],
        'pricing_options' => ['auto_apply_taxes' => true, 'auto_apply_discounts' => false],
    ];
    if ($fulfillment['type'] === 'delivery' && ($fulfillment['fee'] ?? 0) > 0) {
        $order['service_charges'] = [[
            'name'              => 'Delivery fee' . (!empty($fulfillment['zone']['name']) ? ' (' . $fulfillment['zone']['name'] . ')' : ''),
            'amount_money'      => money((int) $fulfillment['fee']),
            'calculation_phase' => 'SUBTOTAL_PHASE',
            'taxable'           => false,
        ]];
    }
    return $order;
}

function totals_from(array $order): array
{
    return [
        'subtotal'    => (int) (($order['total_money']['amount'] ?? 0) - ($order['total_tax_money']['amount'] ?? 0) - ($order['total_service_charge_money']['amount'] ?? 0) + ($order['total_discount_money']['amount'] ?? 0)),
        'tax'         => (int) ($order['total_tax_money']['amount'] ?? 0),
        'deliveryFee' => (int) ($order['total_service_charge_money']['amount'] ?? 0),
        'discount'    => (int) ($order['total_discount_money']['amount'] ?? 0),
        'total'       => (int) ($order['total_money']['amount'] ?? 0),
    ];
}

// ------------------------------------------------------------------------

$action = $req['action'] ?? '';
[$lines, $subtotal] = validate_cart($req['cart'] ?? [], $menu);
$fulfillment = validate_fulfillment($req['fulfillment'] ?? [], $store, $subtotal, in_array($action, ['pay', 'place'], true));

// Local per-item rules (from the Food admin tab): pickup-only items, per-item
// delivery surcharges, and per-item prep time.
$fee = 0;
$prepMinutes = (int) $store['prepMinutes'];
foreach ($lines as $l) {
    $prepMinutes = max($prepMinutes, (int) ($l['item']['prepMinutes'] ?? 0));
}
if ($fulfillment['type'] === 'pickup') {
    foreach ($lines as $l) {
        if (!empty($l['item']['deliveryOnly'])) fail($l['item']['name'] . ' is available for delivery only. Please switch to delivery or remove it.');
    }
}
if ($fulfillment['type'] === 'delivery') {
    foreach ($lines as $l) {
        if (!empty($l['item']['pickupOnly'])) fail($l['item']['name'] . ' is available for pickup only. Please switch to pickup or remove it.');
    }
    // The zone's fee (free above its free-delivery amount), plus any per-item delivery surcharges.
    $fee = $fulfillment['zone'] ? delivery_zone_fee($fulfillment['zone'], $subtotal) : 0;
    $seen = [];
    foreach ($lines as $l) {
        $id = $l['item']['id'];
        if (!empty($l['item']['deliveryFee']) && !isset($seen[$id])) { $fee += (int) $l['item']['deliveryFee']; $seen[$id] = true; }
    }
}
$fulfillment['fee'] = $fee;
$readyIn = $fulfillment['zone'] ? $prepMinutes + (int) $fulfillment['zone']['eta'] : $prepMinutes;
$deliveryInfo = delivery_info($fulfillment, $prepMinutes);

if ($action === 'quote') {
    if (cod_mode()) {
        // Cash on delivery: no card charge and no Square, so show the pre-tax
        // total (sales tax is added in cash at the restaurant).
        send_json(['ok' => true, 'paymentsEnabled' => false, 'codMode' => true, 'delivery' => $deliveryInfo, 'totals' => [
            'subtotal' => $subtotal, 'tax' => null, 'deliveryFee' => $fee, 'discount' => 0, 'total' => $subtotal + $fee,
        ]]);
    }
    // Tax doesn't depend on who/where, so quote without the (possibly half-typed) recipient.
    $quoteOrder = square_order($lines, $fulfillment, [], $store);
    unset($quoteOrder['fulfillments']);
    [$status, $res] = square_request('POST', '/v2/orders/calculate', ['order' => $quoteOrder]);
    if ($status !== 200) fail(square_error_message($res), 502);
    send_json(['ok' => true, 'paymentsEnabled' => true, 'delivery' => $deliveryInfo, 'totals' => totals_from($res['order'])]);
}

if ($action === 'place') {
    // Cash on delivery: record the order and notify the restaurant. No card is
    // charged and no payment processor is contacted.
    if (!cod_mode()) fail('This order should be paid by card. Please refresh and try again.', 409);
    rate_limit('place', 12, 900); // stop a flood of bogus orders

    $c = $req['customer'] ?? [];
    $customer = [
        'name'  => trim(mb_substr((string) ($c['name'] ?? ''), 0, 80)),
        'phone' => trim(mb_substr((string) ($c['phone'] ?? ''), 0, 20)),
        'email' => trim(mb_substr((string) ($c['email'] ?? ''), 0, 120)),
    ];
    if ($customer['name'] === '') fail('Please enter your name.');
    if (strlen(preg_replace('/\D/', '', $customer['phone'])) < 7) fail('Please enter a valid phone number so we can reach you.');
    if ($customer['email'] !== '' && !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address, or leave it blank.');

    $total = $subtotal + $fee;
    $number = strtoupper(bin2hex(random_bytes(3))); // 6-char order number

    // Human-readable item lines for the log and the restaurant's email.
    $itemLines = array_map(function ($l) {
        $mods = array_map(fn($m) => $m['name'], $l['mods']);
        $label = $l['qty'] . ' x ' . $l['item']['name']
            . ($l['variation']['name'] !== 'Regular' ? ' (' . $l['variation']['name'] . ')' : '')
            . ($mods ? ' [' . implode(', ', $mods) . ']' : '')
            . ($l['note'] !== '' ? ' - note: ' . $l['note'] : '');
        return $label . ' = $' . number_format($l['unit'] * $l['qty'] / 100, 2);
    }, $lines);

    $addr = '';
    if ($fulfillment['type'] === 'delivery') {
        $a = $fulfillment['address'];
        $addr = trim(implode(', ', array_filter([
            trim((string) ($a['line1'] ?? '')), trim((string) ($a['line2'] ?? '')),
            trim((string) ($a['city'] ?? '')), trim((string) ($a['zip'] ?? '')),
        ])));
        if (trim((string) ($a['instructions'] ?? '')) !== '') $addr .= ' (' . trim(mb_substr((string) $a['instructions'], 0, 500)) . ')';
    }

    // Save the order to the database (or its backup file if the database is down).
    require_once __DIR__ . '/db.php';
    $saved = save_row('orders', [
        'number'       => $number,
        'payment'      => 'cash on delivery',
        'order_type'   => $fulfillment['type'],
        'name'         => $customer['name'],
        'phone'        => $customer['phone'],
        'email'        => $customer['email'],
        'address'      => $addr,
        'items'        => json_encode($itemLines, JSON_UNESCAPED_UNICODE),
        'subtotal'     => $subtotal,
        'delivery_fee' => $fee,
        'total'        => $total,
        'ip'           => client_ip(),
    ]);

    // Email the restaurant (if notify_email is configured).
    $mailed = notify("New website order #$number (CASH ON DELIVERY)", [
        'Order'    => "#$number",
        'Placed'   => date('l, M j, Y g:i A'),
        'Type'     => strtoupper($fulfillment['type']),
        'Name'     => $customer['name'],
        'Phone'    => $customer['phone'],
        'Email'    => $customer['email'] ?: '(not given)',
        'Address'  => $addr ?: '(pickup)',
        'Delivery zone' => $fulfillment['zone']['name'] ?? 'n/a',
        'Items'    => implode("\n", $itemLines),
        'Subtotal' => '$' . number_format($subtotal / 100, 2),
        'Delivery' => $fee ? '$' . number_format($fee / 100, 2) : 'n/a',
        'To collect (cash)' => '$' . number_format($total / 100, 2) . ' + sales tax',
    ], $customer['email']);

    // Never tell a guest the order went through when the restaurant can't see it.
    if (!$saved && !$mailed) fail('Sorry, we could not place your order right now. Please call us at (954) 565-2526.', 500);

    // The guest's own copy, when they gave an email (the confirmation screen says one was sent).
    if ($customer['email'] !== '') {
        $delivery = $fulfillment['type'] === 'delivery';
        mail_guest($customer['email'], "Your RMS order #$number", 'Thanks, ' . $customer['name'] . '! We have your order and the kitchen is on it.', [
            'Order'    => "#$number",
            $delivery ? 'Delivery to' : 'Pickup at' => $delivery ? $addr : (string) ($store['address'] ?? ''),
            'Ready in' => 'about ' . $readyIn . ' minutes',
            'Items'    => implode("\n", $itemLines),
            'To pay'   => '$' . number_format($total / 100, 2) . ' in cash, plus sales tax',
            'Questions' => 'Call us at ' . (string) ($store['phone'] ?? '(954) 565-2526'),
        ]);
    }

    send_json([
        'ok'          => true,
        'codMode'     => true,
        'orderNumber' => $number,
        'totals'      => ['subtotal' => $subtotal, 'deliveryFee' => $fee, 'tax' => null, 'total' => $total],
        'fulfillment' => $fulfillment['type'],
        'readyInMinutes' => $readyIn,
    ]);
}

if ($action === 'pay') {
    if (!payments_enabled()) fail('Online payment is being set up. Please call us to place your order.', 503);
    rate_limit('pay', 8, 900); // card-testing guard: 8 payment attempts per 15 min per visitor

    $c = $req['customer'] ?? [];
    $customer = [
        'name'  => trim(mb_substr((string) ($c['name'] ?? ''), 0, 80)),
        'phone' => trim(mb_substr((string) ($c['phone'] ?? ''), 0, 20)),
        'email' => trim(mb_substr((string) ($c['email'] ?? ''), 0, 120)),
    ];
    if ($customer['name'] === '') fail('Please enter your name.');
    if (strlen(preg_replace('/\D/', '', $customer['phone'])) < 7) fail('Please enter a valid phone number.');
    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');

    $sourceId = (string) ($req['sourceId'] ?? '');
    $key = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($req['idempotencyKey'] ?? ''));
    if ($sourceId === '' || strlen($key) < 16) fail('Payment details are missing. Please re-enter your card.');
    $tip = max(0, min((int) ($req['tip'] ?? 0), 100000));

    [$status, $res] = square_request('POST', '/v2/orders', [
        'idempotency_key' => "order-$key",
        'order'           => square_order($lines, $fulfillment, $customer, $store),
    ]);
    if ($status !== 200) fail(square_error_message($res), 502);
    $order = $res['order'];
    $totals = totals_from($order);

    $payment = [
        'idempotency_key'     => "pay-$key",
        'source_id'           => $sourceId,
        'amount_money'        => money($totals['total']),
        'order_id'            => $order['id'],
        'location_id'         => config()['location_id'],
        'buyer_email_address' => $customer['email'],
        'note'                => 'RMS website order',
    ];
    if ($tip > 0) $payment['tip_money'] = money($tip);
    if (!empty($req['verificationToken'])) $payment['verification_token'] = (string) $req['verificationToken'];

    [$status, $res] = square_request('POST', '/v2/payments', $payment);
    if ($status !== 200 || ($res['payment']['status'] ?? '') === 'FAILED') fail(square_error_message($res), 402);

    send_json([
        'ok'          => true,
        'orderId'     => $order['id'],
        'orderNumber' => strtoupper(substr($order['id'], -6)),
        'receiptUrl'  => $res['payment']['receipt_url'] ?? null,
        'totals'      => $totals + ['tip' => $tip, 'charged' => $totals['total'] + $tip],
        'fulfillment' => $fulfillment['type'],
        'readyInMinutes' => $readyIn,
    ]);
}

fail('Unknown action.');
