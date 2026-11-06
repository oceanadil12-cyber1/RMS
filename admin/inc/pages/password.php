<?php
/**
 * Change password: the team password for the admin panel and the kitchen display.
 * Saved as a hash in the private data folder (team_password_save() in api/lib.php),
 * replacing 'admin_password' from config.php. Saving it signs out every other
 * browser and kitchen screen.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const TEAM_PASSWORD_MIN = 12;

/** Check the form and save the new password; returns an error message, or '' once saved. */
function change_team_password(): string
{
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if (rate_limited('admin-password', 10, 900)) return 'Too many attempts. Please wait a few minutes and try again.';
    if (!team_password_check($current)) {
        admin_log('password.change.failed', 'current password wrong');
        return 'Your current password isn\'t right.';
    }
    if (mb_strlen($new) < TEAM_PASSWORD_MIN) return 'The new password needs at least ' . TEAM_PASSWORD_MIN . ' characters.';
    if (strlen($new) > 72) return 'The new password can be at most 72 characters.'; // bcrypt's limit
    if ($new !== $confirm) return 'The two new passwords don\'t match.';
    if ($new === $current) return 'The new password is the same as the current one.';
    if (!team_password_save($new)) return 'The new password couldn\'t be saved (the data folder isn\'t writable). Nothing was changed.';

    // Keep this browser signed in; every other one is signed out on its next click.
    session_regenerate_id(true);
    $_SESSION['admin_pw'] = team_password_version();
    unset($_SESSION['csrf']);
    admin_log('password.change', 'admin panel and kitchen display');
    return '';
}

/** A team account changing its own password; returns an error message, or '' once saved. */
function change_own_password(array $u): string
{
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    if (rate_limited('admin-password', 10, 900)) return 'Too many attempts. Please wait a few minutes and try again.';
    if (!password_verify($current, $u['password_hash'])) {
        admin_log('password.change.failed', 'current password wrong');
        return 'Your current password isn\'t right.';
    }
    if (mb_strlen($new) < TEAM_USER_PASSWORD_MIN) return 'The new password needs at least ' . TEAM_USER_PASSWORD_MIN . ' characters.';
    if (strlen($new) > 72) return 'The new password can be at most 72 characters.';
    if ($new !== (string) ($_POST['confirm'] ?? '')) return 'The two new passwords don\'t match.';
    if ($new === $current) return 'The new password is the same as the current one.';
    db_update('admin_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $u['id']]);
    session_regenerate_id(true);
    $_SESSION['admin_pw'] = team_user_version(team_user_find('id', (int) $u['id']));
    unset($_SESSION['csrf']);
    admin_log('password.change', 'own account');
    return '';
}

/** Change password for someone signed in with a team account (not the owner). */
function admin_own_password_page(array $u): void
{
    $error = '';
    if (is_post()) {
        require_csrf();
        $error = change_own_password($u);
        if ($error === '') {
            flash('Password changed. Use the new one next time you sign in, here and on the kitchen display.');
            redirect(admin_url('password'));
        }
    }
    $min = TEAM_USER_PASSWORD_MIN;
    $body = page_head('Change password', 'Your own password, for username <b>' . h($u['username']) . '</b>.')
        . ($error !== '' ? '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($error) . '</span></div>' : '')
        . form_open(admin_url('password'))
        . '<input type="text" name="username" value="' . h($u['username']) . '" autocomplete="username" hidden>'
        . card('Your password', '<div class="form-grid">'
            . '<label class="field"><span>Current password</span><input type="password" name="current" autocomplete="current-password" required></label>'
            . '<label class="field"><span>New password' . info_tip("At least $min characters. A few unrelated words make a strong, memorable password.") . '</span>'
            .   '<input type="password" name="new" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
            . '<label class="field"><span>Confirm new password</span><input type="password" name="confirm" autocomplete="new-password" minlength="' . $min . '" maxlength="72" required></label>'
            . '</div><p class="muted">Saving signs you out on your other devices.</p>')
        . '<div class="savebar"><span></span>' . btn_submit('Change password', 'primary', '', '', 'lock') . '</div></form>';
    admin_render('Change password', $body, 'password');
}

function admin_page_password(): void
{
    $uid = (int) ($_SESSION['admin_uid'] ?? 0);
    if ($uid > 0 && ($u = team_user_find('id', $uid))) admin_own_password_page($u); // ends the request

    $error = '';
    if (is_post()) {
        require_csrf();
        $error = change_team_password();
        if ($error === '') {
            flash('Password changed. Use the new one for the admin panel and the kitchen display. Other devices, including kitchen screens, need to sign in again.');
            redirect(admin_url('password'));
        }
    }

    $rec = team_password_record();
    $changed = $rec && !empty($rec['changed']) ? date('M j, Y g:i A', (int) strtotime((string) $rec['changed'])) : '';
    $i = fn(string $text) => info_tip($text);

    $body = page_head('Change password', 'The owner\'s team password: username <b>admin</b> on the admin panel and the kitchen display (/kds/). Team members have their own (System → Team accounts).')
        . ($error !== '' ? '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($error) . '</span></div>' : '')
        . form_open(admin_url('password'))
        . '<input type="text" name="username" value="admin" autocomplete="username" hidden>'
        . card('Team password', '<div class="form-grid">'
            . '<label class="field"><span>Current password</span><input type="password" name="current" autocomplete="current-password" required></label>'
            . '<label class="field"><span>New password' . $i('At least ' . TEAM_PASSWORD_MIN . ' characters. A few unrelated words make a strong, memorable password.') . '</span>'
            .   '<input type="password" name="new" autocomplete="new-password" minlength="' . TEAM_PASSWORD_MIN . '" maxlength="72" required></label>'
            . '<label class="field"><span>Confirm new password</span><input type="password" name="confirm" autocomplete="new-password" minlength="' . TEAM_PASSWORD_MIN . '" maxlength="72" required></label>'
            . '</div>'
            . '<p class="muted">' . ($changed !== '' ? 'Last changed here on ' . h($changed) . '. ' : '')
            . 'Saving signs out every other device, including kitchen screens.</p>')
        . '<div class="savebar"><span></span>' . btn_submit('Change password', 'primary', '', '', 'lock') . '</div></form>';
    admin_render('Change password', $body, 'password');
}
