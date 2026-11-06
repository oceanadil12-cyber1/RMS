<?php
/**
 * Customer-facing dine-in ordering — entry point.
 *
 * Reached via /dine-in/table/{N}. The URL never changes during the
 * entire flow (welcome → menu → cart → confirmation → reset).
 */

require_once __DIR__ . '/../api/lib.php';

// Apache rewrites /dine-in/table/{N} → ?table=N. For PHP's built-in dev
// server (no .htaccess), fall back to parsing REQUEST_URI directly.
$table = (int) ($_GET['table'] ?? 0);
if ($table < 1 && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/dine-in/table/(\d+)#', $_SERVER['REQUEST_URI'], $m)) {
        $table = (int) $m[1];
    }
}
if ($table < 1 || $table > 999) send_not_found();

// Links start from the site's own folder, so the page works at the domain root or in a folder.
$base = htmlspecialchars(site_base(), ENT_QUOTES);

require_once __DIR__ . '/../api/menu.php';
$menuData = load_menu();

$menuJson = null;
if ($menuData && !empty($menuData['categories']) && !empty($menuData['items'])) {
    $cats = [];
    $items = [];
    foreach ($menuData['categories'] as $cat) {
        $catItems = [];
        foreach ($cat['itemIds'] as $id) {
            if (!isset($menuData['items'][$id])) continue;
            $it = $menuData['items'][$id];
            if (!empty($it['soldOut']) || !empty($it['hidden'])) continue;
            $catItems[] = $id;
            if (!isset($items[$id])) {
                $items[$id] = [
                    'id'          => $it['id'],
                    'name'        => $it['name'],
                    'description' => $it['description'] ?? '',
                    'image'       => $it['image'] ?? null,
                    'soldOut'     => !empty($it['soldOut']),
                    'variations'  => array_map(fn($v) => [
                        'id'      => $v['id'],
                        'name'    => $v['name'],
                        'price'   => $v['price'],
                        'soldOut' => !empty($v['soldOut']),
                    ], $it['variations'] ?? []),
                    'modifiers'   => array_map(fn($g) => [
                        'id'      => $g['id'] ?? '',
                        'name'    => $g['name'],
                        'min'     => $g['min'],
                        'max'     => $g['max'],
                        'choices' => array_map(fn($c) => [
                            'id'      => $c['id'],
                            'name'    => $c['name'],
                            'price'   => $c['price'],
                            'default' => !empty($c['default']),
                            'soldOut' => !empty($c['soldOut']),
                        ], $g['choices'] ?? []),
                    ], $it['modifiers'] ?? []),
                ];
            }
        }
        if ($catItems) {
            $cats[] = ['id' => $cat['id'], 'name' => $cat['name'], 'itemIds' => $catItems];
        }
    }
    $menuJson = json_encode(['categories' => $cats, 'items' => $items],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Dine-In — Table <?= $table ?> | RMS</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0A0A0A">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>images/favicon-32.png">
  <link rel="apple-touch-icon" href="<?= $base ?>images/apple-touch-icon.png">
  <link rel="preload" href="<?= $base ?>fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= $base ?>fonts/playfair-display-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= $base ?>css/style.css?v=20261104f">
  <link rel="stylesheet" href="<?= $base ?>dine-in/assets/dine-in.css?v=10">
</head>
<body>

  <div id="dinein-app" data-table="<?= $table ?>">

    <!-- SCREEN: Welcome / Name Entry -->
    <div class="dinein-screen dinein-screen--active" id="screen-welcome">
      <div class="dinein-welcome">
        <div class="dinein-welcome-logo">
          <img src="<?= $base ?>images/logo.png" alt="RMS" width="280" height="171">
        </div>
        <div class="dinein-welcome-card">
          <div class="dinein-table-badge">
            <span class="dinein-table-label">Table</span>
            <span class="dinein-table-number"><?= $table ?></span>
          </div>
          <h1>Welcome!</h1>
          <p>Enter your first name to get started.</p>
          <form id="dinein-name-form" autocomplete="off" novalidate>
            <div class="dinein-field">
              <label for="dinein-name">Your First Name</label>
              <input type="text" id="dinein-name" name="name"
                     placeholder="e.g. Alex"
                     maxlength="30"
                     pattern="[A-Za-z ]+"
                     autocomplete="given-name"
                     required>
              <span class="dinein-field-error" id="dinein-name-error"></span>
            </div>
            <div class="dinein-welcome-actions" id="dinein-welcome-actions">
              <button type="button" class="dinein-btn dinein-btn--waiter" id="dinein-call-waiter-btn" disabled>
                Call the Waiter
              </button>
              <button type="submit" class="dinein-btn dinein-btn--primary" id="dinein-start-btn" disabled>
                Order by Yourself
              </button>
            </div>
          </form>
        </div>
        <p class="dinein-welcome-footer">
          RMS &middot; 2266 Wilton Drive, Wilton Manors
        </p>
      </div>
    </div>

    <!-- SCREEN: Menu & Cart -->
    <div class="dinein-screen" id="screen-menu">
      <div class="dinein-topbar">
        <div class="dinein-topbar-left">
          <span class="dinein-topbar-table">Table <?= $table ?></span>
          <span class="dinein-topbar-name" id="dinein-greeting"></span>
        </div>
        <button type="button" class="dinein-topbar-waiter" id="dinein-menu-call-waiter">Call Waiter</button>
      </div>
      <div class="dinein-cats-wrap" id="dinein-cats-wrap">
        <button class="dinein-cats-arrow dinein-cats-arrow--left" id="dinein-cats-left" aria-label="Scroll left">&lsaquo;</button>
        <nav class="dinein-cats" id="dinein-cats"></nav>
        <button class="dinein-cats-arrow dinein-cats-arrow--right" id="dinein-cats-right" aria-label="Scroll right">&rsaquo;</button>
      </div>
      <div class="dinein-menu-grid" id="dinein-menu-grid">
        <p class="dinein-loading">Loading menu&hellip;</p>
      </div>
      <!-- Floating cart bar -->
      <div class="dinein-cart-bar" id="dinein-cart-bar" hidden>
        <button class="dinein-btn dinein-btn--primary dinein-cart-bar-btn" id="dinein-view-cart-btn">
          <span id="dinein-cart-count">0 items</span> &middot;
          <span id="dinein-cart-total">$0.00</span>
          <span class="dinein-cart-bar-arrow">View Cart</span>
        </button>
      </div>
    </div>

    <!-- SCREEN: Cart Review -->
    <div class="dinein-screen" id="screen-cart">
      <div class="dinein-topbar">
        <button class="dinein-back-btn" id="dinein-back-to-menu">&larr; Menu</button>
        <span class="dinein-topbar-table">Table <?= $table ?></span>
      </div>
      <div class="dinein-cart-review" id="dinein-cart-review">
        <h2>Your Order</h2>
        <div id="dinein-cart-items"></div>
        <label class="dinein-field dinein-field--notes">
          <span>Special Instructions / Kitchen Notes</span>
          <textarea id="dinein-order-notes" rows="2" maxlength="500" placeholder="Allergies, cooking preferences, etc."></textarea>
        </label>
        <div class="dinein-cart-summary" id="dinein-cart-summary"></div>
        <button class="dinein-btn dinein-btn--primary" id="dinein-place-order-btn" disabled>
          Place Order
        </button>
        <p class="dinein-cart-empty" id="dinein-cart-empty-msg" hidden>
          Your cart is empty. <a href="#" id="dinein-browse-menu">Browse the menu</a> to add items.
        </p>
      </div>
    </div>

    <!-- SCREEN: Order Confirmation (built in Option 4) -->
    <div class="dinein-screen" id="screen-confirmation"></div>

  </div>

  <!-- Item detail modal (inserted by JS) -->
  <div class="dinein-modal-overlay" id="dinein-modal" hidden>
    <div class="dinein-modal-panel" role="dialog" aria-modal="true">
      <button class="dinein-modal-close" id="dinein-modal-close">&times;</button>
      <div class="dinein-modal-scroll" id="dinein-modal-body"></div>
      <div class="dinein-modal-footer" id="dinein-modal-footer"></div>
    </div>
  </div>

  <script>window.__DINEIN_BASE = <?= json_encode(site_base(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<?php if ($menuJson): ?>
  <script>window.__DINEIN_MENU = <?= $menuJson ?>;</script>
<?php endif; ?>
  <script src="<?= $base ?>dine-in/assets/dine-in.js?v=10"></script>
</body>
</html>
