<?php
/**
 * Chat settings: how the website's chat assistant looks and behaves (its button,
 * opening by itself, wording, logo, the contact form before a chat, suggested
 * questions, the team's phone and hours, and the notice under the chat box).
 * Stored by api/chat-settings.php, which js/chat.js reads on every page. A live
 * preview (admin/assets/chat-settings.js) shows the chat as it will look.
 * The AI's own knowledge is edited in Knowledge; its keys stay in the settings file.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

const CHAT_LOGO_MAX_BYTES = 2000000;

/** Store an uploaded logo in images/chat/. Returns [path, error]; ['', ''] when nothing was uploaded. */
function chat_store_logo(array $file): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['', ''];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'That picture is too big to upload.'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['', 'The picture didn\'t upload. Please try again.'];
    if ((int) $file['size'] > CHAT_LOGO_MAX_BYTES) return ['', 'That picture is over 2 MB. Pick a smaller one.'];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$info || !isset($types[$info[2]])) return ['', 'Please use a WebP, JPG or PNG picture.'];
    if (class_exists('finfo') && !in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), ['image/webp', 'image/jpeg', 'image/png'], true)) {
        return ['', 'Please use a WebP, JPG or PNG picture.'];
    }
    $dir = dirname(__DIR__, 3) . '/images/chat';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return ['', 'The picture couldn\'t be saved. The folder images/chat/ must be writable by the website.'];
    @chmod($dir . '/' . $name, 0644);
    return ['images/chat/' . $name, ''];
}

