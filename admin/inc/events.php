<?php
/**
 * The events lineup: what the team edits in the admin, and how it is published to
 * the website's Events page.
 *
 * The public page (events.html) is plain HTML that the day tabs, the home page
 * slider and the chat assistant all read, so publishing writes the very same card
 * markup back into the page, between the "admin:events" comment markers. Nothing
 * else on the page is touched. The team's working copy is api/data/events.json
 * (created from the page the first time the Events section is opened), the
 * previous page is saved to api/data/events-backups/ before every publish, and
 * the page is never written unless the new lineup checks out.
 *
 * An event is one record: title, time, host, description, tags, poster, and
 * either "every week on these days" or "one night only on this date".
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const EVENT_DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
const EVENT_HOST_STYLES = ['hosted' => 'Hosted by', 'with' => 'with', 'plain' => 'Just the text'];
const EVENT_POSTER_W = 480;
const EVENT_POSTER_H = 600;
const EVENT_POSTER_MAX_BYTES = 1500000;
const EVENT_BACKUPS_KEEP = 15;

function events_file(): string
{
    return data_dir() . '/events.json';
}

/** A JSON file as an array ([] if missing or unreadable). Used by events only. */
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

/** Read, change and save a JSON file under one lock. Used by events only. */
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

function events_page_file(): string
{
    return dirname(__DIR__, 2) . '/events.html';
}

function events_poster_dir(): string
{
    return dirname(__DIR__, 2) . '/images/events';
}

function events_backup_dir(): string
{
    return data_dir() . '/events-backups';
}

/** Escape for text between tags / for an attribute value in the page we write. */
function ev_text(string $s): string
{
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ev_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- times and dates ----------

/** "6:30 PM" → "18:30"; '' if it isn't a time. */
function events_time24(string $t): string
{
    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', trim($t), $m)) return '';
    return sprintf('%02d:%02d', ((int) $m[1] % 12) + (strtoupper($m[3]) === 'PM' ? 12 : 0), (int) $m[2]);
}

/** Position of a start time within a night: after-midnight times (before 5 AM) sort after the evening. */
function events_sort_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
    $min = $h * 60 + $m;
    return $min < 300 ? $min + 1440 : $min;
}

/** Y-m-d of the current night: before 4 AM it is still the night before, as everywhere else on the site. */
function events_tonight(): string
{
    return date('Y-m-d', time() - 4 * 3600);
}

function events_valid_date(string $ymd): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** mon..sun for a Y-m-d date. */
function events_day_key(string $ymd): string
{
    return strtolower(substr(date('D', strtotime($ymd . ' 12:00:00')), 0, 3));
}

/** Host text as shown on the page: "Hosted by Ana", "with Ana" or just "Ana". */
function events_host_text(array $e): string
{
    $host = trim((string) ($e['host'] ?? ''));
    if ($host === '') return '';
    $style = (string) ($e['hostStyle'] ?? 'plain');
    return $style === 'hosted' ? 'Hosted by ' . $host : ($style === 'with' ? 'with ' . $host : $host);
}

// ---------- reading the page ----------

/** [prefix style, name] from the text under a title: "Hosted by Ana" → ['hosted', 'Ana']. */
function events_split_host(string $host): array
{
    if (preg_match('/^Hosted by (.+)$/us', $host, $m)) return ['hosted', $m[1]];
    if (preg_match('/^with (.+)$/us', $host, $m)) return ['with', $m[1]];
    return ['plain', $host];
}

/**
 * The events on a copy of the Events page, as records. A show that appears on
 * several days with the same details becomes one record on those days.
 */
