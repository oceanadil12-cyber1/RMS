<?php
/**
 * Dancers: the dancer roster on the website's Dancers page.
 * Add or change dancers here, then publish.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../dancers.php';

function admin_page_dancers(): void
{
    if (is_post()) admin_dancers_post();
    $edit = get_str('edit', 10);
    if ($edit !== '') admin_dancer_form($edit);
    admin_dancers_list();
}

function admin_dancer_find(array $data, string $id): array
{
    foreach ($data['dancers'] as $i => $d) if (($d['id'] ?? '') === $id) return [$d, $i];
    return [null, null];
}

function admin_dancer_thumb(array $d): string
{
    return dancer_has_photo($d)
        ? '<img class="evthumb" src="' . h(SITE_BASE . $d['photo']) . '" alt="" width="40" height="40" loading="lazy" style="height:40px">'
        : '<span class="evthumb none" style="height:40px"></span>';
}

// ---------- saving ----------

function admin_dancer_from_post(?array $existing): array
{
    $errors = [];
    $name = post_str('name', 60);
    $title = post_str('title', 60);
    if ($name === '') $errors[] = 'Give the dancer a name.';

    $photo = '';
    $kept = post_str('photo_path', 120);
    if (preg_match(DANCER_PHOTO_PATH, $kept) && is_file(dirname(__DIR__, 3) . '/' . $kept)) $photo = $kept;
    elseif ($existing) $photo = (string) ($existing['photo'] ?? '');
    [$uploaded, $uploadError] = dancers_store_photo($_FILES['photo_file'] ?? [], $name !== '' ? $name : 'dancer');
    if ($uploadError !== '') $errors[] = $uploadError;
    elseif ($uploaded !== '') $photo = $uploaded;

    return [[
        'id'     => $existing['id'] ?? bin2hex(random_bytes(4)),
        'name'   => $name,
        'title'  => $title,
        'photo'  => $photo,
        'active' => !empty($_POST['active']),
    ], $errors];
}

function admin_dancers_publish_flash(array $data, string $done): void
{
    $err = dancers_publish($data);
    if ($err === '') {
        admin_log('dancer.publish', plural(dancers_generate($data['dancers'])['count'], 'dancer') . ' on the website');
        flash($done);
    } else {
        flash($err, 'err');
    }
}

function admin_dancers_post(): void
{
    require_csrf();
    $data = dancers_store();
    $id = preg_match('/^[a-f0-9]{8}$/', (string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : '';
    [$dancer, $pos] = admin_dancer_find($data, $id);
    $back = admin_url('dancers');

    switch ($_POST['action'] ?? '') {
        case 'save':
            if ($id !== '' && !$dancer) { flash('That dancer wasn\'t found.', 'err'); break; }
            [$rec, $errors] = admin_dancer_from_post($dancer);
            if ($errors) admin_dancer_form($id !== '' ? $id : 'new', $rec, $errors);
            if ($dancer) $data['dancers'][$pos] = $rec; else $data['dancers'][] = $rec;
            dancers_save($data);
            admin_log('dancer.save', $rec['name'] . (empty($rec['active']) ? ' (paused)' : ''));
            $note = dancer_has_photo($rec) || empty($rec['active']) ? '' : ' Add a photo to show them on the website.';
            if (!empty($_POST['publish'])) {
                $err = dancers_publish($data);
                if ($err === '') {
                    admin_log('dancer.publish', plural(dancers_generate($data['dancers'])['count'], 'dancer') . ' on the website');
                    flash('Saved and published. The Dancers page shows it now.' . $note);
                } else {
                    flash('Saved, but ' . lcfirst($err), 'err');
                }
            } else {
                flash('Saved. It isn\'t on the website until you publish.' . $note);
            }
            break;
        case 'delete':
            if ($dancer) {
                array_splice($data['dancers'], $pos, 1);
                dancers_save($data);
                admin_log('dancer.delete', (string) $dancer['name']);
                flash('Deleted "' . $dancer['name'] . '". Publish to take them off the website.');
            }
            break;
        case 'duplicate':
            if ($dancer) {
                $copy = ['id' => bin2hex(random_bytes(4)), 'active' => false] + $dancer;
                array_splice($data['dancers'], $pos + 1, 0, [$copy]);
                dancers_save($data);
                admin_log('dancer.save', $dancer['name'] . ' — duplicated');
                flash('Duplicated. Change the details, then turn it on.');
                $back = admin_url('dancers', ['edit' => $copy['id']]);
            }
            break;
        case 'toggle':
            if ($dancer) {
                $data['dancers'][$pos]['active'] = empty($dancer['active']);
                dancers_save($data);
                admin_log('dancer.save', $dancer['name'] . (empty($dancer['active']) ? ' — resumed' : ' — paused'));
                flash('"' . $dancer['name'] . '" ' . (empty($dancer['active']) ? 'will be shown' : 'is paused') . '. Publish to update the website.');
            }
            break;
        case 'move':
            $dir = (int) ($_POST['dir'] ?? 0);
            if ($dancer && $dir !== 0) {
                $newPos = $pos + $dir;
                if ($newPos >= 0 && $newPos < count($data['dancers'])) {
                    $item = array_splice($data['dancers'], $pos, 1);
                    array_splice($data['dancers'], $newPos, 0, $item);
                    dancers_save($data);
                    flash('Moved "' . $dancer['name'] . '". Publish to update the website.');
                }
            }
            break;
        case 'publish':
            admin_dancers_publish_flash($data, 'Published. The Dancers page now shows this roster.');
            break;
        case 'reimport':
            $data = dancers_import();
            admin_log('dancer.import', plural(count($data['dancers']), 'dancer') . ' read from the page');
            flash('Loaded ' . plural(count($data['dancers']), 'dancer') . ' from the Dancers page.');
            break;
        case 'restore':
            $name = post_str('file', 40);
            $path = dancers_backup_dir() . '/' . $name;
            $html = preg_match('/^dancers-\d{8}-\d{6}\.html$/', $name) && is_file($path) ? (string) file_get_contents($path) : '';
            if ($html === '' || dancers_extract($html) === null) { flash('That earlier copy can\'t be restored.', 'err'); break; }
            dancers_backup_page((string) @file_get_contents(dancers_page_file()));
            if (!dancers_write_page($html)) { flash('The Dancers page can\'t be written.', 'err'); break; }
            $data = dancers_import();
            admin_log('dancer.restore', $name);
            flash('Restored the earlier copy of the Dancers page (' . plural(count($data['dancers']), 'dancer') . ').');
            break;
    }
    redirect($back);
}

// ---------- the list ----------

function admin_dancers_list(): void
{
    $data = dancers_store();
    $dancers = $data['dancers'];
    $state = dancers_state($data);

    $publishForm = fn($label, $class) => form_open(admin_url('dancers')) . '<input type="hidden" name="action" value="publish">' . btn_submit($label, $class, '', '', 'external') . '</form>';
    $reimport = form_open(admin_url('dancers'), 'inline') . '<input type="hidden" name="action" value="reimport">'
        . btn_submit('Load the page\'s dancers here', 'sm', '', '', 'refresh', 'Replace what is in the admin with the dancers on the page now? Changes here that are not published will be lost.') . '</form>';
    $at = (int) strtotime((string) ($data['published']['at'] ?? ''));
    switch ($state['state']) {
        case 'ready':
            $banner = '<div class="callout green">' . icon('check', 18) . '<div><b>The website shows this roster.</b><p class="muted">' . ($at ? 'Last published ' . h(fmt_when($at)) . '.' : '') . '</p></div></div>';
            break;
        case 'changes':
            $banner = '<div class="callout gold">' . icon('alert', 18) . '<div><b>You have changes that aren\'t on the website yet.</b><p class="muted">Visitors still see the roster from the last publish' . ($at ? ' (' . h(fmt_when($at)) . ')' : '') . '.</p></div>'
                . '<div class="callout-act">' . $publishForm('Publish to website', 'primary') . '</div></div>';
            break;
        case 'drift':
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>The Dancers page was edited outside this admin.</b><p class="muted">Publishing will replace those edits with the roster below. To keep them instead, load the page\'s dancers here first.</p></div>'
                . '<div class="callout-act">' . $reimport . $publishForm('Publish anyway', 'sm') . '</div></div>';
            break;
        default:
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>Publishing isn\'t possible yet.</b><p class="muted">' . h($state['detail']) . '</p></div></div>';
    }

    $rows = '';
    $total = count($dancers);
    foreach ($dancers as $i => $d) {
        $off = empty($d['active']);
        $badge = $off ? badge('Paused', 'muted') : (dancer_has_photo($d) ? badge('On website', 'good') : badge('Needs a photo', 'gold'));
        $idField = '<input type="hidden" name="id" value="' . h($d['id']) . '">';
        $moveUp = $i > 0 ? form_open(admin_url('dancers'), 'inline') . $idField . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="-1">'
            . btn_submit("\xE2\x86\x91", 'sm ghost') . '</form>' : '';
        $moveDown = $i < $total - 1 ? form_open(admin_url('dancers'), 'inline') . $idField . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="1">'
            . btn_submit("\xE2\x86\x93", 'sm ghost') . '</form>' : '';
        $rows .= '<tr class="' . ($off ? 'is-off' : '') . '"><td>' . admin_dancer_thumb($d) . '</td>'
            . '<td><a class="row-link" href="' . h(admin_url('dancers', ['edit' => $d['id']])) . '"><b>' . h($d['name']) . '</b></a></td>'
            . '<td>' . h((string) ($d['title'] ?? '')) . '</td>'
            . '<td>' . $badge . '</td>'
            . '<td class="act nowrap">' . $moveUp . $moveDown . '</td>'
            . '<td class="act">' . form_open(admin_url('dancers'), 'inline') . $idField . '<input type="hidden" name="action" value="toggle">'
            . btn_submit($off ? 'Resume' : 'Pause', 'sm ghost') . '</form></td></tr>';
    }
    $content = $rows === ''
        ? empty_state('No dancers yet', 'Add your first dancer.', 'star')
        : '<div class="table"><table class="tbl"><thead><tr><th></th><th>Dancer</th><th>Caption</th><th>Status</th><th>Order</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

    $copies = '';
    foreach (array_slice(dancers_backups(), 0, 10) as $b) {
        $copies .= '<li><span>' . h(fmt_when($b['ts'])) . '<small class="muted"> &middot; ' . h(number_format($b['bytes'] / 1000, 1)) . ' KB</small></span>'
            . form_open(admin_url('dancers'), 'inline') . '<input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="' . h($b['file']) . '">'
            . btn_submit('Restore', 'sm', '', '', 'refresh', 'Put the Dancers page back to how it was then? Today\'s page is saved first, so you can undo this.') . '</form></li>';
    }

    $body = page_head('Dancers', 'The dancer roster on the Dancers page. Add or change dancers here, then publish them to the website.',
            btn_link('View dancers page', SITE_BASE . 'dancers.html', 'sm', 'external', ' target="_blank" rel="noopener"')
            . btn_link('Add dancer', admin_url('dancers', ['edit' => 'new']), 'primary', 'plus'))
        . $banner
        . $content
        . ($copies !== '' ? '<details class="card addcard"><summary><span>' . icon('refresh', 16) . ' Earlier copies of the Dancers page</span><small>Saved automatically before each publish</small></summary><div class="card-body"><ul class="linklist versions">' . $copies . '</ul></div></details>' : '');
    admin_render('Dancers', $body, 'dancers');
}

// ---------- the form ----------

function admin_dancer_form(string $edit, ?array $posted = null, array $errors = []): void
{
    $data = dancers_store();
    $isNew = $edit === 'new';
    [$saved] = $isNew ? [null] : admin_dancer_find($data, $edit);
    if (!$isNew && !$saved) {
        admin_render('Dancer', '<p class="back"><a href="' . h(admin_url('dancers')) . '">' . icon('left', 15) . ' Dancers</a></p>' . empty_state('Dancer not found', 'They may have been deleted.', 'star'), 'dancers');
    }
    $d = $posted ?? $saved ?? ['id' => '', 'name' => '', 'title' => DANCER_DEFAULT_TITLE, 'photo' => '', 'active' => true];
    $photo = (string) ($d['photo'] ?? '');
    $hasPhoto = dancer_has_photo($d);

    $errorHtml = '';
    foreach ($errors as $msg) $errorHtml .= '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($msg) . '</span></div>';

    $photoField = '<div class="poster-field" data-photo>'
        . '<div class="poster-stage">'
        .   '<img class="poster-current" data-current-photo src="' . ($hasPhoto ? h(SITE_BASE . $photo) : '') . '" alt="Current photo"' . ($hasPhoto ? '' : ' hidden') . '>'
        .   '<div class="poster-empty" data-photo-empty' . ($hasPhoto ? ' hidden' : '') . '>' . icon('plus', 22) . '<span>Drop a photo here</span></div>'
        . '</div>'
        . '<div class="poster-tools"><label class="btn sm" for="photo_file">' . icon('plus', 15) . '<span data-choose>' . ($hasPhoto ? 'Replace photo' : 'Choose a photo') . '</span></label>'
        . '<input class="sr" type="file" id="photo_file" name="photo_file" accept="image/webp,image/jpeg,image/png"></div>'
        . '<p class="poster-msg" data-msg>' . ($hasPhoto ? 'This is the current photo.' : 'No photo yet. The dancer only appears on the website once they have a photo.') . '</p></div>';

    $preview = '<div class="pv" data-preview>'
        . '<div class="pv-poster">'
        .   '<img data-pv-photo src="' . ($hasPhoto ? h(SITE_BASE . $photo) : '') . '" alt=""' . ($hasPhoto ? '' : ' hidden') . ' style="object-position:50% 50%">'
        .   '<span class="pv-empty" data-pv-photo-empty' . ($hasPhoto ? ' hidden' : '') . '>No photo</span>'
        . '</div>'
        . '<div class="pv-body" style="text-align:center">'
        .   '<h4 data-pv-name>' . h($d['name'] !== '' ? $d['name'] : 'Dancer name') . '</h4>'
        .   '<p class="pv-host" data-pv-title>' . h((string) ($d['title'] ?? '')) . '</p>'
        . '</div></div>';

    $form = '<form class="eventform" data-dancer-form method="post" enctype="multipart/form-data" action="' . h(admin_url('dancers')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . h($isNew ? '' : (string) $d['id']) . '">'
        . '<input type="hidden" name="photo_path" value="' . h($hasPhoto ? $photo : '') . '">'
        . '<div class="split wideleft"><div class="stack">'
        .   card('Details', '<label class="field wide"><span>Dancer name</span><input name="name" value="' . h($d['name']) . '" maxlength="60" required placeholder="e.g. Anthony"></label>'
                . '<label class="field wide"><span>Caption</span><input name="title" value="' . h((string) ($d['title'] ?? '')) . '" maxlength="60" placeholder="e.g. ' . h(DANCER_DEFAULT_TITLE) . '">'
                . '<small class="muted">Shown under the name on the dancer\'s card.</small></label>')
        .   card('On the website', '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($d['active']) ? ' checked' : '') . '> Show this dancer on the website</label>'
                . '<p class="muted small">Untick to keep them saved without showing them.</p>')
        . '</div><div class="stack">'
        .   card('Photo', $photoField
                . '<div class="legend-text small"><dl class="specs">'
                . '<div><dt>File</dt><dd>WebP, JPG or PNG, up to 2 MB</dd></div>'
                . '<div><dt>Size</dt><dd>At least 400 px wide. Square photos fit best: the card crops to a square.</dd></div>'
                . '<div><dt>No photo?</dt><dd>The dancer stays saved here but isn\'t shown on the website.</dd></div>'
                . '</dl></div>')
        .   card('Preview', $preview . '<p class="muted small">This is roughly how the card looks on the Dancers page.</p>')
        . '</div></div>'
        . '<div class="savebar"><a class="btn ghost" href="' . h(admin_url('dancers')) . '">Cancel</a><span class="grow"></span>'
        . '<button class="btn" type="submit">' . icon('check', 16) . '<span>Save draft</span></button>'
        . '<button class="btn primary" type="submit" name="publish" value="1">' . icon('external', 16) . '<span>Save &amp; publish</span></button></div></form>';

    $extra = '';
    if (!$isNew) {
        $idField = '<input type="hidden" name="id" value="' . h((string) $d['id']) . '">';
        $extra = card('More', '<div class="btns">'
            . form_open(admin_url('dancers'), 'inline') . $idField . '<input type="hidden" name="action" value="duplicate">' . btn_submit('Duplicate', 'sm', '', '', 'plus') . '</form>'
            . form_open(admin_url('dancers'), 'inline') . $idField . '<input type="hidden" name="action" value="delete">'
            . btn_submit('Delete dancer', 'sm danger', '', '', 'trash', 'Delete "' . $d['name'] . '"? Publish afterwards to take them off the website.') . '</form></div>');
    }

    $body = '<p class="back"><a href="' . h(admin_url('dancers')) . '">' . icon('left', 15) . ' Dancers</a></p>'
        . page_head($isNew ? 'Add a dancer' : 'Edit dancer', $isNew ? 'Fill in the details, add a photo, then save. Publish to put them on the website.' : h($d['name']))
        . $errorHtml . $form . $extra;
    admin_render($isNew ? 'Add a dancer' : 'Edit dancer', $body, 'dancers', ['dancers.js']);
}
