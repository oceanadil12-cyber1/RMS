<?php
/**
 * The admin panel for the RMS website: /admin/
 *
 * One place for everything the team looks at and controls:
 *   Guests          orders, reservations, contact messages, newsletter sign-ups
 *   Chat assistant  conversations, insights, what the assistant knows
 *   Website         menu & food, Happy Hour, store settings
 *   System          who changed what (activity log)
 *
 *   /admin/?page=orders              a section
 *   /admin/?page=orders&id=AB12CD    one record
 *   /admin/?api=counts               JSON for the sidebar badges (signed in only)
 *
 * Protected by the team password (config 'admin_password', or the one changed
 * under System → Change password); none set = the panel is switched off. Each section is admin/inc/pages/<name>.php
 * and defines admin_page_<name>(); see docs/ADMIN.md.
 */
define('ADMIN', true);

// /admin without the trailing slash: go to /admin/ (the panel's links and its sign-in cookie
// belong to that folder; Apache does this by itself, PHP's built-in server doesn't).
$requested = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requested !== '' && substr($requested, -1) !== '/' && substr($requested, -9) !== 'index.php') {
    // One leading slash only: "//host" or "/\host" in a Location header would point at another site.
    header('Location: /' . ltrim($requested, '/\\') . '/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}

require __DIR__ . '/inc/bootstrap.php';

admin_send_headers();

if (!team_password_set()) {
    admin_render_bare('Switched off',
        '<div class="login"><div class="login-logo"><img src="' . h(SITE_BASE) . 'images/logo.png" alt="RMS" height="56"></div>'
        . '<h1>The admin panel is switched off</h1>'
        . '<p class="muted">Set <code>admin_password</code> in <code>api/config.php</code> to a long password only the team knows, then reload this page.</p></div>');
}

admin_session_start();

// Everything below reads the database. On a new server (or if it stops answering), a
// signed-in admin gets the screen that connects it; nobody needs to edit files.
try {
    db();
} catch (Throwable $e) {
    if (isset($_GET['api'])) send_json(['ok' => false, 'error' => 'The database can\'t be reached right now.'], 503);
    if (!admin_signed_in()) admin_login();
    require __DIR__ . '/inc/setup.php';
    admin_setup_database();
}

// Sidebar badges (new orders, reservations, messages), asked for every 30 seconds by admin.js.
if (($_GET['api'] ?? '') === 'counts') {
    if (!admin_signed_in(false)) send_json(['ok' => false, 'signedOut' => true], 401);
    send_json(['ok' => true] + admin_counts());
}

// Notification alerts for the topbar bell, asked every 30 seconds by admin.js.
if (($_GET['api'] ?? '') === 'notifications') {
    if (!admin_signed_in(false)) send_json(['ok' => false, 'signedOut' => true], 401);
    send_json(['ok' => true, 'alerts' => admin_notifications()]);
}

// Dine-in order creation API (POST from the waiter interface).
if (($_GET['api'] ?? '') === 'dine-in') {
    if (!admin_signed_in()) send_json(['ok' => false, 'error' => 'Not signed in.'], 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
    require_same_site();
    require __DIR__ . '/inc/dine-in-api.php';
    // dine_in_create() sends the JSON response and exits
}

// Waiter assistance API (list active, resolve).
if (($_GET['api'] ?? '') === 'waiter') {
    if (!admin_signed_in(false)) send_json(['ok' => false, 'signedOut' => true], 401);
    require __DIR__ . '/inc/waiter-api.php';
}

if (!admin_signed_in()) admin_login(); // shows the sign-in page, or redirects once the password is right

if (is_post() && isset($_POST['logout'])) {
    require_csrf();
    admin_logout();
}

$page = get_choice('page', ADMIN_PAGES, 'dashboard');
if (in_array($page, ADMIN_ONLY_PAGES, true) && !admin_is_admin()) {
    admin_render(admin_section_label($page), page_head(admin_section_label($page))
        . empty_state('Only admins can open this', 'The activity log, team accounts and passwords are managed by the owner or an Admin account. Ask one of them if you need something changed, or if you\'ve forgotten your password.', 'lock'), $page);
}
require __DIR__ . '/inc/pages/' . $page . '.php';
call_user_func('admin_page_' . str_replace('-', '_', $page));
