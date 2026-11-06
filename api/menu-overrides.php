<?php
/**
 * Local menu overrides for the admin's Menu & food page (admin/?page=menu).
 *
 * The food menu itself comes live from Square (api/menu.php). This file lets the
 * restaurant adjust it locally, on top of Square, without touching Square:
 *   - hide an item from the website (enable/disable)
 *   - override a variation's price
 *   - mark an item pickup-only (blocked from delivery orders)
 *   - add a per-item delivery surcharge (cents) and a per-item prep time (minutes)
 *
 * Because orders are cash on delivery (no Square charge), the price shown here is
 * exactly what the guest pays, so local price changes are safe. Stored privately
 * in api/data/menu-overrides.json (never committed).
 */
require_once __DIR__ . '/lib.php';

/** The saved overrides, as ['items' => [itemId => [...]]]. */
function read_menu_overrides(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $data = db_setting('menu_overrides');
    } catch (Throwable $e) {
        $data = null;
    }
    return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data : ['items' => []];
}

function write_menu_overrides(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('menu_overrides', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_menu_overrides: ' . $e->getMessage());
        return false;
    }
}

// ---------- schedules (specials on certain days, times or dates) ----------

/**
 * An item's schedule: ['days' => ['TUE'], 'from' => '11:00', 'to' => '15:00',
 * 'start' => '2026-12-20', 'end' => '2027-01-02'], every part optional; no
 * schedule = always on. True when the item should be on the menu at $now
 * (restaurant time). A time window like 22:00–02:00 runs past midnight, and the
 * hours after midnight count as the day it started.
 */
function schedule_active(?array $s, ?DateTimeImmutable $now = null): bool
{
    if (!$s) return true;
    $now = $now ?? new DateTimeImmutable('now');
    $date = $now->format('Y-m-d');
    if (!empty($s['start']) && $date < $s['start']) return false;
    if (!empty($s['end']) && $date > $s['end']) return false;
    $time = $now->format('H:i');
    $from = (string) ($s['from'] ?? '');
    $to = (string) ($s['to'] ?? '');
    $day = strtoupper($now->format('D'));
    if ($from !== '' && $to !== '' && $from > $to) {
        if ($time < $to) $day = strtoupper($now->modify('-1 day')->format('D'));
        elseif ($time < $from) return false;
    } else {
        if ($from !== '' && $time < $from) return false;
        if ($to !== '' && $time >= $to) return false;
    }
    return empty($s['days']) || in_array($day, $s['days'], true);
}

/** "Tue only · 11 AM–3 PM · Dec 20 – Jan 2"; '' for no schedule. */
function schedule_text(?array $s): string
{
    if (!$s) return '';
    $parts = [];
    if (!empty($s['days'])) $parts[] = days_label($s['days']) . (count($s['days']) === 1 ? ' only' : '');
    if (!empty($s['from']) && !empty($s['to'])) $parts[] = clock_label($s['from']) . '–' . clock_label($s['to']);
    $date = fn($d) => date('M j' . (substr($d, 0, 4) !== date('Y') ? ', Y' : ''), (int) strtotime($d));
    if (!empty($s['start']) && !empty($s['end'])) $parts[] = $date($s['start']) . ' – ' . $date($s['end']);
    elseif (!empty($s['start'])) $parts[] = 'from ' . $date($s['start']);
    elseif (!empty($s['end'])) $parts[] = 'until ' . $date($s['end']);
    return implode(' · ', $parts);
}

// ---------- price history (the admin's "Last price") ----------

/**
 * The price each item had before its latest change, so the admin can show
 * "Last price $14.99". Keyed by 'menu:ITEM:VARIATION', 'custom:ID',
 * 'choice:ITEM:CHOICE', 'hh:item:NAME' or 'hh:side:NAME'; each entry is
 * ['prev' => cents, 'changed' => ISO date].
 */
function read_price_history(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $data = db_setting('price_history');
    } catch (Throwable $e) {
        $data = null;
    }
    return is_array($data) ? $data : [];
}

function write_price_history(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('price_history', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_price_history: ' . $e->getMessage());
        return false;
    }
}

/** Note in $history that $key's price went from $old to $new cents (nothing when unchanged or unknown). */
function price_history_note(array &$history, string $key, ?int $old, ?int $new): void
{
    if ($old === null || $new === null || $old === $new) return;
    $history[$key] = ['prev' => $old, 'changed' => date('c')];
}

// ---------- store settings (hours, delivery, fees) ----------

/** Locally-edited store settings (empty = use the frozen snapshot's values). */
function read_store_settings(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $d = db_setting('store_settings');
    } catch (Throwable $e) {
        $d = null;
    }
    return is_array($d) ? $d : [];
}

function write_store_settings(array $data): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('store_settings', $data);
        return true;
    } catch (Throwable $e) {
        error_log('write_store_settings: ' . $e->getMessage());
        return false;
    }
}

/** Merge the locally-edited store settings over the menu's store block. */
function apply_store_settings(array $menu): array
{
    $s = read_store_settings();
    if ($s && !empty($menu['store'])) $menu['store'] = array_merge($menu['store'], $s);
    // Takeaway (pickup) is on unless Store settings switched it off.
    if (!empty($menu['store'])) $menu['store'] += ['pickupEnabled' => true];
    // Delivery zones, fees and minimums from the admin's Delivery page (api/delivery.php).
    if (!empty($menu['store'])) {
        require_once __DIR__ . '/delivery.php';
        $menu['store'] = delivery_apply_store($menu['store']);
    }
    return $menu;
}

