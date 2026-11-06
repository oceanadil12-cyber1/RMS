<?php
/**
 * Insights: what guests ask and order in the chat (counted from their own words,
 * see api/chat-insights.php) and how orders on the website are going. A guide to
 * what guests care about, not exact accounting.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../../../api/chat-insights.php'; // chat_insights(), tally(), top()

/** Links to conversations, e.g. questions the assistant couldn't answer. */
function insight_quotes(array $items, array $periodQuery): string
{
    if (!$items) return '<p class="empty-sm">Nothing yet in this period.</p>';
    $out = '<ul class="quotes">';
    foreach ($items as $i) {
        $out .= '<li><a href="' . h(admin_url('chats', ['c' => $i['cid']] + $periodQuery)) . '">&ldquo;' . h(mb_strimwidth($i['text'], 0, 120, '…')) . '&rdquo;</a>'
            . '<small>' . h(date('M j, g:i A', strtotime($i['time']))) . '</small></li>';
    }
    return $out . '</ul>';
}

function chat_insights_html(array $in, array $periodQuery): string
{
    if (!$in['questions']) return empty_state('No chats in this period yet', 'Insights appear once guests have used the chat.', 'chat');
    $p = $in['party'];
    $q = $in['questions'];
    $partyShare = $in['conversations'] ? round($p['convos'] * 100 / $in['conversations']) : 0;
    $avgSize = $p['size_list'] ? round(array_sum($p['size_list']) / count($p['size_list'])) : null;

    return '<div class="stats">'
        . stat_tile('Conversations', number_format($in['conversations']), admin_url('chats', $periodQuery), '', 'chat')
        . stat_tile('Questions', number_format($q), admin_url('chats', $periodQuery), '', 'info')
        . stat_tile('Dishes ordered via chat', number_format($in['items_ordered']), admin_url('chats', $periodQuery + ['f' => 'ordered']), '', 'bag')
        . stat_tile('Private party inquiries', number_format($p['convos']), admin_url('chats', $periodQuery), '', 'users')
        . stat_tile('Couldn\'t answer', number_format(count($in['unanswered'])), admin_url('knowledge'), 'questions to teach it', 'alert')
        . '</div><div class="grid g2">'
        . card('What guests ask about', bar_list($in['topics'], $q) . '<p class="hint">Share of all questions; one question can touch several topics.</p>')
        . card('Food guests ask about', bar_list($in['foods'], $q) . '<p class="hint">Questions mentioning each kind of food.</p>')
        . card('Dishes ordered through the chat', bar_list($in['ordered'], $in['items_ordered'], 'ordered')
            . '<p class="hint">' . plural($in['ordering_convos'], 'conversation') . ' ordered food. Counts are portions.</p>')
        . card('Shows guests ask about', bar_list($in['shows'], 0, 'times') . '<p class="hint">Shows the assistant showed a card for.</p>')
        . card('Days guests ask about', bar_list($in['days_asked'], 0, 'questions') . '<p class="hint">"Tonight", "tomorrow" and "this weekend" count for the day they mean.</p>')
        . card('When guests chat', bar_list($in['chat_days'], $q) . '<div class="gap"></div>' . bar_list($in['chat_hours'], $q) . '<p class="hint">Questions by the day and time they were asked (a bar day runs until 2 AM).</p>')
        . card('Private parties',
            '<p class="hint">' . plural($p['convos'], 'conversation') . " ($partyShare% of all) asked about a private event" . ($avgSize ? '; average group size ' . $avgSize : '') . '.</p>'
            . '<div class="minis"><div><b>' . $p['price_questions'] . '</b>price questions</div><div><b>' . $p['budget_convos'] . '</b>with budget worries</div>'
            . '<div><b>' . count($p['size_list']) . '</b>gave a group size</div></div>'
            . '<h3>Occasions</h3>' . bar_list($p['occasions'], $p['convos'], 'conversations')
            . '<h3>Group sizes</h3>' . bar_list($p['sizes'], count($p['size_list']), 'groups')
            . '<h3>Packages discussed</h3>' . bar_list($p['packages'], 0, 'mentions')
            . '<h3>Budget worries, in their words</h3>' . insight_quotes($p['budget_quotes'], $periodQuery), '', 'wide')
        . card('Questions it couldn\'t answer',
            '<p class="hint">Add the missing facts on the <a href="' . h(admin_url('knowledge')) . '">Knowledge</a> page and it will answer them next time.</p>' . insight_quotes($in['unanswered'], $periodQuery), '', 'wide')
        . '</div>';
}

