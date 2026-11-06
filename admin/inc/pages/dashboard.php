<?php
/** Dashboard: what needs doing now, how the last two weeks went, and whether the site is set up properly. */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../events.php';

/** Is one of today's [open, close] slots running right now (restaurant time)? Same rule as checkout. */
function admin_hours_open(array $hours, string $tz): bool
{
    $now = new DateTime('now', new DateTimeZone($tz));
    $time = $now->format('H:i:s');
    foreach ($hours[strtoupper($now->format('D'))] ?? [] as $slot) {
        if ($time >= $slot['open'] && $time < $slot['close']) return true;
    }
    return false;
}

/** Today's hours as "11 AM – 10 PM", or "Closed today". */
function admin_hours_today(array $hours, string $tz): string
{
    $slots = $hours[strtoupper((new DateTime('now', new DateTimeZone($tz)))->format('D'))] ?? [];
    if (!$slots) return 'Closed today';
    return implode(', ', array_map(fn($s) => fmt_time12(substr($s['open'], 0, 5)) . ' – ' . fmt_time12(substr($s['close'], 0, 5)), $slots));
}

/** One line in a "needs attention" list. */
function attention_row(string $icon, string $titleHtml, string $subHtml, string $href): string
{
    return '<li><a href="' . h($href) . '"><span class="ar-ico">' . icon($icon, 16) . '</span><span class="ar-main"><b>' . $titleHtml . '</b><small>' . $subHtml . '</small></span>' . icon('right', 14) . '</a></li>';
}

/** A checklist row: $state ok / warn / off. */
function check_row(string $state, string $label, string $detailHtml = '', string $href = ''): string
{
    $ico = $state === 'ok' ? 'check' : ($state === 'warn' ? 'alert' : 'x');
    $inner = '<span class="ck ck-' . $state . '">' . icon($ico, 14) . '</span><span class="ck-main"><b>' . h($label) . '</b>' . ($detailHtml !== '' ? '<small>' . $detailHtml . '</small>' : '') . '</span>';
    return '<li>' . ($href !== '' ? '<a href="' . h($href) . '">' . $inner . icon('right', 14) . '</a>' : '<div>' . $inner . '</div>') . '</li>';
}

