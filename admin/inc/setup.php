<?php
/**
 * First run: give the website its MySQL database from the browser.
 *
 * Shown to a signed-in admin while the database isn't set up yet, or can't be
 * reached with the saved details. On cPanel hosting it can create the database
 * itself with one click: cPanel's own command-line tool (uapi) runs as the
 * account's user, so it needs no password. Where the host doesn't allow that,
 * it takes the name, user and password of a database made in cPanel. Either way
 * it creates the tables from api/setup.sql and saves the details in
 * api/config.php, so nobody has to edit files on the server. Everything else
 * (admin password, emails, chat keys) arrives with the upload package in
 * api/site-settings.php.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

/** The setup screen, or the redirect to the dashboard once the database works. Always ends the request. */
function admin_setup_database(): void
{
    $c = config();
    $v = [
        'db_name' => (string) ($c['db_name'] ?? ''),
        'db_user' => (string) ($c['db_user'] ?? ''),
        'db_host' => (string) ($c['db_host'] ?? '') ?: 'localhost',
        'db_port' => (int) ($c['db_port'] ?? 3306) ?: 3306,
    ];
    $error = '';
    $manualOpen = false;
    // 'new' (nothing saved yet), 'rejected' (MySQL answers but turns the saved details
    // down) or 'down' (MySQL doesn't answer). One-click creation is never offered when
    // it's only down, so a short outage can't switch the site to an empty database.
    $state = 'new';
    if (db_configured()) {
        try {
            db_connect($v['db_host'], $v['db_port'], $v['db_name'], $v['db_user'], (string) ($c['db_pass'] ?? ''));
            $state = 'down'; // it answers now: a moment ago it didn't
        } catch (PDOException $e) {
            $state = in_array(setup_db_error_code($e), [1044, 1045, 1049], true) ? 'rejected' : 'down';
        }
    }
    $uapi = $state === 'down' ? '' : setup_uapi_path();

    if (is_post() && isset($_POST['auto']) && $uapi !== '') {
        require_csrf();
        try {
            $error = setup_finish(setup_create_database($uapi, $v['db_host'], $v['db_port']));
        } catch (RuntimeException $e) {
            error_log('admin setup (cPanel): ' . $e->getMessage());
            $error = 'The database couldn\'t be created automatically (' . rtrim($e->getMessage(), '. ') . '). '
                . 'Create it in cPanel → MySQL Databases and enter it below.';
            $manualOpen = true;
        }
    }

    if (is_post() && isset($_POST['db_name'])) {
        require_csrf();
        $manualOpen = true;
        foreach (['db_name', 'db_user', 'db_host'] as $k) $v[$k] = trim((string) ($_POST[$k] ?? ''));
        $v['db_port'] = (int) ($_POST['db_port'] ?? 3306) ?: 3306;
        if ($v['db_host'] === '') $v['db_host'] = 'localhost';
        $error = ($v['db_name'] === '' || $v['db_user'] === '')
            ? 'Enter the database name and the database user.'
            : setup_finish($v + ['db_pass' => (string) ($_POST['db_pass'] ?? '')]);
    }

    $field = function (string $label, string $name, string $value, string $type = 'text', string $extra = '') {
        return '<label class="field"><span>' . h($label) . '</span><input type="' . $type . '" name="' . $name . '" value="' . h($value) . '"' . $extra . '></label>';
    };
    $manual = '<form class="setup-form" method="post">' . csrf_field()
        . $field('Database name', 'db_name', $v['db_name'], 'text', ' placeholder="e.g. cpuser_rms" autocomplete="off" required' . ($uapi === '' ? ' autofocus' : ''))
        . $field('Database user', 'db_user', $v['db_user'], 'text', ' placeholder="e.g. cpuser_rms" autocomplete="off" required')
        . $field('Database password', 'db_pass', '', 'password', ' autocomplete="new-password"')
        . '<details class="setup-more"><summary>Server address (only if your host gave you one)</summary>'
        .   $field('Host', 'db_host', $v['db_host'], 'text', ' autocomplete="off"')
        .   $field('Port', 'db_port', (string) $v['db_port'], 'number', ' min="1" max="65535"')
        . '</details>'
        . '<button class="btn ' . ($uapi === '' ? 'primary ' : '') . 'block" type="submit">' . icon('check', 16) . '<span>Connect and finish</span></button>'
        . '<p class="hint">cPanel puts the account name in front of both names, e.g. <code>cpuser_rms</code>: use the full names it shows.</p>'
        . '</form>';

    if ($uapi !== '') {
        $body = '<p class="muted">' . ($state === 'new'
                ? 'One last step: the website needs a MySQL database. It can make its own in this hosting account: one click, nothing to type.'
                : 'The saved database details don\'t work (wrong name, user or password). The website can make a new, empty database '
                    . 'in this hosting account instead: one click, nothing to type.') . '</p>'
            . ($error !== '' ? setup_error_html($error) : '')
            . '<form class="setup-form" method="post">' . csrf_field() . '<input type="hidden" name="auto" value="1">'
            . '<button class="btn primary block" type="submit">' . icon('check', 16) . '<span>Create the database for me</span></button></form>'
            . '<details class="setup-more"' . ($manualOpen ? ' open' : '') . '><summary>Use a database I already made</summary>' . $manual . '</details>';
    } elseif ($state === 'new') {
        $body = '<p class="muted">One last step: connect the website to its MySQL database. Create the database and a user in '
            . '<b>cPanel → MySQL Databases</b> (add the user to the database with <b>All Privileges</b>), then enter them here. '
            . 'The tables are created for you.</p>'
            . ($error !== '' ? setup_error_html($error) : '') . $manual;
    } elseif ($state === 'rejected') {
        $body = '<p class="muted">The saved database details don\'t work (wrong name, user or password). Enter the right ones below.</p>'
            . ($error !== '' ? setup_error_html($error) : '') . $manual;
    } else {
        $body = '<p class="muted">The database didn\'t answer. If MySQL is only down for a moment, '
            . '<a href="' . h(ADMIN_BASE) . '">try again</a>; otherwise enter the right details below.</p>'
            . ($error !== '' ? setup_error_html($error) : '') . $manual;
    }
    admin_render_bare('Connect the database',
        '<div class="login setup">'
        . '<div class="login-logo"><img src="' . h(SITE_BASE) . 'images/logo.png" alt="RMS" height="56"></div>'
        . '<h1>Connect the database</h1>' . $body . '</div>');
}

