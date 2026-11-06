<?php
/**
 * Orders: what guests ordered on the website (cash on delivery), and where each
 * order is up to. The order itself is saved by api/checkout.php and never changed
 * here; the team's status and note go in the side file (see store.php).
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** The list's filters from a query string or a form, for links and for coming back after a click. */
function orders_filters(array $src): array
{
    $out = [];
    foreach (['s', 'days', 'from', 'to', 'q', 'pg'] as $k) {
        if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') $out[$k] = mb_substr($src[$k], 0, 80);
    }
    return $out;
}

/** status => [next status, button label] for the one-tap button on an open order. */
function order_next(array $o): ?array
{
    switch ($o['status']) {
        case 'new':       return ['preparing', 'Start'];
        case 'preparing': return ['ready', 'Mark ready'];
        case 'ready':     return ['completed', $o['type'] === 'delivery' ? 'Delivered' : ($o['type'] === 'dine-in' ? 'Served' : 'Picked up')];
    }
    return null;
}

/** Orders in a period that the guest's phone or email also placed (other than $o), newest first. */
function order_repeat_orders(array $o): array
{
    $digits = substr(preg_replace('/\D/', '', $o['phone']), -10);
    $out = [];
    foreach (orders_between(date('Y-m-d', strtotime('-400 days')), date('Y-m-d')) as $x) {
        if ($x['number'] === $o['number']) continue;
        $samePhone = strlen($digits) === 10 && substr(preg_replace('/\D/', '', $x['phone']), -10) === $digits;
        $sameEmail = $o['email'] !== '' && strcasecmp($x['email'], $o['email']) === 0;
        if ($samePhone || $sameEmail) $out[] = $x;
    }
    return $out;
}

function order_type_badge(string $type): string
{
    if ($type === 'delivery') return '<span class="chip">' . icon('truck', 13) . ' Delivery</span>';
    if ($type === 'dine-in') return '<span class="chip">' . icon('home', 13) . ' Dine-in</span>';
    return '<span class="chip">' . icon('bag', 13) . ' Pickup</span>';
}

function admin_page_orders(): void
{
    if (is_post()) admin_orders_post();
    $num = strtoupper(get_str('id', 6));
    if ($num !== '') admin_order_detail($num); // ends the request
    admin_orders_list();
}

/** Change an order's status or note, then back to the list (with its filters) or the order. */
function admin_orders_post(): void
{
    require_csrf();
    parse_str((string) ($_POST['back'] ?? ''), $b);
    $filters = orders_filters(is_array($b) ? $b : []);
    $num = strtoupper(post_str('id', 6));
    $o = order_find($num);
    if (!$o) {
        flash('That order wasn\'t found.', 'err');
        redirect(admin_url('orders', $filters));
    }
    $back = ($_POST['from'] ?? '') === 'list' ? admin_url('orders', $filters) : admin_url('orders', ['id' => $num]);
    switch ($_POST['action'] ?? '') {
        case 'status':
            $status = (string) ($_POST['status'] ?? '');
            if (isset(ORDER_STATUSES[$status])) {
                meta_set_status('order', $num, $status);
                admin_log('order.status', '#' . $num . ' (' . $o['name'] . '): ' . strtolower(ORDER_STATUSES[$status][0]));
                flash('Order #' . $num . ' marked ' . strtolower(ORDER_STATUSES[$status][0]) . '.');
            }
            break;
        case 'note':
            meta_set('order', $num, ['note' => post_str('note', 1000)]);
            admin_log('order.note', '#' . $num);
            flash('Note saved.');
            break;
    }
    redirect($back);
}

