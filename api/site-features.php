<?php
/**
 * Website sections that can be switched off in the admin (Store settings → Website sections):
 * the Events, Performers, Dancers, Entertainment, Gallery, Private Events and Gift Cards pages.
 *
 * GET api/site-features.php  →  a small stylesheet, loaded by every page, that hides the
 * links to switched-off pages (header menu, mobile menu, footer and buttons), so the
 * menu closes up around the remaining links, plus any section marked
 * data-site-feature="<page>" (e.g. the home page's Upcoming Events).
 * api/page.php blocks the pages themselves.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/menu-overrides.php';

/** Page name (its .html file) => [settings key, label, what the page is]. Also listed in .htaccess and .router.php. */
const SITE_FEATURES = [
    'events'         => ['featureEvents', 'Events', 'The weekly events and shows page.'],
    'performers'     => ['featurePerformers', 'Performers', 'The live performers lineup page.'],
    'dancers'        => ['featureDancers', 'Dancers', 'The dancers roster page.'],
    'entertainment'  => ['featureEntertainment', 'Entertainment', 'The entertainment overview page: shows, DJs and stages.'],
    'gallery'        => ['featureGallery', 'Gallery', 'The photo gallery page, and the gallery preview on the home page.'],
    'private-events' => ['featurePrivateEvents', 'Private Events', 'The private events and parties page.'],
    'gift-cards'     => ['featureGiftCards', 'Gift Cards', 'The gift cards page. Switched off, online gift card sales stop too.'],
];

/** Which sections are on, e.g. ['events' => true, 'performers' => false, 'dancers' => true]. On unless switched off. */
function site_features(): array
{
    $s = read_store_settings(); // [] when the database can't be reached: everything stays on
    $out = [];
    foreach (SITE_FEATURES as $page => [$key]) $out[$page] = !array_key_exists($key, $s) || !empty($s[$key]);
    return $out;
}

/** The CSS that hides links to the switched-off pages ('' when all are on). */
function site_features_css(array $features): string
{
    $sel = [];
    foreach ($features as $page => $on) {
        if ($on) continue;
        $sel[] = '[data-site-feature="' . $page . '"]'; // whole sections that only exist for this page
        foreach (['', '/', './'] as $prefix) {
            $sel[] = 'a[href="' . $prefix . $page . '.html"]';
            $sel[] = 'a[href^="' . $prefix . $page . '.html#"]';
            $sel[] = 'li:has(> a[href="' . $prefix . $page . '.html"])';
            $sel[] = '.breadcrumb a[href="' . $prefix . $page . '.html"] + span'; // the "/" after a hidden breadcrumb link
        }
    }
    return $sel ? "/* Website sections switched off in Store settings */\n" . implode(",\n", $sel) . " { display: none !important; }\n" : '';
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    $css = site_features_css(site_features());
    $etag = '"' . md5($css) . '"';
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache'); // re-checked on every page view, so a switch takes effect at once
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
    echo $css;
}
