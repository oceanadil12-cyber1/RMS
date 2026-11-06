<?php
/** Reservations: table requests from the website's reservation form, and their answers. */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** "Today" / "Tomorrow" for a Y-m-d date, else ''. */
function day_hint(string $ymd): string
{
    if ($ymd === date('Y-m-d')) return 'Today';
    if ($ymd === date('Y-m-d', strtotime('+1 day'))) return 'Tomorrow';
    return '';
}

function admin_reservation(string $id): ?array
{
    foreach (reservations_all() as $r) if ($r['id'] === $id) return $r;
    return null;
}

/** A mailto: link with a ready-to-send reply: $kind is 'confirm' or 'decline'. */
function reservation_email(array $r, string $kind): string
{
    $s = admin_store();
    $when = fmt_date($r['date']) . ' at ' . fmt_time12($r['time']);
    $sign = "\n\n— " . $s['name'] . "\n" . $s['address'] . "\n" . $s['phone'];
    if ($kind === 'confirm') {
        return mailto_href($r['email'], 'Your reservation at ' . $s['name'],
            'Hi ' . $r['name'] . ",\n\nYour table for " . reservation_party($r['guests']) . " on $when is confirmed. We look forward to seeing you!\n\nIf your plans change, just reply to this email or give us a call." . $sign);
    }
    return mailto_href($r['email'], 'About your reservation request',
        'Hi ' . $r['name'] . ",\n\nThank you for asking about a table for " . reservation_party($r['guests']) . " on $when. Unfortunately we can't hold a table for that time. Please give us a call and we'll find something that works." . $sign);
}

function admin_page_reservations(): void
{
    if (is_post()) admin_reservations_post();
    $id = get_str('id', 12);
    if ($id !== '') admin_reservation_detail($id); // ends the request
    admin_reservations_list();
}

function admin_reservations_post(): void
{
    require_csrf();
    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : '';
    $r = $id !== '' ? admin_reservation($id) : null;
    if (!$r) {
        flash('That reservation wasn\'t found.', 'err');
        redirect(admin_url('reservations'));
    }
    $back = admin_url('reservations', ['id' => $id]);
    switch ($_POST['action'] ?? '') {
        case 'status':
            $status = (string) ($_POST['status'] ?? '');
            if (isset(RESERVATION_STATUSES[$status])) {
                meta_set('reservation', $id, ['status' => $status]);
                admin_log('reservation.status', $r['name'] . ', ' . $r['date'] . ' ' . $r['time'] . ': ' . strtolower(RESERVATION_STATUSES[$status][0]));
                flash('Marked as ' . strtolower(RESERVATION_STATUSES[$status][0]) . '.');
            }
            break;
        case 'note':
            meta_set('reservation', $id, ['note' => post_str('note', 1000)]);
            admin_log('reservation.note', $r['name'] . ', ' . $r['date']);
            flash('Note saved.');
            break;
    }
    redirect($back);
}