function admin_order_detail(string $num): void
{
    $o = order_find($num);
    if (!$o) {
        admin_render('Order', '<p><a href="' . h(admin_url('orders')) . '">&larr; Orders</a></p>' . empty_state('Order not found', 'Check the order number.', 'bag'), 'orders');
    }
    $delivery = $o['type'] === 'delivery';

    // Items, as a table.
    $rows = '';
    foreach (order_lines($o) as $l) {
        $rows .= '<tr><td class="qty">' . (int) $l['qty'] . '&times;</td><td><b>' . h($l['label']) . '</b>'
            . ($l['mods'] !== '' ? '<div class="cell-sub">' . h($l['mods']) . '</div>' : '')
            . ($l['note'] !== '' ? '<div class="cell-note">' . icon('edit', 12) . ' ' . h($l['note']) . '</div>' : '')
            . '</td><td class="num">' . ($l['total'] !== null ? h(fmt_money($l['total'])) : '') . '</td></tr>';
    }
    $totals = '<tr class="sum"><td colspan="2">Subtotal</td><td class="num">' . h(fmt_money($o['subtotal'])) . '</td></tr>'
        . ($delivery ? '<tr class="sum"><td colspan="2">Delivery fee</td><td class="num">' . h(fmt_money($o['deliveryFee'])) . '</td></tr>' : '')
        . '<tr class="sum grand"><td colspan="2">Total before sales tax</td><td class="num">' . h(fmt_money($o['total'])) . '</td></tr>';

    // Status: the buttons and a short history.
    $next = order_next($o);
    $buttons = '';
    foreach (ORDER_STATUSES as $key => [$label]) {
        if ($key === $o['status']) continue;
        $cls = 'sm' . ($next && $next[0] === $key ? ' primary' : ($key === 'cancelled' ? ' danger' : ''));
        $text = $next && $next[0] === $key ? $next[1] : ($key === 'new' ? 'Back to new' : ($key === 'cancelled' ? 'Cancel order' : 'Mark ' . strtolower($label)));
        $buttons .= btn_submit($text, $cls, 'status', $key, '', $key === 'cancelled' ? 'Cancel order #' . $num . '?' : '');
    }
    $timeline = '<li><b>Placed</b><span>' . h(fmt_when($o['ts'])) . '</span></li>';
    foreach ($o['history'] as $step) {
        if (!isset($step['s'], $step['t'], ORDER_STATUSES[$step['s']])) continue;
        $timeline .= '<li><b>' . h(ORDER_STATUSES[$step['s']][0]) . '</b><span>' . h(fmt_when((string) $step['t'])) . '</span></li>';
    }

    // Other orders from the same guest.
    $repeat = order_repeat_orders($o);
    $repeatHtml = '';
    foreach (array_slice($repeat, 0, 6) as $x) {
        $repeatHtml .= '<li><a href="' . h(admin_url('orders', ['id' => $x['number']])) . '">#' . h($x['number']) . '</a><span>' . h(fmt_when($x['ts'])) . ' &middot; ' . h(fmt_money($x['total'])) . '</span></li>';
    }

    $body = '<p class="back no-print"><a href="' . h(admin_url('orders')) . '">' . icon('left', 15) . ' Orders</a></p>'
        . page_head('Order #' . $o['number'], h(fmt_when($o['ts'])) . ' &middot; ' . h(fmt_ago($o['ts'])),
            status_badge(ORDER_STATUSES, $o['status']) . ' ' . order_type_badge($o['type'])
            . '<button class="btn sm no-print" type="button" data-print>' . icon('print', 16) . '<span>Print</span></button>')
        . '<div class="split"><div class="stack">'
        .   card('Items', '<div class="table flush"><table class="tbl items"><tbody>' . $rows . '</tbody><tfoot>' . $totals . '</tfoot></table></div>')
        .   (isset($o['notes']) && $o['notes'] !== '' ? card('Special Instructions', '<p style="font-weight:600;color:var(--warn)">' . h($o['notes']) . '</p>') : '')
        .   card('Note for the team', form_open(admin_url('orders'), 'no-print')
                . '<input type="hidden" name="id" value="' . h($num) . '"><input type="hidden" name="action" value="note">'
                . '<label class="field wide"><span class="sr">Note</span><textarea name="note" rows="3" maxlength="1000" placeholder="Only the team sees this: allergies noted, driver, substitutions…">' . h($o['note']) . '</textarea></label>'
                . '<div class="bar">' . btn_submit('Save note', 'primary') . '</div></form>'
                . ($o['note'] !== '' ? '<div class="print-only">' . h($o['note']) . '</div>' : ''), '', 'no-print-empty')
        . '</div><div class="stack">'
        .   card('Guest', kv(
                $o['type'] === 'dine-in'
                    ? [['Table', '<b>Table #' . (int) ($o['table'] ?? 0) . '</b>'], ['Name', h($o['name'])], ['Type', 'Dine-in']]
                    : [
                        ['Name', h($o['name'])],
                        ['Phone', $o['phone'] !== '' ? tel_link($o['phone']) : ''],
                        ['Email', $o['email'] !== '' ? mail_link($o['email']) : ''],
                        [$delivery ? 'Deliver to' : 'Pickup', $delivery ? map_link($o['address']) : 'The guest collects at the bar'],
                    ]
            ))
        .   card('Payment', $o['type'] === 'dine-in'
                ? '<p class="pay"><b>Dine-in</b></p><p class="muted">Guest pays at the table.</p>'
                : '<p class="pay"><b>Cash on delivery</b></p><p class="muted">Collect <b class="cash">' . h(fmt_money($o['total'])) . '</b> plus sales tax when the guest '
                . ($delivery ? 'receives the order' : 'picks up') . '. No card was charged online.</p>')
        .   card('Status', form_open(admin_url('orders'), 'no-print')
                . '<input type="hidden" name="id" value="' . h($num) . '"><input type="hidden" name="action" value="status">'
                . '<div class="btns">' . $buttons . '</div></form><ol class="timeline">' . $timeline . '</ol>')
        .   ($repeatHtml !== '' ? card('More from this guest', '<ul class="linklist">' . $repeatHtml . '</ul>'
                . (count($repeat) > 6 ? '<p class="muted small">and ' . (count($repeat) - 6) . ' more</p>' : ''), '', 'no-print') : '')
        . '</div></div>';
    admin_render('Order #' . $num, $body, 'orders');
}

