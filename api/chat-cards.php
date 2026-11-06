<?php
/**
 * Extras shown under the chat assistant's replies (rendered by js/chat.js):
 *
 *  - cards: show cards (poster, day, time, "Reserve a table") for shows the
 *    reply names, and the private-party packages when the reply is about them.
 *    Built from events.html and private-events.html, never from the AI's own
 *    words, so prices and times on cards are always the real ones.
 *  - suggestions: up to three follow-up questions for one-tap replies.
 */

/** Lower case, letters and digits only: "What’s the Tea?" → "whats the tea". */
function card_norm(string $s): string
{
    $s = mb_strtolower(str_replace(['’', "'"], '', $s));
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
}

/** Packages from private-events.html: [['name', 'price', 'features' => [...]], ...]. */
function party_packages(): array
{
    static $packages = null;
    if ($packages !== null) return $packages;
    $packages = [];
    $x = load_page('private-events.html');
    if (!$x) return $packages;
    // Each package box holds its price (h3), name (h4) and a list of what's included.
    foreach ($x->query('//div[h3 and h4 and ul]') as $box) {
        $price = text_of($x->query('./h3', $box)->item(0));
        if (strpos($price, '$') === false) continue;
        $features = [];
        foreach ($x->query('./ul/li', $box) as $li) $features[] = text_of($li);
        $packages[] = ['name' => text_of($x->query('./h4', $box)->item(0)), 'price' => $price, 'features' => $features];
    }
    return $packages;
}

/**
 * Menu items mentioned in the AI's reply, as cards the guest can tap to order.
 * Only shown when the reply lists or recommends dishes (2+ items found), so
 * single-item price answers don't sprout cards.
 */
function menu_cards(string $reply): array
{
    $menu = load_menu();
    if (!$menu) return [];
    $text = ' ' . card_norm($reply) . ' ';
    $found = [];
    foreach ($menu['categories'] as $cat) {
        foreach ($cat['itemIds'] as $id) {
            $item = $menu['items'][$id] ?? null;
            if (!$item || !empty($item['soldOut'])) continue;
            $n = card_norm($item['name']);
            if (strlen($n) < 4 || strpos($text, " $n ") === false) continue;
            $variations = array_filter($item['variations'], fn($v) => empty($v['soldOut']));
            if (!$variations) continue;
            $prices = array_values(array_map(fn($v) => $v['price'], $variations));
            $lo = min($prices);
            $hi = max($prices);
            $price = $lo === $hi ? money($lo) : money($lo) . ' to ' . money($hi);
            $desc = trim(preg_split('/\s*Choose one Side\b|\s*Served with choice of\b/i', $item['description'])[0]);
            $found[] = [
                'name'  => $item['name'],
                'price' => $price,
                'desc'  => mb_strimwidth($desc, 0, 60, '...'),
                'image' => $item['image'] ?? '',
                'pos'   => strpos($text, " $n "),
            ];
        }
    }
    if (count($found) < 2) return [];
    usort($found, fn($a, $b) => $a['pos'] <=> $b['pos']);
    return array_map(fn($f) => ['name' => $f['name'], 'price' => $f['price'], 'desc' => $f['desc'], 'image' => $f['image']], array_slice($found, 0, 6));
}

function chat_cards(string $question, string $reply): array
{
    $cards = [];
    $text = ' ' . card_norm($reply) . ' ';
    $asked = ' ' . card_norm("$question $reply") . ' ';

    // Shows named in the reply, in the order it names them.
    $tonight = date('l', time() - (date('G') < 4 ? 86400 : 0));
    $tonightDate = date('Y-m-d', time() - (date('G') < 4 ? 86400 : 0));
    $found = [];
    foreach (weekly_lineup()['shows'] as $show) {
        $n = card_norm($show['name']);
        if (strlen($n) < 4 || ($pos = strpos($text, " $n ")) === false) continue;
        // A show on several days: the day the conversation mentions, else tonight, else the first.
        $score = strpos($asked, ' ' . mb_strtolower($show['day']) . ' ') !== false ? 2 : ($show['day'] === $tonight ? 1 : 0);
        if (!isset($found[$n]) || $score > $found[$n]['score']) $found[$n] = ['pos' => $pos, 'score' => $score, 'show' => $show];
    }
    uasort($found, fn($a, $b) => $a['pos'] <=> $b['pos']);
    if ($found) {
        $cards[] = ['type' => 'shows', 'shows' => array_values(array_map(function ($f) use ($tonight, $tonightDate) {
            $s = $f['show'];
            // A one-night event is named by its date; a weekly show by its day.
            $day = !empty($s['date']) ? ($s['date'] === $tonightDate ? 'Tonight' : date('D, M j', strtotime($s['date']))) : ($s['day'] === $tonight ? 'Tonight' : $s['day']);
            $s['when'] = $day . ' · ' . $s['time'];
            return $s;
        }, array_slice($found, 0, 4)))];
    }

    // Private-party packages, when the reply goes through them (or recommends one for a party).
    $named = 0;
    foreach (party_packages() as $p) if (strpos($text, ' ' . card_norm($p['name']) . ' ') !== false) $named++;
    $party = preg_match('/\b(party|birthday|private|event|buyout|corporate|celebrat|shower|anniversary)/', card_norm($question));
    if ($named >= 2 || ($named === 1 && $party)) $cards[] = ['type' => 'packages', 'packages' => party_packages()];

    // Food items the reply recommends or lists: tappable cards to start ordering.
    $menuItems = menu_cards($reply);
    if ($menuItems) $cards[] = ['type' => 'menu', 'items' => $menuItems];

    return $cards;
}

/** Up to three follow-up questions, based on what was just discussed. */
function chat_suggestions(string $question, string $reply, array $topics, array $cards, bool $ordered): array
{
    $q = card_norm($question);
    $r = card_norm($reply);
    $types = array_column($cards, 'type');
    $pool = [];
    if ($ordered) array_push($pool, 'Add something else to my order', 'How long until my order is ready?', 'Do you deliver to my area?');
    if (in_array('shows', $types, true)) array_push($pool, 'Can I book a table?', "What's on tomorrow?");
    if (in_array('packages', $types, true) || preg_match('/\b(party|birthday|private|event|buyout|corporate)\b/', $q)) {
        array_push($pool, 'How do I plan a private event?', 'Talk to a person');
    }
    if (in_array('food', $topics, true) && !$ordered) {
        if (preg_match('/\b(recommend|popular|suggest|what.s good|favorite|favourit)\b/', $q)) {
            array_push($pool, "I'll order the wings", "I'll have a burger", 'Browse the full menu');
        } else {
            array_push($pool, 'Browse the full menu', "What's popular?", 'What are the Happy Hour deals?');
        }
    }
    if (in_array('drinks', $topics, true)) array_push($pool, 'What are the Happy Hour deals?', "What's on tonight?");
    if (preg_match('/\b(not sure|please call|give us a call)\b/', $r)) $pool[] = 'Talk to a person';
    array_push($pool, "What's on tonight?", 'Can I book a table?', 'What are the Happy Hour deals?');

    // Not what was just asked, no repeats.
    $out = [];
    foreach ($pool as $s) {
        if (count($out) === 3) break;
        if (in_array($s, $out, true) || card_norm($s) === $q) continue;
        $out[] = $s;
    }
    return $out;
}