/** Chart points for orders: one column a day, or a week for long periods. */
function order_points(array $orders, string $from, string $to): array
{
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $weekly = $days > 45;
    $counts = [];
    foreach ($orders as $o) {
        $d = date('Y-m-d', $o['ts']);
        $key = $weekly ? date('Y-m-d', strtotime('monday this week', $o['ts'])) : $d;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    $points = [];
    $t = $weekly ? strtotime('monday this week', strtotime($from)) : strtotime($from);
    $end = strtotime($to);
    for ($n = 0; $t !== false && $t <= $end && $n < 400; $n++, $t = strtotime($weekly ? '+7 days' : '+1 day', $t)) {
        $key = date('Y-m-d', $t);
        $points[] = ['label' => $weekly ? date('M j', $t) : date('j', $t), 'tip' => ($weekly ? 'Week of ' : '') . date('D, M j', $t), 'value' => $counts[$key] ?? 0];
    }
    return $points;
}

function order_insights_html(string $from, string $to, array $periodQuery): string
{
    $all = orders_between($from, $to);
    $sold = array_values(array_filter($all, fn($o) => $o['status'] !== 'cancelled'));
    if (!$sold) return empty_state('No orders in this period yet', 'Insights appear once guests have ordered on the website.', 'bag');

    $sales = array_sum(array_column($sold, 'total'));
    $deliveries = count(array_filter($sold, fn($o) => $o['type'] === 'delivery'));
    $weekdays = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 0);
    $blocks = ['11 AM to 5 PM' => 0, '5 PM to 9 PM' => 0, '9 PM to 2 AM' => 0, '2 AM to 11 AM' => 0];
    $items = [];
    $guests = [];
    foreach ($sold as $o) {
        $weekdays[date('l', $o['ts'])]++;
        $h = (int) date('G', $o['ts']);
        $blocks[$h >= 11 && $h < 17 ? '11 AM to 5 PM' : ($h >= 17 && $h < 21 ? '5 PM to 9 PM' : ($h >= 21 || $h < 2 ? '9 PM to 2 AM' : '2 AM to 11 AM'))]++;
        foreach (order_lines($o) as $l) tally($items, $l['label'], $l['qty']);
        $who = strlen(preg_replace('/\D/', '', $o['phone'])) >= 10 ? substr(preg_replace('/\D/', '', $o['phone']), -10) : strtolower($o['email']);
        if ($who !== '') $guests[$who] = ($guests[$who] ?? 0) + 1;
    }
    $repeat = count(array_filter($guests, fn($n) => $n > 1));
    $portions = array_sum($items);

    return '<div class="stats">'
        . stat_tile('Orders', number_format(count($sold)), admin_url('orders', $periodQuery + ['s' => 'all']), count($all) > count($sold) ? h(plural(count($all) - count($sold), 'cancelled order')) : '', 'bag')
        . stat_tile('Sales before tax', fmt_money($sales), admin_url('orders', $periodQuery + ['s' => 'all']), '', 'dollar')
        . stat_tile('Average order', fmt_money((int) round($sales / count($sold))), admin_url('orders', $periodQuery + ['s' => 'all']), '', 'chart')
        . stat_tile('Delivery', round($deliveries * 100 / count($sold)) . '%', admin_url('orders', $periodQuery + ['s' => 'all']), h((count($sold) - $deliveries) . ' pickup, ' . $deliveries . ' delivery'), 'truck')
        . stat_tile('Guests who came back', number_format($repeat), admin_url('orders', $periodQuery + ['s' => 'all']), 'ordered more than once', 'users')
        . '</div><div class="grid g2">'
        . card('', column_chart('Orders', ($from === $to ? $from : $from . ' to ' . $to) . ', cancelled orders left out', order_points($sold, $from, $to), 'order'), '', 'chartcard wide')
        . card('Best sellers', bar_list(top($items, 10), $portions, 'sold') . '<p class="hint">Portions sold, from the item lines on each order.</p>')
        . card('Busiest days', bar_list($weekdays, count($sold), 'orders'))
        . card('Busiest times', bar_list($blocks, count($sold), 'orders') . '<p class="hint">When orders were placed.</p>')
        . '</div>';
}

function admin_page_insights(): void
{
    $presets = [1, 7, 30, 120];
    $period = admin_period($presets, 30);
    $tab = get_choice('tab', ['chat', 'orders'], 'chat');
    $keepTab = $tab === 'chat' ? [] : ['tab' => $tab];
    $tabs = tabs_html([
        ['label' => 'Chat assistant', 'href' => admin_url('insights', $period['query']), 'on' => $tab === 'chat'],
        ['label' => 'Orders', 'href' => admin_url('insights', $period['query'] + ['tab' => 'orders']), 'on' => $tab === 'orders'],
    ]);

    if ($tab === 'orders') {
        $content = order_insights_html($period['from'], $period['to'], $period['query']);
        $sub = 'How online orders are going: sales, the busiest times and what sells. Cancelled orders are left out.';
    } else {
        $convos = chat_log_conversations($period['days'], $period['custom'] ? $period['from'] : null, $period['custom'] ? $period['to'] : null);
        $content = chat_insights_html(chat_insights($convos), $period['query']);
        $sub = 'What guests ask about and order in the chat. Counted from their own words, so treat it as a guide rather than exact figures.';
    }
    $body = page_head('Insights', $sub) . '<div class="toolbar">' . $tabs . '</div>' . period_bar('insights', $period, $presets, $keepTab) . $content;
    admin_render('Insights', $body, 'insights');
}