function admin_reservation_detail(string $id): void
{
    $r = admin_reservation($id);
    if (!$r) {
        admin_render('Reservation', '<p><a href="' . h(admin_url('reservations')) . '">&larr; Reservations</a></p>' . empty_state('Reservation not found', '', 'calendar'), 'reservations');
    }
    $past = $r['date'] < date('Y-m-d');
    $hint = day_hint($r['date']);

    $buttons = '';
    foreach (['confirmed' => 'Confirm', 'declined' => 'Decline', 'cancelled' => 'Guest cancelled', 'pending' => 'Back to pending'] as $key => $label) {
        if ($key !== $r['status']) $buttons .= btn_submit($label, 'sm' . ($key === 'confirmed' ? ' primary' : ''), 'status', $key);
    }

    $body = '<p class="back"><a href="' . h(admin_url('reservations')) . '">' . icon('left', 15) . ' Reservations</a></p>'
        . page_head('Table for ' . reservation_party($r['guests']) . ' · ' . ($r['name'] !== '' ? $r['name'] : 'Guest'),
            h(fmt_date($r['date'])) . ' at ' . h(fmt_time12($r['time'])) . ($hint !== '' ? ' &middot; <b>' . $hint . '</b>' : ''),
            status_badge(RESERVATION_STATUSES, $r['status']))
        . ($past && $r['status'] === 'pending' ? '<div class="flash err" role="status">' . icon('alert', 16) . '<span>This date has passed and the request was never answered.</span></div>' : '')
        . '<div class="split"><div class="stack">'
        .   card('Request', kv([
                ['Date', h(fmt_date($r['date']))],
                ['Time', h(fmt_time12($r['time']))],
                ['Party', h(reservation_party($r['guests']))],
                ['Seating', h(reservation_area($r['area']))],
                ['Guest\'s notes', $r['notes'] !== '' ? '<div class="msgbody">' . h($r['notes']) . '</div>' : ''],
                ['Received', h(fmt_when($r['ts'])) . ' &middot; ' . h(fmt_ago($r['ts']))],
            ]))
        .   card('Note for the team', form_open(admin_url('reservations'))
                . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="action" value="note">'
                . '<label class="field wide"><span class="sr">Note</span><textarea name="note" rows="3" maxlength="1000" placeholder="Only the team sees this: table number, who you spoke to…">' . h($r['note']) . '</textarea></label>'
                . '<div class="bar">' . btn_submit('Save note', 'primary') . '</div></form>')
        . '</div><div class="stack">'
        .   card('Guest', kv([
                ['Name', h($r['name'])],
                ['Phone', tel_link($r['phone'])],
                ['Email', mail_link($r['email'])],
            ]) . '<div class="bar">' . btn_link('Call', 'tel:' . preg_replace('/[^\d+]/', '', $r['phone']), '', 'phone') . '</div>')
        .   card('Answer', form_open(admin_url('reservations')) . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="action" value="status">'
                . '<div class="btns">' . $buttons . '</div></form>'
                . '<p class="muted small">Emails open in your own mail program, ready to send.</p>'
                . '<div class="btns">' . btn_link('Email: confirmed', reservation_email($r, 'confirm'), 'sm', 'mail') . btn_link('Email: can\'t fit', reservation_email($r, 'decline'), 'sm', 'mail') . '</div>')
        . '</div></div>';
    admin_render('Reservation', $body, 'reservations');
}

