<?php
/**
 * Food ordering from the chat assistant.
 *
 * The AI can call the add_to_order tool with dishes in plain words ("12 wings,
 * BBQ"). resolve_order() matches them against the live Square menu, checks the
 * size and every required choice (sauce, side, cooking temperature), and returns
 * lines for the site's normal cart (js/order.js). Anything unclear is sent back
 * to the AI so it can ask the guest.
 *
 * Nothing is charged here: the guest reviews the cart, picks pickup or delivery
 * and pays in the usual checkout, which re-prices every line (checkout.php).
 */

const ORDER_TOOL = [
    'type'     => 'function',
    'function' => [
        'name'        => 'add_to_order',
        'description' => "Add dishes from the food menu to the guest's online order (their cart on the website). "
            . 'Call it when the guest says they want to order something (e.g. "I\'ll have wings", "can I get a burger", "add nachos"). '
            . 'Include only the dishes, sizes and choices the guest actually said. '
            . 'Never pick a size or choice for the guest: leave it out and the result tells you what to ask. '
            . 'You can add multiple items in one call. The result lists what was added and what still needs an answer.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'dish'     => ['type' => 'string', 'description' => 'Dish name from the food menu, e.g. "Chicken Wings", "The Champ Burger", "Mexican Street Tacos". Use menu names; close matches are accepted.'],
                            'size'     => ['type' => 'string', 'description' => 'Size or version when the dish has several, e.g. "12 Wings", "6 Wings", "Beef", "Shrimp"'],
                            'quantity' => ['type' => 'integer', 'description' => 'How many (1-20). Default 1.'],
                            'choices'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only options the guest said (sauce like "BBQ" or "Hot", side like "fries", temperature like "medium rare"). Empty if they said none.'],
                            'note'     => ['type' => 'string', 'description' => 'Special instructions: "no onions", "extra cheese", "well done", "gluten-free bun", etc.'],
                            'extra'    => ['type' => 'boolean', 'description' => 'True only when the guest asked for MORE of a dish already in their order'],
                        ],
                        'required'   => ['dish', 'quantity'],
                    ],
                ],
            ],
            'required'   => ['items'],
        ],
    ],
];

