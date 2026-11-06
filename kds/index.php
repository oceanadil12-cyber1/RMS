<?php
/**
 * Kitchen Display System — /kds/
 *
 * A standalone panel for kitchen staff: shows incoming orders (dine-in,
 * delivery, pickup), timers, status workflow, and a settings page.
 * Signed in with a username and password (the owner "admin" + team password, or a
 * team account from Admin → Team accounts), with its own session.
 */
define('KDS', true);

$requested = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requested !== '' && substr($requested, -1) !== '/' && substr($requested, -9) !== 'index.php') {
    header('Location: /' . ltrim($requested, '/\\') . '/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}

require_once __DIR__ . '/../api/lib.php';
require_once __DIR__ . '/../admin/inc/store.php';
require_once __DIR__ . '/../api/team-users.php';

$KDS_BASE = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/kds/index.php'), '/\\') . '/';
$SITE_BASE = rtrim(dirname(rtrim($KDS_BASE, '/')), '/\\') . '/';

// --- headers ---
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// --- session ---
if (!team_password_set()) {
    kds_page('KDS Offline', '<div class="kds-login"><h1>Kitchen Display System</h1><p>Set <code>admin_password</code> in config to enable.</p></div>');
}

// ?reset clears stale session cookies that can block login
if (isset($_GET['reset'])) {
    foreach (['/', '/kds/', '/kds', $KDS_BASE] as $p) {
        setcookie('rms_kds', '', ['expires' => time() - 3600, 'path' => $p, 'httponly' => true, 'samesite' => 'Lax']);
    }
    header('Location: ' . $KDS_BASE);
    exit;
}

session_name('rms_kds');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $KDS_BASE,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.gc_maxlifetime', '43200');
ini_set('session.use_strict_mode', '1');
$dir = data_dir() . '/sessions';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
session_start();

function kds_signed_in(): bool
{
    // Idle 12 hours, or the password, role or account changed since: sign in again. Every role may open the kitchen display.
    if (!team_session_valid('kds', 43200, array_keys(TEAM_ROLES))) return false;
    $_SESSION['kds_seen'] = time();
    return true;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kds_asset(string $file): string
{
    global $KDS_BASE;
    $path = __DIR__ . '/assets/' . $file;
    return h($KDS_BASE . 'assets/' . $file . '?v=' . (is_file($path) ? filemtime($path) : 0));
}

function kds_page(string $title, string $body, bool $withApp = false): void
{
    global $KDS_BASE, $SITE_BASE;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">'
        . '<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="dark"><meta name="theme-color" content="#0a0a0c">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="icon" type="image/png" href="' . h($SITE_BASE) . 'images/favicon-32.png">'
        . '<link rel="stylesheet" href="' . kds_asset('kds.css') . '">'
        . '</head><body' . ($withApp ? ' data-api="' . h($KDS_BASE) . 'api.php"' : '') . '>'
        . $body;
    if ($withApp) {
        echo '<script src="' . kds_asset('kds.js') . '" defer></script>';
    }
    echo '</body></html>';
    exit;
}

// --- sign-in ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kds_logout'])) {
    if (!empty($_SESSION['kds_ok'])) admin_log('logout', 'kitchen display');
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'] ?? '', 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    header('Location: ' . $KDS_BASE);
    exit;
}

if (!kds_signed_in()) {
    $error = '';
    $username = is_string($_POST['username'] ?? null) ? mb_substr(trim($_POST['username']), 0, 30) : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && is_string($_POST['password'])) {
        if (rate_limited('kds-login', 10, 900)) {
            $error = 'Too many attempts. Please wait.';
        } elseif ($who = team_check_login($username, $_POST['password'])) {
            session_regenerate_id(true);
            team_session_start_as('kds', $who);
            admin_log('kds.login');
            header('Location: ' . $KDS_BASE);
            exit;
        } else {
            admin_log('kds.login.failed', '', [], strtolower($username) ?: TEAM_OWNER); // the name they tried
            $error = 'Wrong username or password.';
        }
    }
    kds_page('Kitchen Sign In',
        '<div class="kds-login">'
        . '<div class="kds-login-logo"><img src="' . h($SITE_BASE) . 'images/logo.png" alt="" height="48"></div>'
        . '<h1>Kitchen Display</h1>'
        . '<p>Sign in with your username and password.</p>'
        . ($error !== '' ? '<div class="kds-error">' . h($error) . '</div>' : '')
        . '<form method="post" action="' . h($KDS_BASE) . 'index.php">'
        . '<input type="text" name="username" value="' . h($username) . '" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" placeholder="Username" required' . ($username === '' ? ' autofocus' : '') . '>'
        . '<input type="password" name="password" autocomplete="current-password" placeholder="Password" required' . ($username !== '' ? ' autofocus' : '') . '>'
        . '<button type="submit">Open Kitchen Display</button>'
        . '</form></div>');
}

// --- main KDS page ---
kds_page('Kitchen Display', '
<div id="kds-app">
    <header class="kds-header">
        <div class="kds-brand">
            <img src="' . h($SITE_BASE) . 'images/logo.png" alt="" height="32">
            <span>Kitchen Display</span>
            <small class="kds-who" title="Signed in">' . h((string) ($_SESSION['kds_user'] ?? '')) . '</small>
        </div>
        <div class="kds-summary" id="kds-summary">
            <div class="kds-stat" data-stat="queue"><span class="kds-stat-num" id="stat-queue">0</span><span class="kds-stat-label">Queue</span></div>
            <div class="kds-stat" data-stat="preparing"><span class="kds-stat-num" id="stat-preparing">0</span><span class="kds-stat-label">Preparing</span></div>
            <div class="kds-stat" data-stat="overdue"><span class="kds-stat-num" id="stat-overdue">0</span><span class="kds-stat-label">Overdue</span></div>
            <div class="kds-stat" data-stat="completed"><span class="kds-stat-num" id="stat-completed">0</span><span class="kds-stat-label">Done</span></div>
        </div>
        <div class="kds-actions">
            <select id="kds-filter" class="kds-select">
                <option value="active">Active Orders</option>
                <option value="all">All Types</option>
                <option value="dine-in">Dine-In</option>
                <option value="delivery">Delivery</option>
                <option value="pickup">Pickup</option>
                <option value="queue">Queue Only</option>
                <option value="preparing">Preparing Only</option>
                <option value="overdue">Overdue Only</option>
                <option value="completed">Completed</option>
            </select>
            <select id="kds-sort" class="kds-select">
                <option value="oldest">Oldest First</option>
                <option value="newest">Newest First</option>
                <option value="overdue">Overdue First</option>
                <option value="type">By Type</option>
            </select>
            <button class="kds-btn kds-btn-icon" id="kds-sound-toggle" title="Toggle sound">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
            </button>
            <button class="kds-btn kds-btn-icon" id="kds-settings-toggle" title="Settings">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>
            </button>
            <form method="post" style="display:inline"><input type="hidden" name="kds_logout" value="1"><button class="kds-btn kds-btn-icon" type="submit" title="Sign out">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </button></form>
        </div>
    </header>

    <div class="kds-grid" id="kds-grid">
        <div class="kds-empty" id="kds-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
            <p>No orders right now</p>
            <span>Orders will appear here automatically</span>
        </div>
    </div>

    <div class="kds-settings-panel" id="kds-settings" hidden>
        <div class="kds-settings-overlay" id="kds-settings-close"></div>
        <div class="kds-settings-body">
            <h2>KDS Settings</h2>
            <div class="kds-field">
                <label>Dine-In Prep Time</label>
                <div class="kds-field-input"><input type="number" id="set-dinein" min="1" max="120" value="15"> <span>minutes</span></div>
            </div>
            <div class="kds-field">
                <label>Delivery Prep Time</label>
                <div class="kds-field-input"><input type="number" id="set-delivery" min="1" max="120" value="20"> <span>minutes</span></div>
            </div>
            <div class="kds-field">
                <label>Pickup Prep Time</label>
                <div class="kds-field-input"><input type="number" id="set-pickup" min="1" max="120" value="15"> <span>minutes</span></div>
            </div>
            <div class="kds-field">
                <label>Sound Notifications</label>
                <div class="kds-field-input"><label class="kds-toggle"><input type="checkbox" id="set-sound" checked><span>Enabled</span></label></div>
            </div>
            <div class="kds-settings-actions">
                <button class="kds-btn kds-btn-primary" id="kds-settings-save">Save Settings</button>
                <button class="kds-btn" id="kds-settings-cancel">Cancel</button>
            </div>
        </div>
    </div>
</div>
', true);
