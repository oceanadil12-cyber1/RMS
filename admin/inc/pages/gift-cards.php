<?php
/**
 * Gift cards: cards sold on gift-cards.html and cards the team issues, their
 * balances and redemptions, and the designs and amounts the website offers.
 * The rules live in api/gift-cards-lib.php (shared with the website).
 *
 *   ?page=gift-cards                    the list
 *   ?page=gift-cards&id=12              one card: activate, redeem, adjust, email, void
 *   ?page=gift-cards&view=issue         issue a custom card (comp, prize, sold at the bar)
 *   ?page=gift-cards&view=settings      designs, amounts, on/off
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

function admin_page_gift_cards(): void
{
    if (is_post()) admin_gift_cards_post();
    $id = get_str('id', 12);
    if (ctype_digit($id)) admin_gift_card_detail((int) $id); // ends the request
    $view = get_choice('view', ['list', 'issue', 'settings'], 'list');
    if ($view === 'issue') admin_gift_cards_issue();
    if ($view === 'settings') admin_gift_cards_settings();
    admin_gift_cards_list();
}

/** The tabs over the three views. */
function gift_cards_tabs(string $on): string
{
    return tabs_html([
        ['label' => 'Cards', 'href' => admin_url('gift-cards'), 'on' => $on === 'list'],
        ['label' => 'Issue a gift card', 'href' => admin_url('gift-cards', ['view' => 'issue']), 'on' => $on === 'issue'],
        ['label' => 'Designs & settings', 'href' => admin_url('gift-cards', ['view' => 'settings']), 'on' => $on === 'settings'],
    ]);
}

/** A card's picture with its amount (custom pictures get their words on top). */
function gift_card_face(array $card, string $class = ''): string
{
    $img = giftcards_card_image($card);
    $custom = $card['photo'] !== '' || $card['design'] === 'custom';
    $title = $card['design_title'];
    return '<div class="gc-face' . ($class !== '' ? ' ' . $class : '') . ($custom ? ' is-custom' : '') . '"'
        . ($img !== '' ? ' style="background-image:url(\'' . h(SITE_BASE . $img) . '\')"' : '') . '>'
        . ($custom ? '<span class="gc-face-brand">RMS gift card</span>' . ($title !== '' ? '<span class="gc-face-title">' . h($title) . '</span>' : '') : '')
        . '<span class="gc-face-amount">' . h(fmt_money((int) $card['amount'])) . '</span></div>';
}

function gift_card_label(array $card): string
{
    return '•••• ' . substr($card['code'], -4);
}

/** "Sent Nov 3" / "Sends Dec 25" / "Not sent" for a card's email. */
function gift_card_delivery(array $card): string
{
    if ($card['sent_at']) return 'Sent ' . fmt_when($card['sent_at']);
    if ($card['recipient_email'] === '' && $card['buyer_email'] === '') return 'No email';
    if ($card['status'] !== 'active') return 'Not sent';
    return $card['deliver_on'] && $card['deliver_on'] > date('Y-m-d') ? 'Sends ' . fmt_date($card['deliver_on']) : 'Sending…';
}

// ---------- saving ----------