function events_parse_page(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($doc);
    $cls = fn($c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    $text = fn($n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';

    $events = [];
    $byKey = [];
    foreach ($x->query('//div[' . $cls('ev-day') . '][@data-day]') as $day) {
        $d = $day->getAttribute('data-day');
        if (!isset(EVENT_DAYS[$d])) continue;
        foreach ($x->query('.//article[' . $cls('ev-card') . ']', $day) as $card) {
            $title = $text($x->query('.//h4', $card)->item(0));
            $time = events_time24($text($x->query('.//*[' . $cls('ev-time') . ']', $card)->item(0)));
            if ($title === '' || $time === '') continue;
            [$style, $host] = events_split_host($text($x->query('.//*[' . $cls('ev-host') . ']', $card)->item(0)));
            $tags = [];
            foreach ($x->query('.//*[' . $cls('ev-tags') . ']/li', $card) as $li) $tags[] = $text($li);
            $img = $x->query('.//img', $card)->item(0);
            $alt = $img ? $img->getAttribute('alt') : '';
            $date = $card->getAttribute('data-date');
            $rec = [
                'title' => $title, 'time' => $time, 'host' => $host, 'hostStyle' => $host === '' ? 'plain' : $style,
                'desc' => $text($x->query('.//*[' . $cls('ev-desc') . ']', $card)->item(0)), 'tags' => $tags,
                'poster' => $img ? $img->getAttribute('src') : '', 'alt' => $alt === $title . ' poster' ? '' : $alt,
                'repeat' => events_valid_date($date) ? 'once' : 'weekly', 'date' => events_valid_date($date) ? $date : '',
            ];
            $key = md5(json_encode($rec));
            if ($rec['repeat'] === 'weekly' && isset($byKey[$key])) {
                $events[$byKey[$key]]['days'][] = $d;
                continue;
            }
            $rec = ['id' => substr(sha1($key . '#' . count($events)), 0, 8)] + $rec + ['days' => $rec['repeat'] === 'weekly' ? [$d] : [], 'active' => true];
            $byKey[$key] = count($events);
            $events[] = $rec;
        }
    }
    return $events;
}

// ---------- writing the page ----------

/** A poster's real width and height (the img tag should match the picture); the standard size if it can't be read. */
function events_poster_size(string $poster): array
{
    $info = preg_match('#^images/events/[^/]+$#', $poster) ? @getimagesize(dirname(__DIR__, 2) . '/' . $poster) : false;
    return $info ? [(int) $info[0], (int) $info[1]] : [EVENT_POSTER_W, EVENT_POSTER_H];
}

/** One card, exactly as the page writes them (the tabs, slider and chat read this markup). */
function events_card_html(array $e, string $pad): string
{
    $once = ($e['repeat'] ?? 'weekly') === 'once' && events_valid_date((string) ($e['date'] ?? ''));
    $alt = trim((string) ($e['alt'] ?? '')) !== '' ? $e['alt'] : $e['title'] . ' poster';
    $host = events_host_text($e);
    [$w, $h] = events_poster_size((string) $e['poster']);
    $lines = ['<article class="ev-card"' . ($once ? ' data-date="' . ev_attr($e['date']) . '"' : '') . '>',
        '  <div class="ev-poster">',
        '    <img src="' . ev_attr($e['poster']) . '" alt="' . ev_attr($alt) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async">',
        '    <span class="ev-time">' . ev_text(fmt_time12($e['time'])) . '</span>'];
    if ($once) $lines[] = '    <span class="ev-date">' . ev_text(date('D, M j', strtotime($e['date'] . ' 12:00:00'))) . '</span>';
    $lines[] = '  </div>';
    $lines[] = '  <div class="ev-body">';
    $lines[] = '    <h4>' . ev_text($e['title']) . '</h4>';
    if ($host !== '') $lines[] = '    <p class="ev-host">' . ev_text($host) . '</p>';
    if (trim((string) ($e['desc'] ?? '')) !== '') $lines[] = '    <p class="ev-desc">' . ev_text($e['desc']) . '</p>';
    if (!empty($e['tags'])) $lines[] = '    <ul class="ev-tags">' . implode('', array_map(fn($t) => '<li>' . ev_text((string) $t) . '</li>', $e['tags'])) . '</ul>';
    $lines[] = '  </div>';
    $lines[] = '</article>';
    return $pad . implode("\n" . $pad, $lines);
}

/** The events that will be on the page, per day: [mon => [event + 'dayKey', ...], ...], earliest first. */
function events_by_day(array $events, ?string $tonight = null): array
{
    $tonight = $tonight ?? events_tonight();
    $days = array_fill_keys(array_keys(EVENT_DAYS), []);
    foreach (array_values($events) as $i => $e) {
        if (empty($e['active']) || !preg_match('/^\d{2}:\d{2}$/', (string) ($e['time'] ?? ''))) continue;
        $once = ($e['repeat'] ?? 'weekly') === 'once';
        if ($once) {
            if (!events_valid_date((string) ($e['date'] ?? '')) || $e['date'] < $tonight) continue;
            $keys = [events_day_key($e['date'])];
        } else {
            $keys = array_values(array_intersect(array_keys(EVENT_DAYS), (array) ($e['days'] ?? [])));
        }
        foreach ($keys as $k) $days[$k][] = ['sort' => [events_sort_minutes($e['time']), $i], 'e' => $e];
    }
    foreach ($days as $k => $list) {
        usort($list, fn($a, $b) => $a['sort'] <=> $b['sort']);
        $days[$k] = array_column($list, 'e');
    }
    return $days;
}

/**
 * The two parts of the page the admin writes: 'lineup' (the day tabs and the seven
 * day panels) and 'glance' (the week-at-a-glance grid). The cards inside are in
 * time order; one-night events carry their date.
 */
function events_generate(array $events, ?string $tonight = null): array
{
    $byDay = events_by_day($events, $tonight);

    $tabs = ["      <div class=\"ev-tabs\" role=\"tablist\" aria-label=\"Day of the week\" hidden>"];
    foreach (EVENT_DAYS as $k => $name) {
        $n = count($byDay[$k]);
        $tabs[] = '        <button type="button" role="tab" id="tab-' . $k . '" aria-controls="ev-' . $k . '" aria-selected="false" data-day="' . $k . '"><span class="ev-tab-day">' . substr($name, 0, 3) . '</span><span class="ev-tab-count">' . $n . ' event' . ($n === 1 ? '' : 's') . '</span></button>';
    }
    $tabs[] = '      </div>';

    $panels = [];
    foreach (EVENT_DAYS as $k => $name) {
        $p = ['      <div class="ev-day" id="ev-' . $k . '" role="tabpanel" aria-labelledby="tab-' . $k . '" data-day="' . $k . '">',
            '        <h3 class="ev-day-title">' . $name . '</h3>'];
        if ($byDay[$k]) {
            $p[] = '        <div class="ev-grid">';
            foreach ($byDay[$k] as $e) $p[] = events_card_html($e, '          ');
            $p[] = '        </div>';
        } else {
            $p[] = '        <p class="ev-empty">No shows are scheduled for ' . $name . ' yet. Check back soon!</p>';
        }
        $p[] = '      </div>';
        $panels[] = implode("\n", $p);
    }

    $glance = ['        <div class="ev-glance-grid">'];
    foreach (EVENT_DAYS as $k => $name) {
        $glance[] = '          <div class="ev-glance-day" data-day="' . $k . '">';
        $glance[] = '            <h4>' . $name . '</h4>';
        $glance[] = '            <ul>';
        if (!$byDay[$k]) $glance[] = '              <li class="ev-empty">Nothing scheduled yet</li>';
        foreach ($byDay[$k] as $e) {
            $once = ($e['repeat'] ?? 'weekly') === 'once';
            $glance[] = '              <li' . ($once ? ' data-date="' . ev_attr($e['date']) . '"' : '') . '><a href="#ev-' . $k . '" data-goto="' . $k . '"><span>' . ev_text(fmt_time12($e['time'])) . '</span> '
                . ev_text($e['title']) . ($once ? ' &middot; ' . ev_text(date('M j', strtotime($e['date'] . ' 12:00:00'))) : '') . '</a></li>';
        }
        $glance[] = '            </ul>';
        $glance[] = '          </div>';
    }
    $glance[] = '        </div>';

    return ['lineup' => implode("\n", $tabs) . "\n\n" . implode("\n\n", $panels), 'glance' => implode("\n", $glance)];
}

const EVENT_MARKERS = [
    'lineup' => ['<!-- admin:events:lineup -->', '<!-- /admin:events:lineup -->'],
    'glance' => ['<!-- admin:events:glance -->', '<!-- /admin:events:glance -->'],
];

/** The text between the admin markers on the page: ['lineup' => ..., 'glance' => ...], or null if a marker is missing or repeated. */
function events_extract(string $html): ?array
{
    $out = [];
    foreach (EVENT_MARKERS as $name => [$open, $close]) {
        if (substr_count($html, $open) !== 1 || substr_count($html, $close) !== 1) return null;
        $a = strpos($html, $open) + strlen($open);
        $b = strpos($html, $close);
        if ($b < $a) return null;
        $out[$name] = substr($html, $a, $b - $a);
    }
    return $out;
}

/** The page with new text between the admin markers (null if a marker is missing). */
function events_splice(string $html, array $regions): ?string
{
    if (events_extract($html) === null) return null;
    foreach (EVENT_MARKERS as $name => [$open, $close]) {
        $a = strpos($html, $open) + strlen($open);
        $b = strpos($html, $close);
        // The closing marker keeps the indentation it had on its own line.
        $lineStart = strrpos(substr($html, 0, $b), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($html, $lineStart, $b - $lineStart);
        if (trim($indent) !== '') $indent = '';
        $html = substr($html, 0, $a) . "\n" . $regions[$name] . "\n" . $indent . substr($html, $b);
    }
    return $html;
}

/** A fingerprint of the two regions that ignores whitespace, to tell whether the page matches a lineup. */
function events_regions_hash(array $regions): string
{
    $norm = fn($s) => str_replace('> <', '><', trim(preg_replace('/\s+/', ' ', $s)));
    return sha1($norm($regions['lineup']) . '|' . $norm($regions['glance']));
}

/** How many day panels and cards a copy of the page has (to check a publish before it is written). */
function events_count_page(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($doc);
    $cls = fn($c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    return [
        'days'  => $x->query('//div[' . $cls('ev-day') . '][@data-day]')->length,
        'cards' => $x->query('//div[' . $cls('ev-day') . '][@data-day]//article[' . $cls('ev-card') . ']')->length,
        'tabs'  => $x->query('//*[' . $cls('ev-tabs') . ']//button')->length,
    ];
}

// ---------- the team's copy ----------

/** Save the working copy ['events' => [...], 'published' => ['at', 'hash'], ...]. */
function events_save(array $data): bool
{
    return json_update_file(events_file(), fn($old) => $data);
}

/** Replace the working copy with what the page shows now (this is also how the section starts). */
function events_import(): array
{
    $html = @file_get_contents(events_page_file());
    $regions = $html !== false ? events_extract($html) : null;
    $data = [
        'events'    => $html !== false ? events_parse_page($html) : [],
        'published' => ['at' => date('c'), 'hash' => $regions ? events_regions_hash($regions) : ''],
        'imported'  => date('c'),
    ];
    events_save($data);
    return $data;
}

/** The working copy; on first use it is made from the page as it is now. */
function events_store(): array
{
    $data = json_read_file(events_file());
    if (!isset($data['events']) || !is_array($data['events'])) $data = events_import();
    return $data + ['published' => []];
}

/** Has the section been set up (is there a working copy yet)? */
function events_started(): bool
{
    return isset(json_read_file(events_file())['events']);
}

/**
 * Where the page stands compared to the team's lineup:
 * 'ready' — page matches; 'changes' — the team has changes not published yet;
 * 'drift' — the page was edited outside the admin since the last publish;
 * 'nomarkers' — the page has no admin markers (can't publish).
 */
function events_state(array $data): array
{
    $html = @file_get_contents(events_page_file());
    $current = $html !== false ? events_extract($html) : null;
    if ($current === null) return ['state' => 'nomarkers', 'detail' => $html === false ? 'events.html can\'t be read.' : 'events.html has no admin:events markers.'];
    $now = events_regions_hash($current);
    if ($now === events_regions_hash(events_generate($data['events']))) return ['state' => 'ready', 'detail' => ''];
    $published = (string) ($data['published']['hash'] ?? '');
    return ['state' => ($published !== '' && $now !== $published) ? 'drift' : 'changes', 'detail' => ''];
}

/** Things that would make the published page wrong: [event title => problem]. Only shows that will be on the page. */
function events_problems(array $events): array
{
    $out = [];
    foreach ($events as $e) {
        if (empty($e['active'])) continue;
        $title = (string) ($e['title'] ?? '(untitled)');
        if (!preg_match('#^images/events/[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|jpe?g|png)$#', (string) ($e['poster'] ?? ''))) $out[$title] = 'has no poster';
        elseif (!is_file(dirname(__DIR__, 2) . '/' . $e['poster'])) $out[$title] = 'poster file ' . basename($e['poster']) . ' is missing';
        elseif (($e['repeat'] ?? 'weekly') === 'weekly' && !array_intersect(array_keys(EVENT_DAYS), (array) ($e['days'] ?? []))) $out[$title] = 'has no day';
    }
    return $out;
}

// ---------- backups ----------

/** Earlier copies of the page, newest first: [['file', 'ts', 'bytes'], ...]. */
function events_backups(): array
{
    $out = [];
    foreach (glob(events_backup_dir() . '/events-*.html') ?: [] as $path) {
        if (!preg_match('/^events-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.html$/', basename($path), $m)) continue;
        $out[] = ['file' => basename($path), 'ts' => (int) strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]"), 'bytes' => (int) filesize($path)];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

function events_backup_page(string $html): void
{
    $dir = events_backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/events-' . date('Ymd-His') . '.html', $html, LOCK_EX);
    foreach (array_slice(events_backups(), EVENT_BACKUPS_KEEP) as $old) @unlink($dir . '/' . $old['file']);
}

/** Write the page: to a temporary file first, so visitors never get half a page. */
function events_write_page(string $html): bool
{
    $file = events_page_file();
    $tmp = $file . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $html, LOCK_EX) === strlen($html) && @rename($tmp, $file)) return true;
    @unlink($tmp);
    return @file_put_contents($file, $html, LOCK_EX) === strlen($html);
}

// ---------- publishing ----------

/** Publish the team's lineup to events.html. Returns ['' on success, or the reason it wasn't published]. */
function events_publish(array $data): string
{
    $problems = events_problems($data['events']);
    if ($problems) {
        $first = array_slice($problems, 0, 3, true);
        return 'Not published: ' . implode('; ', array_map(fn($t, $p) => '"' . $t . '" ' . $p, array_keys($first), $first)) . (count($problems) > 3 ? ' (and ' . (count($problems) - 3) . ' more)' : '') . '.';
    }
    $file = events_page_file();
    $html = @file_get_contents($file);
    if ($html === false) return 'Not published: events.html can\'t be read.';
    $regions = events_generate($data['events']);
    $new = events_splice($html, $regions);
    if ($new === null) return 'Not published: events.html has no admin:events markers, so the admin doesn\'t know where the lineup goes.';

    // The new page must still have seven days and exactly the cards we generated.
    $expected = array_sum(array_map('count', events_by_day($data['events'])));
    $count = events_count_page($new);
    if ($count['days'] !== 7 || $count['tabs'] !== 7 || $count['cards'] !== $expected) return 'Not published: the new page failed its safety check, so events.html was left as it was.';

    events_backup_page($html);
    if (!events_write_page($new)) return 'Not published: events.html can\'t be written. Its folder must be writable by the website.';
    $data['published'] = ['at' => date('c'), 'hash' => events_regions_hash($regions)];
    events_save($data);
    return '';
}

// ---------- posters ----------

/** A safe file name stem from a title: "Noche Loka!" → "noche-loka". */
function events_slug(string $title): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    return substr($s, 0, 40) !== '' ? rtrim(substr($s, 0, 40), '-') : 'event';
}

/**
 * Store an uploaded poster in images/events/. It must be a real WebP, JPEG or PNG
 * of exactly 480 x 600 px (the editor in the browser makes it so). Returns
 * [path relative to the site, ''] or ['', reason]; ['', ''] when nothing was uploaded.
 */
function events_store_poster(array $file, string $title): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['', ''];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'That picture is too big to upload.'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['', 'The picture didn\'t upload. Please try again.'];
    if ((int) $file['size'] > EVENT_POSTER_MAX_BYTES) return ['', 'That picture is over ' . number_format(EVENT_POSTER_MAX_BYTES / 1000000, 1) . ' MB. Pick a smaller one.'];

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$info || !isset($types[$info[2]])) return ['', 'Please use a WebP, JPG or PNG picture.'];
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/webp', 'image/jpeg', 'image/png'], true)) return ['', 'Please use a WebP, JPG or PNG picture.'];
    }
    if ($info[0] !== EVENT_POSTER_W || $info[1] !== EVENT_POSTER_H) {
        return ['', 'The poster must be exactly ' . EVENT_POSTER_W . ' × ' . EVENT_POSTER_H . ' px (this one is ' . $info[0] . ' × ' . $info[1] . '). With JavaScript on, the editor resizes any picture to fit.'];
    }
    $dir = events_poster_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = events_slug($title) . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return ['', 'The picture couldn\'t be saved. The folder images/events/ must be writable by the website.'];
    @chmod($dir . '/' . $name, 0644);
    return ['images/events/' . $name, ''];
}