function setup_error_html(string $error): string
{
    return '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($error) . '</span></div>';
}

/**
 * Connect with these details, create the tables and save them in api/config.php.
 * Redirects to the dashboard when it all worked; otherwise returns what went wrong.
 */
function setup_finish(array $db): string
{
    try {
        $pdo = db_connect((string) $db['db_host'], (int) $db['db_port'], (string) $db['db_name'], (string) $db['db_user'], (string) $db['db_pass']);
        db_ensure_schema($pdo, true);
        $missing = db_missing_tables($pdo);
    } catch (PDOException $e) {
        error_log('admin setup: ' . $e->getMessage());
        return setup_db_problem($e);
    }
    if ($missing) {
        return 'Connected, but the tables couldn\'t be created: the user needs All Privileges on the database '
            . '(cPanel → MySQL Databases → Add User To Database). Missing: ' . implode(', ', $missing) . '.';
    }
    if (!db_save_config($db)) {
        return 'Connected, but the details couldn\'t be saved: the folder api/ must be writable by the website '
            . '(File Manager → api → Permissions 755).';
    }
    // Logged through this connection: db() still holds this request's old settings.
    $pdo->prepare('INSERT INTO admin_activity (action, detail, ip) VALUES (?, ?, ?)')
        ->execute(['setup.database', (string) $db['db_name'], client_ip()]);
    flash('Database connected. Everything is ready.');
    redirect(ADMIN_BASE);
    return '';
}

/**
 * cPanel's command-line API, when this host lets the website run it; '' when it
 * doesn't (not cPanel, PHP's exec() switched off, or PHP not running as the
 * account's user). 'cpanel_uapi' in config.php points it elsewhere, for tests.
 */
function setup_uapi_path(): string
{
    $off = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
    if (!function_exists('exec') || in_array('exec', $off, true)) return '';
    $custom = (string) (config()['cpanel_uapi'] ?? '');
    foreach ($custom !== '' ? [$custom] : ['/usr/local/cpanel/bin/uapi', '/usr/bin/uapi'] as $bin) {
        try {
            setup_uapi($bin, 'get_restrictions');
            return $bin;
        } catch (RuntimeException $e) {
            // Not here, or not allowed: try the next one.
        }
    }
    return '';
}

