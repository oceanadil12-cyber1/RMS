<?php
/**
 * Conversations: everything guests asked the website's chat assistant, and what it
 * answered. Chats are saved by api/chat.php (api/chat-log.php); this page looks back
 * over config 'chat_log_days' (at least 120). The team's flag and note are saved on the chat_sessions row.
 * Guests' messages are untrusted text and are always escaped.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/**
 * Did the guest ask about a table reservation in this conversation? Returns the
 * first matching question (and party size if they gave one), else null. Detected
 * from the guest's own words, so treat it as a helpful flag, not a booking.
 */
function reservation_intent(array $c): ?array
{
    $re = '/\breserv\w*|book(?:ing)?\s+a\s+table|book\s+a\s+table|table\s+for\s+\d+|make\s+a\s+(?:booking|reservation)|hold\s+a\s+table|\btable\s+booking/i';
    foreach ($c['turns'] as $t) {
        $q = (string) ($t['question'] ?? '');
        if (preg_match($re, $q)) {
            $party = null;
            if (preg_match('/\b(?:party of|group of|table for|for)\s+(\d{1,3})\b|\b(\d{1,3})\s*(?:people|guests|persons|pax|ppl|of us|adults)\b/i', $q, $m)) {
                $party = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0)) ?: null;
            }
            return ['quote' => $q, 'party' => $party, 'time' => $t['time'] ?? ''];
        }
    }
    return null;
}

/** The list's filters from a query string or a form, for links and for coming back after a click. */
function chat_filters(array $src): array
{
    $out = [];
    foreach (['days', 'from', 'to', 'f', 'q', 'pg'] as $k) {
        if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') $out[$k] = mb_substr($src[$k], 0, 80);
    }
    return $out;
}

