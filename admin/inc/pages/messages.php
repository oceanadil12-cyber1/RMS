<?php
/** Messages: what guests sent through the website's contact form. */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

// The topics offered on contact.html.
const MESSAGE_TOPICS = [
    'general'       => 'General inquiry',
    'reservation'   => 'Reservation',
    'private-event' => 'Private event',
    'catering'      => 'Catering',
    'entertainment' => 'Entertainment / booking',
    'feedback'      => 'Feedback',
];

function message_topic(string $subject): string
{
    return MESSAGE_TOPICS[$subject] ?? ($subject !== '' ? $subject : 'No topic');
}

function admin_message(string $id): ?array
{
    foreach (messages_all() as $m) if ($m['id'] === $id) return $m;
    return null;
}

function admin_page_messages(): void
{
    if (is_post()) admin_messages_post();
    $id = get_str('id', 12);
    if ($id !== '') admin_message_detail($id); // ends the request
    admin_messages_list();
}

/** Save a status, a note, or delete one message; then back to where the click came from. */
function admin_messages_post(): void
{
    require_csrf();
    $id = (string) ($_POST['id'] ?? '');
    $m = $id !== '' ? admin_message($id) : null;
    if (!$m) {
        flash('That message wasn\'t found.', 'err');
        redirect(admin_url('messages'));
    }
    $who = $m['name'] !== '' ? $m['name'] : mask_email($m['email']); // what goes in the activity log
    $back = admin_url('messages', ['id' => $id]);
    switch ($_POST['action'] ?? '') {
        case 'status':
            $status = (string) ($_POST['status'] ?? '');
            if (isset(MESSAGE_STATUSES[$status])) {
                meta_set('message', $id, ['status' => $status]);
                admin_log('message.status', $who . ': ' . strtolower(MESSAGE_STATUSES[$status][0]));
                flash('Marked as ' . strtolower(MESSAGE_STATUSES[$status][0]) . '.');
                if ($status === 'archived') $back = admin_url('messages');
            }
            break;
        case 'note':
            meta_set('message', $id, ['note' => post_str('note', 1000)]);
            admin_log('message.note', $who);
            flash('Note saved.');
            break;
        case 'delete':
            message_delete((int) $id);
            admin_log('message.delete', $who . ' <' . mask_email($m['email']) . '>');
            flash('Message deleted.');
            $back = admin_url('messages');
            break;
    }
    redirect($back);
}

function admin_message_detail(string $id): void
{
    $m = admin_message($id);
    if (!$m) {
        admin_render('Message', '<p><a href="' . h(admin_url('messages')) . '">&larr; Messages</a></p>' . empty_state('Message not found', 'It may have been deleted.', 'mail'), 'messages');
    }
    if ($m['status'] === 'new') { // opening a message reads it
        meta_set('message', $id, ['status' => 'read']);
        messages_all(true);
        $m['status'] = 'read';
    }

    $store = admin_store();
    $reply = mailto_href($m['email'], 'Re: your message to ' . $store['name'],
        'Hi' . ($m['name'] !== '' ? ' ' . $m['name'] : '') . ",\n\n\n\n" . '— ' . $store['name'] . "\n" . $store['phone']);

    $statusLabels = ['new' => 'Mark as unread', 'read' => 'Mark as read', 'replied' => 'Mark as replied', 'archived' => 'Archive'];
    $buttons = '';
    foreach ($statusLabels as $key => $label) {
        if ($key !== $m['status']) $buttons .= btn_submit($label, 'sm' . ($key === 'replied' ? ' primary' : ''), 'status', $key);
    }

    $body = '<p class="back"><a href="' . h(admin_url('messages')) . '">' . icon('left', 15) . ' Messages</a></p>'
        . page_head($m['name'] !== '' ? 'Message from ' . $m['name'] : 'Message', h(fmt_when($m['ts'])) . ' &middot; ' . h(fmt_ago($m['ts'])),
            status_badge(MESSAGE_STATUSES, $m['status']) . ' <span class="chip">' . h(message_topic($m['subject'])) . '</span>')
        . '<div class="split">'
        . '<div class="stack">'
        .   card('Message', '<div class="msgbody">' . h($m['message']) . '</div>')
        .   card('Note for the team', form_open(admin_url('messages'))
                . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="action" value="note">'
                . '<label class="field wide"><span class="sr">Note</span><textarea name="note" rows="3" maxlength="1000" placeholder="Only the team sees this: what you told them, what to follow up…">' . h($m['note']) . '</textarea></label>'
                . '<div class="bar">' . btn_submit('Save note', 'primary') . '</div></form>')
        . '</div>'
        . '<div class="stack">'
        .   card('Guest', kv([
                ['Name', h($m['name'])],
                ['Email', mail_link($m['email'])],
                ['Phone', $m['phone'] !== '' ? tel_link($m['phone']) : ''],
            ]) . '<div class="bar">' . btn_link('Reply by email', $reply, 'primary', 'mail') . '</div>')
        .   card('Status', form_open(admin_url('messages')) . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="action" value="status">'
                . '<div class="btns">' . $buttons . '</div></form>')
        .   card('Delete', '<p class="muted">Removes the message from the saved list for good (spam, or a guest\'s request). This can\'t be undone.</p>'
                . form_open(admin_url('messages')) . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="action" value="delete">'
                . btn_submit('Delete message', 'sm danger', '', '', 'trash', 'Delete this message for good?') . '</form>')
        . '</div></div>';
    admin_render('Message', $body, 'messages');
}

