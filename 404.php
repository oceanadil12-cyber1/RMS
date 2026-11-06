<?php
/**
 * The branded "page not found" page for addresses that don't exist (.htaccess
 * sends them here). Serves 404.html with a 404 status and its links pointed at
 * the site's folder, so it also looks right when the site isn't at the domain root.
 */
require __DIR__ . '/api/lib.php';
send_not_found();
