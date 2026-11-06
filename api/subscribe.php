<?php
/**
 * POST api/subscribe.php  (JSON { "email": "..." })  — newsletter sign-up.
 *
 * Saves the address to the subscribers table and, once Square credentials
 * are in config.php, adds the person to the restaurant's Square Customer
 * Directory so they can be reached with Square Marketing email campaigns.
 */
require_once __DIR__ . '/lib.php';

$in = read_form();
$email = strtolower(clean_field($in['email'] ?? '', 120));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');

$page = clean_field($in['page'] ?? '', 60);
try {
    require_once __DIR__ . '/db.php';
    $stmt = db()->prepare("INSERT INTO subscribers (email, page) VALUES (?, ?) ON DUPLICATE KEY UPDATE page = page");
    $stmt->execute([$email, $page]);
    $saved = true;
} catch (Throwable $e) {
    error_log('subscribe: ' . $e->getMessage());
    $saved = unsaved_keep('subscribers', ['email' => $email, 'page' => $page]);
}

$inSquare = false;
if (payments_enabled()) {
    [$status, $found] = square_request('POST', '/v2/customers/search', [
        'query' => ['filter' => ['email_address' => ['exact' => $email]]],
        'limit' => 1,
    ]);
    if ($status === 200 && !empty($found['customers'])) {
        $inSquare = true;
    } else {
        [$status] = square_request('POST', '/v2/customers', [
            'idempotency_key' => 'newsletter-' . md5($email),
            'email_address'   => $email,
            'note'            => 'Newsletter sign-up from the website',
        ]);
        $inSquare = $status === 200;
    }
}

if (!$saved && !$inSquare) fail('Sorry, sign-up is unavailable right now. Please try again later.', 500);
send_json(['ok' => true]);