function admin_orders_list(): void
{
    $presets = [1, 7, 30, 90];
    $period = admin_period($presets, 7);
    $views = ['active' => 'Active', 'new' => 'New', 'preparing' => 'Preparing', 'ready' => 'Ready', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'];
    $view = get_choice('s', array_keys($views), 'active');
    $q = get_str('q', 80);
    $inView = fn($key, $o) => $key === 'all' ? true : ($key === 'active' ? in_array($o['status'], ['new', 'preparing', 'ready'], true) : $o['status'] === $key);

    $all = orders_between($period['from'], $period['to']);
    $match = fn($o) => $q === '' || stripos($o['number'] . ' ' . $o['name'] . ' ' . $o['phone'] . ' ' . $o['email'] . ' ' . $o['address'] . ' ' . implode(' ', $o['items']), $q) !== false;
    $matched = array_values(array_filter($all, $match));
    $shown = array_values(array_filter($matched, fn($o) => $inView($view, $o)));

    $filters = orders_filters($_GET);
    unset($filters['pg']);

    if (isset($_GET['csv'])) {
        csv_download('rms-orders', ['order', 'placed', 'status', 'type', 'name', 'phone', 'email', 'address', 'items', 'subtotal', 'delivery fee', 'total before tax', 'team note'],
            array_map(fn($o) => [$o['number'], date('Y-m-d H:i', $o['ts']), $o['status'], $o['type'], $o['name'], $o['phone'], $o['email'], $o['address'], implode(' | ', $o['items']),
                number_format($o['subtotal'] / 100, 2, '.', ''), number_format($o['deliveryFee'] / 100, 2, '.', ''), number_format($o['total'] / 100, 2, '.', ''), $o['note']], $shown));
    }

    // Numbers for the period (cancelled orders don't count as sales).
    $sold = array_values(array_filter($all, fn($o) => $o['status'] !== 'cancelled'));
    $sales = array_sum(array_column($sold, 'total'));
    $deliveries = count(array_filter($sold, fn($o) => $o['type'] === 'delivery'));
    $pickups = count(array_filter($sold, fn($o) => $o['type'] === 'pickup'));
    $dineins = count(array_filter($sold, fn($o) => $o['type'] === 'dine-in'));
    $cancelled = count($all) - count($sold);

    $tabs = [];
    foreach ($views as $key => $label) {
        $tabs[] = ['label' => $label, 'href' => admin_url('orders', array_diff_key($filters, ['s' => 1]) + ($key === 'active' ? [] : ['s' => $key])), 'on' => $key === $view,
            'count' => count(array_filter($matched, fn($o) => $inView($key, $o)))];
    }
    [$pageRows, $pager] = paginate($shown, 50, 'orders', $filters);

    $back = http_build_query($filters + (isset($_GET['pg']) ? ['pg' => get_str('pg', 6)] : []));
    $rows = '';
    foreach ($pageRows as $o) {
        $lines = order_lines($o);
        $first = $lines ? $lines[0] : ['qty' => 0, 'label' => '', 'total' => null];
        $next = order_next($o);
        $action = $next
            ? form_open(admin_url('orders'), 'inline') . '<input type="hidden" name="id" value="' . h($o['number']) . '"><input type="hidden" name="action" value="status">'
                . '<input type="hidden" name="status" value="' . h($next[0]) . '"><input type="hidden" name="from" value="list"><input type="hidden" name="back" value="' . h($back) . '">'
                . btn_submit($next[1], 'sm primary') . '</form>'
            : '';
        $rows .= '<tr class="order-' . h($o['status']) . '"><td class="nowrap"><a class="row-link" href="' . h(admin_url('orders', ['id' => $o['number']])) . '"><b>#' . h($o['number']) . '</b></a></td>'
            . '<td class="nowrap">' . h(fmt_when($o['ts'])) . '<div class="cell-sub">' . h(fmt_ago($o['ts'])) . '</div></td>'
            . '<td>' . guest_cell($o['name'], $o['phone']) . '</td>'
            . '<td>' . order_type_badge($o['type']) . '</td>'
            . '<td class="q">' . ($lines ? h($first['qty'] . '× ' . snip($first['label'], 60)) . (count($lines) > 1 ? '<div class="cell-sub">+ ' . plural(count($lines) - 1, 'more item') . '</div>' : '') : '<span class="muted">&ndash;</span>')
            . ($o['note'] !== '' ? '<div class="cell-sub">' . icon('edit', 12) . ' ' . h(snip($o['note'], 50)) . '</div>' : '') . '</td>'
            . '<td class="num">' . h(fmt_money($o['total'])) . '</td>'
            . '<td>' . status_badge(ORDER_STATUSES, $o['status']) . '</td>'
            . '<td class="act">' . $action . '</td></tr>';
    }

    $body = page_head('Orders', 'Orders placed on the website. They are cash on delivery: the guest pays when they pick up or receive the order.',
            btn_link('Download spreadsheet', admin_url('orders', $filters + ['csv' => 1]), '', 'download'))
        . period_bar('orders', $period, $presets, array_diff_key($filters, ['days' => 1, 'from' => 1, 'to' => 1]))
        . '<div class="stats">'
        . stat_tile('Total orders', number_format(count($sold)), admin_url('orders', $filters), $cancelled ? h(plural($cancelled, 'cancelled order')) : '', 'bag')
        . stat_tile('Dine-in orders', number_format($dineins), admin_url('orders', $filters), $sold ? h(round($dineins * 100 / count($sold)) . '% of orders') : '', 'home')
        . stat_tile('Pickup orders', number_format($pickups), admin_url('orders', $filters), $sold ? h(round($pickups * 100 / count($sold)) . '% of orders') : '', 'bag')
        . stat_tile('Delivery orders', number_format($deliveries), admin_url('orders', $filters), $sold ? h(round($deliveries * 100 / count($sold)) . '% of orders') : '', 'truck')
        . stat_tile('Sales before tax', fmt_money($sales), admin_url('orders', $filters), '', 'dollar')
        . stat_tile('Average order', $sold ? fmt_money((int) round($sales / count($sold))) : '$0.00', admin_url('orders', $filters), '', 'chart')
        . '</div>'
        . '<div class="toolbar">' . tabs_html($tabs) . '<span class="grow"></span>' . search_form('orders', array_diff_key($filters, ['q' => 1]), $q, 'Search name, phone, item…') . '</div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching orders' : ($view === 'active' ? 'No open orders' : 'No orders here'),
                $q !== '' ? '' : 'Orders placed on the website appear here as soon as they come in.', 'bag')
            : '<div class="table"><table class="tbl orders"><thead><tr><th>Order</th><th>Placed</th><th>Guest</th><th>Type</th><th>Items</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager);
    admin_render('Orders', $body, 'orders');
}
