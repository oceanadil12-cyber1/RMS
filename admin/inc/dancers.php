<?php
/**
 * The dancers roster: what the team edits in the admin, and how it is published
 * to the dancer grid on the website's Dancers page (dancers.html).
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const DANCER_PHOTO_MAX_BYTES = 2000000;
const DANCER_BACKUPS_KEEP = 15;
const DANCER_PHOTO_PATH = '#^images/dancers/[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|jpe?g|png)$#';
const DANCER_DEFAULT_TITLE = 'RMS Dancer';
const DANCER_MARKERS = ['<!-- admin:dancers:roster -->', '<!-- /admin:dancers:roster -->'];

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

function dancers_file(): string
{
    return data_dir() . '/dancers.json';
}

function dancers_page_file(): string
{
    return dirname(__DIR__, 2) . '/dancers.html';
}

function dancers_photo_dir(): string
{
    return dirname(__DIR__, 2) . '/images/dancers';
}

function dancers_backup_dir(): string
{
    return data_dir() . '/dancers-backups';
}

function dancer_text(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dancer_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** True when the dancer has a photo file that exists. */
function dancer_has_photo(array $d): bool
{
    $photo = (string) ($d['photo'] ?? '');
    return preg_match(DANCER_PHOTO_PATH, $photo) === 1 && is_file(dirname(__DIR__, 2) . '/' . $photo);
}

function dancers_photo_size(string $photo): array
{
    $info = preg_match('#^images/dancers/[^/]+$#', $photo)
        ? @getimagesize(dirname(__DIR__, 2) . '/' . $photo) : false;
    return $info ? [(int) $info[0], (int) $info[1]] : [600, 600];
}

/** Photo alt text, e.g. "Anthony, RMS dancer": lower-cases the caption but keeps all-caps words. */
function dancer_alt(array $d): string
{
    $title = trim((string) ($d['title'] ?? ''));
    if ($title === '') return (string) $d['name'];
    $title = preg_replace_callback('/\S+/u', fn($m) => (mb_strlen($m[0]) > 1 && mb_strtoupper($m[0]) === $m[0]) ? $m[0] : mb_strtolower($m[0]), $title);
    return $d['name'] . ', ' . $title;
}

// ---------- reading the page ----------

