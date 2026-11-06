<?php
/**
 * Serves the pages listed in SITE_FEATURES (api/site-features.php) while they are switched on in
 * Store settings, and "page not found" while they're off.
 *
 * .htaccess (Apache) and .router.php (PHP's built-in server) send those pages
 * (/events.html, /gallery.html ... and /events etc.) here as api/page.php?name=events,
 * so the address in the browser doesn't change.
 */
require_once __DIR__ . '/site-features.php';

$name = (string) ($_GET['name'] ?? '');
if (!isset(SITE_FEATURES[$name]) || empty(site_features()[$name])) send_not_found();

$file = dirname(__DIR__) . '/' . $name . '.html';
if (!is_file($file)) send_not_found();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
readfile($file);
