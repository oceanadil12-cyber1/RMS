<?php
/**
 * Gift cards for gift-cards.html (see api/gift-cards-lib.php).
 *
 *   GET  api/gift-cards.php                 → designs, amounts and how cards are paid for
 *   GET  api/gift-cards.php?photo=<id>      → a customer's own card picture
 *
 *   POST (JSON) { "action": "buy", "design": "holiday-green" | "custom", "title": "...",
 *                 "photo": "data:image/jpeg;base64,...", "amount": 5000,
 *                 "buyer": {name, email, phone}, "recipient": {name, email} | null,
 *                 "message": "...", "deliverOn": "2026-12-25",
 *                 "sourceId": "<card token>", "idempotencyKey": "<uuid>" }
 *       Square mode: charges the card, creates the gift card in Square and emails it.
 *       Request mode (no card payments yet): saves the request for the team, who
 *       take payment and activate it in Admin → Gift cards.
 *   POST { "action": "balance", "code": "7712 3456 ..." }   → balance of a card
 *   POST { "action": "view", "token": "<32 hex>" }           → the card behind an emailed link
 */
require_once __DIR__ . '/gift-cards-lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['photo'])) {
        $file = giftcards_photo_file((string) $_GET['photo']);
        if ($file === '' || !is_file($file)) { http_response_code(404); exit; }
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    $s = giftcards_settings();
    try { giftcards_send_due(); } catch (Throwable $e) { error_log('giftcards_send_due: ' . $e->getMessage()); }
    $mode = giftcards_mode();
    send_json([
        'ok'                => true,
        'enabled'           => !empty($s['enabled']),
        'mode'              => $mode,
        'designs'           => array_values(array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name'], 'title' => $d['title'], 'image' => $d['image']],
                                array_filter($s['designs'], fn($d) => !empty($d['active'])))),
        'amounts'           => array_map('intval', $s['amounts']),
        'min'               => (int) $s['min'],
        'max'               => (int) $s['max'],
        'allowCustomAmount' => !empty($s['allowCustomAmount']),
        'allowCustomDesign' => !empty($s['allowCustomDesign']),
        'applicationId'     => $mode === 'square' ? config()['application_id'] : null,
        'locationId'        => $mode === 'square' ? config()['location_id'] : null,
        'environment'       => config()['environment'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
require_same_site();
$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

try {
    db();
} catch (Throwable $e) {
    fail('Gift cards are unavailable right now. Please call us at (954) 565-2526.', 503);
}

$action = $req['action'] ?? '';

if ($action === 'balance') {
    rate_limit('gift-balance', 10, 600); // nobody can try codes one after another
    $code = giftcards_clean_code((string) ($req['code'] ?? ''));
    if (strlen($code) < 8) fail('Please enter the code from your gift card.');
    $card = giftcards_find('code', $code);
    if ($card) {
        $card = giftcards_square_refresh($card);
        if ($card['status'] === 'pending') fail('This gift card has not been activated yet. Please call us at (954) 565-2526.');
        send_json(['ok' => true, 'balance' => (int) $card['balance'], 'status' => $card['status'], 'last4' => substr($code, -4)]);
    }
    // A Square card sold at the bar (not on the website) is only in Square.
    if (payments_enabled() && ($sq = giftcards_square_lookup($code))) {
        send_json(['ok' => true, 'balance' => $sq['balance'], 'status' => $sq['state'] === 'DEACTIVATED' ? 'void' : ($sq['balance'] > 0 ? 'active' : 'used'), 'last4' => substr($code, -4)]);
    }
    fail('We couldn\'t find a gift card with that code. Please check it and try again.', 404);
}

if ($action === 'view') {
    rate_limit('gift-view', 30, 600);
    $token = (string) ($req['token'] ?? '');
    $card = preg_match('/^[a-f0-9]{32}$/', $token) ? giftcards_find('token', $token) : null;
    if (!$card) fail('This gift card link isn\'t valid. Please check the email it came in.', 404);
    $card = giftcards_square_refresh($card);
    send_json(['ok' => true, 'card' => giftcards_public($card, in_array($card['status'], ['active', 'used'], true))]);
}

if ($action !== 'buy') fail('Unknown action.');

// ---------- buying a card ----------

$s = giftcards_settings();
require_once __DIR__ . '/site-features.php';
// Off when Gift cards are switched off here (Admin → Gift cards) or the Gift Cards page is (Store settings → Website sections).
if (empty($s['enabled']) || empty(site_features()['gift-cards'])) fail('Online gift cards are not available right now. Please visit us or call (954) 565-2526.', 503);
rate_limit('gift-buy', 8, 900); // card-testing guard

$str = fn($v, $max) => clean_field($v, $max);

// Design: one of the team's, or the buyer's own picture and words.
$designId = (string) ($req['design'] ?? '');
$title = '';
$photoBytes = '';
if ($designId === 'custom') {
    if (empty($s['allowCustomDesign'])) fail('Please choose one of our designs.');
    $title = $str($req['title'] ?? '', 40);
    if (!empty($req['photo'])) {
        [$photoBytes, $err] = giftcards_photo_decode((string) $req['photo']);
        if ($err !== '') fail($err);
    }
    if ($photoBytes === '' && $title === '') fail('Add a picture or a few words for your own design.');
} else {
    $design = giftcards_design($designId);
    if (!$design || empty($design['active'])) fail('Please choose a design for your gift card.');
}

// Amount: a preset, or any whole-dollar amount between the limits.
$amount = (int) ($req['amount'] ?? 0);
if (!in_array($amount, array_map('intval', $s['amounts']), true)) {
    if (empty($s['allowCustomAmount'])) fail('Please choose one of the amounts.');
    if ($amount % 100 !== 0 || $amount < (int) $s['min'] || $amount > (int) $s['max']) {
        fail('Please enter a whole-dollar amount from $' . number_format($s['min'] / 100) . ' to $' . number_format($s['max'] / 100) . '.');
    }
}

$b = (array) ($req['buyer'] ?? []);
$buyer = ['name' => $str($b['name'] ?? '', 80), 'email' => $str($b['email'] ?? '', 120), 'phone' => $str($b['phone'] ?? '', 20)];
if ($buyer['name'] === '') fail('Please enter your name.');
if (!filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address for your receipt.');
$mode = giftcards_mode();
if ($mode === 'request' && strlen(preg_replace('/\D/', '', $buyer['phone'])) < 10) fail('Please enter your phone number so we can arrange payment.');

$recipient = ['name' => '', 'email' => ''];
$message = '';
if (!empty($req['recipient'])) {
    $r = (array) $req['recipient'];
    $recipient = ['name' => $str($r['name'] ?? '', 80), 'email' => $str($r['email'] ?? '', 120)];
    if ($recipient['name'] === '') fail('Please enter the recipient\'s name.');
    if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address for the recipient.');
    $message = $str($req['message'] ?? '', 300);
}

$deliverOn = null;
$d = (string) ($req['deliverOn'] ?? '');
if ($d !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) fail('Please choose a valid delivery date.');
    if ($d < date('Y-m-d')) fail('The delivery date has passed. Please choose today or later.');
    if ($d > date('Y-m-d', strtotime('+1 year'))) fail('Please choose a delivery date within the next year.');
    if ($d > date('Y-m-d')) $deliverOn = $d;
}

$row = [
    'origin'          => 'online',
    'design'          => $designId,
    'design_title'    => $title,
    'amount'          => $amount,
    'buyer_name'      => $buyer['name'],
    'buyer_email'     => $buyer['email'],
    'buyer_phone'     => $buyer['phone'],
    'recipient_name'  => $recipient['name'],
    'recipient_email' => $recipient['email'],
    'message'         => $message,
    'deliver_on'      => $deliverOn,
];
$money = '$' . number_format($amount / 100, 2);
$forWhom = $recipient['email'] !== '' ? $recipient['name'] . ' <' . $recipient['email'] . '>' : 'the buyer';
$when = $deliverOn ? date('l, M j, Y', strtotime($deliverOn)) : 'right away';

if ($mode === 'request') {
    // No online payment yet: keep the request; the team takes payment and activates it.
    if ($photoBytes !== '' && ($row['photo'] = giftcards_photo_save($photoBytes)) === '') fail('Your picture could not be saved. Please try again.', 500);
    $card = giftcards_create($row + ['source' => 'local', 'status' => 'pending'], 'Requested on the website');
    notify("Gift card request: $money for $forWhom", [
        'Amount'  => $money,
        'Buyer'   => $buyer['name'] . ', ' . $buyer['phone'] . ', ' . $buyer['email'],
        'For'     => $forWhom,
        'Deliver' => $when,
        'Next'    => 'Call the buyer to take payment, then open Admin → Gift cards and press "Mark paid".',
    ], $buyer['email']);
    mail_guest($buyer['email'], 'Your RMS gift card request', 'Thanks, ' . $buyer['name'] . '! We have your gift card request and will call you shortly to take payment.', [
        'Amount'    => $money,
        'For'       => $recipient['name'] !== '' ? $recipient['name'] : 'You',
        'Delivered' => 'by email ' . ($deliverOn ? 'on ' . $when : 'as soon as it\'s paid'),
        'Questions' => 'Call us at (954) 565-2526',
    ]);
    send_json(['ok' => true, 'mode' => 'request', 'reference' => substr($card['code'], -4), 'amount' => $amount]);
}

// Square: charge the card, then create and load the gift card in Square.
$sourceId = (string) ($req['sourceId'] ?? '');
$key = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($req['idempotencyKey'] ?? ''));
if ($sourceId === '' || strlen($key) < 16) fail('Payment details are missing. Please re-enter your card.');

$payment = [
    'idempotency_key'     => "gpay-$key",
    'source_id'           => $sourceId,
    'amount_money'        => ['amount' => $amount, 'currency' => 'USD'],
    'location_id'         => config()['location_id'],
    'buyer_email_address' => $buyer['email'],
    'note'                => "Gift card ($money) bought on the website",
];
if (!empty($req['verificationToken'])) $payment['verification_token'] = (string) $req['verificationToken'];
[$status, $res] = square_request('POST', '/v2/payments', $payment);
if ($status !== 200 || ($res['payment']['status'] ?? '') === 'FAILED') fail(square_error_message($res), 402);
$paymentId = (string) $res['payment']['id'];

// The same payment sent twice (a retry after a dropped connection) is the same card.
$card = giftcards_find('square_payment_id', $paymentId);
if (!$card) {
    if ($photoBytes !== '') $row['photo'] = giftcards_photo_save($photoBytes);
    $row += ['square_payment_id' => $paymentId, 'receipt_url' => (string) ($res['payment']['receipt_url'] ?? '')];
    [$sq, $err] = giftcards_square_issue($amount, $paymentId, $key);
    if ($sq) {
        $card = giftcards_create($row + ['source' => 'square', 'status' => 'active', 'code' => $sq['gan'], 'square_card_id' => $sq['id'], 'balance' => $amount], 'Bought on the website, paid by card');
    } else {
        // Paid but not created: keep it for the team (Admin → Gift cards → Retry in Square).
        error_log("gift card issue after payment $paymentId: $err");
        $card = giftcards_create($row + ['source' => 'square', 'status' => 'error', 'note' => "Square didn't create the card: $err"], 'Paid by card; Square card not created yet');
        notify("Gift card needs attention: $money paid, card not created", [
            'Amount'  => $money, 'Buyer' => $buyer['name'] . ', ' . $buyer['email'], 'For' => $forWhom,
            'Payment' => $paymentId, 'Problem' => $err, 'Next' => 'Admin → Gift cards → open the card → "Retry in Square".',
        ], $buyer['email']);
    }
}

if ($card['status'] === 'active' && $card['sent_at'] === null && $deliverOn === null) giftcards_send($card);
if ($card['status'] === 'active' && $card['recipient_email'] !== '') {
    mail_guest($buyer['email'], 'Your RMS gift card receipt', 'Thanks, ' . $buyer['name'] . '! Your gift card is on its way.', [
        'Amount'    => $money,
        'For'       => $recipient['name'] . ' (' . $recipient['email'] . ')',
        'Delivered' => $deliverOn ? 'on ' . $when : 'now, by email',
        'Card ends' => substr($card['code'], -4),
        'Receipt'   => $card['receipt_url'],
    ]);
}
send_json([
    'ok'      => true,
    'mode'    => 'square',
    'status'  => $card['status'],
    'card'    => $card['status'] === 'active' && $card['recipient_email'] === '' ? giftcards_public($card, true) : giftcards_public($card, false),
    'token'   => $card['recipient_email'] === '' ? $card['token'] : null,
    'receipt' => $card['receipt_url'],
]);
