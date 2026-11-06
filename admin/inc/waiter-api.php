<?php
/**
 * Admin API for waiter assistance requests.
 *
 * GET  ?api=waiter           → list active requests
 * POST ?api=waiter&do=resolve  { "id": 5 }  → resolve a request
 */
if (!defined('ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/../../api/db.php';

$action = $_GET['do'] ?? 'list';

if ($action === 'list') {
    $rows = db()->query("SELECT id, created_at, table_number, customer_name FROM waiter_calls WHERE status = 'active' ORDER BY created_at ASC")->fetchAll();
    foreach ($rows as &$r) $r['created_at'] = db_iso($r['created_at']);
    unset($r);
    send_json(['ok' => true, 'calls' => $rows]);
}

if ($action === 'resolve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
    require_same_site();
    $req = json_decode((string) file_get_contents('php://input'), true);
    $id = (int) ($req['id'] ?? 0);
    if ($id < 1) fail('Invalid request ID.');

    $stmt = db()->prepare("UPDATE waiter_calls SET status = 'resolved', resolved_at = ? WHERE id = ? AND status = 'active'");
    $stmt->execute([date('Y-m-d H:i:s'), $id]);

    if ($stmt->rowCount() === 0) fail('Request not found or already resolved.');

    admin_log('waiter.resolve', 'Resolved waiter call #' . $id);
    send_json(['ok' => true]);
}

fail('Unknown action.', 400);
