<?php
/**
 * Shared helpers for the online-ordering API.
 *
 * Menu data comes from the restaurant's public Square Online store feed
 * (the same data your-store.square.site shows). Orders and
 * payments go through Square's official Orders & Payments APIs using the
 * credentials in config.php (see config.example.php).
 */

date_default_timezone_set('America/New_York'); // restaurant time for saved submissions

const STORE_FEED ='https://cdn5.editmysite.com/app/store/api/v28/editor/users/124721230/sites/684991061342937356';
const STORE_LOCATION = '9E9MT9QP4KND5';
const CACHE_DIR = __DIR__ . '/cache';

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        // Three layers, first one wins: config.php (this server: the database details
        // the admin's first-run screen saves), site-settings.php (the owner's settings,
        // shipped in the upload package), then the defaults below. A blank value in
        // config.php counts as not set, so a copy of config.example.php can't blank
        // out the owner's settings.
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? (array) require $file : [];
        $cfg = array_filter($cfg, function ($v) { return $v !== ''; });
        $site = __DIR__ . '/site-settings.php';
        if (is_file($site)) $cfg += (array) require $site;
        $cfg += [
            'db_host'         => 'localhost',
            'db_name'         => '',
            'db_user'         => '',
            'db_pass'         => '',
            'db_port'         => 3306,
            'environment'     => 'sandbox',
            'application_id'  => '',
            'access_token'    => '',
            'location_id'     => STORE_LOCATION,
            // Where the food menu comes from: 'local' (the frozen data/menu.json,
            // managed from the Food admin tab — no Square at runtime) or 'square'
            // (pull live from the Square online store). Cash-on-delivery launch
            // runs on 'local' so the site never depends on Square.
            'menu_source'     => 'local',
            'menu_cache_mins' => 15,
            'notify_email'    => '',
            'from_email'      => '',
            'data_dir'        => '',
            'chat_provider'     => 'ollama',
            'ollama_url'        => 'http://localhost:11434',
            'ollama_model'      => 'llama3.2:3b',
            'anthropic_api_key' => '',
            'claude_model'      => 'claude-opus-5',
            'chat_fallback'     => '',
            'mistral_api_key'   => '',
            'mistral_model'     => 'ministral-14b-latest',
            'groq_api_key'      => '',
            'groq_model'        => 'openai/gpt-oss-120b',
            'gemini_api_key'    => '',
            'gemini_model'      => 'gemini-3.8-flash',
            'chat_daily_limit'  => 500,
            'chat_log'          => true,
            'chat_log_days'     => 120,
            // Password for the admin panel (/admin/); empty = panel switched off.
            // 'chat_admin_password' is the older name for the same setting.
            'admin_password'      => '',
            'chat_admin_password' => '',
            // How online orders are paid: 'cod' (cash on delivery, no online
            // charge) or 'card' (charge via Square). No payment processor has
            // been chosen yet, so cash on delivery is the default.
            'checkout_mode'     => 'cod',
        ];
    }
    return $cfg;
}

/** True once real Square credentials have been added to config.php. */
function payments_enabled(): bool
{
    $c = config();
    return $c['application_id'] !== '' && $c['access_token'] !== '' && $c['location_id'] !== '';
}

/**
 * True when orders are placed as cash on delivery (no online card charge).
 * On unless 'checkout_mode' is 'card' AND Square card payments are configured,
 * so the site never silently charges before a processor is really set up.
 */
function cod_mode(): bool
{
    return (config()['checkout_mode'] ?? 'cod') !== 'card' || !payments_enabled();
}

// ---------- team password (admin panel and kitchen display) ----------

/**
 * A password changed in the admin panel is kept here as a hash, in the private
 * data folder, and replaces 'admin_password' from config.php. Deleting this file
 * (cPanel → File Manager) brings the config.php password back, for a forgotten one.
 */
function team_password_file(): string
{
    return data_dir() . '/team-password.json';
}

/** The changed password's record (['hash' => ..., 'changed' => ...]), or null when config.php's is in use. */
function team_password_record(): ?array
{
    $file = team_password_file();
    if (!is_file($file)) return null;
    $rec = json_decode((string) @file_get_contents($file), true);
    return is_array($rec) && is_string($rec['hash'] ?? null) && $rec['hash'] !== '' ? $rec : null;
}