function admin_gift_cards_post(): void
{
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'issue') admin_gift_cards_issue_post();
    if (in_array($action, ['settings', 'design-add', 'design-remove'], true)) admin_gift_cards_settings_post($action);

    $card = ctype_digit((string) ($_POST['id'] ?? '')) ? giftcards_find('id', (int) $_POST['id']) : null;
    if (!$card) {
        flash('That gift card wasn\'t found.', 'err');
        redirect(admin_url('gift-cards'));
    }
    $id = (int) $card['id'];
    $back = admin_url('gift-cards', ['id' => $id]);
    $label = gift_card_label($card);
    $balance = (int) $card['balance'];

    switch ($action) {
        case 'activate': // payment taken for a website request
            if ($card['status'] !== 'pending') break;
            $how = post_str('paid_how', 60) ?: 'Paid';
            db_update('gift_cards', ['status' => 'active', 'balance' => (int) $card['amount']], ['id' => $id]);
            giftcards_activity($id, 'activate', (int) $card['amount'], (int) $card['amount'], $how);
            admin_log('giftcard.activate', "$label, " . fmt_money((int) $card['amount']) . ": $how");
            $card = giftcards_find('id', $id);
            $sendNow = !$card['deliver_on'] || $card['deliver_on'] <= date('Y-m-d');
            if ($sendNow && ($card['recipient_email'] !== '' || $card['buyer_email'] !== '')) {
                if (giftcards_send($card)) flash('Activated and emailed.');
                else flash('Activated, but the email couldn\'t be sent. Press "Send email now" to try again.', 'err');
            } else {
                flash($card['deliver_on'] ? 'Activated. It will be emailed on ' . fmt_date($card['deliver_on']) . '.' : 'Activated.');
            }
            break;

        case 'redeem':
        case 'adjust':
            $cents = dollars_to_cents(post_str('amount', 12));
            $note = post_str('note', 120);
            if ($cents === null || $cents === 0) { flash('Enter an amount.', 'err'); break; }
            if ($action === 'redeem') {
                if ($cents < 0) { flash('Enter the amount spent as a positive number.', 'err'); break; }
                $change = -$cents;
            } else {
                $change = ($_POST['direction'] ?? '') === 'remove' ? -abs($cents) : abs($cents);
            }
            if ($card['status'] !== 'active' && !($action === 'adjust' && $card['status'] === 'used')) { flash('Only active cards can be changed.', 'err'); break; }
            if ($balance + $change < 0) { flash('That\'s more than the ' . fmt_money($balance) . ' left on the card.', 'err'); break; }
            if ($card['source'] === 'square') {
                if ($action !== 'redeem') { flash('Change a Square card\'s balance in the Square Dashboard.', 'err'); break; }
                $err = giftcards_square_redeem($card, $cents, $note);
                if ($err !== '') { flash("Square didn't take it: $err", 'err'); break; }
            }
            $new = $balance + $change;
            db_update('gift_cards', ['balance' => $new, 'status' => $new > 0 ? 'active' : 'used'], ['id' => $id]);
            giftcards_activity($id, $action, $change, $new, $note);
            admin_log('giftcard.' . $action, "$label: " . ($change < 0 ? '−' : '+') . fmt_money(abs($change)) . ', ' . fmt_money($new) . ' left' . ($note !== '' ? " ($note)" : ''));
            flash(($action === 'redeem' ? 'Redeemed ' . fmt_money($cents) : 'Balance ' . ($change < 0 ? 'lowered' : 'raised')) . '. ' . fmt_money($new) . ' left on the card.');
            break;

        case 'refresh':
            $card = giftcards_square_refresh($card);
            flash('Balance from Square: ' . fmt_money((int) $card['balance']) . '.');
            break;

        case 'retry': // paid on the website, but Square didn't create the card
            if ($card['status'] !== 'error' || $card['square_payment_id'] === '') break;
            [$sq, $err] = giftcards_square_issue((int) $card['amount'], $card['square_payment_id'], 'p' . $card['square_payment_id']);
            if (!$sq) { flash("Square still didn't create the card: $err", 'err'); break; }
            db_update('gift_cards', ['status' => 'active', 'code' => $sq['gan'], 'square_card_id' => $sq['id'], 'balance' => (int) $card['amount']], ['id' => $id]);
            giftcards_activity($id, 'activate', (int) $card['amount'], (int) $card['amount'], 'Created in Square');
            admin_log('giftcard.retry', '•••• ' . substr($sq['gan'], -4) . ' created in Square');
            $card = giftcards_find('id', $id);
            if (!$card['deliver_on'] || $card['deliver_on'] <= date('Y-m-d')) giftcards_send($card);
            flash('Created in Square' . (giftcards_find('id', $id)['sent_at'] ? ' and emailed.' : '.'));
            break;

        case 'email':
            if ($card['status'] !== 'active') { flash('Only active cards can be emailed.', 'err'); break; }
            if (giftcards_send($card)) {
                admin_log('giftcard.email', "$label to " . mask_email($card['recipient_email'] ?: $card['buyer_email']));
                flash('Emailed.');
            } else {
                flash('The email couldn\'t be sent. Check the address, and that the website can send mail.', 'err');
            }
            break;

        case 'edit':
            $email = post_str('recipient_email', 120);
            $date = post_str('deliver_on', 10);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('That email address doesn\'t look right.', 'err'); break; }
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { flash('That date doesn\'t look right.', 'err'); break; }
            db_update('gift_cards', [
                'recipient_name' => post_str('recipient_name', 80), 'recipient_email' => $email,
                'message' => post_str('message', 300), 'deliver_on' => $date !== '' ? $date : null,
            ], ['id' => $id]);
            admin_log('giftcard.edit', $label);
            flash('Saved.');
            break;

        case 'void':
            if ($card['source'] === 'square' && $card['status'] !== 'error') { flash('Deactivate Square cards in the Square Dashboard (Gift Cards).', 'err'); break; }
            db_update('gift_cards', ['status' => 'void', 'balance' => 0], ['id' => $id]);
            giftcards_activity($id, 'void', -$balance, 0, post_str('note', 120));
            admin_log('giftcard.void', $label . ($balance ? ', ' . fmt_money($balance) . ' cancelled' : ''));
            flash('Card voided.');
            break;

        case 'note':
            db_update('gift_cards', ['note' => post_str('note', 1000)], ['id' => $id]);
            admin_log('giftcard.note', $label);
            flash('Note saved.');
            break;
    }
    redirect($back);
}

