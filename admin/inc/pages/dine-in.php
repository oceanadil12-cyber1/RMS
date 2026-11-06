<?php
/**
 * Dine-In: staff ordering interface for dine-in, takeaway and delivery orders.
 *
 * Pick the order type (a table number for dine-in; phone and address for
 * delivery), browse the menu, build an order, add special instructions, and
 * submit — the order appears on the Kitchen Display System automatically.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_dine_in(): void
{
    $menu = load_menu();
    $categories = [];
    $items = [];
    if ($menu && !empty($menu['items'])) {
        foreach ($menu['categories'] as $category) {
            foreach ($category['itemIds'] as $id) {
                $item = $menu['items'][$id] ?? null;
                if (!$item || !empty($item['soldOut']) || !empty($item['hidden'])) continue;
                $categories[$category['name']][] = $id;
                $items[$id] = $item;
            }
        }
    }

    $menuJson = json_encode([
        'categories' => $categories,
        'items' => array_map(function ($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'variations' => array_map(fn($v) => [
                    'id' => $v['id'],
                    'name' => $v['name'],
                    'price' => $v['price'],
                    'soldOut' => !empty($v['soldOut']),
                ], $item['variations'] ?? []),
                'modifiers' => array_map(fn($g) => [
                    'name' => $g['name'],
                    'min' => $g['min'],
                    'max' => $g['max'],
                    'choices' => array_map(fn($c) => [
                        'id' => $c['id'],
                        'name' => $c['name'],
                        'price' => $c['price'],
                        'soldOut' => !empty($c['soldOut']),
                    ], $g['choices'] ?? []),
                ], $item['modifiers'] ?? []),
            ];
        }, $items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $body = page_head('Dine-In Order',
        'Create a dine-in, takeaway or delivery order. It will appear on the Kitchen Display automatically.',
        btn_link('Open Kitchen Display', '../kds/', '', 'external', ' target="_blank"'))
    . '<div id="dine-in-app" data-menu=\'' . h($menuJson) . '\' data-api="' . h(admin_url('', ['api' => 'dine-in'])) . '">'
    . '<div class="split">'

    // left: menu
    . '<div class="stack" id="dinein-menu-col">'
    .   '<div class="card">'
    .     '<header class="card-head"><div id="dinein-cat-tabs" class="tabs" aria-label="Menu categories"></div></header>'
    .     '<div class="card-body"><div id="dinein-items" class="dinein-items"></div></div>'
    .   '</div>'
    . '</div>'

    // right: order
    . '<div class="stack" id="dinein-order-col">'
    .   '<div class="card">'
    .     '<header class="card-head"><h2>Order</h2></header>'
    .     '<div class="card-body">'
    .       '<div id="dinein-type" class="tabs dinein-type" role="radiogroup" aria-label="Order type">'
    .         '<a href="#" class="on" data-type="dine-in" role="radio" aria-checked="true">Dine-In</a>'
    .         '<a href="#" data-type="pickup" role="radio" aria-checked="false">Takeaway</a>'
    .         '<a href="#" data-type="delivery" role="radio" aria-checked="false">Delivery</a>'
    .       '</div>'
    .       '<label class="field" id="dinein-table-field"><span>Table Number</span>'
    .         '<input type="number" id="dinein-table" min="1" max="999" placeholder="Enter table #" required>'
    .       '</label>'
    .       '<label class="field"><span>Guest Name</span>'
    .         '<input type="text" id="dinein-guest" maxlength="80" placeholder="Optional">'
    .       '</label>'
    .       '<div id="dinein-delivery-fields" hidden>'
    .         '<label class="field"><span>Phone</span>'
    .           '<input type="tel" id="dinein-phone" maxlength="30" placeholder="For the driver" autocomplete="off">'
    .         '</label>'
    .         '<label class="field"><span>Delivery Address</span>'
    .           '<textarea id="dinein-address" rows="2" maxlength="300" placeholder="Street, unit, city, ZIP"></textarea>'
    .         '</label>'
    .       '</div>'
    .       '<div id="dinein-cart" class="dinein-cart">'
    .         '<p class="muted" id="dinein-cart-empty">No items added yet. Select items from the menu.</p>'
    .       '</div>'
    .       '<label class="field"><span>Special Instructions / Kitchen Notes</span>'
    .         '<textarea id="dinein-notes" rows="2" maxlength="500" placeholder="Allergies, cooking preferences, etc."></textarea>'
    .       '</label>'
    .       '<div class="bar">'
    .         '<span id="dinein-total" class="dinein-total"></span>'
    .         '<button class="btn primary" id="dinein-submit" disabled>'
    .           icon('check', 16) . '<span>Send to Kitchen</span>'
    .         '</button>'
    .       '</div>'
    .       '<div id="dinein-result" hidden></div>'
    .     '</div>'
    .   '</div>'
    . '</div>'

    . '</div></div>';

    admin_render('Dine-In', $body, 'dine-in', ['dine-in.js']);
}