function dancers_parse_page(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($doc);
    $cls = fn($c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    $text = fn($n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';

    $dancers = [];
    foreach ($x->query('//figure[' . $cls('dancer-card') . ']') as $card) {
        $name = $text($x->query('.//figcaption//h3', $card)->item(0));
        if ($name === '') continue;
        $img = $x->query('.//div[' . $cls('dancer-card-img') . ']//img', $card)->item(0);
        $dancers[] = [
            'id'     => substr(sha1($name . '#' . count($dancers)), 0, 8),
            'name'   => $name,
            'title'  => $text($x->query('.//figcaption//span', $card)->item(0)),
            'photo'  => $img ? $img->getAttribute('src') : '',
            'active' => true,
        ];
    }
    return $dancers;
}

// ---------- writing the page ----------

function dancers_card_html(array $d, int $i): string
{
    [$w, $h] = dancers_photo_size((string) $d['photo']);
    $delay = ['', ' fade-in-delay-1', ' fade-in-delay-2'][$i % 3];
    $caption = '<h3>' . dancer_text($d['name']) . '</h3>'
        . (trim((string) ($d['title'] ?? '')) !== '' ? '<span>' . dancer_text($d['title']) . '</span>' : '');
    return implode("\n", [
        '        <figure class="dancer-card fade-in' . $delay . '">',
        '          <div class="dancer-card-img"><img loading="lazy" decoding="async" src="' . dancer_attr($d['photo']) . '" alt="' . dancer_attr(dancer_alt($d)) . '" width="' . $w . '" height="' . $h . '"></div>',
        '          <figcaption>' . $caption . '</figcaption>',
        '        </figure>',
    ]);
}

/** The roster HTML for the page, and how many dancers it shows. Paused dancers and those without a photo are left out. */
function dancers_generate(array $dancers): array
{
    $shown = array_values(array_filter($dancers, fn($d) => !empty($d['active']) && dancer_has_photo($d)));
    $cards = [];
    foreach ($shown as $i => $d) $cards[] = dancers_card_html($d, $i);
    return ['roster' => implode("\n", $cards), 'count' => count($shown)];
}

function dancers_extract(string $html): ?string
{
    [$open, $close] = DANCER_MARKERS;
    if (substr_count($html, $open) !== 1 || substr_count($html, $close) !== 1) return null;
    $a = strpos($html, $open) + strlen($open);
    $b = strpos($html, $close);
    return $b < $a ? null : substr($html, $a, $b - $a);
}

function dancers_splice(string $html, string $roster): ?string
{
    if (dancers_extract($html) === null) return null;
    [$open, $close] = DANCER_MARKERS;
    $a = strpos($html, $open) + strlen($open);
    $b = strpos($html, $close);
    $lineStart = strrpos(substr($html, 0, $b), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
    $indent = substr($html, $lineStart, $b - $lineStart);
    if (trim($indent) !== '') $indent = '';
    return substr($html, 0, $a) . "\n" . ($roster !== '' ? $roster . "\n" : '') . $indent . substr($html, $b);
}

function dancers_roster_hash(string $roster): string
{
    return sha1(str_replace('> <', '><', trim(preg_replace('/\s+/', ' ', $roster))));
}

// ---------- the team's copy ----------

function dancers_save(array $data): bool
{
    return json_update_file(dancers_file(), fn($old) => $data);
}

function dancers_import(): array
{
    $html = @file_get_contents(dancers_page_file());
    $roster = $html !== false ? dancers_extract($html) : null;
    $data = [
        'dancers'   => $html !== false ? dancers_parse_page($html) : [],
        'published' => ['at' => date('c'), 'hash' => $roster !== null ? dancers_roster_hash($roster) : ''],
        'imported'  => date('c'),
    ];
    dancers_save($data);
    return $data;
}

function dancers_store(): array
{
    $data = json_read_file(dancers_file());
    if (!isset($data['dancers']) || !is_array($data['dancers'])) $data = dancers_import();
    return $data + ['published' => []];
}

/** ready (the page matches), changes (unpublished edits), drift (the page was edited elsewhere) or nomarkers. */
function dancers_state(array $data): array
{
    $html = @file_get_contents(dancers_page_file());
    $current = $html !== false ? dancers_extract($html) : null;
    if ($current === null) return ['state' => 'nomarkers', 'detail' => $html === false ? 'dancers.html can\'t be read.' : 'dancers.html has no admin:dancers markers.'];
    $now = dancers_roster_hash($current);
    if ($now === dancers_roster_hash(dancers_generate($data['dancers'])['roster'])) return ['state' => 'ready', 'detail' => ''];
    $published = (string) ($data['published']['hash'] ?? '');
    return ['state' => ($published !== '' && $now !== $published) ? 'drift' : 'changes', 'detail' => ''];
}

// ---------- backups ----------

function dancers_backups(): array
{
    $out = [];
    foreach (glob(dancers_backup_dir() . '/dancers-*.html') ?: [] as $path) {
        if (!preg_match('/^dancers-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.html$/', basename($path), $m)) continue;
        $out[] = ['file' => basename($path), 'ts' => (int) strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]"), 'bytes' => (int) filesize($path)];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function dancers_backup_page(string $html): void
{
    $dir = dancers_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/dancers-' . date('Ymd-His') . '.html', $html, LOCK_EX);
    foreach (array_slice(dancers_backups(), DANCER_BACKUPS_KEEP) as $old) @unlink($dir . '/' . $old['file']);
}

function dancers_write_page(string $html): bool
{
    $file = dancers_page_file();
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $html, LOCK_EX) === strlen($html) && @rename($tmp, $file)) return true;
    @unlink($tmp);
    return @file_put_contents($file, $html, LOCK_EX) === strlen($html);
}

// ---------- publishing ----------

/** Writes the roster into dancers.html. Returns '' when published, or why it wasn't. */
function dancers_publish(array $data): string
{
    $file = dancers_page_file();
    $html = @file_get_contents($file);
    if ($html === false) return 'Not published: dancers.html can\'t be read.';
    $gen = dancers_generate($data['dancers']);
    $new = dancers_splice($html, $gen['roster']);
    if ($new === null) return 'Not published: dancers.html has no admin:dancers markers.';

    dancers_backup_page($html);
    if (!dancers_write_page($new)) return 'Not published: dancers.html can\'t be written.';
    $data['published'] = ['at' => date('c'), 'hash' => dancers_roster_hash($gen['roster'])];
    dancers_save($data);
    return '';
}

// ---------- photos ----------

function dancers_slug(string $name): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    return substr($s, 0, 40) !== '' ? rtrim(substr($s, 0, 40), '-') : 'dancer';
}

/** Stores an uploaded photo in images/dancers/. Returns [path, error]; ['', ''] when nothing was uploaded. */
function dancers_store_photo(array $file, string $name): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['', ''];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'That picture is too big to upload.'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['', 'The picture didn\'t upload. Please try again.'];
    if ((int) $file['size'] > DANCER_PHOTO_MAX_BYTES) return ['', 'That picture is over ' . number_format(DANCER_PHOTO_MAX_BYTES / 1000000, 1) . ' MB. Pick a smaller one.'];

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$info || !isset($types[$info[2]])) return ['', 'Please use a WebP, JPG or PNG picture.'];
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png'], true)) return ['', 'Please use a WebP, JPG or PNG picture.'];
    }
    if ($info[0] < 400) return ['', 'The photo should be at least 400 px wide (this one is ' . $info[0] . ' px).'];

    $dir = dancers_photo_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = dancers_slug($name) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $fname)) return ['', 'The picture couldn\'t be saved. The folder images/dancers/ must be writable by the website.'];
    @chmod($dir . '/' . $fname, 0644);
    return ['images/dancers/' . $fname, ''];
}