/** Lower case, punctuation removed, and a few spellings made to match Square's names. */
function order_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace(['/\bmedium\b/', '/\bwell done\b/', '/\bbarbecue\b|\bbar b q\b/', '/\bfry\b/'], ['med', 'well', 'bbq', 'fries'], $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Finds $want among $names (key => name). Returns [key, candidates]: key is null
 * when nothing fits or several fit equally well (candidates then lists them).
 */
function order_match(string $want, array $names, bool $partial = false): array
{
    [$key, $candidates] = order_match_exactish($want, $names);
    if ($key !== null || !$partial) return [$key, $candidates];

    // Dish names only: the guest's words may include an option ("hot wings").
    // Names sharing some words, adding at most one of their own, count as a match.
    $words = explode(' ', order_norm($want));
    $fits = [];
    foreach ($names as $k => $name) {
        $nameWords = explode(' ', order_norm($name));
        $shared = count(array_intersect($nameWords, $words));
        if ($shared > 0) $fits[$k] = count($nameWords) - $shared;
    }
    $close = array_keys(array_filter($fits, fn($extra) => $extra <= 1));
    if (count($close) === 1) return [$close[0], []];
    return [null, $candidates ?: array_map(fn($k) => $names[$k], $close ?: array_keys($fits))];
}

function order_match_exactish(string $want, array $names): array
{
    $w = order_norm($want);
    if ($w === '') return [null, []];
    $norm = array_map('order_norm', $names);

    $exact = array_keys($norm, $w, true);
    if (count($exact) === 1) return [$exact[0], []];

    // Names containing every word the guest said. One that adds at most one word
    // is a match ("wings" → "Chicken Wings"); otherwise ask ("shrimp" tacos →
    // grilled or fried shrimp?).
    $words = explode(' ', $w);
    $fits = [];
    foreach ($norm as $key => $n) {
        $nameWords = explode(' ', $n);
        if (!array_diff($words, $nameWords)) $fits[$key] = count($nameWords) - count($words);
        elseif (strlen($n) >= 3 && strpos(" $w ", " $n ") !== false) $fits[$key] = 0; // "medium rare please" → "Med Rare"
    }
    if ($fits) {
        $close = array_keys(array_filter($fits, fn($extra) => $extra <= 1));
        if (count($close) === 1) return [$close[0], []];
        return [null, array_map(fn($k) => $names[$k], array_keys($fits))];
    }

    // Typos: the closest spelling if clearly close; a few suggestions if roughly close.
    $scores = [];
    foreach ($norm as $key => $n) { similar_text($w, $n, $pct); $scores[$key] = $pct; }
    arsort($scores);
    $key = array_key_first($scores);
    if ($key !== null && $scores[$key] >= 78) return [$key, []];
    $near = array_keys(array_filter($scores, fn($pct) => $pct >= 60));
    return [null, array_map(fn($k) => $names[$k], array_slice($near, 0, 3))];
}

function order_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/**
 * The guest's current cart (sent by the browser with each message, plus lines
 * added during this request), so dishes aren't added twice by mistake.
 * Call with an array to set it.
 */
function current_order(?array $set = null): array
{
    static $lines = [];
    if ($set !== null) $lines = $set;
    return $lines;
}

/** Same dish, size and options? */
function order_same_line(array $a, array $b): bool
{
    $mods = fn($l) => implode(',', array_map('strval', array_sort_copy((array) ($l['modifiers'] ?? []))));
    return (string) $a['itemId'] === (string) $b['itemId'] && (string) $a['variationId'] === (string) $b['variationId'] && $mods($a) === $mods($b);
}

function array_sort_copy(array $a): array
{
    sort($a);
    return $a;
}

/** "2 × Chicken Wings (12 Wings, BBQ)" for a cart line, or '' if it's no longer on the menu. */
function order_line_label(array $line, array $menu): string
{
    $it = $menu['items'][$line['itemId']] ?? null;
    if (!$it) return '';
    $v = array_values(array_filter($it['variations'], fn($x) => $x['id'] === $line['variationId']))[0] ?? null;
    if (!$v) return '';
    $details = count($it['variations']) > 1 ? [$v['name']] : [];
    foreach ($it['modifiers'] as $g) foreach ($g['choices'] as $c) if (in_array($c['id'], (array) $line['modifiers'], true)) $details[] = $c['name'];
    return max(1, (int) $line['qty']) . " × $it[name]" . ($details ? ' (' . implode(', ', $details) . ')' : '');
}

/** The guest's cart as text for the AI ("1 × Edamame; 2 × Tacos (Tofu)"), '' when empty. */
function current_order_text(): string
{
    $menu = current_order() ? load_menu() : null;
    if (!$menu) return '';
    return implode('; ', array_filter(array_map(fn($l) => order_line_label($l, $menu), current_order())));
}

/** "Hot, Mild, BBQ (+$2.00)" */
function order_choice_list(array $choices): string
{
    return implode(', ', array_map(fn($c) => $c['name'] . ($c['price'] ? ' (+' . order_money($c['price']) . ')' : ''), array_filter($choices, fn($c) => empty($c['soldOut']))));
}

/**
 * Turns the tool's items into cart lines. Returns [$lines, $result]: $lines for
 * the browser (with a label and total for the chat), $result for the AI.
 */
function resolve_order(array $items): array
{
    $menu = load_menu();
    if (!$menu) return [[], ['error' => 'The online menu is unavailable right now. Ask the guest to call (954) 565-2526 to order.']];

    // Only dishes that are on the menu today.
    $names = [];
    foreach ($menu['categories'] as $cat) foreach ($cat['itemIds'] as $id) if (isset($menu['items'][$id])) $names[$id] = $menu['items'][$id]['name'];

    $lines = [];
    $added = [];
    $problems = [];
    foreach (array_slice($items, 0, 10) as $req) {
        $want = trim((string) ($req['dish'] ?? ''));
        $qty = max(1, min(20, (int) ($req['quantity'] ?? 1)));
        [$id, $candidates] = order_match($want, $names, true);
        if ($id === null) {
            $problems[] = ['dish' => $want, 'problem' => $candidates
                ? 'Which dish did the guest mean: ' . implode(', ', $candidates) . '?'
                : 'Not on the online food menu (drinks can\'t be ordered online).'];
            continue;
        }
        $it = $menu['items'][$id];
        if (!empty($it['soldOut'])) { $problems[] = ['dish' => $it['name'], 'problem' => 'Sold out today.']; continue; }

        $choicesWanted = array_values(array_filter(array_map('strval', (array) ($req['choices'] ?? []))));
        $itemProblems = [];

        // Size / version (the AI sometimes puts it among the choices instead).
        $variations = array_filter($it['variations'], fn($v) => empty($v['soldOut']));
        $varNames = array_map(fn($v) => $v['name'], $variations);
        $variation = null;
        if (count($variations) === 1) {
            $variation = reset($variations);
        } else {
            // Also try the words of the dish the guest said beyond its menu name ("shrimp" in "shrimp tacos").
            $extraWords = implode(' ', array_diff(explode(' ', order_norm($want)), explode(' ', order_norm($it['name']))));
            $narrowed = [];
            foreach (array_merge([(string) ($req['size'] ?? ''), $extraWords], $choicesWanted) as $i => $try) {
                if ($try === '') continue;
                [$k, $candidates] = order_match($try, $varNames);
                if ($k !== null) {
                    $variation = $variations[$k];
                    if ($i === 1) $extraWords = '';
                    if ($i > 1) unset($choicesWanted[$i - 2]);
                    break;
                }
                if ($candidates && !$narrowed) $narrowed = $candidates;
            }
            if (!$variation) {
                $options = $narrowed
                    ? array_filter($variations, fn($v) => in_array($v['name'], $narrowed, true))
                    : $variations;
                $itemProblems[] = 'Needs a size/version' . ($narrowed ? ' (the guest said something that fits more than one)' : '') . ': '
                    . implode(', ', array_map(fn($v) => $v['name'] . ' ' . order_money($v['price']), $options)) . '.';
            }
        }

        // Options: match each wanted choice to a group of this dish.
        $picked = []; // group index => [choice, ...]
        // Words in the dish name may be an option too ("hot wings" → sauce: Hot). Quietly skipped if not.
        $leftover = count($variations) === 1
            ? implode(' ', array_diff(explode(' ', order_norm($want)), explode(' ', order_norm($it['name']))))
            : ($extraWords ?? '');
        if ($leftover !== '') {
            foreach ($it['modifiers'] as $gi => $g) {
                $available = array_filter($g['choices'], fn($c) => empty($c['soldOut']));
                [$ci] = order_match($leftover, array_map(fn($c) => $c['name'], $available));
                if ($ci !== null && !in_array($available[$ci], $picked[$gi] ?? [], true)) { $picked[$gi][] = $available[$ci]; break; }
            }
        }
        foreach ($choicesWanted as $want2) {
            // Already picked from the dish name ("hot wings" + choice "hot").
            foreach ($picked as $group) foreach ($group as $c) if (order_norm($c['name']) === order_norm($want2)) continue 3;
            $found = false;
            $maybe = [];
            foreach ($it['modifiers'] as $gi => $g) {
                $available = array_filter($g['choices'], fn($c) => empty($c['soldOut']));
                [$ci, $candidates] = order_match($want2, array_map(fn($c) => $c['name'], $available));
                if ($ci === null) { $maybe = array_merge($maybe, $candidates); continue; }
                $full = $g['max'] > 0 && count($picked[$gi] ?? []) >= $g['max'];
                if ($full) continue;
                $picked[$gi][] = $available[$ci];
                $found = true;
                break;
            }
            if ($found) continue;
            $itemProblems[] = $maybe
                ? "\"$want2\" could mean: " . implode(', ', array_unique($maybe)) . '. Ask which one.'
                : "\"$want2\" isn't an option for this dish.";
        }
        foreach ($it['modifiers'] as $gi => $g) {
            $count = count($picked[$gi] ?? []);
            if ($count >= $g['min']) continue;
            $defaults = array_values(array_filter($g['choices'], fn($c) => !empty($c['default']) && empty($c['soldOut'])));
            if (count($defaults) >= $g['min'] - $count) { $picked[$gi] = array_merge($picked[$gi] ?? [], array_slice($defaults, 0, $g['min'] - $count)); continue; }
            $itemProblems[] = "Needs \"$g[name]\" (choose " . ($g['min'] > 1 ? $g['min'] : 'one') . '): ' . order_choice_list($g['choices']) . '.';
        }

        if ($itemProblems) { $problems[] = ['dish' => $it['name'], 'problem' => implode(' ', $itemProblems)]; continue; }

        $mods = array_merge(...array_values($picked ?: [[]]));
        $unit = $variation['price'] + array_sum(array_map(fn($c) => $c['price'], $mods));
        $details = array_merge(count($it['variations']) > 1 ? [$variation['name']] : [], array_map(fn($c) => $c['name'], $mods));
        $label = "$qty × $it[name]" . ($details ? ' (' . implode(', ', $details) . ')' : '');
        $note = mb_substr(trim((string) ($req['note'] ?? '')), 0, 200);
        $line = [
            'itemId'      => $it['id'] ?? $id,
            'variationId' => $variation['id'],
            'qty'         => $qty,
            'modifiers'   => array_map(fn($c) => $c['id'], $mods),
            'note'        => $note,
        ];
        // AIs tend to re-send the whole order when the guest answers a question.
        if (empty($req['extra'])) {
            $existing = array_values(array_filter(current_order(), fn($l) => order_same_line($l, $line)));
            if ($existing) {
                $problems[] = ['dish' => $it['name'], 'problem' => 'Already in the order, so not added again. Only if the guest asked for more, call again with extra: true.'];
                continue;
            }
        }
        $lines[] = $line + ['label' => $label, 'total' => $unit * $qty];
        current_order(array_merge(current_order(), [$line]));
        $added[] = "$label " . order_money($unit * $qty);
    }

    $today = strtoupper(date('D'));
    $hours = array_map(fn($s) => date('g:i A', strtotime($s['open'])) . '-' . date('g:i A', strtotime($s['close'])), $menu['store']['pickupHours'][$today] ?? []);
    return [$lines, [
        'added_to_order' => $added,
        'not_added'      => $problems,
        'online_ordering_hours_today' => $hours ? implode(', ', $hours) : 'closed today',
        'next_step'      => $added
            ? 'Tell the guest what was added and the price. Ask "Anything else?" If they say no, tell them to tap "Review order" to choose pickup or delivery and place it. Payment is cash on delivery.'
            : 'Nothing was added yet. Ask the guest about the items in not_added. Be specific: name the missing choice and list the options.',
    ]];
}