function admin_page_dashboard(): void
{
    if (is_post()) {
        require_csrf();
        if (($_POST['action'] ?? '') === 'move-unsaved') {
            [$moved, $left] = unsaved_move();
            admin_log('db.unsaved', $moved . ' moved' . ($left ? ', ' . $left . ' still waiting' : ''));
            flash($left ? 'Moved ' . plural($moved, 'record') . ' into the database; ' . $left . ' could not be moved yet.' : 'Moved ' . plural($moved, 'record') . ' into the database.', $left ? 'err' : 'ok');
        }
        redirect(admin_url('dashboard'));
    }

    $today = date('Y-m-d');
    $cfg = config();

    // ---- orders ----
    $orders14 = orders_recent(14);
    $ordersToday = array_values(array_filter($orders14, fn($o) => date('Y-m-d', $o['ts']) === $today && $o['status'] !== 'cancelled'));
    $salesToday = array_sum(array_column($ordersToday, 'total'));
    $newOrders = array_values(array_filter($orders14, fn($o) => $o['status'] === 'new'));
    $inProgress = count(array_filter($orders14, fn($o) => in_array($o['status'], ['preparing', 'ready'], true)));

    // ---- reservations, messages, subscribers ----
    $pendingRes = array_values(array_filter(reservations_all(), fn($r) => $r['status'] === 'pending' && $r['date'] >= $today));
    usort($pendingRes, fn($a, $b) => $a['at'] <=> $b['at']);
    $confirmedRes = count(array_filter(reservations_all(), fn($r) => $r['status'] === 'confirmed' && $r['date'] >= $today));
    $unread = array_values(array_filter(messages_all(), fn($m) => $m['status'] === 'new'));
    $subs = subscribers_all();
    $subsWeek = count(array_filter($subs, fn($s) => $s['ts'] >= strtotime('-7 days')));

    // ---- chats (last 14 days) ----
    $convos = chat_log_conversations(14);
    $chatsToday = 0;
    $questionsToday = 0;
    $chatPerDay = [];
    foreach ($convos as $c) {
        $end = date('Y-m-d', strtotime($c['end']));
        $chatPerDay[$end] = ($chatPerDay[$end] ?? 0) + 1;
        if ($end === $today) { $chatsToday++; $questionsToday += count($c['turns']); }
    }
    try { $flagged = (int) db()->query("SELECT COUNT(*) FROM chat_sessions WHERE flagged = 1")->fetchColumn(); } catch (Throwable $e) { $flagged = 0; }

    // ---- the two charts ----
    $orderPoints = [];
    $chatPoints = [];
    $perDay = [];
    foreach ($orders14 as $o) {
        if ($o['status'] === 'cancelled') continue;
        $d = date('Y-m-d', $o['ts']);
        $perDay[$d] = ($perDay[$d] ?? 0) + 1;
    }
    for ($i = 13; $i >= 0; $i--) {
        $t = strtotime("-$i days");
        $d = date('Y-m-d', $t);
        $label = date('j', $t);
        $tip = date('D, M j', $t);
        $orderPoints[] = ['label' => $label, 'tip' => $tip, 'value' => $perDay[$d] ?? 0];
        $chatPoints[] = ['label' => $label, 'tip' => $tip, 'value' => $chatPerDay[$d] ?? 0];
    }

    // ---- the website right now ----
    $base = load_menu_base();
    $menu = load_menu();
    $store = $menu['store'] ?? [];
    $tz = (string) ($store['timezone'] ?? 'America/New_York');
    $ov = read_menu_overrides();
    $hiddenItems = count(array_filter($ov['items'] ?? [], fn($o) => !empty($o['hidden'])));
    $customItems = count($ov['custom'] ?? []);
    $hh = happy_hour_data();
    $nowMin = (int) date('G') * 60 + (int) date('i');
    $hhToday = empty($hh['days']) || in_array(strtoupper(date('D')), $hh['days'], true);
    $hhLive = !empty($hh['enabled']) && $hhToday && $nowMin >= hh_min($hh['start']) && $nowMin < hh_min($hh['end']);
    $pickupOn = $store && ($store['pickupEnabled'] ?? true);
    $pickupOpen = $pickupOn && admin_hours_open($store['pickupHours'] ?? [], $tz);
    $deliveryOpen = $store && !empty($store['deliveryEnabled']) && admin_hours_open($store['deliveryHours'] ?? [], $tz);
    $codMode = cod_mode();

    // The Events page: how many shows, and whether the website has the latest.
    $evData = events_started() ? events_store() : null;
    $evState = $evData ? events_state($evData)['state'] : '';
    $evCount = $evData ? count(array_filter($evData['events'], fn($e) => !empty($e['active']))) : 0;
    $evRow = $evData === null
        ? check_row('off', 'Events: not opened yet', 'Manage the weekly lineup and special nights', admin_url('events'))
        : check_row($evState === 'ready' ? 'ok' : 'warn', 'Events: ' . plural($evCount, 'event') . ' on the lineup',
            $evState === 'ready' ? 'The website shows this lineup' : ($evState === 'changes' ? 'Changes are waiting to be published' : ($evState === 'drift' ? 'The page was edited outside the admin' : 'Publishing isn\'t set up')), admin_url('events'));

    $now = '<ul class="rows">'
        . check_row($pickupOpen ? 'ok' : 'off', 'Take Away: ' . ($store && !$pickupOn ? 'switched off' : ($pickupOpen ? 'taking orders' : 'closed')),
            h(!$store ? 'Menu unavailable' : ($pickupOn ? admin_hours_today($store['pickupHours'] ?? [], $tz) : 'Turn it on in Store settings')), admin_url('settings'))
        . check_row(empty($store['deliveryEnabled']) ? 'off' : ($deliveryOpen ? 'ok' : 'off'),
            'Delivery: ' . (empty($store['deliveryEnabled']) ? 'switched off' : ($deliveryOpen ? 'taking orders' : 'closed')),
            h(!empty($store['deliveryEnabled']) ? admin_hours_today($store['deliveryHours'] ?? [], $tz) . ' · ' . ($store['delivery']['feeText'] ?? 'fee ' . fmt_money((int) ($store['deliveryFee'] ?? 0))) : 'Turn it on on the Delivery page'), admin_url('delivery'))
        . check_row($hhLive ? 'ok' : 'off', 'Happy Hour: ' . (empty($hh['enabled']) ? 'hidden on the site' : ($hhLive ? 'on now' : 'not right now')),
            h((empty($hh['days']) ? '' : days_label($hh['days']) . ' · ') . hh_fmt($hh['start']) . ' – ' . hh_fmt($hh['end']) . ' · ' . count($hh['items']) . ' items'), admin_url('happy-hour'))
        . $evRow
        . check_row('ok', 'Menu: ' . ($base ? plural(count($base['items']), 'item') : 'unavailable'),
            h(($hiddenItems ? $hiddenItems . ' hidden' : 'none hidden') . ($customItems ? ' · ' . plural($customItems, 'item') . ' you added' : '')), admin_url('menu'))
        . check_row('ok', 'Payment: ' . ($codMode ? 'cash on delivery' : 'card through Square'), $codMode ? 'No online charge; guests pay when they collect' : 'Guests pay by card at checkout')
        . '</ul>';

    // ---- setup checks ----
    $dataOk = is_dir(data_dir()) ? is_writable(data_dir()) : @mkdir(data_dir(), 0750, true);
    $dbVersion = (string) db()->query('SELECT VERSION()')->fetchColumn();
    $missingTables = array_values(array_diff(db_expected_tables(), db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
    $unsaved = unsaved_count();
    $provider = (string) $cfg['chat_provider'];
    $keyName = $provider . '_api_key';
    $providerReady = $provider === 'ollama' || !empty($cfg[$keyName === 'claude_api_key' ? 'anthropic_api_key' : $keyName]);
    $limit = (int) $cfg['chat_daily_limit'];
    $used = (int) @file_get_contents(CACHE_DIR . '/chat-count-' . date('Y-m-d') . '.txt');
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || substr($host, -6) === '.local' || substr($host, -5) === '.test';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $checks = '<ul class="rows">'
        . ($missingTables ? check_row('warn', 'Database tables missing', 'Import <code>api/setup.sql</code> in phpMyAdmin (missing: ' . h(implode(', ', $missingTables)) . ')') : '')
        . check_row($unsaved ? 'warn' : 'ok', 'Database connected', h((stripos($dbVersion, 'mariadb') !== false ? 'MariaDB ' : 'MySQL ') . preg_replace('/-.*$/', '', $dbVersion)) . ($unsaved ? ' · ' . plural($unsaved, 'record') . ' still in the backup file' : ' · orders, messages and settings are stored here'))
        . check_row($dataOk ? 'ok' : 'warn', $dataOk ? 'Backup folder is writable' : 'Can\'t write the backup folder', $dataOk ? 'Sign-ins, and guest records if the database is ever down' : 'The folder api/data/ must be writable by the website')
        . check_row(trim((string) $cfg['notify_email']) !== '' ? 'ok' : 'warn', trim((string) $cfg['notify_email']) !== '' ? 'Email alerts are on' : 'Email alerts are off',
            trim((string) $cfg['notify_email']) !== '' ? 'New orders and messages are also emailed to you' : 'Orders and messages only show here. Set <code>notify_email</code> in api/config.php to get them by email too.')
        . check_row($providerReady ? 'ok' : 'warn', 'Chat assistant: ' . $provider . ($cfg['chat_fallback'] ? ' + backup' : ''),
            $providerReady ? ($limit > 0 ? h(number_format($used) . ' of ' . number_format($limit) . ' messages used today') : 'No daily limit') : 'No API key set for this provider')
        . check_row($cfg['chat_log'] ? 'ok' : 'warn', $cfg['chat_log'] ? 'Chats are recorded' : 'Chat recording is off', $cfg['chat_log'] ? 'Saved in the database for Conversations and Insights' : 'Conversations and Insights stay empty')
        . check_row($https || $local ? 'ok' : 'warn', $https ? 'Secure connection (HTTPS)' : ($local ? 'Running on this computer' : 'Not using HTTPS'),
            $https || $local ? '' : 'Turn on HTTPS so the admin password isn\'t sent in the clear')
        . check_row(team_password_strong() ? 'ok' : 'warn', team_password_strong() ? 'Strong admin password' : 'Short admin password', team_password_strong() ? '' : 'Use at least 12 characters (System → Change password)')
        . '</ul>';

    // ---- needs attention ----
    $need = '';
    if ($unsaved) {
        $need .= '<li><form method="post">' . csrf_field()
            . '<span class="ar-ico">' . icon('alert', 16) . '</span><span class="ar-main"><b>' . plural($unsaved, 'guest record') . ' kept in the backup file</b>'
            . '<small>Saved while the database was down. Move them in so they show up in the admin.</small></span>'
            . '<button class="btn sm primary" type="submit" name="action" value="move-unsaved">Move into database</button></form></li>';
    }
    foreach (array_slice($newOrders, 0, 5) as $o) {
        $need .= attention_row('bag', 'New order #' . h($o['number']) . ' &middot; ' . h($o['name']), h(ucfirst($o['type']) . ' · ' . fmt_money($o['total']) . ' · ' . fmt_ago($o['ts'])), admin_url('orders', ['id' => $o['number']]));
    }
    foreach (array_slice($pendingRes, 0, 5) as $r) {
        $need .= attention_row('calendar', 'Reservation request &middot; ' . h($r['name']), h(fmt_date($r['date']) . ' at ' . fmt_time12($r['time']) . ' · ' . reservation_party($r['guests'])), admin_url('reservations', ['id' => $r['id']]));
    }
    foreach (array_slice($unread, 0, 5) as $m) {
        $need .= attention_row('mail', 'Message from ' . h($m['name'] !== '' ? $m['name'] : $m['email']), h(snip($m['message'], 70)), admin_url('messages', ['id' => $m['id']]));
    }
    $needBody = $need !== '' ? '<ul class="rows attn">' . $need . '</ul>' : empty_state('All caught up', 'New orders, reservation requests and messages show up here.', 'check');
    $moreLinks = '';
    if (count($newOrders) > 5) $moreLinks .= '<a href="' . h(admin_url('orders', ['s' => 'new'])) . '">' . (count($newOrders) - 5) . ' more new orders</a>';
    if (count($pendingRes) > 5) $moreLinks .= '<a href="' . h(admin_url('reservations', ['view' => 'pending'])) . '">' . (count($pendingRes) - 5) . ' more reservations</a>';
    if (count($unread) > 5) $moreLinks .= '<a href="' . h(admin_url('messages', ['view' => 'new'])) . '">' . (count($unread) - 5) . ' more messages</a>';
    if ($flagged) $moreLinks .= '<a href="' . h(admin_url('chats', ['f' => 'flagged'])) . '">' . plural($flagged, 'chat') . ' flagged for follow-up</a>';

    // ---- recent activity (the owner and Admin accounts only, like the Activity log) ----
    $act = '';
    foreach (admin_is_admin() ? admin_activity(6) : [] as $a) {
        [$label, $tone] = admin_action_label((string) $a['action']);
        $act .= '<li><div><span class="ck-main"><b>' . h($label) . '</b>' . ($a['detail'] !== '' ? '<small>' . h(snip((string) $a['detail'], 70)) . '</small>' : '') . '</span></div><time class="muted small">' . h(fmt_ago((int) strtotime((string) $a['time']))) . '</time></li>';
    }

    $hour = (int) date('G');
    $greeting = $hour < 5 ? 'Late night' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));

    $body = page_head($greeting, h(date('l, F j')) . ' &middot; here\'s what\'s happening at ' . h($store['name'] ?? "RMS") . '.')
        . '<div class="stats">'
        . stat_tile('Orders today', number_format(count($ordersToday)), admin_url('orders', ['days' => 1, 's' => 'all']), h(fmt_money($salesToday) . ' before tax'), 'bag')
        . stat_tile('New orders', number_format(count($newOrders)), admin_url('orders', ['s' => 'new']), h($inProgress . ' in progress'), 'clock', count($newOrders) ? 'attn' : '')
        . stat_tile('Reservation requests', number_format(count($pendingRes)), admin_url('reservations', ['view' => 'pending']), h($confirmedRes . ' confirmed, upcoming'), 'calendar', count($pendingRes) ? 'attn' : '')
        . stat_tile('Unread messages', number_format(count($unread)), admin_url('messages', ['view' => 'new']), '', 'mail', count($unread) ? 'attn' : '')
        . stat_tile('Chats today', number_format($chatsToday), admin_url('chats', ['days' => 1]), h(plural($questionsToday, 'question')), 'chat')
        . stat_tile('Subscribers', number_format(count($subs)), admin_url('subscribers'), h('+' . $subsWeek . ' this week'), 'users')
        . '</div>'
        . '<div class="dash">'
        . '<div class="stack">'
        .   card('Needs your attention', $needBody . ($moreLinks !== '' ? '<div class="morelinks">' . $moreLinks . '</div>' : ''))
        .   '<div class="grid g2">' . card('', column_chart('Orders per day', 'Last 14 days, cancelled orders left out', $orderPoints, 'order'), '', 'chartcard')
        .   card('', column_chart('Chats per day', 'Last 14 days, conversations', $chatPoints, 'chat'), '', 'chartcard') . '</div>'
        . '</div>'
        . '<div class="stack">'
        .   card('The website right now', $now)
        .   card('Setup checks', $checks)
        .   (!admin_is_admin() ? '' : card('Recent activity', $act !== '' ? '<ul class="rows act">' . $act . '</ul><div class="morelinks"><a href="' . h(admin_url('activity')) . '">See all activity</a></div>' : '<p class="muted">Nothing yet.</p>'))
        . '</div></div>';
    admin_render('Dashboard', $body, 'dashboard');
}
