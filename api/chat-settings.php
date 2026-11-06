<?php
/**
 * GET api/chat-settings.php  →  how the chat assistant looks and behaves, as JSON.
 *
 * Edited in the admin (admin/?page=chat-settings) and stored in the settings
 * table. js/chat.js reads it before it shows anything. Until it is first edited,
 * the defaults below apply: they are what the chat has always done. Browsers may
 * reuse it for a minute, so a change shows on the website within about a minute.
 */
require_once __DIR__ . '/lib.php';

const CHAT_SETTINGS_DEFAULTS = [
    'enabled'     => true,
    'position'    => 'right',   // right | left | hidden (no floating button)
    'button'      => 'Ask us',
    'autoOpen'    => 'smart',   // smart (computers open it, phones get a bubble) | bubble | open | off
    'autoDelay'   => 3,         // seconds after the first page has loaded
    'bubble'      => 'Hi! Need a table or food? Ask me.',
    'title'       => 'Ask RMS',
    'status'      => 'Online now · virtual assistant',
    'welcome'     => "I'm the RMS virtual assistant. I can help you order food, check tonight's shows, find Happy Hour deals, book a table or plan a private event.",
    'logo'        => '',        // images/chat/... ; empty = RMS logo
    'gate'        => 'email',   // email (required, name optional) | optional (can skip) | off
    'gatePhone'   => 'off',     // off | optional | required
    'gateText'    => "Before we start, what's your email? We'll use it to follow up if you'd like. Your name is optional.",
    'suggestions' => ['Show me the menu', "What's on tonight?", 'What are the Happy Hour deals?', 'How do I book a table?'],
    'phone'       => '(954) 565-2526',
    'hours'       => 'Open every day, 11 AM to 2 AM',
    'notice'      => 'AI assistant. Chats are saved so our team can follow up.',
    'privacyUrl'  => '',
];

const CHAT_LOGO_PATH = '#^images/chat/[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|jpe?g|png)$#';

/** The settings as saved (or the defaults), each one checked. */
function chat_settings(): array
{
    $saved = [];
    try {
        require_once __DIR__ . '/db.php';
        $saved = db_setting('chat_settings', []);
    } catch (Throwable $e) {
        // No database yet: the defaults keep the chat working as it always has.
    }
    return chat_settings_clean(is_array($saved) ? $saved : []);
}

function chat_settings_save(array $settings): bool
{
    try {
        require_once __DIR__ . '/db.php';
        db_setting_save('chat_settings', chat_settings_clean($settings));
        return true;
    } catch (Throwable $e) {
        error_log('chat_settings_save: ' . $e->getMessage());
        return false;
    }
}

/** Fill in missing settings from the defaults and drop anything out of range. */
function chat_settings_clean(array $in): array
{
    $d = CHAT_SETTINGS_DEFAULTS;
    $text = function (string $key, int $max, bool $allowEmpty = false) use ($in, $d) {
        if (!array_key_exists($key, $in)) return $d[$key];
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $in[$key])));
        $v = mb_substr($v, 0, $max);
        return $v === '' && !$allowEmpty ? $d[$key] : $v;
    };
    $pick = fn(string $key, array $allowed) => in_array($in[$key] ?? null, $allowed, true) ? $in[$key] : $d[$key];

    $suggestions = $d['suggestions'];
    if (array_key_exists('suggestions', $in)) {
        $list = is_array($in['suggestions']) ? $in['suggestions'] : preg_split('/\r\n|\r|\n/', (string) $in['suggestions']);
        $suggestions = array_slice(array_values(array_unique(array_filter(array_map(
            fn($s) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s))), 0, 80), $list
        ), 'strlen'))), 0, 6);
    }
    $logo = (string) ($in['logo'] ?? '');
    $privacy = trim((string) ($in['privacyUrl'] ?? ''));

    return [
        'enabled'     => array_key_exists('enabled', $in) ? (bool) $in['enabled'] : $d['enabled'],
        'position'    => $pick('position', ['right', 'left', 'hidden']),
        'button'      => $text('button', 24),
        'autoOpen'    => $pick('autoOpen', ['smart', 'bubble', 'open', 'off']),
        'autoDelay'   => max(0, min(60, (int) ($in['autoDelay'] ?? $d['autoDelay']))),
        'bubble'      => $text('bubble', 90),
        'title'       => $text('title', 40),
        'status'      => $text('status', 60, true),
        'welcome'     => $text('welcome', 400),
        'logo'        => preg_match(CHAT_LOGO_PATH, $logo) && is_file(dirname(__DIR__) . '/' . $logo) ? $logo : '',
        'gate'        => $pick('gate', ['email', 'optional', 'off']),
        'gatePhone'   => $pick('gatePhone', ['off', 'optional', 'required']),
        'gateText'    => $text('gateText', 200),
        'suggestions' => $suggestions,
        'phone'       => $text('phone', 30),
        'hours'       => $text('hours', 80, true),
        'notice'      => $text('notice', 200, true),
        'privacyUrl'  => preg_match('#^https?://[^\s<>"]+$#i', $privacy) ? mb_substr($privacy, 0, 300) : '',
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    $s = chat_settings();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