function admin_gift_cards_issue_post(): void
{
    $back = admin_url('gift-cards', ['view' => 'issue']);
    $amount = dollars_to_cents(post_str('amount', 12));
    $design = post_str('design', 40);
    $title = post_str('title', 40);
    $email = post_str('recipient_email', 120);
    $date = post_str('deliver_on', 10);
    $paid = post_str('paid_how', 60);
    if ($amount === null || $amount < 100 || $amount > 1000000) { flash('Enter an amount from $1 to $10,000.', 'err'); redirect($back); }
    if ($design !== 'custom' && !giftcards_design($design)) $design = 'custom';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('That email address doesn\'t look right.', 'err'); redirect($back); }
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d'))) { flash('Choose today or a later day to send it.', 'err'); redirect($back); }

    $card = giftcards_create([
        'source' => 'local', 'origin' => 'admin', 'status' => 'active',
        'design' => $design, 'design_title' => $title, 'amount' => $amount, 'balance' => $amount,
        'buyer_name' => post_str('from_name', 80),
        'recipient_name' => post_str('recipient_name', 80), 'recipient_email' => $email,
        'message' => post_str('message', 300), 'deliver_on' => $date > date('Y-m-d') ? $date : null,
        'note' => post_str('note', 1000),
    ], 'Issued by the team' . ($paid !== '' ? ": $paid" : ''));
    admin_log('giftcard.issue', gift_card_label($card) . ', ' . fmt_money($amount) . ($card['recipient_name'] !== '' ? ' for ' . $card['recipient_name'] : '') . ($paid !== '' ? " ($paid)" : ''));
    $sent = $email !== '' && !$card['deliver_on'] && giftcards_send($card);
    flash('Gift card ' . giftcards_format_code($card['code']) . ' issued' . ($sent ? ' and emailed.' : '.'));
    redirect(admin_url('gift-cards', ['id' => $card['id']]));
}

function admin_gift_cards_settings_post(string $action): void
{
    $back = admin_url('gift-cards', ['view' => 'settings']);
    $s = giftcards_settings();

    if ($action === 'design-add') {
        $name = post_str('name', 40);
        if ($name === '') { flash('Give the design a name.', 'err'); redirect($back); }
        [$path, $err] = gift_cards_store_design($_FILES['image'] ?? [], $name);
        if ($err !== '' || $path === '') { flash($err ?: 'Choose a picture for the design.', 'err'); redirect($back); }
        $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'design';
        $taken = array_column($s['designs'], 'id');
        $base = $id;
        for ($n = 2; in_array($id, $taken, true) || $id === 'custom'; $n++) $id = "$base-$n";
        $s['designs'][] = ['id' => $id, 'name' => $name, 'title' => post_str('title', 40), 'image' => $path, 'active' => true];
        giftcards_settings_save($s);
        admin_log('giftcard.design', "Added \"$name\"");
        flash("Design \"$name\" added. It's on the website now.");
        redirect($back);
    }

    if ($action === 'design-remove') {
        $id = post_str('design', 40);
        $s['designs'] = array_values(array_filter($s['designs'], fn($d) => $d['id'] !== $id));
        giftcards_settings_save($s);
        admin_log('giftcard.design', "Removed $id");
        flash('Design removed. Cards already sold with it keep their picture.');
        redirect($back);
    }

    $amounts = [];
    foreach (preg_split('/[\s,]+/', post_str('amounts', 200)) as $a) {
        $c = dollars_to_cents($a);
        if ($c !== null && $c >= 100 && $c <= 1000000) $amounts[] = $c;
    }
    $amounts = array_values(array_unique($amounts));
    sort($amounts);
    $min = dollars_to_cents(post_str('min', 12));
    $max = dollars_to_cents(post_str('max', 12));
    if (!$amounts) { flash('Enter at least one amount, like 25, 50, 100.', 'err'); redirect($back); }
    if ($min === null || $max === null || $min < 100 || $max < $min) { flash('Check the lowest and highest custom amounts.', 'err'); redirect($back); }

    $designs = [];
    foreach ($s['designs'] as $d) {
        $key = 'd_' . $d['id'];
        $d['name'] = post_str($key . '_name', 40) ?: $d['name'];
        $d['title'] = post_str($key . '_title', 40);
        $d['active'] = !empty($_POST[$key . '_active']);
        $d['sort'] = (int) ($_POST[$key . '_sort'] ?? 0);
        $designs[] = $d;
    }
    usort($designs, fn($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($designs as &$d) unset($d['sort']);
    unset($d);

    $s = [
        'enabled' => !empty($_POST['enabled']), 'useSquare' => !empty($_POST['useSquare']),
        'amounts' => $amounts, 'min' => (int) (round($min / 100) * 100), 'max' => (int) (round($max / 100) * 100),
        'allowCustomAmount' => !empty($_POST['allowCustomAmount']), 'allowCustomDesign' => !empty($_POST['allowCustomDesign']),
        'designs' => $designs,
    ] + $s;
    giftcards_settings_save($s);
    admin_log('giftcard.settings', ($s['enabled'] ? 'On' : 'Off') . ', amounts ' . implode('/', array_map(fn($c) => '$' . ($c / 100), $amounts)));
    flash('Gift card settings saved.');
    redirect($back);
}

/** An uploaded design picture into images/giftcards/: [path, ''] or ['', reason]. */
function gift_cards_store_design(array $file, string $name): array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return ['', 'Choose a picture for the design.'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'That picture is too big to upload.'];
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['', 'The picture didn\'t upload. Please try again.'];
    if ((int) $file['size'] > GIFTCARD_DESIGN_MAX_BYTES) return ['', 'That picture is over ' . (GIFTCARD_DESIGN_MAX_BYTES / 1000000) . ' MB. Save a smaller copy and try again.'];
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (!$info || !isset($types[$info[2]])) return ['', 'Please use a WebP, JPG or PNG picture. (A PDF design needs saving as a picture first.)'];
    if (class_exists('finfo') && !in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), ['image/webp', 'image/jpeg', 'image/png'], true)) {
        return ['', 'Please use a WebP, JPG or PNG picture.'];
    }
    if ($info[0] < 600) return ['', 'That picture is only ' . $info[0] . ' px wide. Use one at least 1200 px wide so it looks sharp.'];
    $dir = dirname(__DIR__, 3) . '/' . GIFTCARD_DESIGN_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file_name = (trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'design') . '-' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], "$dir/$file_name")) return ['', 'The picture couldn\'t be saved. The folder ' . GIFTCARD_DESIGN_DIR . '/ must be writable by the website.'];
    @chmod("$dir/$file_name", 0644);
    return [GIFTCARD_DESIGN_DIR . '/' . $file_name, ''];
}

