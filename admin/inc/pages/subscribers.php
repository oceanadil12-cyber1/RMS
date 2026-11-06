<?php
/** Subscribers: everyone who signed up for the newsletter on the website. */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_subscribers(): void
{
    if (is_post()) {
        require_csrf();
        $email = strtolower(post_str('email', 120));
        if (($_POST['action'] ?? '') === 'remove' && $email !== '') {
            if (subscriber_remove($email)) {
                admin_log('subscriber.remove', mask_email($email));
                flash('Removed ' . $email . ' from the newsletter list.');
            } else {
                flash('That address wasn\'t on the list.', 'err');
            }
        }
        redirect(admin_url('subscribers', ['q' => post_str('q', 80), 'pg' => post_str('pg', 6)]));
    }

    $all = subscribers_all();
    if (isset($_GET['csv'])) {
        csv_download('rms-subscribers', ['email', 'signed up', 'page'],
            array_map(fn($s) => [$s['email'], $s['ts'] ? date('Y-m-d H:i', $s['ts']) : '', $s['page']], $all));
    }

    $q = get_str('q', 80);
    $week = strtotime('-7 days');
    $month = strtotime('-30 days');
    $inWeek = $inMonth = 0;
    $byPage = [];
    foreach ($all as $s) {
        if ($s['ts'] >= $week) $inWeek++;
        if ($s['ts'] >= $month) $inMonth++;
        $key = $s['page'] !== '' ? $s['page'] : '(not recorded)';
        $byPage[$key] = ($byPage[$key] ?? 0) + 1;
    }
    arsort($byPage);

    $shown = $q === '' ? $all : array_values(array_filter($all, fn($s) => stripos($s['email'], $q) !== false));
    [$pageRows, $pager] = paginate($shown, 100, 'subscribers', ['q' => $q]);

    $rows = '';
    foreach ($pageRows as $s) {
        $rows .= '<tr><td><div class="gcell"><span class="avatar">' . h(initial($s['email'])) . '</span><span class="cell-main">' . mail_link($s['email']) . '</span></div></td>'
            . '<td class="nowrap">' . h(fmt_when($s['ts'])) . '<div class="cell-sub">' . h(fmt_ago($s['ts'])) . '</div></td>'
            . '<td>' . ($s['page'] !== '' ? '<span class="chip">' . h($s['page']) . '</span>' : '<span class="muted">&ndash;</span>') . '</td>'
            . '<td class="act">' . form_open(admin_url('subscribers'), 'inline')
            . '<input type="hidden" name="action" value="remove"><input type="hidden" name="email" value="' . h($s['email']) . '">'
            . hidden_fields(['q' => $q, 'pg' => get_str('pg', 6)])
            . btn_submit('Remove', 'sm danger ghost', '', '', 'trash', 'Remove ' . $s['email'] . ' from the newsletter list?')
            . '</form></td></tr>';
    }

    $body = page_head('Subscribers', 'Everyone who signed up for the newsletter on the website, one row per email address.',
            btn_link('Download spreadsheet', admin_url('subscribers', ['csv' => 1]), '', 'download'))
        . '<div class="stats">'
        . stat_tile('Subscribers', number_format(count($all)), admin_url('subscribers'), '', 'users')
        . stat_tile('New in 7 days', number_format($inWeek), admin_url('subscribers'), '', 'plus')
        . stat_tile('New in 30 days', number_format($inMonth), admin_url('subscribers'), '', 'calendar')
        . '</div>'
        . ($byPage ? '<div class="grid g2">' . card('Where they signed up', bar_list($byPage, count($all), 'subscribers')) . '</div>' : '')
        . '<div class="toolbar">' . search_form('subscribers', [], $q, 'Search email addresses') . '<span class="grow"></span><span class="muted">' . number_format(count($shown)) . ($q !== '' ? ' match' . (count($shown) === 1 ? '' : 'es') : ' in total') . '</span></div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching addresses' : 'No subscribers yet', $q !== '' ? '' : 'People who join the newsletter on the website appear here.', 'users')
            : '<div class="table"><table class="tbl"><thead><tr><th>Email</th><th>Signed up</th><th>From page</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager)
        . '<p class="hint">Removing someone here takes them off this list only. If the site is connected to Square, remove them from your Square Customer Directory too.</p>';
    admin_render('Subscribers', $body, 'subscribers');
}
