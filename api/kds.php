<?php
/**
 * POST api/kds.php   (JSON body, session-authenticated)
 *
 * Kitchen Display System API — orders, status changes, settings.
 *
 *   action: "orders"        → all today's orders with KDS metadata
 *   action: "start"         → begin preparation (timer starts)
 *   action: "complete"      → mark order done
 *   action: "recall"        → move completed order back to preparing
 *   action: "settings-get"  → current KDS settings
 *   action: "settings-save" → update KDS settings
 *   action: "counts"        → badge-style counters only
 */
define('KDS', true);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/inc/store.php';
require_once __DIR__ . '/team-users.php';

// --- session auth (a username and password, like the admin, with its own session) ---
if (!team_password_set()) fail('KDS is not configured.', 503);

session_name('rms_kds');
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/kds/')), '/\\') . '/kds/';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $base,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.gc_maxlifetime', '43200');
ini_set('session.use_strict_mode', '1');
$dir = data_dir() . '/sessions';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
session_start();

function kds_signed_in(): bool
{
    // Same rules as kds/index.php: idle 12 hours, or the password, role or account changed since.
    if (!team_session_valid('kds', 43200, array_keys(TEAM_ROLES))) return false;
    $_SESSION['kds_seen'] = time();
    return true;
}

if (!kds_signed_in()) fail('Not signed in.', 401);

try {
    db();
} catch (Throwable $e) {
    if (!db_configured()) fail('The website isn\'t connected to its database yet. Sign in to the admin panel once to connect it.', 503);
    fail('The database can\'t be reached right now. Orders are kept safe; try again in a moment.', 503);
}

// --- request handling ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
require_same_site();

$req = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($req)) fail('Invalid request.');
$action = (string) ($req['action'] ?? '');

// --- KDS settings ---
function kds_settings(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $s = db_setting('kds_settings');
    } catch (Throwable $e) {
        $s = null;
    }
    return (is_array($s) ? $s : []) + [
        'dine_in_prep_minutes'  => 15,
        'delivery_prep_minutes' => 20,
        'pickup_prep_minutes'   => 15,
        'sound_enabled'         => true,
    ];
}

function kds_prep_seconds(string $type): int
{
    $s = kds_settings();
    switch ($type) {
        case 'delivery': return (int) $s['delivery_prep_minutes'] * 60;
        case 'dine-in':  return (int) $s['dine_in_prep_minutes'] * 60;
        default:         return (int) $s['pickup_prep_minutes'] * 60;
    }
}

// --- order helpers ---

/** All today's orders (and yesterday if early) with KDS-relevant fields. */
function kds_orders(): array
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $rows = orders_between($yesterday, $today);
    $out = [];

    foreach ($rows as $o) {
        if ($o['status'] === 'cancelled') continue;
        if ($o['status'] === 'completed' || $o['status'] === 'ready') {
            $completedAt = $o['kds_completed'] ?? null;
            if ($completedAt && (time() - strtotime($completedAt)) > 1800) continue;
            if (!$completedAt && date('Y-m-d', $o['ts']) < $today) continue;
        }

        $out[] = [
            'number'       => $o['number'],
            'type'         => $o['type'],
            'table'        => $o['table'] ?? null,
            'name'         => $o['name'],
            'phone'        => $o['phone'],
            'email'        => $o['email'],
            'payment'      => $o['payment'],
            'items'        => $o['items'],
            'notes'        => $o['notes'] ?? '',
            'note'         => $o['note'],
            'total'        => $o['total'],
            'ts'           => $o['ts'],
            'time'         => date('c', $o['ts']),
            'status'       => $o['status'],
            'prep_started' => db_iso($o['kds_started'] ?? null),
            'prep_duration'=> $o['kds_duration'] ?? kds_prep_seconds($o['type']),
            'completed_at' => db_iso($o['kds_completed'] ?? null),
        ];
    }
    usort($out, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
}

// --- actions ---

if ($action === 'orders') {
    send_json(['ok' => true, 'orders' => kds_orders(), 'settings' => kds_settings()]);
}

if ($action === 'counts') {
    $orders = kds_orders();
    $counts = ['queue' => 0, 'preparing' => 0, 'overdue' => 0, 'completed' => 0, 'total' => count($orders)];
    $now = time();
    foreach ($orders as $o) {
        if ($o['status'] === 'completed' || $o['status'] === 'ready') {
            $counts['completed']++;
        } elseif ($o['status'] === 'preparing') {
            $counts['preparing']++;
            if ($o['prep_started']) {
                $elapsed = $now - strtotime($o['prep_started']);
                if ($elapsed > $o['prep_duration']) $counts['overdue']++;
            }
        } else {
            $counts['queue']++;
        }
    }
    send_json(['ok' => true, 'counts' => $counts]);
}

if ($action === 'start') {
    $num = strtoupper(trim((string) ($req['number'] ?? '')));
    $o = order_find($num);
    if (!$o) fail('Order not found.');

    $duration = kds_prep_seconds($o['type']);
    meta_set_status('order', $num, 'preparing');
    meta_set('order', $num, [
        'kds_started'  => date('Y-m-d H:i:s'),
        'kds_duration' => $duration,
    ]);
    admin_log('kds.start', '#' . $num);
    send_json(['ok' => true]);
}

if ($action === 'complete') {
    $num = strtoupper(trim((string) ($req['number'] ?? '')));
    $o = order_find($num);
    if (!$o) fail('Order not found.');

    meta_set_status('order', $num, 'ready');
    meta_set('order', $num, ['kds_completed' => date('Y-m-d H:i:s')]);
    admin_log('kds.complete', '#' . $num);
    send_json(['ok' => true]);
}

if ($action === 'recall') {
    $num = strtoupper(trim((string) ($req['number'] ?? '')));
    $o = order_find($num);
    if (!$o) fail('Order not found.');

    meta_set_status('order', $num, 'preparing');
    meta_set('order', $num, ['kds_completed' => null]);
    admin_log('kds.recall', '#' . $num);
    send_json(['ok' => true]);
}

if ($action === 'settings-get') {
    send_json(['ok' => true, 'settings' => kds_settings()]);
}

if ($action === 'settings-save') {
    $s = $req['settings'] ?? [];
    $before = kds_settings();
    $data = [
        'dine_in_prep_minutes'  => max(1, min(120, (int) ($s['dine_in_prep_minutes'] ?? 15))),
        'delivery_prep_minutes' => max(1, min(120, (int) ($s['delivery_prep_minutes'] ?? 20))),
        'pickup_prep_minutes'   => max(1, min(120, (int) ($s['pickup_prep_minutes'] ?? 15))),
        'sound_enabled'         => !empty($s['sound_enabled']),
    ];
    require_once __DIR__ . '/db.php';
    db_setting_save('kds_settings', $data);
    $names = ['dine_in_prep_minutes' => 'Dine-in prep time', 'delivery_prep_minutes' => 'Delivery prep time', 'pickup_prep_minutes' => 'Pickup prep time'];
    $changes = [];
    foreach ($names as $k => $label) if ((int) $before[$k] !== $data[$k]) $changes[] = $label . ': ' . (int) $before[$k] . ' → ' . $data[$k] . ' min';
    if (!empty($before['sound_enabled']) !== $data['sound_enabled']) $changes[] = 'Kitchen sound ' . ($data['sound_enabled'] ? 'on' : 'off');
    if ($changes) admin_log('kds.settings', '', $changes);
    send_json(['ok' => true, 'settings' => $data]);
}

fail('Unknown action.');