// ---------- one card ----------

function admin_gift_card_detail(int $id): void
{
    $card = giftcards_find('id', $id);
    $back = '<p class="back"><a href="' . h(admin_url('gift-cards')) . '">' . icon('left', 15) . ' Gift cards</a></p>';
    if (!$card) admin_render('Gift card', $back . empty_state('Gift card not found', '', 'gift'), 'gift-cards');
    if ($card['source'] === 'square' && $card['status'] === 'active') $card = giftcards_square_refresh($card);

    $hidden = '<input type="hidden" name="id" value="' . $id . '">';
    $form = fn(string $action, string $inner) => form_open(admin_url('gift-cards')) . $hidden . '<input type="hidden" name="action" value="' . h($action) . '">' . $inner . '</form>';
    $local = $card['source'] === 'local';
    $design = giftcards_design($card['design']);

    // What can be done with it now.
    $actions = '';
    if ($card['status'] === 'pending') {
        $actions .= card('Take payment', '<p class="muted small">Requested on the website. Call the buyer, take payment at the bar or by phone, then activate the card. It\'s emailed '
            . ($card['deliver_on'] ? 'on ' . h(fmt_date($card['deliver_on'])) : 'straight away') . '.</p>'
            . $form('activate', '<div class="form-grid"><label class="field"><span>How it was paid</span><select name="paid_how"><option>Card, taken by phone</option><option>Card at the bar</option><option>Cash at the bar</option><option>Other</option></select></label>'
                . '<div class="field sm">' . btn_submit('Mark paid & activate', 'primary', '', '', 'check') . '</div></div>')
            . $form('void', '<div class="bar">' . btn_submit('Cancel request', 'sm ghost', '', '', 'x', 'Cancel this gift card request?') . '</div>'));
    }
    if ($card['status'] === 'error') {
        $actions .= card('Needs attention', '<p class="small">The buyer paid by card (Square payment <code>' . h($card['square_payment_id']) . '</code>), but Square didn\'t create the gift card. Try again; if it keeps failing, refund the payment in the Square Dashboard and void this card.</p>'
            . $form('retry', '<div class="bar">' . btn_submit('Retry in Square', 'primary', '', '', 'refresh') . '</div>')
            . $form('void', '<div class="bar">' . btn_submit('Void', 'sm ghost', '', '', 'x', 'Void this card? Refund the payment in Square yourself.') . '</div>'));
    }
    if ($card['status'] === 'active') {
        $actions .= card('Redeem', '<p class="muted small">' . ($local
                ? 'Take what the guest spent off the card.'
                : 'This is a Square card: it\'s normally redeemed on the Square POS. Use this only for spending made outside the POS.') . '</p>'
            . $form('redeem', '<div class="form-grid"><label class="field sm"><span>Amount spent</span>' . money_input('amount', '', '0.00', true) . '</label>'
                . '<label class="field"><span>Note</span><input name="note" maxlength="120" placeholder="Table 12, check #"></label>'
                . '<div class="field sm">' . btn_submit('Redeem', 'primary') . '</div></div>'));
    }
    if ($local && in_array($card['status'], ['active', 'used'], true)) {
        $actions .= card('Adjust balance', $form('adjust', '<div class="form-grid"><label class="field sm"><span>Amount</span>' . money_input('amount', '', '0.00', true) . '</label>'
            . '<label class="field sm"><span>Change</span><select name="direction"><option value="add">Add to card</option><option value="remove">Take off card</option></select></label>'
            . '<label class="field"><span>Reason</span><input name="note" maxlength="120" placeholder="Refunded item, correction…"></label>'
            . '<div class="field sm">' . btn_submit('Save', '') . '</div></div>'));
    }

    $buttons = '';
    if ($card['status'] === 'active' && ($card['recipient_email'] !== '' || $card['buyer_email'] !== '')) $buttons .= $form('email', btn_submit($card['sent_at'] ? 'Send email again' : 'Send email now', 'sm', '', '', 'mail'));
    if (!$local && $card['status'] !== 'error') $buttons .= $form('refresh', btn_submit('Balance from Square', 'sm', '', '', 'refresh'));
    if ($local && in_array($card['status'], ['active', 'used'], true)) $buttons .= $form('void', btn_submit('Void card', 'sm ghost', '', '', 'x', 'Void this card? Its balance can no longer be used.'));
    $buttons .= btn_link('Open card page', SITE_BASE . 'gift-cards.html#card=' . $card['token'], 'sm', 'external', ' target="_blank" rel="noopener"');

    $rows = '';
    foreach (giftcards_history($id) as $a) {
        $rows .= '<tr><td class="nowrap">' . h(fmt_when($a['created_at'])) . '</td><td>' . h(ucfirst($a['type'])) . '</td>'
            . '<td class="num">' . ((int) $a['amount'] === 0 ? '' : ((int) $a['amount'] < 0 ? '−' : '+') . h(fmt_money(abs((int) $a['amount'])))) . '</td>'
            . '<td class="num">' . h(fmt_money((int) $a['balance_after'])) . '</td><td class="q">' . h($a['detail']) . '</td></tr>';
    }

    $body = $back
        . page_head(fmt_money((int) $card['amount']) . ' gift card ' . gift_card_label($card),
            ($card['origin'] === 'admin' ? 'Issued by the team' : 'Bought on the website') . ' &middot; ' . h(fmt_when($card['created_at']))
            . ' &middot; ' . ($local ? 'Kept in this panel' : 'Square gift card'),
            status_badge(GIFTCARD_STATUSES, $card['status']))
        . '<div class="split"><div class="stack">'
        .   '<section class="card"><div class="card-body gc-detail">' . gift_card_face($card, 'lg')
        .     '<div>' . kv([
                ['Code', in_array($card['status'], ['active', 'used'], true) ? '<code class="gc-code">' . h(giftcards_format_code($card['code'])) . '</code>' : '<span class="muted">Shown once active</span>'],
                ['Balance', '<b class="gc-bal">' . h(fmt_money((int) $card['balance'])) . '</b> of ' . h(fmt_money((int) $card['amount']))],
                ['Design', h($card['photo'] !== '' || $card['design'] === 'custom' ? 'Own design' . ($card['design_title'] !== '' ? ': "' . $card['design_title'] . '"' : '') : ($design['name'] ?? $card['design']))],
                ['Email', h(gift_card_delivery($card))],
              ]) . '<div class="btns">' . $buttons . '</div></div></div></section>'
        .   $actions
        .   card('History', $rows === '' ? '<p class="empty-sm">Nothing yet.</p>' : '<div class="table"><table class="tbl"><thead><tr><th>When</th><th>What</th><th class="num">Amount</th><th class="num">Balance</th><th>Detail</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
        . '</div><div class="stack">'
        .   card($card['origin'] === 'admin' ? 'From' : 'Bought by', $card['buyer_name'] . $card['buyer_email'] === '' ? '<p class="muted small">Issued by the team.</p>' : kv([
                ['Name', h($card['buyer_name'])],
                ['Email', mail_link($card['buyer_email'])],
                ['Phone', $card['buyer_phone'] !== '' ? tel_link($card['buyer_phone']) : ''],
                ['Receipt', $card['receipt_url'] !== '' ? '<a href="' . h($card['receipt_url']) . '" target="_blank" rel="noopener noreferrer">Square receipt</a>' : ''],
            ]))
        .   card('Recipient', $form('edit', '<div class="form-grid">'
                . '<label class="field"><span>Name</span><input name="recipient_name" maxlength="80" value="' . h($card['recipient_name']) . '"></label>'
                . '<label class="field"><span>Email</span><input type="email" name="recipient_email" maxlength="120" value="' . h($card['recipient_email']) . '" placeholder="' . h($card['buyer_email'] !== '' ? 'Blank = the buyer' : '') . '"></label>'
                . '<label class="field wide"><span>Message</span><textarea name="message" rows="2" maxlength="300">' . h($card['message']) . '</textarea></label>'
                . '<label class="field"><span>Email on</span><input type="date" name="deliver_on" value="' . h((string) $card['deliver_on']) . '"></label>'
                . '</div><div class="bar">' . btn_submit('Save', 'sm') . '</div>'))
        .   card('Note for the team', $form('note', '<label class="field wide"><span class="sr">Note</span><textarea name="note" rows="3" maxlength="1000" placeholder="Only the team sees this">' . h($card['note']) . '</textarea></label><div class="bar">' . btn_submit('Save note', 'primary') . '</div>'))
        . '</div></div>';
    admin_render('Gift card', $body, 'gift-cards');
}

// ---------- the list ----------

function admin_gift_cards_list(): void
{
    $views = [
        'all'     => ['All',              null],
        'pending' => ['Awaiting payment', 'pending'],
        'active'  => ['Active',           'active'],
        'error'   => ['Needs attention',  'error'],
        'used'    => ['Fully used',       'used'],
        'void'    => ['Void',             'void'],
    ];
    $view = get_choice('status', array_keys($views), 'all');
    $q = get_str('q', 80);
    $all = db()->query('SELECT * FROM gift_cards ORDER BY id DESC')->fetchAll();

    if (isset($_GET['csv'])) {
        csv_download('rms-gift-cards', ['created', 'code', 'type', 'origin', 'status', 'amount', 'balance', 'buyer', 'buyer email', 'buyer phone', 'recipient', 'recipient email', 'email on', 'sent', 'note'],
            array_map(fn($c) => [$c['created_at'], $c['code'], $c['source'], $c['origin'], $c['status'], $c['amount'] / 100, $c['balance'] / 100, $c['buyer_name'], $c['buyer_email'], $c['buyer_phone'], $c['recipient_name'], $c['recipient_email'], $c['deliver_on'], $c['sent_at'], $c['note']], $all));
    }

    $digits = preg_replace('/\D/', '', $q);
    $match = fn($c) => $q === '' || stripos($c['buyer_name'] . ' ' . $c['buyer_email'] . ' ' . $c['recipient_name'] . ' ' . $c['recipient_email'] . ' ' . $c['buyer_phone'], $q) !== false
        || (strlen($digits) >= 4 && strpos($c['code'], $digits) !== false);
    $tabs = [];
    foreach ($views as $key => [$label, $status]) {
        $tabs[] = ['label' => $label, 'href' => admin_url('gift-cards', ['status' => $key === 'all' ? '' : $key, 'q' => $q]), 'on' => $key === $view,
            'count' => count(array_filter($all, fn($c) => ($status === null || $c['status'] === $status) && $match($c)))];
    }
    $shown = array_values(array_filter($all, fn($c) => ($views[$view][1] === null || $c['status'] === $views[$view][1]) && $match($c)));
    [$pageRows, $pager] = paginate($shown, 50, 'gift-cards', ['status' => $view === 'all' ? '' : $view, 'q' => $q]);

    $active = array_filter($all, fn($c) => $c['status'] === 'active');
    $outstanding = array_sum(array_map(fn($c) => (int) $c['balance'], $active));
    $waiting = count(array_filter($all, fn($c) => in_array($c['status'], ['pending', 'error'], true)));
    $month = date('Y-m');
    $soldMonth = array_sum(array_map(fn($c) => (int) $c['amount'], array_filter($all, fn($c) => substr($c['created_at'], 0, 7) === $month && $c['origin'] === 'online' && in_array($c['status'], ['active', 'used'], true))));

    $rows = '';
    foreach ($pageRows as $c) {
        $url = admin_url('gift-cards', ['id' => $c['id']]);
        $rows .= '<tr><td><a class="row-link gc-row" href="' . h($url) . '">' . gift_card_face($c, 'sm') . '<span><b>' . h(gift_card_label($c)) . '</b><span class="cell-sub">'
            . ($c['source'] === 'square' ? 'Square' : ($c['origin'] === 'admin' ? 'Issued by team' : 'Website request')) . '</span></span></a></td>'
            . '<td class="num"><b>' . h(fmt_money((int) $c['balance'])) . '</b><div class="cell-sub">of ' . h(fmt_money((int) $c['amount'])) . '</div></td>'
            . '<td>' . ($c['buyer_name'] !== '' || $c['buyer_email'] !== '' ? guest_cell($c['buyer_name'], $c['buyer_email']) : '<span class="muted">Team</span>') . '</td>'
            . '<td>' . ($c['recipient_name'] !== '' ? h($c['recipient_name']) . '<div class="cell-sub">' . h($c['recipient_email']) . '</div>' : '<span class="muted">Buyer</span>') . '</td>'
            . '<td class="nowrap"><span class="cell-sub">' . h(gift_card_delivery($c)) . '</span></td>'
            . '<td>' . status_badge(GIFTCARD_STATUSES, $c['status']) . '</td>'
            . '<td class="nowrap"><span class="cell-sub">' . h(fmt_when($c['created_at'])) . '</span></td></tr>';
    }

    $mode = giftcards_mode() === 'square'
        ? 'Sold online through Square: each card is created in Square and redeemed on the Square POS.'
        : 'Card payments are off, so the website takes <b>requests</b>: call the buyer, take payment, then press "Mark paid".';
    $body = page_head('Gift cards', $mode, btn_link('Download spreadsheet', admin_url('gift-cards', ['csv' => 1]), '', 'download') . btn_link('Issue a gift card', admin_url('gift-cards', ['view' => 'issue']), 'primary', 'plus'))
        . gift_cards_tabs('list')
        . '<div class="stats">'
        . stat_tile('Waiting for you', number_format($waiting), admin_url('gift-cards', ['status' => 'pending']), 'Requests to take payment for', 'clock', $waiting ? 'attn' : '')
        . stat_tile('Active cards', number_format(count($active)), admin_url('gift-cards', ['status' => 'active']), h(fmt_money($outstanding)) . ' still to spend', 'gift')
        . stat_tile('Sold online this month', fmt_money($soldMonth), admin_url('gift-cards'), '', 'dollar')
        . '</div>'
        . '<div class="toolbar">' . tabs_html($tabs) . '<span class="grow"></span>' . search_form('gift-cards', ['status' => $view === 'all' ? '' : $view], $q, 'Search name, email, last 4 digits') . '</div>'
        . ($rows === ''
            ? empty_state($q !== '' ? 'No matching gift cards' : 'No gift cards here yet', $q !== '' ? '' : 'Cards bought on the website and cards you issue appear here.', 'gift')
            : '<div class="table"><table class="tbl"><thead><tr><th>Card</th><th class="num">Balance</th><th>Bought by</th><th>For</th><th>Email</th><th>Status</th><th>Created</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' . $pager);
    admin_render('Gift cards', $body, 'gift-cards');
}

// ---------- issue a card ----------

function admin_gift_cards_issue(): void
{
    $s = giftcards_settings();
    $opts = '';
    foreach ($s['designs'] as $d) $opts .= '<option value="' . h($d['id']) . '">' . h($d['name']) . (empty($d['active']) ? ' (hidden on website)' : '') . '</option>';
    $opts .= '<option value="custom">No picture: words only</option>';

    $form = form_open(admin_url('gift-cards')) . '<input type="hidden" name="action" value="issue">'
        . '<div class="form-grid">'
        . '<label class="field sm"><span>Amount</span>' . money_input('amount', '', '50.00', true) . '</label>'
        . '<label class="field"><span>Design</span><select name="design">' . $opts . '</select></label>'
        . '<label class="field"><span>Words on the card' . info_tip('Optional. Shown on the card when the design is "No picture: words only".') . '</span><input name="title" maxlength="40" placeholder="Congratulations, Sam!"></label>'
        . '</div><div class="form-grid">'
        . '<label class="field"><span>Recipient name</span><input name="recipient_name" maxlength="80"></label>'
        . '<label class="field"><span>Recipient email' . info_tip('Leave blank to not email it. You can read or print the code from the card\'s page.') . '</span><input type="email" name="recipient_email" maxlength="120"></label>'
        . '<label class="field"><span>From</span><input name="from_name" maxlength="80" placeholder="' . h(admin_store()['name']) . '"></label>'
        . '</div><div class="form-grid">'
        . '<label class="field wide"><span>Message</span><textarea name="message" rows="2" maxlength="300" placeholder="Thanks for celebrating with us!"></textarea></label>'
        . '<label class="field"><span>Email on' . info_tip('Leave blank to email it straight away, or pick the day it should arrive.') . '</span><input type="date" name="deliver_on" min="' . date('Y-m-d') . '"></label>'
        . '<label class="field"><span>Paid?</span><select name="paid_how"><option>Sold at the bar: card</option><option>Sold at the bar: cash</option><option>Complimentary</option><option>Prize / giveaway</option><option>Replacement card</option></select></label>'
        . '<label class="field wide"><span>Note for the team</span><input name="note" maxlength="300" placeholder="Why it was issued, who approved it…"></label>'
        . '</div><div class="bar">' . btn_submit('Issue gift card', 'primary', '', '', 'gift') . '</div></form>';

    $body = page_head('Gift cards', 'Make a gift card for any amount: sold at the bar, a comp for a guest, a raffle prize. It gets its own code and works like any other card.')
        . gift_cards_tabs('issue')
        . '<div class="split wideleft">' . card('Issue a gift card', $form)
        . card('How team cards work', '<ul class="plain small"><li>The card is kept in this panel: redeem it here when the guest spends it.</li>'
            . '<li>With an email, the guest gets their code and a link to see the card. Without one, read or print the code from the card\'s page.</li>'
            . (giftcards_mode() === 'square' ? '<li>These cards are not in Square, so the Square POS won\'t find them. Redeem them here.</li>' : '')
            . '</ul>') . '</div>';
    admin_render('Issue a gift card', $body, 'gift-cards');
}

// ---------- designs and settings ----------

function admin_gift_cards_settings(): void
{
    $s = giftcards_settings();
    $mode = giftcards_mode();

    $designs = '';
    foreach ($s['designs'] as $i => $d) {
        $k = 'd_' . $d['id'];
        $designs .= '<div class="gc-design-row">'
            . '<div class="gc-face sm" style="background-image:url(\'' . h(SITE_BASE . $d['image']) . '\')"></div>'
            . '<label class="field"><span>Name</span><input name="' . h($k) . '_name" maxlength="40" value="' . h($d['name']) . '"></label>'
            . '<label class="field"><span>Greeting' . info_tip('The line shown in the order summary, like "Happy Holidays". The picture itself is shown exactly as uploaded.') . '</span><input name="' . h($k) . '_title" maxlength="40" value="' . h($d['title']) . '"></label>'
            . '<label class="field sm"><span>Order</span><input name="' . h($k) . '_sort" inputmode="numeric" value="' . ($i + 1) . '"></label>'
            . '<label class="check"><input type="checkbox" name="' . h($k) . '_active" value="1"' . (!empty($d['active']) ? ' checked' : '') . '> On website</label>'
            . '<button class="btn sm ghost" type="submit" form="gc-remove-' . h($d['id']) . '" data-confirm="Remove the design &quot;' . h($d['name']) . '&quot;?">' . icon('trash', 15) . '<span class="sr">Remove</span></button>'
            . '</div>';
    }
    $removeForms = '';
    foreach ($s['designs'] as $d) { // the remove buttons sit in the settings form, so they point at these with form=""
        $removeForms .= '<form id="gc-remove-' . h($d['id']) . '" method="post" action="' . h(admin_url('gift-cards')) . '" hidden>' . csrf_field()
            . '<input type="hidden" name="action" value="design-remove"><input type="hidden" name="design" value="' . h($d['id']) . '"></form>';
    }

    $settings = form_open(admin_url('gift-cards')) . '<input type="hidden" name="action" value="settings">'
        . '<div class="btns gc-checks">'
        . '<label class="check"><input type="checkbox" name="enabled" value="1"' . (!empty($s['enabled']) ? ' checked' : '') . '> Sell gift cards on the website</label>'
        . '<label class="check"><input type="checkbox" name="useSquare" value="1"' . (!empty($s['useSquare']) ? ' checked' : '') . '> Take card payments through Square when it\'s set up' . info_tip('When the Square keys are in api/config.php, buyers pay by card and the card is created in Square. Untick to keep taking requests instead.') . '</label>'
        . '<label class="check"><input type="checkbox" name="allowCustomAmount" value="1"' . (!empty($s['allowCustomAmount']) ? ' checked' : '') . '> Let buyers choose their own amount</label>'
        . '<label class="check"><input type="checkbox" name="allowCustomDesign" value="1"' . (!empty($s['allowCustomDesign']) ? ' checked' : '') . '> Let buyers design their own card' . info_tip('Adds a "Design Your Own" choice: the buyer uploads a photo and writes the words on the card.') . '</label>'
        . '</div>'
        . '<div class="form-grid">'
        . '<label class="field"><span>Amounts to offer</span><input name="amounts" value="' . h(implode(', ', array_map(fn($c) => $c % 100 ? number_format($c / 100, 2) : (string) ($c / 100), $s['amounts']))) . '"><small class="muted">Dollars, separated by commas</small></label>'
        . '<label class="field sm"><span>Own amount from</span>' . money_input('min', (string) ($s['min'] / 100)) . '</label>'
        . '<label class="field sm"><span>up to</span>' . money_input('max', (string) ($s['max'] / 100)) . '</label>'
        . '</div>'
        . '<h3 class="gc-sub">Designs</h3>'
        . ($designs !== '' ? '<div class="gc-design-list">' . $designs . '</div>' : '<p class="muted small">No designs yet: add one below.</p>')
        . '<div class="bar">' . btn_submit('Save settings', 'primary') . '</div></form>' . $removeForms;

    $add = '<form method="post" enctype="multipart/form-data" action="' . h(admin_url('gift-cards')) . '">' . csrf_field() . '<input type="hidden" name="action" value="design-add">'
        . '<div class="form-grid">'
        . '<label class="field"><span>Name</span><input name="name" maxlength="40" required placeholder="Holiday 2026"></label>'
        . '<label class="field"><span>Greeting</span><input name="title" maxlength="40" placeholder="Happy Holidays"></label>'
        . '<label class="field wide"><span>Picture</span><input type="file" name="image" accept="image/webp,image/jpeg,image/png" required>'
        . '<small class="muted">WebP, JPG or PNG up to 4 MB, ideally 1600 × 1000 px (card shape). The picture is shown as it is, so put any wording on the design itself. A PDF from the designer needs exporting as a picture first.</small></label>'
        . '</div><div class="bar">' . btn_submit('Add design', 'primary', '', '', 'plus') . '</div></form>';

    $status = $mode === 'square'
        ? badge('Selling through Square', 'good') . ' <span class="muted small">Card payments are set up (' . h(config()['environment']) . ').</span>'
        : badge('Taking requests', 'warn') . ' <span class="muted small">' . (payments_enabled() ? 'Square is set up but switched off below.' : 'Square card payments aren\'t set up (api/config.php), so buyers send a request and you take payment.') . '</span>';

    $body = page_head('Gift cards', 'What the website\'s Gift Cards page offers.', btn_link('View the page', SITE_BASE . 'gift-cards.html', '', 'external', ' target="_blank" rel="noopener"'))
        . gift_cards_tabs('settings')
        . '<p class="gc-mode">' . $status . '</p>'
        . '<div class="split wideleft">' . card('Settings & designs', $settings) . card('Add a design', $add) . '</div>';
    admin_render('Gift card settings', $body, 'gift-cards');
}