/** Everything one guest said and was told, lower case, for searching. */
function chat_haystack(array $c): string
{
    $s = ($c['name'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '');
    foreach ($c['turns'] as $t) $s .= ' ' . ($t['question'] ?? '') . ' ' . ($t['reply'] ?? '');
    return mb_strtolower($s);
}

function admin_page_chats(): void
{
    // chat_log_cleanup() disabled — data kept until manually deleted.
    if (is_post()) admin_chats_post();
    $cid = get_str('c', 40);
    if ($cid !== '') admin_chat_detail($cid); // ends the request
    admin_chats_list();
}

/** Flag a conversation for follow-up, or save the team's note on it. */
function admin_chats_post(): void
{
    require_csrf();
    parse_str((string) ($_POST['back'] ?? ''), $b);
    $filters = chat_filters(is_array($b) ? $b : []);
    $cid = chat_log_cid($_POST['cid'] ?? '');
    switch ($_POST['action'] ?? '') {
        case 'flag':
            $stmt = db()->prepare("SELECT flagged FROM chat_sessions WHERE cid = ?");
            $stmt->execute([$cid]);
            $cur = $stmt->fetch();
            $on = $cur ? !(bool) $cur['flagged'] : true;
            meta_set('chat', $cid, ['flagged' => $on]);
            admin_log('chat.flag', $cid . ($on ? ': flagged for follow-up' : ': flag removed'));
            flash($on ? 'Flagged for follow-up.' : 'Flag removed.');
            break;
        case 'note':
            meta_set('chat', $cid, ['note' => post_str('note', 1000)]);
            admin_log('chat.note', $cid);
            flash('Note saved.');
            break;
    }
    redirect(admin_url('chats', ['c' => $cid] + $filters));
}

function admin_chat_detail(string $rawCid): void
{
    $cid = chat_log_cid($rawCid);
    $filters = chat_filters($_GET);
    unset($filters['pg']);
    $listUrl = admin_url('chats', $filters);
    $all = chat_log_conversations(max(120, (int) config()['chat_log_days']));
    $c = $all[$cid] ?? null;
    if (!$c) {
        admin_render('Conversation', '<p class="back"><a href="' . h($listUrl) . '">' . icon('left', 15) . ' Conversations</a></p>'
            . empty_state('Conversation not found', 'It may have been deleted, or be older than ' . max(120, (int) config()['chat_log_days']) . ' days.', 'chat'), 'chats');
    }
    $when = fn($iso) => fmt_when((string) $iso);
    $ordered = array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns']));
    $email = (string) ($c['email'] ?? '');
    $name = (string) ($c['name'] ?? '');
    $phone = (string) ($c['phone'] ?? '');
    $ip = (string) ($c['ip'] ?? '');
    $device = (string) ($c['device'] ?? '');
    $browser = (string) ($c['browser'] ?? '');
    $dist = $ip !== '' ? geo_distance($ip) : null;
    $meta = meta_get('chat', $cid);

    $who = implode(' &middot; ', array_filter([$name !== '' ? h($name) : '', $email !== '' ? mail_link($email) : '', $phone !== '' ? tel_link($phone) : '']));
    $chips = [];
    if ($device !== '' || $browser !== '') $chips[] = '<span class="chip">' . h($device) . ($device !== '' && $browser !== '' ? ' &middot; ' : '') . h($browser) . '</span>';
    if ($ip !== '') $chips[] = '<span class="chip">IP ' . h($ip) . '</span>';
    if ($dist) $chips[] = '<span class="chip gold">~' . number_format($dist['miles'], $dist['miles'] < 10 ? 1 : 0) . ' mi from the bar' . ($dist['place'] !== '' ? ' &middot; ' . h($dist['place']) : '') . '</span>';

    // Earlier chats from the same email (a returning guest).
    $prev = [];
    if ($email !== '') {
        foreach ($all as $oc) {
            if (($oc['cid'] ?? '') !== $cid && strcasecmp(trim((string) ($oc['email'] ?? '')), $email) === 0) $prev[] = $oc;
        }
    }

    $banners = '';
    $rez = reservation_intent($c);
    if ($rez) {
        $banners .= '<div class="callout blue">' . icon('calendar', 18) . '<div><b>Reservation request' . ($rez['party'] ? ' &middot; party of ' . (int) $rez['party'] : '') . '</b>'
            . '<p>&ldquo;' . h($rez['quote']) . '&rdquo;</p>'
            . '<p class="muted">The assistant can\'t book tables. Follow up with the guest, or point them to the reservation form (/contact.html#reserve) or (954) 565-2526.</p></div></div>';
    }
    if ($prev) {
        $items = '';
        foreach ($prev as $oc) {
            $items .= '<li><a href="' . h(admin_url('chats', ['c' => $oc['cid']] + $filters)) . '">' . h($when($oc['end'])) . ' &middot; ' . count($oc['turns']) . ' Q</a> <span class="muted">' . h(snip((string) ($oc['turns'][0]['question'] ?? ''), 80)) . '</span></li>';
        }
        $banners .= '<div class="callout gold">' . icon('refresh', 18) . '<div><b>Returning guest &mdash; ' . plural(count($prev), 'earlier chat') . ' from ' . h($email) . '</b><ul class="linklist">' . $items . '</ul></div></div>';
    }

    $back = http_build_query($filters);
    $team = form_open(admin_url('chats')) . '<input type="hidden" name="cid" value="' . h($cid) . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="bar"><button class="btn sm' . (!empty($meta['flag']) ? ' primary' : '') . '" type="submit" name="action" value="flag">' . icon('flag', 15) . '<span>' . (!empty($meta['flag']) ? 'Flagged for follow-up' : 'Flag for follow-up') . '</span></button></div></form>'
        . form_open(admin_url('chats')) . '<input type="hidden" name="cid" value="' . h($cid) . '"><input type="hidden" name="back" value="' . h($back) . '"><input type="hidden" name="action" value="note">'
        . '<label class="field wide"><span class="sr">Note</span><textarea name="note" rows="2" maxlength="1000" placeholder="Only the team sees this: who followed up, what was promised…">' . h((string) ($meta['note'] ?? '')) . '</textarea></label>'
        . '<div class="bar">' . btn_submit('Save note', 'primary') . '</div></form>';

    $chat = '<div class="chat">';
    foreach ($c['turns'] as $t) {
        $chat .= '<div class="msg guest">' . h($t['question']) . '</div><div class="meta r">Guest &middot; ' . h($when($t['time'])) . '</div>'
            // Markdown asterisks are removed, as in the chat window (js/chat.js).
            . '<div class="msg bot">' . h(preg_replace(['/\*\*(.+?)\*\*/s', '/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/m'], ['$1', '$1$2'], (string) $t['reply'])) . '</div>'
            . '<div class="meta">' . h(trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? ''))) . ' &middot; ' . h($t['seconds'] ?? '') . 's</div>';
        if (!empty($t['ordered'])) $chat .= '<div class="order">' . icon('bag', 15) . ' Added to order: ' . h(implode(', ', $t['ordered'])) . '</div>';
    }
    $chat .= '</div>';

    $body = '<p class="back"><a href="' . h($listUrl) . '">' . icon('left', 15) . ' Conversations</a></p>'
        . page_head('Conversation ' . $cid, h($when($c['start'])) . ' &ndash; ' . h($when($c['end'])) . ' &middot; ' . h(plural(count($c['turns']), 'question'))
            . ($ordered ? ' &middot; <span class="tag">ordered ' . count($ordered) . ' item' . (count($ordered) === 1 ? '' : 's') . '</span>' : ''),
            !empty($meta['flag']) ? badge('Follow-up', 'gold') : '')
        . ($who !== '' ? '<p class="who">' . $who . '</p>' : '<p class="muted">No contact details were given.</p>')
        . ($chips ? '<p class="chips">' . implode(' ', $chips) . '</p>' : '')
        . $banners
        . '<div class="split"><div>' . $chat . '</div><div class="stack">' . card('Team', $team) . '</div></div>';
    admin_render('Conversation', $body, 'chats');
}