function admin_reservations_list(): void
{
    $today = date('Y-m-d');
    $open = fn($r) => in_array($r['status'], ['pending', 'confirmed'], true);
    // view => [label, filter]
    $views = [
        'upcoming'  => ['Upcoming',            fn($r) => $r['date'] >= $today && $open($r)],
        'pending'   => ['Waiting for answer',  fn($r) => $r['status'] === 'pending'],
        'confirmed' => ['Confirmed',           fn($r) => $r['status'] === 'confirmed' && $r['date'] >= $today],
        'past'      => ['Past',                fn($r) => $r['date'] < $today],
        'closed'    => ['Declined / cancelled', fn($r) => !$open($r)],
        'all'       => ['All',                 fn($r) => true],
    ];
    $view = get_choice('view', array_keys($views), 'upcoming');
    $q = get_str('q', 80);
    $all = reservations_all();

    if (isset($_GET['csv'])) {
        csv_download('rms-reservations', ['received', 'name', 'email', 'phone', 'date', 'time', 'guests', 'area', 'notes', 'status', 'team note'],
            array_map(fn($r) => [$r['received'], $r['name'], $r['email'], $r['phone'], $r['date'], $r['time'], $r['guests'], reservation_area($r['area']), $r['notes'], $r['status'], $r['note']], $all));
    }

    $match = fn($r) => $q === '' || stripos($r['name'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['notes'], $q) !== false;
    $tabs = [];
    foreach ($views as $key => [$label, $filter]) {
        $tabs[] = ['label' => $label, 'href' => admin_url('reservations', ['view' => $key === 'upcoming' ? '' : $key, 'q' => $q]), 'on' => $key === $view,
            'count' => count(array_filter($all, fn($r) => $filter($r) && $match($r)))];
    }
    $shown = array_values(array_filter($all, fn($r) => $views[$view][1]($r) && $match($r)));
    if (in_array($view, ['upcoming', 'pending', 'confirmed'], true)) usort($shown, fn($a, $b) => $a['at'] <=> $b['at']); // soonest first
    elseif ($view === 'past') usort($shown, fn($a, $b) => $b['at'] <=> $a['at']);
    [$pageRows, $pager] = paginate($shown, 50, 'reservations', ['view' => $view === 'upcoming' ? '' : $view, 'q' => $q]);

    $waiting = count(array_filter($all, fn($r) => $r['status'] === 'pending' && $r['date'] >= $today));
    $confirmed = count(array_filter($all, fn($r) => $r['status'] === 'confirmed' && $r['date'] >= $today));
    $guestsToday = array_sum(array_map(fn($r) => (int) $r['guests'], array_filter($all, fn($r) => $r['date'] === $today && $open($r))));

    $rows = '';
    foreach ($pageRows as $r) {
        $url = admin_url('reservations', ['id' => $r['id']]);
        $hint = day_hint($r['date']);
        $rows .= '<tr><td class="nowrap"><a class="row-link" href="' . h($url) . '"><b>' . h(fmt_date($r['date'])) . '</b></a>'
            . '<div class="cell-sub">' . h(fmt_time12($r['time'])) . ($hint !== '' ? ' &middot; ' . $hint : '') . '</div></td>'
            . '<td class="nowrap">' . h(reservation_party($r['guests'])) . '</td>'
            . '<td>' . guest_cell($r['name'], $r['phone']) . '</td>'
            . '<td>' . h(reservation_area($r['area'])) . '</td>'
            . '<td class="q">' . ($r['notes'] !== '' ? h(snip($r['notes'], 80)) : '<span class="muted">&ndash;</span>')
            . ($r['note'] !== '' ? '<div class="cell-sub">' . icon('edit', 12) . ' ' . h(snip($r['note'], 60)) . '</div>' : '') . '</td>'
            . '<td class="nowrap"><span class="cell-sub">' . h(fmt_ago($r['ts'])) . '</span></td>'
            . '<td>' . status_badge(RESERVATION_STATUSES, $r['status']) . '</td></tr>';
    }

    $body = page_head('Reservations', 'Table requests from the website\'s reservation form. The website only takes requests: confirm each one with the guest.',
            btn_link('Download spreadsheet', admin_url('reservations', ['csv' => 1]), '', 'download'))
        . '<div class="stats">'
        . stat_tile('Waiting for an answer', number_format($waiting), admin_url('reservations', ['view' => 'pending']), '', 'clock', $waiting ? 'attn' : '')
        . stat_tile('Confirmed, upcoming', number_format($confirmed), admin_url('reservations', ['view' => 'confirmed']), '', 'check')
        . stat_tile('Guests expected today', number_format($guestsToday), admin_url('reservations'), '', 'users')
        . '</div>'
        . '<div class="toolbar">' . tabs_html($tabs) . '<span class="grow"></span>' . search_form('reservations', ['view' => $view === 'upcoming' ? '' : $view], $q, 'Search name, phone, notes') . '</div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching reservations' : 'No reservations here', $q !== '' ? '' : 'Requests from the website\'s reservation form appear here.', 'calendar')
            : '<div class="table"><table class="tbl"><thead><tr><th>Date &amp; time</th><th>Party</th><th>Guest</th><th>Seating</th><th>Notes</th><th>Received</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager);
    admin_render('Reservations', $body, 'reservations');
}
