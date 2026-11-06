<?php
/**
 * Team accounts: who signs in to the admin panel and the kitchen display, so the
 * activity log can say who did what. Private: blocked by api/.htaccess and .router.php.
 *
 * Two kinds of sign-in:
 *   username "admin" + the team password   the owner account. Always works (it's the
 *                                          one in config.php or set under Change
 *                                          password), so nobody can be locked out.
 *   a username from the admin_users table  made in Admin → Team accounts, each with
 *                                          its own password and role.
 *
 * Roles: admin (everything, including the activity log, team accounts and every
 * password), staff (the admin panel without those, and the kitchen display) and kitchen
 * (kitchen display only). Only admins change passwords, their own included; others ask one.
 * The admin-only sections are ADMIN_ONLY_PAGES in admin/inc/bootstrap.php.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

const TEAM_OWNER = 'admin';
const TEAM_ROLES = [
    'admin'   => ['Admin',   'Everything, including the activity log, accounts and passwords'],
    'staff'   => ['Staff',   'Admin panel and kitchen display, without the activity log, accounts or passwords'],
    'kitchen' => ['Kitchen', 'Kitchen display only'],
];
const TEAM_USER_PASSWORD_MIN = 10;

/** "  Maria.G " → "maria.g"; '' when it isn't a valid username (3–30 of a–z, 0–9, dot, dash, underscore). */
function team_clean_username(string $u): string
{
    $u = strtolower(trim($u));
    return preg_match('/^[a-z0-9][a-z0-9._-]{2,29}$/', $u) ? $u : '';
}

function team_user_find(string $column, $value): ?array
{
    if (!in_array($column, ['id', 'username'], true)) return null;
    $stmt = db()->prepare("SELECT * FROM admin_users WHERE `$column` = ? LIMIT 1");
    $stmt->execute([$value]);
    return $stmt->fetch() ?: null;
}

function team_users_all(): array
{
    return db()->query('SELECT * FROM admin_users ORDER BY active DESC, name, username')->fetchAll();
}

/** Changes whenever the account's password, role or on/off state does, which signs its browsers out. */
function team_user_version(array $u): string
{
    return substr(hash('sha256', $u['password_hash'] . '|' . $u['role'] . '|' . (int) $u['active']), 0, 16);
}

/**
 * Who a username and password belong to: ['uid', 'username', 'name', 'role', 'v'], or null.
 * A blank username counts as the owner, as the sign-in form had no username box before.
 */
function team_check_login(string $username, string $password): ?array
{
    $username = strtolower(trim($username));
    if ($username === '' || $username === TEAM_OWNER) {
        return team_password_check($password)
            ? ['uid' => 0, 'username' => TEAM_OWNER, 'name' => 'Owner', 'role' => 'admin', 'v' => team_password_version()]
            : null;
    }
    try {
        $u = team_user_find('username', $username);
    } catch (Throwable $e) {
        return null;
    }
    if (!$u || !(int) $u['active'] || !password_verify($password, $u['password_hash'])) return null;
    return ['uid' => (int) $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'v' => team_user_version($u)];
}

/** Remember who signed in, in this session under $p ('admin' or 'kds'). */
function team_session_start_as(string $p, array $who): void
{
    $_SESSION[$p . '_ok'] = true;
    $_SESSION[$p . '_seen'] = time();
    $_SESSION[$p . '_pw'] = $who['v'];
    $_SESSION[$p . '_uid'] = $who['uid'];
    $_SESSION[$p . '_user'] = $who['username'];
    $_SESSION[$p . '_name'] = $who['name'];
    $_SESSION[$p . '_role'] = $who['role'];
    if ($who['uid'] > 0) {
        try { db_update('admin_users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $who['uid']]); } catch (Throwable $e) {}
    }
}

/**
 * Is this session still signed in? False (and the session emptied) once it's been idle
 * too long, or the password, role or account changed since, or the role isn't one of $roles.
 * Sessions from before team accounts existed carry no user: they're the owner.
 */
function team_session_valid(string $p, int $idleSeconds, array $roles): bool
{
    if (empty($_SESSION[$p . '_ok'])) return false;
    $ok = time() - (int) ($_SESSION[$p . '_seen'] ?? 0) <= $idleSeconds;
    $uid = (int) ($_SESSION[$p . '_uid'] ?? 0);
    if ($ok && $uid === 0) {
        $ok = ($_SESSION[$p . '_pw'] ?? '') === team_password_version();
        $_SESSION[$p . '_user'] = TEAM_OWNER;
        $_SESSION[$p . '_name'] = $_SESSION[$p . '_name'] ?? 'Owner';
        $_SESSION[$p . '_role'] = 'admin';
    } elseif ($ok) {
        try {
            $u = team_user_find('id', $uid);
        } catch (Throwable $e) {
            $u = null;
        }
        $ok = $u && (int) $u['active'] && ($_SESSION[$p . '_pw'] ?? '') === team_user_version($u);
        if ($ok) {
            $_SESSION[$p . '_name'] = $u['name'];
            $_SESSION[$p . '_role'] = $u['role'];
        }
    }
    if ($ok && !in_array($_SESSION[$p . '_role'] ?? '', $roles, true)) $ok = false;
    if (!$ok) $_SESSION = [];
    return $ok;
}

/** The username of whoever is signed in on this request ('' for guests on the website). */
function team_actor(): string
{
    return (string) ($_SESSION['admin_user'] ?? $_SESSION['kds_user'] ?? '');
}