function admin_chats_list(): void
{
    $presets = [1, 7, 30, 120];
    $period = admin_period($presets, 30);
    $views = ['all' => 'All', 'reservation' => 'Reservation requests', 'ordered' => 'Ordered food', 'contact' => 'Left an email', 'flagged' => 'Flagged'];
    $view = get_choice('f', array_keys($views), 'all');
    $qRaw = get_str('q', 80);
    $q = mb_strtolower($qRaw);
    $convos = chat_log_conversations($period['days'], $period['custom'] ? $period['from'] : null, $period['custom'] ? $period['to'] : null);
    $flags = [];

    if (isset($_GET['csv'])) {
        $rows = [];
        foreach (array_reverse($convos) as $c) {
            $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
            $rz = reservation_intent($c);
            foreach ($c['turns'] as $t) {
                $rows[] = [$c['cid'], $c['name'] ?? '', $c['email'] ?? '', $c['phone'] ?? '', $c['ip'] ?? '', $c['device'] ?? '', $c['browser'] ?? '', $d ? round($d['miles']) : '', $d ? $d['place'] : '',
                    $rz ? 'yes' : '', ($rz && $rz['party']) ? $rz['party'] : '', $t['time'], $t['question'], $t['reply'], implode('; ', $t['ordered'] ?? []),
                    trim(($t['provider'] ?? '') . ' ' . ($t['model'] ?? '')), $t['input_tokens'] ?? 0, $t['output_tokens'] ?? 0, $t['seconds'] ?? 0];
            }
        }
        csv_download('rms-chats', ['conversation', 'name', 'email', 'phone', 'ip', 'device', 'browser', 'distance (mi)', 'location', 'reservation asked', 'reservation party', 'time', 'guest asked', 'assistant replied', 'added to order', 'AI', 'input tokens', 'output tokens', 'seconds'], $rows);
    }

    // Numbers for the whole period, before any filter.
    $rezMap = [];
    foreach ($convos as $cid => $c) { $r = reservation_intent($c); if ($r) $rezMap[$cid] = $r; }
    $questions = array_sum(array_map(fn($c) => count($c['turns']), $convos));
    $hasOrder = fn($c) => (bool) array_filter($c['turns'], fn($t) => !empty($t['ordered']));
    $withOrders = count(array_filter($convos, $hasOrder));
    $tokens = array_sum(array_map(fn($c) => array_sum(array_map(fn($t) => ($t['input_tokens'] ?? 0) + ($t['output_tokens'] ?? 0), $c['turns'])), $convos));

    $match = fn($c) => $q === '' || mb_strpos(chat_haystack($c), $q) !== false;
    $inView = [
        'all'         => fn($c) => true,
        'reservation' => fn($c) => isset($rezMap[$c['cid']]),
        'ordered'     => $hasOrder,
        'contact'     => fn($c) => trim((string) ($c['email'] ?? '')) !== '',
        'flagged'     => fn($c) => !empty($c['flagged']),
    ];
    $filters = chat_filters($_GET);
    unset($filters['pg']);
    $tabs = [];
    foreach ($views as $key => $label) {
        $tabs[] = ['label' => $label, 'href' => admin_url('chats', array_diff_key($filters, ['f' => 1]) + ($key === 'all' ? [] : ['f' => $key])), 'on' => $key === $view,
            'count' => count(array_filter($convos, fn($c) => $inView[$key]($c) && $match($c)))];
    }
    $shown = array_values(array_filter($convos, fn($c) => $inView[$view]($c) && $match($c)));
    [$pageRows, $pager] = paginate($shown, 50, 'chats', $filters);

    // Emails that appear more than once in this period are returning guests.
    $emailCounts = [];
    foreach ($convos as $c) {
        $e = strtolower(trim((string) ($c['email'] ?? '')));
        if ($e !== '') $emailCounts[$e] = ($emailCounts[$e] ?? 0) + 1;
    }

    $rows = '';
    foreach ($pageRows as $c) {
        $items = count(array_merge(...array_map(fn($t) => $t['ordered'] ?? [], $c['turns'])));
        $email = (string) ($c['email'] ?? '');
        $name = (string) ($c['name'] ?? '');
        $badges = '';
        if ($email !== '' && ($emailCounts[strtolower(trim($email))] ?? 1) > 1) $badges .= ' <span class="ret" title="Several chats from this email in this period">' . icon('refresh', 12) . ' returning</span>';
        if (isset($rezMap[$c['cid']])) $badges .= ' <span class="rez" title="Asked about a reservation">' . icon('calendar', 12) . ' reservation</span>';
        if (!empty($c['flagged'])) $badges .= ' <span class="flagged" title="Flagged for follow-up">' . icon('flag', 12) . ' follow-up</span>';
        $guest = ($name !== '' || $email !== '') ? guest_cell($name, $email, $badges) : '<div class="gcell"><span class="avatar empty">?</span><span class="ginfo"><span class="muted">No contact</span>' . $badges . '</span></div>';
        $device = (string) ($c['device'] ?? '');
        $browser = (string) ($c['browser'] ?? '');
        $d = !empty($c['ip']) ? geo_distance($c['ip']) : null;
        $note = (string) ($c['note'] ?? '');
        $rows .= '<tr><td class="nowrap">' . h(fmt_when((string) $c['end'])) . '<div class="cell-sub">' . h(fmt_ago((int) strtotime((string) $c['end']))) . '</div></td><td>' . $guest . '</td>'
            . '<td>' . ($device !== '' ? h($device) . ($browser !== '' ? '<div class="cell-sub">' . h($browser) . '</div>' : '') : '<span class="muted">&ndash;</span>') . '</td>'
            . '<td>' . ($d ? '~' . number_format($d['miles'], $d['miles'] < 10 ? 1 : 0) . ' mi' . ($d['place'] !== '' ? '<div class="cell-sub">' . h($d['place']) . '</div>' : '') : '<span class="muted">&ndash;</span>') . '</td>'
            . '<td class="num">' . count($c['turns']) . '</td>'
            . '<td>' . ($items ? '<span class="tag">' . $items . ' item' . ($items === 1 ? '' : 's') . '</span>' : '<span class="muted">&ndash;</span>') . '</td>'
            . '<td class="q"><a class="row-link" href="' . h(admin_url('chats', ['c' => $c['cid']] + $filters)) . '">' . h(snip((string) ($c['turns'][0]['question'] ?? ''), 90)) . '</a>'
            . ($note !== '' ? '<div class="cell-sub">' . icon('edit', 12) . ' ' . h(snip($note, 60)) . '</div>' : '') . '</td></tr>';
    }

    $body = page_head('Conversations', 'Guests give an email (and optionally a name) before chatting; their IP address, device and browser are recorded too. Chats are kept in the database.',
            btn_link('Download spreadsheet', admin_url('chats', $filters + ['csv' => 1]), '', 'download'))
        . period_bar('chats', $period, $presets, array_diff_key($filters, ['days' => 1, 'from' => 1, 'to' => 1]))
        . '<div class="stats">'
        . stat_tile('Conversations', number_format(count($convos)), admin_url('chats', $filters), '', 'chat')
        . stat_tile('Questions', number_format($questions), admin_url('chats', $filters), '', 'info')
        . stat_tile('Reservation requests', number_format(count($rezMap)), admin_url('chats', array_diff_key($filters, ['f' => 1]) + ['f' => 'reservation']), 'asked in the chat', 'calendar')
        . stat_tile('With food ordered', number_format($withOrders), admin_url('chats', array_diff_key($filters, ['f' => 1]) + ['f' => 'ordered']), '', 'bag')
        . stat_tile('AI tokens used', number_format($tokens), admin_url('chats', $filters), 'what the AI bill is based on', 'activity')
        . '</div>'
        . '<div class="toolbar">' . tabs_html($tabs) . '<span class="grow"></span>' . search_form('chats', array_diff_key($filters, ['q' => 1]), $qRaw, 'Search chats') . '</div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching chats' : 'No chats in this period', $q !== '' ? '' : 'When guests use the "Ask us" chat on the website, their conversations appear here.', 'chat')
            : '<div class="table"><table class="tbl"><thead><tr><th>Last message</th><th>Guest</th><th>Device</th><th>Distance</th><th class="num">Questions</th><th>Ordered</th><th>First question</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager);
    admin_render('Conversations', $body, 'chats');
}