function admin_page_chat_settings(): void
{
    $cur = chat_settings();

    if (is_post()) {
        require_csrf();
        $new = [
            'enabled'     => ($_POST['enabled'] ?? '1') === '1',
            'position'    => (string) ($_POST['position'] ?? ''),
            'button'      => (string) ($_POST['button'] ?? ''),
            'autoOpen'    => (string) ($_POST['autoOpen'] ?? ''),
            'autoDelay'   => (int) ($_POST['autoDelay'] ?? 3),
            'bubble'      => (string) ($_POST['bubble'] ?? ''),
            'title'       => (string) ($_POST['title'] ?? ''),
            'status'      => (string) ($_POST['status'] ?? ''),
            'welcome'     => (string) ($_POST['welcome'] ?? ''),
            'gate'        => (string) ($_POST['gate'] ?? ''),
            'gatePhone'   => (string) ($_POST['gatePhone'] ?? ''),
            'gateText'    => (string) ($_POST['gateText'] ?? ''),
            'suggestions' => (string) ($_POST['suggestions'] ?? ''),
            'phone'       => (string) ($_POST['phone'] ?? ''),
            'hours'       => (string) ($_POST['hours'] ?? ''),
            'notice'      => (string) ($_POST['notice'] ?? ''),
            'privacyUrl'  => (string) ($_POST['privacyUrl'] ?? ''),
            'logo'        => $cur['logo'],
        ];
        $privacy = trim($new['privacyUrl']);
        [$logo, $logoError] = chat_store_logo($_FILES['logo_file'] ?? []);
        if ($logo !== '') $new['logo'] = $logo;
        elseif (!empty($_POST['logo_default'])) $new['logo'] = '';
        // A replaced upload isn't needed any more.
        if ($new['logo'] !== $cur['logo'] && $cur['logo'] !== '' && preg_match(CHAT_LOGO_PATH, $cur['logo'])) @unlink(dirname(__DIR__, 3) . '/' . $cur['logo']);

        if (!chat_settings_save($new)) {
            flash('The settings couldn\'t be saved: the database didn\'t answer. Please try again.', 'err');
        } else {
            // Which settings changed, by the names on this page.
            $names = ['enabled' => 'Chat on the website', 'position' => 'Button position', 'button' => 'Button style', 'autoOpen' => 'Open by itself',
                'autoDelay' => 'Open-by-itself delay', 'bubble' => 'Bubble text', 'title' => 'Title', 'status' => 'Status line', 'welcome' => 'Welcome message',
                'gate' => 'Ask for contact details', 'gatePhone' => 'Ask for phone', 'gateText' => 'Contact details text', 'suggestions' => 'Suggested questions',
                'phone' => 'Phone', 'hours' => 'Hours', 'notice' => 'Notice', 'privacyUrl' => 'Privacy policy link', 'logo' => 'Logo'];
            $saved = chat_settings();
            $changes = [];
            foreach ($names as $k => $label) {
                if (($cur[$k] ?? null) == ($saved[$k] ?? null)) continue;
                $changes[] = $k === 'enabled' ? 'Chat ' . (!empty($saved['enabled']) ? 'switched on' : 'switched off') : $label . ' changed';
            }
            if ($changes) admin_log('chat.settings', '', $changes);
            $notes = [];
            if ($logoError !== '') $notes[] = 'The new logo wasn\'t used: ' . lcfirst($logoError);
            if ($privacy !== '' && !preg_match('#^https?://#i', $privacy)) $notes[] = 'The privacy policy link was left out: it must start with https://';
            flash($notes ? 'Saved, but ' . lcfirst(implode(' ', $notes)) : 'Saved. The chat on the website shows the changes within a minute.', $notes ? 'err' : 'ok');
        }
        redirect(admin_url('chat-settings'));
    }

    $c = $cur;
    $opt = function (string $name, array $choices, string $sel): string {
        $o = '';
        foreach ($choices as $value => $label) $o .= '<option value="' . h($value) . '"' . ((string) $value === $sel ? ' selected' : '') . '>' . h($label) . '</option>';
        return '<select name="' . $name . '" data-cs="' . $name . '">' . $o . '</select>';
    };
    $field = fn(string $label, string $control, string $help = '', string $class = '') =>
        '<label class="field' . ($class !== '' ? ' ' . $class : '') . '"><span>' . h($label) . '</span>' . $control . ($help !== '' ? '<small class="muted">' . $help . '</small>' : '') . '</label>';
    $input = fn(string $name, string $value, int $max, string $extra = '') =>
        '<input name="' . $name . '" value="' . h($value) . '" maxlength="' . $max . '" data-cs="' . $name . '"' . $extra . '>';
    $logoUrl = SITE_BASE . ($c['logo'] !== '' ? $c['logo'] : 'images/apple-touch-icon.png');

    $form = '<form method="post" action="' . h(admin_url('chat-settings')) . '" enctype="multipart/form-data" class="cs-form">' . csrf_field()
        . card('Chat on the website', '<div class="form-grid">'
            . $field('Chat assistant', $opt('enabled', ['1' => 'On', '0' => 'Off (hidden on every page)'], $c['enabled'] ? '1' : '0'))
            . $field('Floating chat button', $opt('position', ['right' => 'Bottom right of every page', 'left' => 'Bottom left of every page', 'hidden' => 'Hidden'], $c['position']),
                'Hidden: guests only see the chat when it opens by itself.')
            . $field('Button text', $input('button', $c['button'], 24), 'Phones show the icon only.', 'sm')
            . $field('Opens by itself', $opt('autoOpen', [
                    'smart'  => 'Computers: open the chat. Phones: greeting bubble',
                    'bubble' => 'Greeting bubble on every device',
                    'open'   => 'Open the chat on every device',
                    'off'    => 'Off (only when clicked)',
                ], $c['autoOpen']), 'Once per visit: a guest who closes it isn\'t asked again. On phones the chat fills the screen, and Google ranks pages with screen-covering pop-ups lower, so the bubble is recommended there.', 'wide')
            . $field('Phone greeting bubble', $input('bubble', $c['bubble'], 90))
            . $field('After (seconds)', '<input type="number" name="autoDelay" value="' . (int) $c['autoDelay'] . '" min="0" max="60" data-cs="autoDelay">', 'After the first page has loaded.', 'sm')
            . '</div>')
        . card('Chat window', '<div class="form-grid">'
            . $field('Title', $input('title', $c['title'], 40))
            . $field('Line under the title', $input('status', $c['status'], 60))
            . $field('Welcome message', '<textarea name="welcome" rows="3" maxlength="400" data-cs="welcome">' . h($c['welcome']) . '</textarea>', '', 'wide')
            . '<div class="field wide"><span>Logo</span><div class="cs-logo"><img src="' . h($logoUrl) . '" alt="Current chat logo" width="56" height="56" data-cs-logo data-default="' . h(SITE_BASE . 'images/apple-touch-icon.png') . '">'
                . '<div><input type="file" name="logo_file" accept="image/webp,image/jpeg,image/png" data-cs="logo_file">'
                . ($c['logo'] !== '' ? '<label class="check"><input type="checkbox" name="logo_default" value="1" data-cs="logo_default"> Use the RMS logo again</label>' : '')
                . '<small class="muted">A square WebP, JPG or PNG, up to 2 MB. Shown round in the chat header and welcome.</small></div></div></div>'
            . '</div>')
        . card('Before the chat starts', '<div class="form-grid">'
            . $field('Ask for name and email first', $opt('gate', ['email' => 'Email required, name optional', 'optional' => 'Optional (the guest can skip it)', 'off' => 'Off (no form)'], $c['gate']),
                'What guests enter is saved with the conversation in Conversations.')
            . $field('Phone number in that form', $opt('gatePhone', ['off' => 'Off', 'optional' => 'Optional', 'required' => 'Required'], $c['gatePhone']),
                'Required applies when the guest fills in the form. If the form is optional, Skip still skips all of it.')
            . $field('Text above that form', $input('gateText', $c['gateText'], 200), '', 'wide')
            . '</div>')
        . card('Suggested questions', '<div class="form-grid">'
            . $field('Questions', '<textarea name="suggestions" rows="5" data-cs="suggestions">' . h(implode("\n", $c['suggestions'])) . '</textarea>',
                'One per line, up to six. Shown as buttons when a chat starts; a tap asks that question.', 'wide')
            . '</div>')
        . card('Talk to a person', '<div class="form-grid">'
            . $field('Team phone number', $input('phone', $c['phone'], 30))
            . $field('Team hours', $input('hours', $c['hours'], 80))
            . '<p class="muted wide">Shown when a guest asks for a person, and under the chat box.</p>'
            . '</div>')
        . card('Notice and privacy', '<div class="form-grid">'
            . $field('Notice under the chat box', $input('notice', $c['notice'], 200), 'Tell guests that chats are saved. Leave blank for no notice.', 'wide')
            . $field('Privacy policy link', $input('privacyUrl', $c['privacyUrl'], 300, ' type="url" placeholder="https://"'), 'Shown as "Privacy policy" at the end of that notice. Leave blank for no link.', 'wide')
            . '<p class="muted wide">"Chat built by SEOBERG" always shows at the bottom of the chat.</p>'
            . '</div>')
        . '<div class="savebar"><span class="muted">The chat on the website updates within a minute of saving.</span>' . btn_submit('Save changes', 'primary', '', '', 'check') . '</div>'
        . '</form>';

    // The preview mirrors js/chat.js's layout; admin/assets/chat-settings.js keeps it in step with the form.
    $preview = '<aside class="cs-preview" aria-label="Live preview"><div class="cs-pv-label">Live preview</div>'
        . '<div class="cs-chat" data-pv="chat">'
        .   '<div class="cs-head"><img src="' . h($logoUrl) . '" alt="" width="34" height="34" data-pv="logo"><div><b data-pv="title"></b><span data-pv="status"></span></div></div>'
        .   '<div class="cs-body"><img class="cs-welcome-logo" src="' . h($logoUrl) . '" alt="" width="48" height="48" data-pv="logo">'
        .     '<h4>Hey there! 👋</h4><p data-pv="welcome"></p><div class="cs-chips" data-pv="chips"></div>'
        .     '<div class="cs-gate" data-pv="gate"><p data-pv="gateText"></p>'
        .       '<span class="cs-lbl">Name <em>(optional)</em></span><i>Your name</i>'
        .       '<span class="cs-lbl">Email <em data-pv="emailOpt">(optional)</em></span><i>you@example.com</i>'
        .       '<div data-pv="phoneRow"><span class="cs-lbl">Phone <em data-pv="phoneOpt">(optional)</em></span><i>(954) 555-0100</i></div>'
        .       '<div class="cs-go">Start chatting</div><div class="cs-skip" data-pv="skip">Skip</div></div>'
        .   '</div>'
        .   '<div class="cs-foot"><span data-pv="notice"></span><span>To book or for anything urgent, call <a data-pv="phone"></a>.</span><span>Chat built by <a>SEOBERG</a></span></div>'
        . '</div>'
        . '<div class="cs-launch-row" data-pv="launchRow"><div class="cs-bubble" data-pv="bubble"></div><div class="cs-launch" data-pv="button"></div></div>'
        . '<p class="cs-off" data-pv="off">The chat is switched off: guests don\'t see it on any page.</p>'
        . '</aside>';

    $body = page_head('Chat settings', 'How the chat assistant looks and behaves on the website: its button, opening by itself, wording, the form before a chat, and the team\'s details. What the assistant knows is edited in <a href="' . h(admin_url('knowledge')) . '">Knowledge</a>.',
            btn_link('View website', SITE_BASE, 'sm', 'external', ' target="_blank" rel="noopener"'))
        . '<div class="cs-layout">' . $form . $preview . '</div>';
    admin_render('Chat settings', $body, 'chat-settings', ['chat-settings.js']);
}