function admin_messages_list(): void
{
    $views = ['inbox' => 'Inbox', 'new' => 'Unread', 'replied' => 'Replied', 'archived' => 'Archived', 'all' => 'All'];
    $inView = [
        'inbox'    => fn($m) => in_array($m['status'], ['new', 'read'], true),
        'new'      => fn($m) => $m['status'] === 'new',
        'replied'  => fn($m) => $m['status'] === 'replied',
        'archived' => fn($m) => $m['status'] === 'archived',
        'all'      => fn($m) => true,
    ];
    $view = get_choice('view', array_keys($views), 'inbox');
    $q = get_str('q', 80);
    $all = messages_all();

    if (isset($_GET['csv'])) {
        csv_download('rms-messages', ['received', 'name', 'email', 'phone', 'topic', 'message', 'status', 'note'],
            array_map(fn($m) => [$m['received'], $m['name'], $m['email'], $m['phone'], message_topic($m['subject']), $m['message'], $m['status'], $m['note']], $all));
    }

    $match = fn($m) => $q === '' || stripos($m['name'] . ' ' . $m['email'] . ' ' . $m['phone'] . ' ' . $m['subject'] . ' ' . $m['message'], $q) !== false;
    $tabs = [];
    foreach ($views as $key => $label) {
        $tabs[] = ['label' => $label, 'href' => admin_url('messages', ['view' => $key === 'inbox' ? '' : $key, 'q' => $q]), 'on' => $key === $view,
            'count' => count(array_filter($all, fn($m) => $inView[$key]($m) && $match($m)))];
    }
    $shown = array_values(array_filter($all, fn($m) => $inView[$view]($m) && $match($m)));
    [$pageRows, $pager] = paginate($shown, 50, 'messages', ['view' => $view === 'inbox' ? '' : $view, 'q' => $q]);

    $rows = '';
    foreach ($pageRows as $m) {
        $unread = $m['status'] === 'new';
        $url = admin_url('messages', ['id' => $m['id']]);
        $rows .= '<tr class="' . ($unread ? 'unread' : '') . '"><td>' . guest_cell($m['name'], $m['email']) . '</td>'
            . '<td><span class="chip">' . h(message_topic($m['subject'])) . '</span></td>'
            . '<td class="q"><a class="row-link" href="' . h($url) . '">' . h(snip($m['message'], 110)) . '</a>'
            . ($m['note'] !== '' ? '<div class="cell-sub">' . icon('edit', 12) . ' ' . h(snip($m['note'], 70)) . '</div>' : '') . '</td>'
            . '<td class="nowrap">' . h(fmt_when($m['ts'])) . '<div class="cell-sub">' . h(fmt_ago($m['ts'])) . '</div></td>'
            . '<td>' . status_badge(MESSAGE_STATUSES, $m['status']) . '</td></tr>';
    }

    $body = page_head('Messages', 'What guests sent through the contact form on the website.',
            btn_link('Download spreadsheet', admin_url('messages', ['csv' => 1]), '', 'download'))
        . '<div class="toolbar">' . tabs_html($tabs) . '<span class="grow"></span>' . search_form('messages', ['view' => $view === 'inbox' ? '' : $view], $q, 'Search messages') . '</div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching messages' : ($view === 'inbox' ? 'Your inbox is empty' : 'Nothing here'), $q !== '' ? '' : 'Messages sent from the website\'s contact form appear here.', 'mail')
            : '<div class="table"><table class="tbl"><thead><tr><th>From</th><th>Topic</th><th>Message</th><th>Received</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager);
    admin_render('Messages', $body, 'messages');
}
