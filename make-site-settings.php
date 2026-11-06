<?php
/**
 * Used by make-upload-package.ps1: writes the owner's settings from this
 * computer's api/config.php into the upload package as api/site-settings.php,
 * so the live site has its admin password, emails and chat keys the moment
 * it's uploaded.
 *
 * Left out: the database (each server connects its own from the admin panel's
 * first-run screen), data_dir and the Ollama AI (this computer only), empty
 * values, and a from_email on a free-mail domain like Gmail (mail sent from a
 * web server as Gmail lands in spam; the server sends as website@its-domain).
 *
 * Usage: php make-site-settings.php <output file>
 */
$out = $argv[1] ?? '';
$src = __DIR__ . '/api/config.php';
if ($out === '') { fwrite(STDERR, "Usage: php make-site-settings.php <output file>\n"); exit(1); }
if (!is_file($src)) { fwrite(STDERR, "api/config.php not found: the owner's settings come from it.\n"); exit(1); }

$c = (array) require $src;

// 'chat_admin_password' is the older name for the admin password.
if (($c['admin_password'] ?? '') === '' && ($c['chat_admin_password'] ?? '') !== '') $c['admin_password'] = $c['chat_admin_password'];
unset($c['chat_admin_password']);

foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'db_port', 'data_dir', 'ollama_url', 'ollama_model'] as $k) unset($c[$k]);
if (preg_match('/@(gmail|googlemail|yahoo|outlook|hotmail|live|icloud|me|aol|proton|protonmail)\./i', (string) ($c['from_email'] ?? ''))) unset($c['from_email']);
$c = array_filter($c, function ($v) { return $v !== '' && $v !== null; });

if (($c['admin_password'] ?? '') === '') { fwrite(STDERR, "api/config.php has no admin password.\n"); exit(1); }
if (($c['chat_provider'] ?? '') === 'ollama') { fwrite(STDERR, "chat_provider is 'ollama', which only runs on this computer: pick an online AI first.\n"); exit(1); }

$php = "<?php\n"
    . "/**\n"
    . " * The owner's settings for the live website (admin password, emails, chat keys),\n"
    . " * built into the upload package on " . date('Y-m-d H:i') . ".\n"
    . " * The server's own api/config.php (its database details) wins over anything here.\n"
    . " * Private: api/.htaccess blocks it from the web. Never share it.\n"
    . " */\n"
    . 'return ' . var_export($c, true) . ";\n";
if (file_put_contents($out, $php) !== strlen($php)) { fwrite(STDERR, "Couldn't write $out\n"); exit(1); }
echo implode(', ', array_keys($c)) . "\n";
