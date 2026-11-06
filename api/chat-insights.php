<?php
/**
 * Trends from the chat history, for the Insights page of the admin (admin/?page=insights).
 *
 * Everything is counted from the guests' own words with keyword lists (below,
 * easy to extend) plus what the chat logged: dishes added to orders and the
 * shows its cards showed. It's a guide to what guests care about, not exact
 * statistics; real sales are in Square.
 */

/** What guests ask about. A question can count for several topics. */
const INSIGHT_TOPICS = [
    'Shows & events'          => '/\b(show|shows|drag|karaoke|music|dj|dance|dancers?|go-?go|lineup|perform|bingo|latin|entertain|tonight|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/',
    'Food & menu'             => '/\b(food|eat|menu|dish|kitchen|hungry|burgers?|wings?|tacos?|nachos?|salads?|sandwich|pizza|flatbread|fries|dessert|appetizer|vegan|vegetarian|gluten|allerg)/',
    'Drinks'                  => '/\b(drinks?|cocktails?|margaritas?|martinis?|beer|wine|vodka|tequila|rum|shots?|sangria|frozen|alcohol)\b/',
    'Happy Hour & deals'      => '/\b(happy hour|deals?|specials?|2 ?for ?1|two for one|discount|cheap)\b/',
    'Table reservations'      => '/\b(book|booking|reserv|table)/',
    'Private events'          => '/\b(private|party|parties|birthday|corporate|buyout|celebrat|anniversary|shower|wedding|bachelor|graduation|package)/',
    'Ordering & delivery'     => '/\b(order|deliver|delivery|pickup|pick up|takeout|take out)/',
    'Hours, location & parking' => '/\b(open|close|closing|hours|address|where|parking|park|location|directions|far)\b/',
    'Jobs'                    => '/\b(job|jobs|hiring|hire|apply|application|work there|work here|career)\b/',
];

/** Food interest groups: [label => pattern]. */
const INSIGHT_FOODS = [
    'Wings'                    => '/\bwings?\b/',
    'Burgers'                  => '/\bburgers?\b/',
    'Tacos & Mexican'          => '/\b(tacos?|nachos?|quesadillas?|guacamole|salsa|mexican)\b/',
    'Salads'                   => '/\bsalads?\b/',
    'Flatbreads & pizza'       => '/\b(flatbreads?|pizzas?)\b/',
    'Sandwiches & wraps'       => '/\b(sandwich|sandwiches|wraps?|reuben|cuban|gyro|blt|melt)\b/',
    'Seafood'                  => '/\b(shrimp|mahi|fish|snapper|tuna|clam|seafood|crab)\b/',
    'Asian dishes'             => '/\b(sesame|general tso|orange chicken|potstickers?|rangoon|edamame|fried rice|asian)\b/',
    'Appetizers & sides'       => '/\b(appetizers?|starters?|fries|onion rings|tots|sides?|mozzarella|egg rolls?)\b/',
    'Desserts'                 => '/\b(desserts?|cake|pudding|sweet)\b/',
    'Vegan / vegetarian'       => '/\b(vegan|vegetarian|veggie|plant based|tofu)\b/',
    'Allergies / gluten-free'  => '/\b(allerg\w*|gluten|nut|nuts|dairy|celiac)\b/',
];

const INSIGHT_OCCASIONS = [
    'Birthday'            => '/\b(birthday|bday)\b/',
    'Corporate / team'    => '/\b(corporate|company|team|office|work party|holiday party|staff)\b/',
    'Wedding / engagement' => '/\b(wedding|engagement|engaged|rehearsal)\b/',
    'Bachelor(ette)'      => '/\bbachelor(ette)?\b/',
    'Shower'              => '/\b(baby shower|bridal shower|shower)\b/',
    'Anniversary'         => '/\banniversary\b/',
    'Graduation'          => '/\b(graduation|grad)\b/',
];

const INSIGHT_PARTY = '/\b(private|party|parties|birthday|corporate|buyout|celebrat\w*|anniversary|shower|wedding|bachelor\w*|graduation|package|event space|rent the)\b/';
const INSIGHT_BUDGET = '/\b(budget|expensive|too much|afford|cheaper|cheapest|discount|lower price|less than|under \$?\d|over budget|pricey|negotiat\w*|payment plan|deposit)\b/';
const INSIGHT_PRICE = '/\b(price|prices|cost|costs|how much|rate|rates|minimum|per person|\$\d)/';
const INSIGHT_UNANSWERED = "/\b(not sure|i don'?t know|don'?t have (that|any|information|details)|isn'?t (listed|available)|not listed|unable to|can'?t find)\b/";

/** Adds one to $counts[$key]. */
function tally(array &$counts, string $key, int $by = 1): void
{
    $counts[$key] = ($counts[$key] ?? 0) + $by;
}

/** Highest first, top $n. */
function top(array $counts, int $n = 8): array
{
    arsort($counts);
    return array_slice($counts, 0, $n, true);
}