/** Build a menu item (in the load_menu shape) from a saved custom-item entry. */
function custom_menu_item(array $c): ?array
{
    if (empty($c['id']) || empty($c['name'])) return null;
    $id = (string) $c['id'];
    $item = [
        'id'          => $id,
        'squareId'    => null,
        'name'        => (string) $c['name'],
        'description' => (string) ($c['description'] ?? ''),
        'image'       => null,
        'soldOut'     => false,
        'maxQty'      => 0,
        'variations'  => [['id' => $id . '-v', 'name' => 'Regular', 'price' => max(0, (int) ($c['price'] ?? 0)), 'soldOut' => false]],
        'modifiers'   => [],
        'custom'      => true,
    ];
    if (!empty($c['pickupOnly']))  $item['pickupOnly'] = true;
    if (!empty($c['deliveryOnly'])) $item['deliveryOnly'] = true;
    if (!empty($c['deliveryFee'])) $item['deliveryFee'] = max(0, (int) $c['deliveryFee']);
    if (!empty($c['prepMinutes'])) $item['prepMinutes'] = max(0, (int) $c['prepMinutes']);
    return $item;
}

/**
 * Apply the saved overrides to a menu array (from load_menu_base): drop hidden
 * items, override prices, attach pickupOnly / deliveryFee / prepMinutes, and add
 * the restaurant's own custom items (not in Square) so checkout and the
 * front-end treat them like any other dish.
 */
function apply_menu_overrides(array $menu): array
{
    $menu = apply_store_settings($menu); // hours, delivery, fees
    if (empty($menu['items'])) return $menu;
    $ov = read_menu_overrides();

    foreach ($menu['items'] as $id => &$item) {
        $o = ($ov['items'] ?? [])[$id] ?? null;
        if (!$o) continue;
        if (!empty($o['hidden'])) { unset($menu['items'][$id]); continue; }
        // Scheduled specials leave the menu (website, checkout, chat, table ordering) outside their days and times.
        if (!schedule_active($o['schedule'] ?? null)) { unset($menu['items'][$id]); continue; }
        if (!empty($o['prices']) && is_array($o['prices'])) {
            foreach ($item['variations'] as &$v) {
                if (isset($o['prices'][$v['id']])) $v['price'] = max(0, (int) $o['prices'][$v['id']]);
            }
            unset($v);
        }
        if (!empty($o['pickupOnly']))  $item['pickupOnly'] = true;
        if (!empty($o['deliveryOnly'])) $item['deliveryOnly'] = true;
        if (!empty($o['deliveryFee'])) $item['deliveryFee'] = max(0, (int) $o['deliveryFee']);
        if (!empty($o['prepMinutes'])) $item['prepMinutes'] = max(0, (int) $o['prepMinutes']);
        if (isset($o['description']) && $o['description'] !== '') $item['description'] = (string) $o['description'];

        // Option-group edits: hide groups/choices and re-price choices.
        if (!empty($item['modifiers']) && (!empty($o['hideGroups']) || !empty($o['hideChoices']) || !empty($o['choicePrices']))) {
            $hideG = array_flip($o['hideGroups'] ?? []);
            $hideC = array_flip($o['hideChoices'] ?? []);
            $cp = $o['choicePrices'] ?? [];
            $groups = [];
            foreach ($item['modifiers'] as $g) {
                if (isset($hideG[$g['id']])) continue;
                $choices = [];
                foreach ($g['choices'] as $c) {
                    if (isset($hideC[$c['id']])) continue;
                    if (isset($cp[$c['id']])) $c['price'] = max(0, (int) $cp[$c['id']]);
                    $choices[] = $c;
                }
                if (!$choices) continue;
                $g['choices'] = $choices;
                if ($g['min'] > count($choices)) $g['min'] = count($choices);
                $groups[] = $g;
            }
            $item['modifiers'] = $groups;
        }
    }
    unset($item);

    // Custom items: add to the menu and to their chosen category (created if gone).
    foreach ($ov['custom'] ?? [] as $c) {
        if (!schedule_active($c['schedule'] ?? null)) continue;
        $item = custom_menu_item((array) $c);
        if (!$item) continue;
        $id = $item['id'];
        $menu['items'][$id] = $item;
        $catId = (string) ($c['category'] ?? '');
        $placed = false;
        foreach ($menu['categories'] as &$cat) {
            if ($cat['id'] === $catId) { $cat['itemIds'][] = $id; $placed = true; break; }
        }
        unset($cat);
        if (!$placed) $menu['categories'][] = ['id' => $catId ?: 'extras', 'name' => (string) ($c['categoryName'] ?? 'More'), 'itemIds' => [$id]];
    }

    // Drop hidden items from their categories, then drop empty categories.
    foreach ($menu['categories'] as &$c) {
        $c['itemIds'] = array_values(array_filter($c['itemIds'], fn($id) => isset($menu['items'][$id])));
    }
    unset($c);
    $menu['categories'] = array_values(array_filter($menu['categories'], fn($c) => $c['itemIds']));
    return $menu;
}