/** The password from config.php ('admin_password', or the older 'chat_admin_password'). */
function config_team_password(): string
{
    $c = config();
    return (string) ($c['admin_password'] !== '' ? $c['admin_password'] : $c['chat_admin_password']);
}

/** False when no password is set anywhere: the admin panel and kitchen display are switched off. */
function team_password_set(): bool
{
    return team_password_record() !== null || config_team_password() !== '';
}

function team_password_check(string $input): bool
{
    $rec = team_password_record();
    if ($rec) return password_verify($input, $rec['hash']);
    $password = config_team_password();
    return $password !== '' && hash_equals($password, $input);
}

/** 12+ characters (a changed password always is; the panel won't save a shorter one). */
function team_password_strong(): bool
{
    return team_password_record() !== null || strlen(config_team_password()) >= 12;
}

/**
 * Changes whenever the password does. Each sign-in remembers it, so changing
 * the password signs out every other browser and kitchen screen.
 */
function team_password_version(): string
{
    $rec = team_password_record();
    return substr(hash('sha256', $rec ? $rec['hash'] : config_team_password()), 0, 16);
}

/** Save a new password (as a hash). False if the data folder can't be written. */
function team_password_save(string $new): bool
{
    $dir = data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $json = json_encode(['hash' => password_hash($new, PASSWORD_DEFAULT), 'changed' => date('c')]);
    $file = team_password_file();
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) { @unlink($tmp); return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

function square_base(): string
{
    return config()['environment'] === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    send_json(['ok' => false, 'error' => $message], $status);
}

/** cURL handle with sane defaults (uses the OS certificate store when available). */
function curl_handle(string $url, array $opts = [])
{
    $ch = curl_init($url);
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT      => 'RMSWebsite/1.0',
    ];
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        $defaults[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts + $defaults);
    return $ch;
}

/**
 * GET several public-feed URLs, a few at a time (the feed rate-limits bursts),
 * retrying failures once. Returns decoded JSON keyed like $urls (null on failure).
 */
function feed_get_many(array $urls, int $batchSize = 10): array
{
    $out = [];
    $pending = $urls;
    for ($attempt = 0; $attempt < 2 && $pending; $attempt++) {
        if ($attempt > 0) usleep(1500000);
        $failed = [];
        foreach (array_chunk($pending, $batchSize, true) as $batch) {
            $multi = curl_multi_init();
            $handles = [];
            foreach ($batch as $key => $url) {
                $handles[$key] = curl_handle($url);
                curl_multi_add_handle($multi, $handles[$key]);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) curl_multi_select($multi, 1.0);
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $key => $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $data = $code === 200 ? json_decode((string) curl_multi_getcontent($ch), true) : null;
                if ($data === null) $failed[$key] = $batch[$key];
                $out[$key] = $data;
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }
            curl_multi_close($multi);
        }
        $pending = $failed;
    }
    return $out;
}

function feed_get(string $path)
{
    return feed_get_many(['x' => STORE_FEED . $path])['x'];
}

/** Call Square's REST API. Returns [httpStatus, decodedBody]. */
function square_request(string $method, string $path, ?array $body = null): array
{
    $c = config();
    $ch = curl_handle(square_base() . $path, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $c['access_token'],
            'Content-Type: application/json',
            'Square-Version: 2024-10-17',
        ],
        CURLOPT_POSTFIELDS    => $body === null ? null : json_encode($body),
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string) $res, true) ?? []];
}

function square_error_message(array $body): string
{
    $err = $body['errors'][0] ?? null;
    if (!$err) return 'Square did not accept the request.';
    $friendly = [
        'CARD_DECLINED'          => 'Your card was declined. Please try another card.',
        'CVV_FAILURE'            => 'The security code (CVV) is incorrect.',
        'ADDRESS_VERIFICATION_FAILURE' => 'The billing ZIP code does not match your card.',
        'INVALID_EXPIRATION'     => 'The card expiration date is invalid.',
        'INSUFFICIENT_FUNDS'     => 'Your card has insufficient funds.',
        'GENERIC_DECLINE'        => 'Your card was declined. Please try another card.',
    ];
    return $friendly[$err['code'] ?? ''] ?? ($err['detail'] ?? 'Payment could not be completed.');
}

// ---------- days and times (menu schedules, Happy Hour) ----------

