<?php
/**
 * Where the admin panel reads and writes the team's records (MySQL via PDO).
 */
if (!defined('ADMIN') && !defined('KDS')) { http_response_code(403); exit('Forbidden'); }

// status key => [label, tone]. The first key is what a record has until someone changes it.
const ORDER_STATUSES = [
    'new'       => ['New',       'info'],
    'preparing' => ['Preparing', 'warn'],
    'ready'     => ['Ready',     'good'],
    'completed' => ['Completed', 'muted'],
    'cancelled' => ['Cancelled', 'crit'],
];
const RESERVATION_STATUSES = [
    'pending'   => ['Pending',   'warn'],
    'confirmed' => ['Confirmed', 'good'],
    'declined'  => ['Declined',  'crit'],
    'cancelled' => ['Cancelled', 'muted'],
];
const MESSAGE_STATUSES = [
    'new'      => ['New',      'info'],
    'read'     => ['Read',     'muted'],
    'replied'  => ['Replied',  'good'],
    'archived' => ['Archived', 'muted'],
];

require_once __DIR__ . '/../../api/db.php';

// ---------- statuses, notes and flags ----------

function meta_get(string $kind, string $id): array
{
    return [];
}

/** Set a record's status. For orders, also updates status_history JSON. */
function meta_set_status(string $kind, string $id, string $status): void
{
    try {
        if ($kind === 'order') {
            $stmt = db()->prepare("SELECT status, status_history FROM orders WHERE number = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return;
            if ($row['status'] === $status) return;
            $history = $row['status_history'] ? json_decode($row['status_history'], true) : [];
            $history[] = ['s' => $status, 't' => date('c')];
            $history = array_slice($history, -20);
            db_update('orders', ['status' => $status, 'status_history' => json_encode($history)], ['number' => $id]);
        } elseif ($kind === 'reservation') {
            db_update('reservations', ['status' => $status], ['id' => (int) $id]);
        } elseif ($kind === 'message') {
            db_update('contacts', ['status' => $status], ['id' => (int) $id]);
        }
    } catch (Throwable $e) {
        error_log("meta_set_status($kind, $id): " . $e->getMessage());
    }
}

/** Set fields on a record. */
function meta_set(string $kind, string $id, array $fields): void
{
    try {
        if ($kind === 'order') {
            $allowed = ['note', 'kds_started', 'kds_duration', 'kds_completed'];
            $data = [];
            foreach ($fields as $k => $v) {
                if (in_array($k, $allowed, true)) $data[$k] = $v;
            }
            if ($data) db_update('orders', $data, ['number' => $id]);
        } elseif ($kind === 'reservation') {
            $data = [];
            if (array_key_exists('status', $fields)) $data['status'] = (string) $fields['status'];
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('reservations', $data, ['id' => (int) $id]);
        } elseif ($kind === 'message') {
            $data = [];
            if (array_key_exists('status', $fields)) $data['status'] = (string) $fields['status'];
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('contacts', $data, ['id' => (int) $id]);
        } elseif ($kind === 'chat') {
            $data = [];
            if (array_key_exists('flagged', $fields)) $data['flagged'] = $fields['flagged'] ? 1 : 0;
            if (array_key_exists('note', $fields)) $data['note'] = (string) $fields['note'];
            if ($data) db_update('chat_sessions', $data, ['cid' => $id]);
        }
    } catch (Throwable $e) {
        error_log("meta_set($kind, $id): " . $e->getMessage());
    }
}

function meta_forget(string $kind, string $id): void
{
}

// ---------- orders ----------

/** Normalize a DB order row to the shape the admin/KDS pages expect. */
function order_from_row(array $r): array
{
    $items = is_string($r['items'] ?? null) ? json_decode($r['items'], true) : ($r['items'] ?? []);
    $history = is_string($r['status_history'] ?? null) ? json_decode($r['status_history'], true) : ($r['status_history'] ?? []);
    return [
        'number'      => $r['number'],
        'time'        => $r['created_at'],
        'ts'          => (int) strtotime($r['created_at']),
        'payment'     => $r['payment'],
        'type'        => $r['order_type'],
        'table'       => $r['table_number'] !== null ? (int) $r['table_number'] : null,
        'name'        => $r['name'],
        'phone'       => $r['phone'],
        'email'       => $r['email'],
        'address'     => $r['address'],
        'notes'       => $r['order_notes'] ?? '',
        'items'       => array_values(array_map('strval', is_array($items) ? $items : [])),
        'subtotal'    => (int) $r['subtotal'],
        'deliveryFee' => (int) $r['delivery_fee'],
        'total'       => (int) $r['total'],
        'status'      => isset(ORDER_STATUSES[$r['status']]) ? $r['status'] : 'new',
        'note'        => (string) ($r['note'] ?? ''),
        'history'     => is_array($history) ? $history : [],
        'kds_started'   => $r['kds_started'] ?? null,
        'kds_duration'  => $r['kds_duration'] ?? null,
        'kds_completed' => $r['kds_completed'] ?? null,
    ];
}

/** Orders saved from $from to $to (Y-m-d, inclusive), newest first. */
function orders_between(string $from, string $to): array
{
    $stmt = db()->prepare("SELECT * FROM orders WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->execute([$from, $to]);
    return array_map('order_from_row', $stmt->fetchAll());
}

/** The last $days days of orders, today included. */
function orders_recent(int $days): array
{
    return orders_between(date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days')), date('Y-m-d'));
}

/** One order by its six-character number; null if not found. */
function order_find(string $number): ?array
{
    $number = strtoupper($number);
    if (!preg_match('/^[A-F0-9]{6}$/', $number)) return null;
    $stmt = db()->prepare("SELECT * FROM orders WHERE number = ?");
    $stmt->execute([$number]);
    $row = $stmt->fetch();
    return $row ? order_from_row($row) : null;
}

/**
 * An order's item lines as saved by checkout ("2 x Wings (10) [BBQ] - note: x = $18.00"),
 * split into [['qty', 'label', 'mods', 'note', 'total' (cents)], ...]. A line that
 * doesn't fit that shape is kept whole.
 */
function order_lines(array $o): array
{
    $out = [];
    foreach ($o['items'] as $s) {
        $line = ['qty' => 1, 'label' => $s, 'mods' => '', 'note' => '', 'total' => null];
        if (preg_match('/^(\d+) x (.*) = \$([\d,]+(?:\.\d{2})?)$/su', $s, $m)) {
            $rest = $m[2];
            $line['qty'] = (int) $m[1];
            $line['total'] = (int) round(((float) str_replace(',', '', $m[3])) * 100);
            if (($p = strpos($rest, ' - note: ')) !== false) {
                $line['note'] = substr($rest, $p + 9);
                $rest = substr($rest, 0, $p);
            }
            if (preg_match('/^(.*) \[(.*)\]$/su', $rest, $mm)) {
                $line['mods'] = $mm[2];
                $rest = $mm[1];
            }
            $line['label'] = $rest;
        }
        $out[] = $line;
    }
    return $out;
}

// ---------- reservations, contact messages, newsletter ----------

const RESERVATION_AREAS = ['main-bar' => 'Main Bar', 'lounge' => 'Lounge', 'patio' => 'Outdoor Patio'];

function reservation_area(string $area): string
{
    return RESERVATION_AREAS[$area] ?? ($area !== '' ? $area : 'No preference');
}

function reservation_party(string $guests): string
{
    return $guests === '8' ? '8+ guests' : ($guests === '1' ? '1 guest' : $guests . ' guests');
}

/** Reservation requests, newest first, with id, status, note, ts (received) and at (the booking time). */
function reservations_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    $stmt = db()->query("SELECT * FROM reservations ORDER BY received DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $r['id'] = (string) $r['id'];
        $r['date'] = $r['reservation_date'];
        $r['time'] = $r['reservation_time'];
        $r['ts'] = (int) strtotime($r['received']);
        $r['at'] = (int) strtotime($r['reservation_date'] . ' ' . (preg_match('/^\d{1,2}:\d{2}$/', $r['reservation_time']) ? $r['reservation_time'] : '00:00'));
        $out[] = $r;
    }
    return $memo = $out;
}

/** Contact-form messages, newest first, with id, status, note and ts. */
function messages_all(bool $reload = false): array
{
    static $memo = null;
    if ($memo !== null && !$reload) return $memo;
    $stmt = db()->query("SELECT * FROM contacts ORDER BY received DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $r['id'] = (string) $r['id'];
        $r['ts'] = (int) strtotime($r['received']);
        $out[] = $r;
    }
    return $memo = $out;
}

/** Newsletter sign-ups, newest first. */
function subscribers_all(): array
{
    $stmt = db()->query("SELECT email, signed_up, page FROM subscribers ORDER BY signed_up DESC");
    $out = [];
    while ($r = $stmt->fetch()) {
        $out[] = [
            'email' => $r['email'],
            'ts'    => (int) strtotime($r['signed_up']),
            'page'  => $r['page'],
            'times' => 1,
        ];
    }
    return $out;
}

/** Delete a subscriber by email; returns true if removed. */
function subscriber_remove(string $email): bool
{
    $stmt = db()->prepare("DELETE FROM subscribers WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->rowCount() > 0;
}

/** Delete a contact message by id; returns true if removed. */
function message_delete(int $id): bool
{
    $stmt = db()->prepare("DELETE FROM contacts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// ---------- guest records kept while the database was down ----------

/** How many guest records are waiting in api/data/unsaved/ (see unsaved_keep in api/lib.php). */
function unsaved_count(): int
{
    $n = 0;
    foreach (glob(data_dir() . '/unsaved/*.jsonl') ?: [] as $file) {
        $n += count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }
    return $n;
}

/** Move the waiting records into the database; returns [moved, still waiting]. */
function unsaved_move(): array
{
    $moved = $left = 0;
    foreach (glob(data_dir() . '/unsaved/*.jsonl') ?: [] as $file) {
        $table = basename($file, '.jsonl');
        if (!isset(UNSAVED_TABLES[$table])) continue;
        // Work on a renamed copy, so a guest saving right now appends to a fresh file instead of being overwritten.
        $work = $file . '.moving-' . bin2hex(random_bytes(3));
        if (!@rename($file, $work)) continue;
        $keep = [];
        foreach (file($work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            try {
                db_insert($table, $row);
                $moved++;
            } catch (Throwable $e) {
                if ($e instanceof PDOException && $e->getCode() === '23000') { $moved++; continue; } // already in (same order number or email)
                $keep[] = $line; // database gone again, or a row it won't take: keep it for next time
                $left++;
            }
        }
        if ($keep) file_put_contents($file, implode("\n", $keep) . "\n", FILE_APPEND | LOCK_EX);
        @unlink($work);
    }
    return [$moved, $left];
}

// ---------- what needs attention ----------

/** Numbers for the sidebar badges: new orders, upcoming reservations waiting for an answer, unread messages. */
function admin_counts(): array
{
    $cutoff = date('Y-m-d', strtotime('-13 days'));
    $today = date('Y-m-d');

    $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE status = 'new' AND DATE(created_at) >= ?");
    $stmt->execute([$cutoff]);
    $orders = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND reservation_date >= ?");
    $stmt->execute([$today]);
    $reservations = (int) $stmt->fetchColumn();

    $messages = (int) db()->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();

    $wa = 0;
    try { $wa = (int) db()->query("SELECT COUNT(*) FROM waiter_calls WHERE status = 'active'")->fetchColumn(); } catch (\Exception $e) {}

    // Gift card requests to take payment for (and paid cards Square didn't create). This also
    // emails the cards whose day has come, as it runs on every admin page and every 30 seconds.
    $gc = 0;
    if (function_exists('giftcards_send_due')) {
        try {
            $gc = (int) db()->query("SELECT COUNT(*) FROM gift_cards WHERE status IN ('pending','error')")->fetchColumn();
            giftcards_send_due();
        } catch (\Exception $e) {}
    }

    return ['orders' => $orders, 'reservations' => $reservations, 'gift-cards' => $gc, 'messages' => $messages, 'waiter-assistance' => $wa];
}

// ---------- notification alerts ----------

function admin_notifications(): array
{
    $store = admin_store();
    if (empty($store['notificationsEnabled'])) return [];

    $alerts = [];
    $now = time();
    $today = date('Y-m-d');
    $alertNotStarted = (int) ($store['alertOrderNotStarted'] ?? 5);
    $alertLatePrep   = (int) ($store['alertOrderLatePrep'] ?? 0);
    $alertReadyIdle  = (int) ($store['alertOrderReadyIdle'] ?? 5);
    $alertEventSoon  = (int) ($store['alertEventSoon'] ?? 30);

    // Active orders (new, preparing, ready) from the last 24 hours.
    $cutoff = date('Y-m-d H:i:s', $now - 86400);
    $stmt = db()->prepare("SELECT * FROM orders WHERE status IN ('new','preparing','ready') AND created_at >= ? ORDER BY created_at ASC");
    $stmt->execute([$cutoff]);
    while ($r = $stmt->fetch()) {
        $o = order_from_row($r);
        $age = $now - $o['ts'];

        // Order not started.
        if ($o['status'] === 'new' && $alertNotStarted > 0 && $age >= $alertNotStarted * 60) {
            $alerts[] = [
                'type' => 'order-not-started',
                'tone' => 'crit',
                'message' => 'Order #' . $o['number'] . ' not started (' . floor($age / 60) . ' min)',
                'href' => '?page=orders&id=' . $o['number'],
                'ts' => $o['ts'],
            ];
        }

        // Order prep overtime.
        if ($o['status'] === 'preparing' && $o['kds_started']) {
            $started = strtotime($o['kds_started']);
            $expected = $o['kds_duration'] ?? ($store['prepMinutes'] ?? 25) * 60;
            if ($alertLatePrep > 0) $expected = $alertLatePrep * 60;
            $elapsed = $now - $started;
            if ($elapsed > $expected) {
                $alerts[] = [
                    'type' => 'order-late-prep',
                    'tone' => 'warn',
                    'message' => 'Order #' . $o['number'] . ' prep overtime (' . floor($elapsed / 60) . ' min)',
                    'href' => '?page=orders&id=' . $o['number'],
                    'ts' => $started,
                ];
            }
        }

        // Order ready but idle.
        if ($o['status'] === 'ready' && $alertReadyIdle > 0 && $o['kds_completed']) {
            $completed = strtotime($o['kds_completed']);
            $idle = $now - $completed;
            if ($idle >= $alertReadyIdle * 60) {
                $type = $o['type'] === 'delivery' ? 'order-ready-idle-delivery' : ($o['type'] === 'dine-in' ? 'order-ready-idle-dinein' : 'order-ready-idle-pickup');
                $label = $o['type'] === 'delivery' ? 'not dispatched' : ($o['type'] === 'dine-in' ? 'not served' : 'not picked up');
                $alerts[] = [
                    'type' => $type,
                    'tone' => 'warn',
                    'message' => 'Order #' . $o['number'] . ' ready ' . floor($idle / 60) . ' min, ' . $label,
                    'href' => '?page=orders&id=' . $o['number'],
                    'ts' => $completed,
                ];
            }
        }
    }

    // Upcoming events today.
    if ($alertEventSoon > 0) {
        try {
            $stmt = db()->prepare("SELECT name, time_label FROM events WHERE event_date = ?");
            $stmt->execute([$today]);
            while ($ev = $stmt->fetch()) {
                $t = trim((string) $ev['time_label']);
                if (preg_match('/(\d{1,2}):(\d{2})\s*(AM|PM)?/i', $t, $m)) {
                    $h = (int) $m[1];
                    $min = (int) $m[2];
                    $ampm = strtoupper($m[3] ?? '');
                    if ($ampm === 'PM' && $h < 12) $h += 12;
                    if ($ampm === 'AM' && $h === 12) $h = 0;
                    $eventTs = strtotime($today . ' ' . sprintf('%02d:%02d:00', $h, $min));
                    $diff = $eventTs - $now;
                    if ($diff > 0 && $diff <= $alertEventSoon * 60) {
                        $alerts[] = [
                            'type' => 'event-soon',
                            'tone' => 'info',
                            'message' => $ev['name'] . ' starts in ' . ceil($diff / 60) . ' min',
                            'href' => '?page=events',
                            'ts' => $eventTs,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // Pending reservations for today/tomorrow.
    try {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = db()->prepare("SELECT * FROM reservations WHERE status = 'pending' AND reservation_date IN (?, ?) ORDER BY reservation_date, reservation_time");
        $stmt->execute([$today, $tomorrow]);
        while ($r = $stmt->fetch()) {
            $alerts[] = [
                'type' => 'reservation-pending',
                'tone' => 'info',
                'message' => 'Reservation from ' . $r['name'] . ' for ' . fmt_date($r['reservation_date']) . ' needs a response',
                'href' => '?page=reservations&view=pending',
                'ts' => (int) strtotime($r['received']),
            ];
        }
    } catch (Throwable $e) {}

    // Active waiter assistance calls.
    try {
        $rows = db()->query("SELECT id, created_at, table_number, customer_name FROM waiter_calls WHERE status = 'active' ORDER BY created_at ASC")->fetchAll();
        foreach ($rows as $r) {
            $age = $now - strtotime($r['created_at']);
            $alerts[] = [
                'type' => 'waiter-call',
                'tone' => $age > 300 ? 'crit' : 'warn',
                'message' => 'Table ' . $r['table_number'] . ' needs assistance' . ($r['customer_name'] ? ' (' . $r['customer_name'] . ')' : '') . ' — ' . floor($age / 60) . ' min ago',
                'href' => '?page=waiter-assistance',
                'ts' => (int) strtotime($r['created_at']),
            ];
        }
    } catch (Throwable $e) {}

    return $alerts;
}

// ---------- the business's own details ----------

/** Name, address and phone as set in Store settings (with the usual fallbacks), for email templates and print-outs. */
function admin_store(): array
{
    static $store = null;
    if ($store === null) {
        $menu = load_menu();
        $store = array_filter((array) ($menu['store'] ?? []), 'is_scalar') + [
            'name' => "RMS", 'address' => '2266 Wilton Drive, Wilton Manors, FL 33305', 'phone' => '(954) 565-2526',
        ];
    }
    return $store;
}

// ---------- activity log ----------

// action code => [label, tone] for the Activity page and the dashboard.
const ADMIN_ACTIONS = [
    'login'              => ['Signed in', 'muted'],
    'login.failed'       => ['Wrong password', 'crit'],
    'logout'             => ['Signed out', 'muted'],
    'setup.database'     => ['Database connected', 'gold'],
    'order.status'       => ['Order status changed', 'gold'],
    'order.note'         => ['Order note saved', 'muted'],
    'reservation.status' => ['Reservation updated', 'gold'],
    'reservation.note'   => ['Reservation note saved', 'muted'],
    'message.status'     => ['Message updated', 'gold'],
    'message.note'       => ['Message note saved', 'muted'],
    'message.delete'     => ['Message deleted', 'crit'],
    'subscriber.remove'  => ['Subscriber removed', 'crit'],
    'chat.flag'          => ['Chat flagged', 'gold'],
    'chat.note'          => ['Chat note saved', 'muted'],
    'menu.items'         => ['Menu saved', 'gold'],
    'menu.add'           => ['Menu item added', 'gold'],
    'menu.custom'        => ['Added items saved', 'gold'],
    'menu.item'          => ['Item options saved', 'gold'],
    'event.save'         => ['Event saved', 'gold'],
    'event.delete'       => ['Event deleted', 'crit'],
    'event.publish'      => ['Events published', 'good'],
    'event.import'       => ['Events loaded from the page', 'muted'],
    'event.restore'      => ['Events page restored', 'gold'],
    'performer.save'     => ['Performer saved', 'gold'],
    'performer.delete'   => ['Performer deleted', 'crit'],
    'performer.publish'  => ['Performers published', 'good'],
    'performer.import'   => ['Performers loaded from the page', 'muted'],
    'performer.restore'  => ['Performers page restored', 'gold'],
    'dancer.save'        => ['Dancer saved', 'gold'],
    'dancer.delete'      => ['Dancer deleted', 'crit'],
    'dancer.publish'     => ['Dancers published', 'good'],
    'dancer.import'      => ['Dancers loaded from the page', 'muted'],
    'dancer.restore'     => ['Dancers page restored', 'gold'],
    'happy.save'         => ['Happy Hour saved', 'gold'],
    'chat.settings'      => ['Chat settings saved', 'gold'],
    'settings.save'      => ['Store settings saved', 'gold'],
    'delivery.save'      => ['Delivery settings saved', 'gold'],
    'delivery.zone'      => ['Delivery zone changed', 'gold'],
    'knowledge.save'     => ['Chat knowledge saved', 'gold'],
    'knowledge.restore'  => ['Chat knowledge restored', 'gold'],
    'order.dine-in'      => ['Dine-in order created', 'gold'],
    'order.takeaway'     => ['Takeaway order created', 'gold'],
    'order.delivery'     => ['Delivery order created', 'gold'],
    'happy.add'          => ['Happy Hour item added', 'gold'],
    'password.change'    => ['Password changed', 'warn'],
    'password.change.failed' => ['Password change refused', 'crit'],
    'alerts.settings'    => ['Email alerts settings saved', 'gold'],
    'alerts.test'        => ['Test alert', 'muted'],
    'kds.login'          => ['KDS signed in', 'muted'],
    'kds.login.failed'   => ['KDS wrong password', 'crit'],
    'kds.start'          => ['KDS order started', 'gold'],
    'kds.complete'       => ['KDS order completed', 'good'],
    'kds.recall'         => ['KDS order recalled', 'warn'],
    'kds.settings'       => ['KDS settings saved', 'gold'],
    'waiter.call'        => ['Waiter called', 'gold'],
    'waiter.resolve'     => ['Waiter call resolved', 'muted'],
    'db.unsaved'         => ['Backed-up records moved into the database', 'good'],
    'giftcard.issue'     => ['Gift card issued', 'gold'],
    'giftcard.activate'  => ['Gift card paid & activated', 'good'],
    'giftcard.redeem'    => ['Gift card redeemed', 'gold'],
    'giftcard.adjust'    => ['Gift card balance changed', 'warn'],
    'giftcard.void'      => ['Gift card voided', 'crit'],
    'giftcard.email'     => ['Gift card emailed', 'muted'],
    'giftcard.edit'      => ['Gift card recipient changed', 'muted'],
    'giftcard.note'      => ['Gift card note saved', 'muted'],
    'giftcard.retry'     => ['Gift card created in Square', 'good'],
    'giftcard.settings'  => ['Gift card settings saved', 'gold'],
    'giftcard.design'    => ['Gift card designs changed', 'gold'],
    'user.add'           => ['Team account added', 'gold'],
    'user.edit'          => ['Team account changed', 'gold'],
    'user.password'      => ['Team account password reset', 'warn'],
    'user.disable'       => ['Team account switched off', 'warn'],
    'user.enable'        => ['Team account switched on', 'good'],
    'user.delete'        => ['Team account deleted', 'crit'],
];

function admin_action_label(string $code): array
{
    return ADMIN_ACTIONS[$code] ?? [$code, 'muted'];
}

/**
 * Note what someone did in the admin: $action is a short code (see the Activity page),
 * $detail plain text. $changes lists each individual change ("Wings: price $6.99 → $7.49");
 * the log keeps a short summary of them, the email alert lists them all. $user is who did
 * it: by default whoever is signed in (team_actor()), '' for a guest on the website.
 */
function admin_log(string $action, string $detail = '', array $changes = [], ?string $user = null): void
{
    if ($changes) {
        $summary = implode('; ', $changes);
        $detail = trim($detail . ($detail !== '' ? ': ' : '') . $summary);
        if (mb_strlen($detail) > 300) $detail = mb_substr($detail, 0, 280) . '… (' . count($changes) . ' changes)';
    }
    $user = mb_substr($user ?? (function_exists('team_actor') ? team_actor() : ''), 0, 30);
    try {
        db_insert('admin_activity', [
            'action'   => $action,
            'username' => $user,
            'detail'   => mb_substr($detail, 0, 300),
            'ip'       => client_ip(),
        ]);
        // Activity log trim disabled — all entries are kept until manually deleted.
    } catch (Throwable $e) {
        error_log('admin_log: ' . $e->getMessage());
    }
    change_alert($action, $detail, $changes, null, $user);
}

// ---------- email alerts for changes ----------

/** Sign-ins and sign-outs (and refused password changes): emailed only when 'signins' is on. */
const ALERT_SIGNIN_ACTIONS = ['login', 'login.failed', 'logout', 'kds.login', 'kds.login.failed', 'password.change.failed'];
/** Kitchen and floor work that happens all day (orders already email on their own): only when 'kitchen' is on. */
const ALERT_KITCHEN_ACTIONS = ['kds.start', 'kds.complete', 'kds.recall', 'waiter.call', 'waiter.resolve', 'order.dine-in', 'order.takeaway', 'order.delivery'];

/** Email alert settings: on unless switched off, sent to 'email' (blank = config notify_email). */
function alert_settings(): array
{
    try {
        $s = db_setting('change_alerts');
    } catch (Throwable $e) {
        $s = null;
    }
    return array_merge(['enabled' => true, 'email' => '', 'signins' => false, 'kitchen' => false], is_array($s) ? $s : []);
}

/** Where alerts go: the address set on the Activity page, else the restaurant's notify_email. */
function alert_recipient(array $s): string
{
    $to = trim((string) ($s['email'] ?? ''));
    if ($to === '') $to = trim((string) (config()['notify_email'] ?? ''));
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

/** Should this action be emailed under these settings? */
function alert_wanted(string $action, array $s): bool
{
    if (empty($s['enabled'])) return false;
    if (in_array($action, ALERT_SIGNIN_ACTIONS, true)) return !empty($s['signins']);
    if (in_array($action, ALERT_KITCHEN_ACTIONS, true)) return !empty($s['kitchen']);
    return true;
}

/**
 * Email the team about a change just logged. A mail problem never stops the change itself.
 * Passing $settings sends it to that recipient whatever the switches say (test alerts,
 * and the last alert when alerts are switched off).
 */
function change_alert(string $action, string $detail, array $changes = [], ?array $settings = null, ?string $user = null): bool
{
    $s = $settings ?? alert_settings();
    if ($settings === null && !alert_wanted($action, $s)) return false;
    $to = alert_recipient($s);
    if ($to === '') return false;

    [$label] = admin_action_label($action);
    $where = defined('KDS') ? 'Kitchen display' : (defined('ADMIN') ? 'Admin panel' : 'Website');
    $first = $changes ? $changes[0] . (count($changes) > 1 ? ' (+' . (count($changes) - 1) . ' more)' : '') : $detail;
    $subject = 'RMS alert: ' . $label . ($first !== '' ? ' — ' . $first : '');
    if (mb_strlen($subject) > 140) $subject = mb_substr($subject, 0, 137) . '…';

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^a-z0-9.:\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    $link = $host !== '' ? ($https ? 'https://' : 'http://') . $host . site_base() . 'admin/?page=activity' : '';

    $body = "A change was just made in RMS system.\n\n"
        . 'What:    ' . $label . "\n"
        . ($changes
            ? "Changes:\n" . implode("\n", array_map(fn($c) => '  - ' . $c, $changes)) . "\n"
            : 'Details: ' . ($detail !== '' ? $detail : '(none)') . "\n")
        . 'When:    ' . date('l, M j, Y g:i A') . "\n"
        . 'Who:     ' . (($user ?? (function_exists('team_actor') ? team_actor() : '')) ?: 'a guest on the website') . "\n"
        . 'Where:   ' . $where . "\n"
        . 'From:    ' . (client_ip() ?: 'unknown') . " (IP address)\n"
        . ($link !== '' ? "\nFull activity log: $link\n" : '')
        . "\nIf you didn't expect this change, check the activity log and consider changing the admin password.\n"
        . "These alerts can be switched off under System → Activity log.\n";

    $headers = 'From: RMS Alerts <' . mail_from() . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (!$ok) error_log("change_alert: could not email $action alert");
    return $ok;
}

/** The newest $limit activity entries, newest first. */
/** The latest log entries, optionally only one person's ($user = a username, or '-' for guests on the website). */
function admin_activity(int $limit = 100, string $user = ''): array
{
    $where = $user === '' ? '' : 'WHERE username = ?';
    $stmt = db()->prepare("SELECT logged_at AS time, action, username, detail, ip FROM admin_activity $where ORDER BY id DESC LIMIT ?");
    $stmt->execute($user === '' ? [$limit] : [$user === '-' ? '' : $user, $limit]);
    return $stmt->fetchAll();
}

/** Every username that appears in the log, for the Activity page's filter. */
function admin_activity_users(): array
{
    return db()->query("SELECT DISTINCT username FROM admin_activity WHERE username <> '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
}
