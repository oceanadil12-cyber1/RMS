<?php
/**
 * The performers lineup: what the team edits in the admin, and how it is published
 * to the website's Performers page.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const PERF_PHOTO_MAX_BYTES = 2000000;
const PERF_BACKUPS_KEEP = 15;
const PERF_PHOTO_PATH = '#^images/performers/[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|jpe?g|png)$#';

if (!function_exists('json_read_file')) {
    function json_read_file(string $file): array
    {
        $fh = is_file($file) ? @fopen($file, 'r') : false;
        if (!$fh) return [];
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('json_update_file')) {
    function json_update_file(string $file, callable $change): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $fh = @fopen($file, 'c+');
        if (!$fh) return false;
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true);
        $data = $change(is_array($data) ? $data : []);
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $ok;
    }
}

function performers_file(): string
{
    return data_dir() . '/performers.json';
}

function performers_page_file(): string
{
    return dirname(__DIR__, 2) . '/performers.html';
}

function performers_photo_dir(): string
{
    return dirname(__DIR__, 2) . '/images/performers';
}

function performers_backup_dir(): string
{
    return data_dir() . '/performers-backups';
}

function perf_text(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function perf_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function perf_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name));
    $out = '';
    foreach ($words as $w) {
        $c = mb_strtoupper(mb_substr($w, 0, 1));
        if ($c !== '' && $c !== '"' && $c !== "\u{201C}" && $c !== "\u{201D}") $out .= $c;
    }
    return mb_substr($out, 0, 3) ?: '?';
}

function performers_photo_size(string $photo): array
{
    $info = preg_match('#^images/performers/[^/]+$#', $photo)
        ? @getimagesize(dirname(__DIR__, 2) . '/' . $photo) : false;
    return $info ? [(int) $info[0], (int) $info[1]] : [800, 800];
}

// ---------- reading the page ----------

function performers_parse_page(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($doc);
    $cls = fn($c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    $text = fn($n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';

    $performers = [];

    foreach ($x->query('//section[' . $cls('perf-strip') . ']') as $strip) {
        $img = $x->query('.//div[' . $cls('perf-strip-photo') . ']//img', $strip)->item(0);
        $name = $text($x->query('.//h2[' . $cls('perf-strip-name') . ']', $strip)->item(0));
        if ($name === '') continue;
        $genres = [];
        foreach ($x->query('.//*[' . $cls('perf-genre') . ']', $strip) as $g) $genres[] = $text($g);
        $performers[] = [
            'id'      => substr(sha1($name . '#' . count($performers)), 0, 8),
            'name'    => $name,
            'tagline' => $text($x->query('.//*[' . $cls('perf-strip-tagline') . ']', $strip)->item(0)),
            'bio'     => $text($x->query('.//*[' . $cls('perf-strip-bio') . ']', $strip)->item(0)),
            'genres'  => $genres,
            'photo'   => $img ? $img->getAttribute('src') : '',
            'active'  => true,
        ];
    }

    foreach ($x->query('//div[' . $cls('perf-coming-card') . ']') as $card) {
        $name = $text($x->query('.//h3', $card)->item(0));
        if ($name === '') continue;
        $genres = [];
        foreach ($x->query('.//*[' . $cls('perf-genre') . ']', $card) as $g) $genres[] = $text($g);
        $performers[] = [
            'id'      => substr(sha1($name . '#coming#' . count($performers)), 0, 8),
            'name'    => $name,
            'tagline' => $text($x->query('.//*[' . $cls('perf-strip-tagline') . ']', $card)->item(0)),
            'bio'     => $text($x->query('.//*[' . $cls('perf-strip-bio') . ']', $card)->item(0)),
            'genres'  => $genres,
            'photo'   => '',
            'active'  => true,
        ];
    }

    return $performers;
}

// ---------- writing the page ----------

function performers_strip_html(array $p, int $num, bool $reverse, bool $eager): string
{
    [$w, $h] = performers_photo_size((string) $p['photo']);
    $cls = 'perf-strip' . ($reverse ? ' perf-strip--reverse' : '');
    $loading = $eager ? 'loading="eager"' : 'loading="lazy" decoding="async"';
    $lines = [
        '  <!-- ' . sprintf('%02d', $num) . ' ' . perf_text($p['name']) . ' -->',
        '  <section class="' . $cls . '">',
        '    <div class="perf-strip-photo">',
        '      <img src="' . perf_attr($p['photo']) . '" alt="' . perf_attr($p['name']) . ' performing" width="' . $w . '" height="' . $h . '" ' . $loading . '>',
        '    </div>',
        '    <div class="perf-strip-content">',
        '      <span class="perf-strip-num">' . sprintf('%02d', $num) . '</span>',
        '      <h2 class="perf-strip-name">' . perf_text($p['name']) . '</h2>',
    ];
    if (trim((string) ($p['tagline'] ?? '')) !== '')
        $lines[] = '      <p class="perf-strip-tagline">' . perf_text($p['tagline']) . '</p>';
    if (trim((string) ($p['bio'] ?? '')) !== '')
        $lines[] = '      <p class="perf-strip-bio">' . perf_text($p['bio']) . '</p>';
    if (!empty($p['genres'])) {
        $lines[] = '      <div class="perf-strip-genres">';
        foreach ($p['genres'] as $g) $lines[] = '        <span class="perf-genre">' . perf_text($g) . '</span>';
        $lines[] = '      </div>';
    }
    $lines[] = '      <a href="events.html" class="perf-strip-cta">See Schedule &rarr;</a>';
    $lines[] = '    </div>';
    $lines[] = '  </section>';
    return implode("\n", $lines);
}

function performers_coming_card_html(array $p): string
{
    $initials = perf_initials($p['name']);
    $lines = [
        '        <div class="perf-coming-card fade-in">',
        '          <div class="perf-coming-avatar"><span>' . perf_text($initials) . '</span></div>',
        '          <div class="perf-coming-info">',
        '            <h3>' . perf_text($p['name']) . '</h3>',
    ];
    if (trim((string) ($p['tagline'] ?? '')) !== '')
        $lines[] = '            <p class="perf-strip-tagline">' . perf_text($p['tagline']) . '</p>';
    if (trim((string) ($p['bio'] ?? '')) !== '')
        $lines[] = '            <p class="perf-strip-bio">' . perf_text($p['bio']) . '</p>';
    if (!empty($p['genres'])) {
        $lines[] = '            <div class="perf-strip-genres">';
        foreach ($p['genres'] as $g) $lines[] = '              <span class="perf-genre">' . perf_text($g) . '</span>';
        $lines[] = '            </div>';
    }
    $lines[] = '          </div>';
    $lines[] = '        </div>';
    return implode("\n", $lines);
}

function performers_generate(array $performers): array
{
    $lineup = [];
    $coming = [];
    foreach ($performers as $p) {
        if (empty($p['active'])) continue;
        $hasPhoto = preg_match(PERF_PHOTO_PATH, (string) ($p['photo'] ?? ''))
            && is_file(dirname(__DIR__, 2) . '/' . $p['photo']);
        if ($hasPhoto) $lineup[] = $p;
        else $coming[] = $p;
    }

    $strips = [];
    foreach ($lineup as $i => $p) {
        $strips[] = performers_strip_html($p, $i + 1, $i % 2 === 1, $i === 0);
    }
    $lineupHtml = $strips ? "\n" . implode("\n\n", $strips) : '';

    $comingHtml = '';
    if ($coming) {
        $desc = count($coming) === 1
            ? 'Photo on the way &mdash; but already tearing up our stage.'
            : 'Photos on the way &mdash; but they\'re already tearing up our stage.';
        $cards = implode("\n\n", array_map('performers_coming_card_html', $coming));
        $comingHtml = "\n"
            . "  <section class=\"section\">\n"
            . "    <div class=\"container\">\n"
            . "      <div class=\"section-header\">\n"
            . "        <span class=\"section-label\">More to Come</span>\n"
            . "        <h2 class=\"section-title\">Coming <em>Soon</em></h2>\n"
            . "        <p class=\"section-desc\">" . $desc . "</p>\n"
            . "        <div class=\"gold-line\"></div>\n"
            . "      </div>\n"
            . "\n"
            . "      <div class=\"perf-coming-grid\">\n"
            . $cards . "\n"
            . "\n"
            . "      </div>\n"
            . "    </div>\n"
            . "  </section>";
    }

    return [
        'lineup' => $lineupHtml,
        'coming' => $comingHtml,
        'count'  => count($lineup) + count($coming),
    ];
}

const PERF_MARKERS = [
    'lineup' => ['<!-- admin:performers:lineup -->', '<!-- /admin:performers:lineup -->'],
    'coming' => ['<!-- admin:performers:coming -->', '<!-- /admin:performers:coming -->'],
];

function performers_extract(string $html): ?array
{
    $out = [];
    foreach (PERF_MARKERS as $name => [$open, $close]) {
        if (substr_count($html, $open) !== 1 || substr_count($html, $close) !== 1) return null;
        $a = strpos($html, $open) + strlen($open);
        $b = strpos($html, $close);
        if ($b < $a) return null;
        $out[$name] = substr($html, $a, $b - $a);
    }
    return $out;
}

function performers_splice(string $html, array $regions): ?string
{
    if (performers_extract($html) === null) return null;
    foreach (PERF_MARKERS as $name => [$open, $close]) {
        $a = strpos($html, $open) + strlen($open);
        $b = strpos($html, $close);
        $lineStart = strrpos(substr($html, 0, $b), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($html, $lineStart, $b - $lineStart);
        if (trim($indent) !== '') $indent = '';
        $html = substr($html, 0, $a) . "\n" . $regions[$name] . "\n" . $indent . substr($html, $b);
    }
    return $html;
}

function performers_regions_hash(array $regions): string
{
    $norm = fn($s) => str_replace('> <', '><', trim(preg_replace('/\s+/', ' ', $s)));
    return sha1($norm($regions['lineup']) . '|' . $norm($regions['coming']));
}

// ---------- the team's copy ----------

function performers_save(array $data): bool
{
    return json_update_file(performers_file(), fn($old) => $data);
}

function performers_import(): array
{
    $html = @file_get_contents(performers_page_file());
    $regions = $html !== false ? performers_extract($html) : null;
    $data = [
        'performers' => $html !== false ? performers_parse_page($html) : [],
        'published'  => ['at' => date('c'), 'hash' => $regions ? performers_regions_hash($regions) : ''],
        'imported'   => date('c'),
    ];
    performers_save($data);
    return $data;
}

function performers_store(): array
{
    $data = json_read_file(performers_file());
    if (!isset($data['performers']) || !is_array($data['performers'])) $data = performers_import();
    return $data + ['published' => []];
}

function performers_state(array $data): array
{
    $html = @file_get_contents(performers_page_file());
    $current = $html !== false ? performers_extract($html) : null;
    if ($current === null) return ['state' => 'nomarkers', 'detail' => $html === false ? 'performers.html can\'t be read.' : 'performers.html has no admin:performers markers.'];
    $gen = performers_generate($data['performers']);
    $now = performers_regions_hash($current);
    if ($now === performers_regions_hash(['lineup' => $gen['lineup'], 'coming' => $gen['coming']])) return ['state' => 'ready', 'detail' => ''];
    $published = (string) ($data['published']['hash'] ?? '');
    return ['state' => ($published !== '' && $now !== $published) ? 'drift' : 'changes', 'detail' => ''];
}

function performers_problems(array $performers): array
{
    $out = [];
    foreach ($performers as $p) {
        if (empty($p['active'])) continue;
        $name = (string) ($p['name'] ?? '(unnamed)');
        if (!empty($p['photo']) && !is_file(dirname(__DIR__, 2) . '/' . $p['photo']))
            $out[$name] = 'photo file ' . basename($p['photo']) . ' is missing';
    }
    return $out;
}

// ---------- backups ----------

function performers_backups(): array
{
    $out = [];
    foreach (glob(performers_backup_dir() . '/performers-*.html') ?: [] as $path) {
        if (!preg_match('/^performers-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.html$/', basename($path), $m)) continue;
        $out[] = ['file' => basename($path), 'ts' => (int) strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]"), 'bytes' => (int) filesize($path)];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function performers_backup_page(string $html): void
{
    $dir = performers_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/performers-' . date('Ymd-His') . '.html', $html, LOCK_EX);
    foreach (array_slice(performers_backups(), PERF_BACKUPS_KEEP) as $old) @unlink($dir . '/' . $old['file']);
}

function performers_write_page(string $html): bool
{
    $file = performers_page_file();
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $html, LOCK_EX) === strlen($html) && @rename($tmp, $file)) return true;
    @unlink($tmp);
    return @file_put_contents($file, $html, LOCK_EX) === strlen($html);
}

// ---------- publishing ----------

function performers_publish(array $data): string
{
    $problems = performers_problems($data['performers']);
    if ($problems) {
        $first = array_slice($problems, 0, 3, true);
        return 'Not published: ' . implode('; ', array_map(fn($t, $p) => '"' . $t . '" ' . $p, array_keys($first), $first)) . (count($problems) > 3 ? ' (and ' . (count($problems) - 3) . ' more)' : '') . '.';
    }
    $file = performers_page_file();
    $html = @file_get_contents($file);
    if ($html === false) return 'Not published: performers.html can\'t be read.';
    $gen = performers_generate($data['performers']);
    $regions = ['lineup' => $gen['lineup'], 'coming' => $gen['coming']];
    $new = performers_splice($html, $regions);
    if ($new === null) return 'Not published: performers.html has no admin:performers markers.';

    $count = $gen['count'];
    $new = preg_replace(
        '/(<span class="perf-stat-num">)\d+(<\/span><span class="perf-stat-label">Artists<\/span>)/',
        '${1}' . $count . '${2}',
        $new, 1
    );

    performers_backup_page($html);
    if (!performers_write_page($new)) return 'Not published: performers.html can\'t be written.';
    $data['published'] = ['at' => date('c'), 'hash' => performers_regions_hash($regions)];
    performers_save($data);
    return '';
}

// ---------- photos ----------

function performers_slug(string $name): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    return substr($s, 0, 40) !== '' ? rtrim(substr($s, 0, 40), '-') : 'performer';
}

function performers_store_photo(array $file, string $name): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['', ''];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'That picture is too big to upload.'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['', 'The picture didn\'t upload. Please try again.'];
    if ((int) $file['size'] > PERF_PHOTO_MAX_BYTES) return ['', 'That picture is over ' . number_format(PERF_PHOTO_MAX_BYTES / 1000000, 1) . ' MB. Pick a smaller one.'];

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$info || !isset($types[$info[2]])) return ['', 'Please use a WebP, JPG or PNG picture.'];
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png'], true)) return ['', 'Please use a WebP, JPG or PNG picture.'];
    }
    if ($info[0] < 400) return ['', 'The photo should be at least 400 px wide (this one is ' . $info[0] . ' px).'];

    $dir = performers_photo_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = performers_slug($name) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) return ['', 'The picture couldn\'t be saved. The folder images/performers/ must be writable by the website.'];
    @chmod($dir . '/' . $fname, 0644);
    return ['images/performers/' . $fname, ''];
}