const WEEK_DAYS = ['MON' => 'Mon', 'TUE' => 'Tue', 'WED' => 'Wed', 'THU' => 'Thu', 'FRI' => 'Fri', 'SAT' => 'Sat', 'SUN' => 'Sun'];

/** ['MON', 'TUE', 'WED', 'FRI'] → "Mon–Wed, Fri"; none or all seven → "Every day". */
function days_label(array $days): string
{
    $keys = array_keys(WEEK_DAYS);
    $on = array_values(array_filter(array_keys($keys), fn($i) => in_array($keys[$i], $days, true)));
    if (!$on || count($on) === 7) return 'Every day';
    $runs = [];
    foreach ($on as $i) {
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last][1] === $i - 1) $runs[$last][1] = $i;
        else $runs[] = [$i, $i];
    }
    return implode(', ', array_map(function ($r) use ($keys) {
        [$a, $b] = $r;
        if ($a === $b) return WEEK_DAYS[$keys[$a]];
        return WEEK_DAYS[$keys[$a]] . ($b - $a === 1 ? ', ' : '–') . WEEK_DAYS[$keys[$b]];
    }, $runs));
}

/** "15:00" → "3 PM"; "15:30" → "3:30 PM". */
function clock_label(string $hhmm): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) return $hhmm;
    $h = (int) $m[1] % 24;
    return (($h % 12) ?: 12) . ((int) $m[2] ? ':' . $m[2] : '') . ($h < 12 ? ' AM' : ' PM');
}

// ---------- form submissions (contact, reservations, newsletter) ----------

/**
 * Where form submissions are saved. Defaults to api/data/ (blocked by
 * api/.htaccess on Apache); set 'data_dir' in config.php to a folder outside the
 * public web root for hosts that ignore .htaccess (e.g. Nginx).
 */
function data_dir(): string
{
    return rtrim((string) (config()['data_dir'] ?? ''), '/\\') ?: __DIR__ . '/data';
}

/**
 * The website's address path, ending in "/": "/" at a domain's root, or e.g.
 * "/rms/" when the files sit in a folder. Worked out from the running script,
 * so pages built in PHP link correctly wherever the site is uploaded.
 */
function site_base(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $base = '/';
    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $file = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($root !== '' && $file !== '' && strpos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root)); // e.g. "/dine-in/index.php"
        if (substr($name, -strlen($rel)) === $rel) $base = rtrim(substr($name, 0, -strlen($rel)), '/') . '/';
    }
    return $base;
}

/** Send the branded "page not found" page with a 404 status, its links pointed at this site's folder. Ends the request. */
function send_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $html = (string) @file_get_contents(dirname(__DIR__) . '/404.html');
    $base = site_base();
    // 404.html links from the domain root ("/menu.html"); in a folder they need its prefix.
    if ($base !== '/') {
        $html = preg_replace_callback('#\b(href|src)="/(?!/)#', function ($m) use ($base) { return $m[1] . '="' . $base; }, $html);
    }
    echo $html;
    exit;
}

/**
 * The visitor's IP address. Prefers Cloudflare's / a proxy's header when the
 * site runs behind one (many shared hosts do), falling back to REMOTE_ADDR.
 * X-Forwarded-For can be spoofed, so this is fine for analytics but must not be
 * trusted for security decisions.
 */
function client_ip(): string
{
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if (filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $part) {
        $ip = trim($part);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/**
 * Only accept JSON posted by this website's own pages. Other sites can't send
 * JSON here without a CORS preflight (which we never allow), and a browser's
 * Origin header must match this host when present.
 */
function require_same_site(): void
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) fail('Invalid request.', 415);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $from = parse_url($origin, PHP_URL_HOST);
        $self = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        if (!$from || strcasecmp($from, $self) !== 0) fail('Invalid request.', 403);
    }
}

/**
 * Count one request from this visitor (by IP) for a bucket such as 'form' or
 * 'pay'; true once they have made $max requests within $windowSeconds. Counts are
 * kept in api/cache/rate/ (private). A disk problem never blocks a customer.
 */