function chat_insights(array $convos): array
{
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $out = [
        'questions' => 0, 'conversations' => count($convos), 'ordering_convos' => 0, 'items_ordered' => 0,
        'topics' => [], 'foods' => [], 'ordered' => [], 'shows' => [],
        'days_asked' => array_fill_keys($days, 0), 'chat_days' => array_fill_keys($days, 0),
        'chat_hours' => ['11 AM to 5 PM' => 0, '5 PM to 9 PM' => 0, '9 PM to 2 AM' => 0, '2 AM to 11 AM' => 0],
        'party' => ['convos' => 0, 'occasions' => [], 'sizes' => ['Up to 20' => 0, '21 to 60' => 0, '61 to 100' => 0, 'Over 100' => 0],
                    'size_list' => [], 'price_questions' => 0, 'budget_convos' => 0, 'budget_quotes' => [], 'packages' => ['Bronze' => 0, 'Gold' => 0, 'Platinum' => 0]],
        'unanswered' => [],
    ];

    foreach ($convos as $cid => $c) {
        $isParty = false;
        $budget = false;
        $ordered = false;
        foreach ($c['turns'] as $t) {
            $q = mb_strtolower((string) ($t['question'] ?? ''));
            $reply = (string) ($t['reply'] ?? '');
            $ts = strtotime($t['time'] ?? 'now');
            $out['questions']++;

            // Topics and food interest.
            $any = false;
            foreach (INSIGHT_TOPICS as $label => $re) if (preg_match($re, $q)) { tally($out['topics'], $label); $any = true; }
            if (!$any) tally($out['topics'], 'Other / small talk');
            foreach (INSIGHT_FOODS as $label => $re) if (preg_match($re, $q)) tally($out['foods'], $label);

            // Dishes the chat put in the cart: "2 × Chicken Wings (12 Wings, BBQ)".
            foreach ($t['ordered'] ?? [] as $label) {
                if (preg_match('/^(\d+)\s*×\s*([^(]+)/u', $label, $m)) {
                    tally($out['ordered'], trim($m[2]), (int) $m[1]);
                    $out['items_ordered'] += (int) $m[1];
                    $ordered = true;
                }
            }

            // Shows the reply's cards showed (logged by chat.php).
            foreach ($t['shows'] ?? [] as $show) tally($out['shows'], $show);

            // Days guests ask about. The bar's day runs until 2 AM (4 AM to be safe).
            $barDay = date('l', $ts - (date('G', $ts) < 4 ? 86400 : 0));
            foreach ($days as $d) if (strpos($q, mb_strtolower($d)) !== false) $out['days_asked'][$d]++;
            if (preg_match('/\b(tonight|today|this evening|right now)\b/', $q)) $out['days_asked'][$barDay]++;
            if (preg_match('/\btomorrow\b/', $q)) $out['days_asked'][date('l', strtotime("$barDay +1 day"))]++;
            if (preg_match('/\bweekend\b/', $q)) foreach (['Friday', 'Saturday', 'Sunday'] as $d) $out['days_asked'][$d]++;

            // When guests chat.
            $out['chat_days'][$barDay]++;
            $h = (int) date('G', $ts);
            $slot = $h >= 11 && $h < 17 ? '11 AM to 5 PM' : ($h >= 17 && $h < 21 ? '5 PM to 9 PM' : ($h >= 21 || $h < 2 ? '9 PM to 2 AM' : '2 AM to 11 AM'));
            $out['chat_hours'][$slot]++;

            // Private parties.
            if (preg_match(INSIGHT_PARTY, $q)) $isParty = true;
            if ($isParty) {
                foreach (INSIGHT_OCCASIONS as $label => $re) if (preg_match($re, $q)) tally($out['party']['occasions'], $label);
                if (preg_match_all('/\b(?:party of|group of|for)\s+(\d{1,3})\b|\b(\d{1,3})\s*(?:people|guests|persons|pax|ppl|friends|of us|adults)\b/', $q, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        $n = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                        if ($n < 2 || $n > 500) continue;
                        $out['party']['size_list'][] = $n;
                        $bucket = $n <= 20 ? 'Up to 20' : ($n <= 60 ? '21 to 60' : ($n <= 100 ? '61 to 100' : 'Over 100'));
                        $out['party']['sizes'][$bucket]++;
                    }
                }
                if (preg_match(INSIGHT_PRICE, $q)) $out['party']['price_questions']++;
                if (preg_match(INSIGHT_BUDGET, $q)) {
                    $budget = true;
                    if (count($out['party']['budget_quotes']) < 6) $out['party']['budget_quotes'][] = ['cid' => $cid, 'time' => $t['time'], 'text' => $t['question']];
                }
                foreach (['Bronze', 'Gold', 'Platinum'] as $pkg) if (stripos($reply, $pkg) !== false || stripos($q, $pkg) !== false) tally($out['party']['packages'], $pkg);
            }

            // Questions the assistant couldn't answer: things to add to chatbot-knowledge.md.
            if (preg_match(INSIGHT_UNANSWERED, mb_strtolower($reply))) {
                $out['unanswered'][] = ['cid' => $cid, 'time' => $t['time'], 'text' => $t['question']];
            }
        }
        if ($isParty) $out['party']['convos']++;
        if ($budget) $out['party']['budget_convos']++;
        if ($ordered) $out['ordering_convos']++;
    }

    $out['topics'] = top($out['topics'], 10);
    $out['foods'] = top($out['foods'], 12);
    $out['ordered'] = top($out['ordered'], 10);
    $out['shows'] = top($out['shows'], 10);
    $out['party']['occasions'] = top($out['party']['occasions'], 8);
    $out['unanswered'] = array_slice(array_reverse($out['unanswered']), 0, 10);
    return $out;
}
