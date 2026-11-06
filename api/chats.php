<?php
/**
 * api/chats.php: the old address of the chat history page.
 *
 * The chat history grew into the admin panel (/admin/): orders, reservations,
 * messages, subscribers, the chat assistant, the menu, events and settings. This
 * file only forwards old links and bookmarks to their new home, and does nothing else.
 *
 *   api/chats.php                 → admin/            (the dashboard)
 *   api/chats.php?days=30         → admin/?page=chats&days=30
 *   api/chats.php?c=<id>          → admin/?page=chats&c=<id>
 *   api/chats.php?view=insights   → admin/?page=insights
 *   api/chats.php?view=food       → admin/?page=menu     (&item=<id> too)
 *   api/chats.php?view=settings   → admin/?page=settings
 *   api/chats.php?view=happy      → admin/?page=happy-hour
 *   api/chats.php?csv=1&days=30   → admin/?page=chats&csv=1&days=30
 */
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/chats.php')), '/\\') . '/admin/';

/** The named query values that are plain text, as a map for the new link. */
$keep = function (array $names): array {
    $out = [];
    foreach ($names as $n) {
        if (isset($_GET[$n]) && is_string($_GET[$n]) && $_GET[$n] !== '') $out[$n] = $_GET[$n];
    }
    return $out;
};

$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : '';
$page = '';
$params = [];
if (isset($_GET['c'])) { $page = 'chats'; $params = $keep(['c', 'days', 'from', 'to']); }
elseif (isset($_GET['csv'])) { $page = 'chats'; $params = ['csv' => 1] + $keep(['days', 'from', 'to']); }
elseif ($view === 'insights') { $page = 'insights'; $params = $keep(['days', 'from', 'to']); }
elseif ($view === 'food') { $page = 'menu'; $params = $keep(['item']); }
elseif ($view === 'settings') { $page = 'settings'; }
elseif ($view === 'happy') { $page = 'happy-hour'; }
elseif (isset($_GET['days']) || isset($_GET['from']) || isset($_GET['to'])) { $page = 'chats'; $params = $keep(['days', 'from', 'to']); }

header('Location: ' . $base . ($page !== '' ? '?' . http_build_query(['page' => $page] + $params) : ''), true, 302);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
