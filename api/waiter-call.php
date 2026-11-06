<?php
/**
 * POST api/waiter-call.php   (JSON body, public — no auth)
 *
 * Customer-facing "Call the Waiter" button.
 *
 *   { "table": 14, "name": "Alex" }
 *
 * Creates a temporary active waiter request visible in the Admin Panel.
 * Only one active request per table at a time.
 */
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
require_same_site();
// Guests on the venue Wi-Fi all share one address, so the tight limit is per table.
rate_limit('waiter', 60, 300);

$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');

$table = (int) ($req['table'] ?? 0);
if ($table < 1 || $table > 999) fail('Invalid table number.');
rate_limit('waiter-table-' . $table, 4, 300);

$name = trim(mb_substr((string) ($req['name'] ?? ''), 0, 30));
if ($name === '' || !preg_match('/^[A-Za-z][A-Za-z ]*$/', $name) || mb_strlen($name) < 2) {
    fail('Please enter a valid name.');
}

require_once __DIR__ . '/db.php';

$existing = db()->prepare("SELECT id FROM waiter_calls WHERE table_number = ? AND status = 'active'");
$existing->execute([$table]);
if ($existing->fetch()) {
    send_json(['ok' => true, 'already' => true]);
}

save_row('waiter_calls', [
    'table_number'  => $table,
    'customer_name' => $name,
    'status'        => 'active',
    'ip'            => client_ip(),
]);

define('KDS', true);
require_once __DIR__ . '/../admin/inc/store.php';
admin_log('waiter.call', 'Table ' . $table . ' — ' . $name);

send_json(['ok' => true]);