/** Run one function of cPanel's Mysql module; returns its data, or throws with cPanel's own message. */
function setup_uapi(string $bin, string $func, array $args = [])
{
    // Values are passed as they are: none contain "%" or "+", which uapi would decode.
    $cmd = escapeshellarg($bin) . ' --output=json Mysql ' . $func;
    foreach ($args as $k => $val) $cmd .= ' ' . escapeshellarg($k . '=' . $val);
    // PHP often runs without HOME set; uapi wants the account's home folder.
    if (DIRECTORY_SEPARATOR === '/' && preg_match('#^(/home\d*/[^/]+)/#', __DIR__, $m)) $cmd = 'HOME=' . escapeshellarg($m[1]) . ' ' . $cmd;
    $out = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $out, $code);
    $json = json_decode(implode("\n", $out), true);
    $res = is_array($json) ? ($json['result'] ?? $json) : null;
    if (!is_array($res) || empty($res['status'])) {
        $why = is_array($res) ? trim(implode(' ', array_map('strval', (array) ($res['errors'] ?? [])))) : '';
        throw new RuntimeException($why !== '' ? $why : 'cPanel didn\'t answer');
    }
    return $res['data'] ?? null;
}

/**
 * Create a database and a user with All Privileges on it, named after the account
 * (e.g. cpuser_rms; cpuser_rms2 if that's taken), with a long random password
 * that only api/config.php keeps. Returns the db_* settings.
 */
function setup_create_database(string $bin, string $host, int $port): array
{
    $limits = (array) setup_uapi($bin, 'get_restrictions');
    $prefix = (string) ($limits['prefix'] ?? '');
    $taken = array_merge(
        array_column((array) setup_uapi($bin, 'list_databases'), 'database'),
        array_column((array) setup_uapi($bin, 'list_users'), 'user')
    );
    $name = '';
    for ($i = 1; $i <= 20 && $name === ''; $i++) {
        $try = $prefix . 'rms' . ($i > 1 ? $i : '');
        if (!in_array($try, $taken, true)) $name = $try;
    }
    if ($name === '') throw new RuntimeException('every name from ' . $prefix . 'rms to ' . $prefix . 'rms20 is taken');

    // 32 characters of letters, digits, "-", "_" and ".": strong enough for cPanel's password check.
    $pass = 'Aa1-' . substr(strtr(base64_encode(random_bytes(30)), '+/=', '-_.'), 0, 28);
    setup_uapi($bin, 'create_database', ['name' => $name]);
    setup_uapi($bin, 'create_user', ['name' => $name, 'password' => $pass]);
    setup_uapi($bin, 'set_privileges_on_database', ['user' => $name, 'database' => $name, 'privileges' => 'ALL PRIVILEGES']);
    return ['db_host' => $host, 'db_port' => $port, 'db_name' => $name, 'db_user' => $name, 'db_pass' => $pass];
}

/** MySQL's error number for a failed connection (1045 = wrong password, 2002 = no answer, …). */
function setup_db_error_code(PDOException $e): int
{
    return preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int) $m[1] : (int) $e->getCode();
}

/** A plain-words reason a connection failed (never the password). */
function setup_db_problem(PDOException $e): string
{
    $code = setup_db_error_code($e);
    switch ($code) {
        case 1045: return 'The database user or password isn\'t right. Check them in cPanel → MySQL Databases (you can set a new password for the user there).';
        // MySQL gives this one both when the database doesn't exist and when the user has no access to it.
        case 1044: return 'This user can\'t open that database. Check the database name is the full one cPanel shows (e.g. cpuser_rms), '
            . 'and that the user is added to it: cPanel → MySQL Databases → Add User To Database, tick All Privileges.';
        case 1049: return 'There\'s no database with that name. Use the full name cPanel shows, with the account prefix (e.g. cpuser_rms).';
        case 2002:
        case 2003:
        case 2005:
        case 2006: return 'MySQL didn\'t answer at that server address. On cPanel hosting the host is localhost.';
    }
    return 'The database refused the connection (' . $code . '). Check the details in cPanel → MySQL Databases.';
}