function rate_limited(string $bucket, int $max, int $windowSeconds): bool
{
    $dir = CACHE_DIR . '/rate';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = time();
    if (mt_rand(1, 100) === 1) { // tidy up old counters now and then
        foreach (glob("$dir/*.json") ?: [] as $old) if ($now - @filemtime($old) > 86400) @unlink($old);
    }
    $fh = @fopen("$dir/$bucket-" . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '') . '.json', 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $hits = array_values(array_filter(json_decode((string) stream_get_contents($fh), true) ?: [], fn($t) => $t > $now - $windowSeconds));
    $blocked = count($hits) >= $max;
    if (!$blocked) {
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $blocked;
}

/** Allow at most $max requests per $windowSeconds per visitor; stops spam floods and card-testing. */
function rate_limit(string $bucket, int $max, int $windowSeconds): void
{
    if (rate_limited($bucket, $max, $windowSeconds)) fail('Too many attempts. Please wait a few minutes and try again, or call us at (954) 565-2526.', 429);
}

/** Parse a JSON POST body; basic bot checks shared by the public forms. */
function read_form(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
    require_same_site();
    rate_limit('form', 8, 600);
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Invalid request.');
    // Honeypot field (hidden from people) and a minimum time on the page.
    if (!empty($in['website'])) send_json(['ok' => true]);
    if (isset($in['startedAt']) && (time() * 1000 - (int) $in['startedAt']) < 2000) send_json(['ok' => true]);
    return $in;
}

function clean_field($value, int $max = 200): string
{
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value));
    return mb_substr($value, 0, $max);
}

// Guest records worth keeping when the database is down, with the column that holds when they arrived.
const UNSAVED_TABLES = ['orders' => 'created_at', 'contacts' => 'received', 'reservations' => 'received', 'subscribers' => 'signed_up'];

/**
 * Save a row to the database. $table is the DB table name, $row is col => value.
 * If the database can't be reached, a guest record is kept in the backup file instead
 * (see unsaved_keep), so it is never lost. Returns false only when neither worked.
 */
function save_row(string $table, array $row): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_insert($table, $row);
        return true;
    } catch (Throwable $e) {
        error_log("save_row($table): " . $e->getMessage());
        return unsaved_keep($table, $row);
    }
}

/**
 * Keep a guest record the database didn't take in api/data/unsaved/<table>.jsonl. The
 * admin dashboard warns about these and moves them into the database once it's back.
 */
function unsaved_keep(string $table, array $row): bool
{
    if (!isset(UNSAVED_TABLES[$table])) return false;
    $row[UNSAVED_TABLES[$table]] = $row[UNSAVED_TABLES[$table]] ?? date('Y-m-d H:i:s');
    $dir = data_dir() . '/unsaved';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $ok = @file_put_contents("$dir/$table.jsonl", $line, FILE_APPEND | LOCK_EX) !== false;
    if (!$ok) error_log("unsaved_keep($table): could not write the backup file");
    return $ok;
}

/** Email the restaurant (config notify_email). Returns false if not configured or mail fails. */
function notify(string $subject, array $fields, string $replyTo = ''): bool
{
    $to = trim((string) (config()['notify_email'] ?? ''));
    if ($to === '') return false;
    $body = '';
    foreach ($fields as $k => $v) $body .= str_pad($k . ':', 12) . str_replace("\n", "\n            ", (string) $v) . "\n";
    $headers = 'From: RMS Website <' . mail_from() . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: $replyTo\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** Email a guest (their copy of an order). Replies go to the restaurant. False if the address is invalid or mail fails. */
function mail_guest(string $to, string $subject, string $intro, array $fields): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $body = $intro . "\n\n";
    foreach ($fields as $k => $v) $body .= str_pad($k . ':', 14) . str_replace("\n", "\n              ", (string) $v) . "\n";
    $body .= "\nRMS\n";
    $headers = "From: \"RMS\" <" . mail_from() . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $reply = trim((string) (config()['notify_email'] ?? ''));
    if (filter_var($reply, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: $reply\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** The sender address: config from_email, else website@ this site's own domain. */
function mail_from(): string
{
    $host = preg_replace('/[^a-z0-9.\-]/i', '', preg_replace('/^www\.|:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';
    return trim((string) (config()['from_email'] ?? '')) ?: "website@$host";
}

function cache_read(string $name, int $maxAgeSeconds)
{
    $file = CACHE_DIR . "/$name.json";
    if (is_file($file) && (time() - filemtime($file)) < $maxAgeSeconds) {
        return json_decode((string) file_get_contents($file), true);
    }
    return null;
}

function cache_write(string $name, $data): void
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    @file_put_contents(CACHE_DIR . "/$name.json", json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
