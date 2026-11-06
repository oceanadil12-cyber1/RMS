<?php
/**
 * MySQL database connection (PDO).
 *
 * Uses the 'db_*' settings in api/config.php. On a new server the admin panel's
 * first-run screen (/admin/) tests the details, creates the tables and saves
 * them there, so nobody has to edit files (see docs/DEPLOYMENT.md).
 */
require_once __DIR__ . '/lib.php';

/** True once the database name and user are saved (whether or not the server answers). */
function db_configured(): bool
{
    $c = config();
    return ($c['db_name'] ?? '') !== '' && ($c['db_user'] ?? '') !== '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = config();
    if (!db_configured()) {
        throw new RuntimeException('The database is not set up yet: open /admin/ to connect it.');
    }

    try {
        $pdo = db_connect((string) ($c['db_host'] ?? 'localhost'), (int) ($c['db_port'] ?? 3306),
            (string) $c['db_name'], (string) $c['db_user'], (string) ($c['db_pass'] ?? ''));
    } catch (PDOException $e) {
        // Rethrown without the PDO constructor in the trace, so the password can never show in an error page.
        error_log('db connect: ' . $e->getMessage());
        throw new RuntimeException('Could not connect to the database.');
    }

    db_ensure_schema($pdo);
    return $pdo;
}

/**
 * Open a connection (throws PDOException). The session time zone follows PHP's
 * (America/New_York, set in lib.php), so DEFAULT CURRENT_TIMESTAMP and NOW()
 * match date() whatever the server's clock zone is.
 */
function db_connect(string $host, int $port, string $name, string $user, string $pass): PDO
{
    $dsn = 'mysql:host=' . ($host !== '' ? $host : 'localhost') . ';port=' . ($port ?: 3306) . ";dbname=$name;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5, // seconds to wait for a connection, so a stuck server can't hang every page
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . date('P') . "'",
    ]);
}

/**
 * Save this server's database details in api/config.php (written to a temporary
 * file first, then swapped in). Other settings already in config.php are kept,
 * except blank ones (they'd count as not set anyway). Any earlier config.php is
 * kept beside it as a dot-file, which .htaccess never serves. False if the folder
 * isn't writable.
 */
function db_save_config(array $db): bool
{
    $file = __DIR__ . '/config.php';
    $keep = ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'db_port' => 3306];
    $values = array_intersect_key($db + $keep, $keep);
    $values['db_port'] = (int) $values['db_port'] ?: 3306;
    $old = is_file($file) ? (array) (include $file) : [];
    $values += array_filter($old, function ($v) { return $v !== ''; });

    $php = "<?php\n"
        . "/**\n"
        . " * This server's own settings; the database details were saved by the admin panel's\n"
        . " * first-run screen on " . date('Y-m-d H:i') . ".\n"
        . " * The owner's settings (admin password, emails, chat keys) are in site-settings.php.\n"
        . " * A value set here wins over site-settings.php. Never share or upload this file.\n"
        . " */\n"
        . 'return ' . var_export($values, true) . ";\n";

    $tmp = __DIR__ . '/.config-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($tmp, $php, LOCK_EX) !== strlen($php)) { @unlink($tmp); return false; }
    if (is_file($file)) @copy($file, __DIR__ . '/.config-before-' . date('Ymd-His') . '.php');
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    return true;
}

/** The table names setup.sql creates. */
function db_expected_tables(): array
{
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', (string) @file_get_contents(__DIR__ . '/setup.sql'), $m);
    return $m[1];
}

/** The setup.sql tables this database doesn't have. */
function db_missing_tables(PDO $pdo): array
{
    return array_values(array_diff(db_expected_tables(), $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Create any missing tables from setup.sql, so a fresh install only needs the db_*
 * settings filled in (importing setup.sql in phpMyAdmin also works). Runs once per
 * version of setup.sql: a marker file in data_dir() remembers it.
 */
function db_ensure_schema(PDO $pdo, bool $force = false): void
{
    $file = __DIR__ . '/setup.sql';
    if (!is_file($file)) return;
    $marker = data_dir() . '/.schema-' . substr((string) md5_file($file), 0, 12);
    if (!$force && is_file($marker)) return;
    try {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
        db_add_missing_columns($pdo);
    } catch (PDOException $e) {
        // Usually a user without the CREATE privilege; setup.sql can still be imported by hand.
        error_log('db_ensure_schema: ' . $e->getMessage());
        return;
    }
    if (!is_dir(data_dir())) @mkdir(data_dir(), 0750, true);
    @file_put_contents($marker, date('c') . "\n");
}

/**
 * Columns added after a table was first created. CREATE TABLE IF NOT EXISTS leaves an
 * existing table as it is, so a database made by an older version gets them here.
 * (No ADD COLUMN IF NOT EXISTS: MySQL 8 doesn't have it.)
 */
function db_add_missing_columns(PDO $pdo): void
{
    $later = [
        'chat_sessions' => ['phone' => "VARCHAR(30) NOT NULL DEFAULT '' AFTER email"], // Admin → Chat settings can ask for it
        'admin_activity' => ['username' => "VARCHAR(30) NOT NULL DEFAULT '' AFTER action"], // who did it (team accounts)
    ];
    $has = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($later as $table => $columns) {
        foreach ($columns as $column => $definition) {
            $has->execute([$table, $column]);
            if ((int) $has->fetchColumn() === 0) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

/** A DATETIME from the database as ISO 8601 with the offset (what browsers parse reliably); null stays null. */
function db_iso(?string $value): ?string
{
    if ($value === null || $value === '') return null;
    $ts = strtotime($value);
    return $ts === false ? null : date('c', $ts);
}

/** Insert a row; returns the auto-increment id. */
function db_insert(string $table, array $data): int
{
    $cols = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $stmt = db()->prepare("INSERT INTO `$table` ($cols) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}

/** Update rows matching $where; returns the number of affected rows. */
function db_update(string $table, array $data, array $where): int
{
    $setParts = [];
    $values = [];
    foreach ($data as $col => $val) {
        $setParts[] = "`$col` = ?";
        $values[] = $val;
    }
    $whereParts = [];
    foreach ($where as $col => $val) {
        $whereParts[] = "`$col` = ?";
        $values[] = $val;
    }
    $sql = "UPDATE `$table` SET " . implode(', ', $setParts)
         . " WHERE " . implode(' AND ', $whereParts);
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount();
}

/** Read a JSON setting from the settings table; returns the decoded value or $default. */
function db_setting(string $key, $default = null)
{
    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    $decoded = json_decode($row['setting_value'], true);
    return $decoded !== null ? $decoded : $default;
}

/** Write a JSON setting to the settings table (insert or update). */
function db_setting_save(string $key, $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // VALUES() rather than the newer row alias, so this also runs on MariaDB.
    $stmt = db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $json]);
}
