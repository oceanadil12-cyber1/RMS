<?php
/**
 * Performers: the live artist lineup on the website's Performers page.
 * Add or change performers here, then publish.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../performers.php';

function admin_page_performers(): void
{
    if (is_post()) admin_performers_post();
    $edit = get_str('edit', 10);
    if ($edit !== '') admin_performer_form($edit);
    admin_performers_list();
}

function admin_performer_find(array $data, string $id): array
{
    foreach ($data['performers'] as $i => $p) if (($p['id'] ?? '') === $id) return [$p, $i];
    return [null, null];
}

function admin_performer_thumb(array $p): string
{
    $photo = (string) ($p['photo'] ?? '');
    return preg_match(PERF_PHOTO_PATH, $photo)
        ? '<img class="evthumb" src="' . h(SITE_BASE . $photo) . '" alt="" width="40" height="40" loading="lazy">'
        : '<span class="evthumb none"></span>';
}

// ---------- saving ----------

function admin_performer_genres(string $raw): array
{
    $genres = [];
    foreach (preg_split('/[,\n]+/', $raw) as $t) {
        $t = trim(mb_substr(trim($t), 0, 30));
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $genres), true)) $genres[] = $t;
    }
    return $genres;
}

function admin_performer_from_post(?array $existing): array
{
    $errors = [];
    $name = post_str('name', 80);
    $tagline = post_str('tagline', 120);
    $bio = post_str('bio', 500);
    $genres = admin_performer_genres((string) ($_POST['genres'] ?? ''));

    if ($name === '') $errors[] = 'Give the performer a name.';
    if (count($genres) > 8) $errors[] = 'Use up to 8 genres.';

    $photo = '';
    $kept = post_str('photo_path', 120);
    if (preg_match(PERF_PHOTO_PATH, $kept) && is_file(dirname(__DIR__, 3) . '/' . $kept)) $photo = $kept;
    elseif ($existing) $photo = (string) ($existing['photo'] ?? '');
    [$uploaded, $uploadError] = performers_store_photo($_FILES['photo_file'] ?? [], $name !== '' ? $name : 'performer');
    if ($uploadError !== '') $errors[] = $uploadError;
    elseif ($uploaded !== '') $photo = $uploaded;

    return [[
        'id'      => $existing['id'] ?? bin2hex(random_bytes(4)),
        'name'    => $name,
        'tagline' => $tagline,
        'bio'     => $bio,
        'genres'  => $genres,
        'photo'   => $photo,
        'active'  => !empty($_POST['active']),
    ], $errors];
}

function admin_performers_post(): void
{
    require_csrf();
    $data = performers_store();
    $id = preg_match('/^[a-f0-9]{8}$/', (string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : '';
    [$performer, $pos] = admin_performer_find($data, $id);
    $back = admin_url('performers');

    switch ($_POST['action'] ?? '') {
        case 'save':
            if ($id !== '' && !$performer) { flash('That performer wasn\'t found.', 'err'); break; }
            [$rec, $errors] = admin_performer_from_post($performer);
            if ($errors) admin_performer_form($id !== '' ? $id : 'new', $rec, $errors);
            if ($performer) $data['performers'][$pos] = $rec; else $data['performers'][] = $rec;
            performers_save($data);
            admin_log('performer.save', $rec['name'] . (empty($rec['active']) ? ' (paused)' : ''));
            if (!empty($_POST['publish'])) {
                $err = performers_publish($data);
                if ($err === '') {
                    admin_log('performer.publish', plural(count($data['performers']), 'performer') . ' in the admin');
                    flash('Saved and published. The Performers page shows it now.');
                } else {
                    flash('Saved, but ' . lcfirst($err), 'err');
                }
            } else {
                flash('Saved. It isn\'t on the website until you publish.');
            }
            break;
        case 'delete':
            if ($performer) {
                array_splice($data['performers'], $pos, 1);
                performers_save($data);
                admin_log('performer.delete', (string) $performer['name']);
                flash('Deleted "' . $performer['name'] . '". Publish to take them off the website.');
            }
            break;
        case 'duplicate':
            if ($performer) {
                $copy = ['id' => bin2hex(random_bytes(4)), 'active' => false] + $performer;
                array_splice($data['performers'], $pos + 1, 0, [$copy]);
                performers_save($data);
                admin_log('performer.save', $performer['name'] . ' — duplicated');
                flash('Duplicated. Change the details, then turn it on.');
                $back = admin_url('performers', ['edit' => $copy['id']]);
            }
            break;
        case 'toggle':
            if ($performer) {
                $data['performers'][$pos]['active'] = empty($performer['active']);
                performers_save($data);
                admin_log('performer.save', $performer['name'] . (empty($performer['active']) ? ' — resumed' : ' — paused'));
                flash('"' . $performer['name'] . '" ' . (empty($performer['active']) ? 'will be shown' : 'is paused') . '. Publish to update the website.');
            }
            break;
        case 'move':
            $dir = (int) ($_POST['dir'] ?? 0);
            if ($performer && $dir !== 0) {
                $newPos = $pos + $dir;
                if ($newPos >= 0 && $newPos < count($data['performers'])) {
                    $item = array_splice($data['performers'], $pos, 1);
                    array_splice($data['performers'], $newPos, 0, $item);
                    performers_save($data);
                    flash('Moved "' . $performer['name'] . '". Publish to update the website.');
                }
            }
            break;
        case 'publish':
            $err = performers_publish($data);
            if ($err === '') {
                admin_log('performer.publish', plural(count($data['performers']), 'performer') . ' in the admin');
                flash('Published. The Performers page now shows this lineup.');
            } else {
                flash($err, 'err');
            }
            break;
        case 'reimport':
            $data = performers_import();
            admin_log('performer.import', plural(count($data['performers']), 'performer') . ' read from the page');
            flash('Loaded ' . plural(count($data['performers']), 'performer') . ' from the Performers page.');
            break;
        case 'restore':
            $name = post_str('file', 40);
            $path = performers_backup_dir() . '/' . $name;
            $html = preg_match('/^performers-\d{8}-\d{6}\.html$/', $name) && is_file($path) ? (string) file_get_contents($path) : '';
            if ($html === '' || performers_extract($html) === null) { flash('That earlier copy can\'t be restored.', 'err'); break; }
            performers_backup_page((string) @file_get_contents(performers_page_file()));
            if (!performers_write_page($html)) { flash('The Performers page can\'t be written.', 'err'); break; }
            $data = performers_import();
            admin_log('performer.restore', $name);
            flash('Restored the earlier copy of the Performers page (' . plural(count($data['performers']), 'performer') . ').');
            break;
    }
    redirect($back);
}

// ---------- the list ----------

function admin_performers_list(): void
{
    $data = performers_store();
    $performers = $data['performers'];
    $state = performers_state($data);
    $problems = performers_problems($performers);

    $publishForm = fn($label, $class) => form_open(admin_url('performers')) . '<input type="hidden" name="action" value="publish">' . btn_submit($label, $class, '', '', 'external') . '</form>';
    $reimport = form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="action" value="reimport">'
        . btn_submit('Load the page\'s performers here', 'sm', '', '', 'refresh', 'Replace what is in the admin with the performers on the page now? Changes here that are not published will be lost.') . '</form>';
    $at = (int) strtotime((string) ($data['published']['at'] ?? ''));
    switch ($state['state']) {
        case 'ready':
            $banner = '<div class="callout green">' . icon('check', 18) . '<div><b>The website shows this lineup.</b><p class="muted">' . ($at ? 'Last published ' . h(fmt_when($at)) . '.' : '') . '</p></div></div>';
            break;
        case 'changes':
            $banner = '<div class="callout gold">' . icon('alert', 18) . '<div><b>You have changes that aren\'t on the website yet.</b><p class="muted">Visitors still see the lineup from the last publish' . ($at ? ' (' . h(fmt_when($at)) . ')' : '') . '.</p></div>'
                . '<div class="callout-act">' . $publishForm('Publish to website', 'primary') . '</div></div>';
            break;
        case 'drift':
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>The Performers page was edited outside this admin.</b><p class="muted">Publishing will replace those edits with the lineup below. To keep them instead, load the page\'s performers here first.</p></div>'
                . '<div class="callout-act">' . $reimport . $publishForm('Publish anyway', 'sm') . '</div></div>';
            break;
        default:
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>Publishing isn\'t possible yet.</b><p class="muted">' . h($state['detail']) . '</p></div></div>';
    }
    if ($problems) {
        $banner .= '<div class="callout red">' . icon('alert', 18) . '<div><b>' . h(plural(count($problems), 'performer')) . ' can\'t be published yet</b><ul class="linklist">'
            . implode('', array_map(fn($t, $p) => '<li>' . h($t) . ' <span class="muted">' . h($p) . '</span></li>', array_keys($problems), $problems)) . '</ul></div></div>';
    }

    $rows = '';
    $total = count($performers);
    foreach ($performers as $i => $p) {
        $off = empty($p['active']);
        $hasPhoto = preg_match(PERF_PHOTO_PATH, (string) ($p['photo'] ?? ''));
        $badge = $off ? badge('Paused', 'muted') : ($hasPhoto ? badge('On website', 'good') : badge('Coming soon', 'gold'));
        $moveUp = $i > 0 ? form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="id" value="' . h($p['id']) . '"><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="-1">'
            . btn_submit("\xE2\x86\x91", 'sm ghost') . '</form>' : '';
        $moveDown = $i < $total - 1 ? form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="id" value="' . h($p['id']) . '"><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="1">'
            . btn_submit("\xE2\x86\x93", 'sm ghost') . '</form>' : '';
        $rows .= '<tr class="' . ($off ? 'is-off' : '') . '"><td>' . admin_performer_thumb($p) . '</td>'
            . '<td><a class="row-link" href="' . h(admin_url('performers', ['edit' => $p['id']])) . '"><b>' . h($p['name']) . '</b></a>'
            . (($p['tagline'] ?? '') !== '' ? '<div class="cell-sub">' . h($p['tagline']) . '</div>' : '') . '</td>'
            . '<td>' . implode(' ', array_map(fn($g) => '<span class="chip">' . h($g) . '</span>', (array) ($p['genres'] ?? []))) . '</td>'
            . '<td>' . $badge . '</td>'
            . '<td class="act nowrap">' . $moveUp . $moveDown . '</td>'
            . '<td class="act">' . form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="id" value="' . h($p['id']) . '"><input type="hidden" name="action" value="toggle">'
            . btn_submit($off ? 'Resume' : 'Pause', 'sm ghost') . '</form></td></tr>';
    }
    $content = $rows === ''
        ? empty_state('No performers yet', 'Add your first performer.', 'music')
        : '<div class="table"><table class="tbl"><thead><tr><th></th><th>Performer</th><th>Genres</th><th>Status</th><th>Order</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

    $copies = '';
    foreach (array_slice(performers_backups(), 0, 10) as $b) {
        $copies .= '<li><span>' . h(fmt_when($b['ts'])) . '<small class="muted"> &middot; ' . h(number_format($b['bytes'] / 1000, 1)) . ' KB</small></span>'
            . form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="' . h($b['file']) . '">'
            . btn_submit('Restore', 'sm', '', '', 'refresh', 'Put the Performers page back to how it was then? Today\'s page is saved first, so you can undo this.') . '</form></li>';
    }

    $body = page_head('Performers', 'The live artist lineup on the Performers page. Add or change performers here, then publish them to the website.',
            btn_link('View performers page', SITE_BASE . 'performers.html', 'sm', 'external', ' target="_blank" rel="noopener"')
            . btn_link('Add performer', admin_url('performers', ['edit' => 'new']), 'primary', 'plus'))
        . $banner
        . $content
        . ($copies !== '' ? '<details class="card addcard"><summary><span>' . icon('refresh', 16) . ' Earlier copies of the Performers page</span><small>Saved automatically before each publish</small></summary><div class="card-body"><ul class="linklist versions">' . $copies . '</ul></div></details>' : '');
    admin_render('Performers', $body, 'performers');
}

// ---------- the form ----------

function admin_performer_genre_suggestions(array $performers): array
{
    $count = [];
    foreach ($performers as $p) foreach ((array) ($p['genres'] ?? []) as $g) $count[$g] = ($count[$g] ?? 0) + 1;
    arsort($count);
    return array_slice(array_keys($count), 0, 14);
}

function admin_performer_form(string $edit, ?array $posted = null, array $errors = []): void
{
    $data = performers_store();
    $isNew = $edit === 'new';
    [$saved] = $isNew ? [null] : admin_performer_find($data, $edit);
    if (!$isNew && !$saved) {
        admin_render('Performer', '<p class="back"><a href="' . h(admin_url('performers')) . '">' . icon('left', 15) . ' Performers</a></p>' . empty_state('Performer not found', 'They may have been deleted.', 'music'), 'performers');
    }
    $p = $posted ?? $saved ?? [
        'id' => '', 'name' => '', 'tagline' => '', 'bio' => '', 'genres' => [], 'photo' => '', 'active' => true,
    ];
    $photo = (string) ($p['photo'] ?? '');
    $hasPhoto = preg_match(PERF_PHOTO_PATH, $photo) && is_file(dirname(__DIR__, 3) . '/' . $photo);

    $chips = '';
    foreach (admin_performer_genre_suggestions($data['performers']) as $g) {
        $chips .= '<button type="button" class="chip tagchip" data-tag="' . h($g) . '">' . h($g) . '</button>';
    }

    $errorHtml = '';
    foreach ($errors as $msg) $errorHtml .= '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($msg) . '</span></div>';

    $photoField = '<div class="poster-field" data-photo>'
        . '<div class="poster-stage">'
        .   '<img class="poster-current" data-current-photo src="' . ($hasPhoto ? h(SITE_BASE . $photo) : '') . '" alt="Current photo"' . ($hasPhoto ? '' : ' hidden') . '>'
        .   '<div class="poster-empty" data-photo-empty' . ($hasPhoto ? ' hidden' : '') . '>' . icon('plus', 22) . '<span>Drop a photo here</span></div>'
        . '</div>'
        . '<div class="poster-tools"><label class="btn sm" for="photo_file">' . icon('plus', 15) . '<span data-choose>' . ($hasPhoto ? 'Replace photo' : 'Choose a photo') . '</span></label>'
        . '<input class="sr" type="file" id="photo_file" name="photo_file" accept="image/webp,image/jpeg,image/png"></div>'
        . '<p class="poster-msg" data-msg>' . ($hasPhoto ? 'This is the current photo.' : 'No photo yet. Without a photo, the performer appears in the "Coming Soon" section.') . '</p></div>';

    $preview = '<div class="pv" data-preview>'
        . '<div class="pv-poster">'
        .   '<img data-pv-photo src="' . ($hasPhoto ? h(SITE_BASE . $photo) : '') . '" alt=""' . ($hasPhoto ? '' : ' hidden') . '>'
        .   '<span class="pv-empty" data-pv-photo-empty' . ($hasPhoto ? ' hidden' : '') . '>No photo</span>'
        . '</div>'
        . '<div class="pv-body">'
        .   '<h4 data-pv-name>' . h($p['name'] !== '' ? $p['name'] : 'Performer name') . '</h4>'
        .   '<p class="pv-host" data-pv-tagline>' . h(($p['tagline'] ?? '') !== '' ? $p['tagline'] : 'A short tagline') . '</p>'
        .   '<p class="pv-desc" data-pv-bio>' . h(($p['bio'] ?? '') !== '' ? $p['bio'] : 'Bio text appears here.') . '</p>'
        .   '<ul class="pv-tags" data-pv-genres>' . implode('', array_map(fn($g) => '<li>' . h($g) . '</li>', (array) $p['genres'])) . '</ul>'
        . '</div></div>';

    $form = '<form class="eventform" data-performer-form method="post" enctype="multipart/form-data" action="' . h(admin_url('performers')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . h($isNew ? '' : (string) $p['id']) . '">'
        . '<input type="hidden" name="photo_path" value="' . h($hasPhoto ? $photo : '') . '">'
        . '<div class="split wideleft"><div class="stack">'
        .   card('Details', '<label class="field wide"><span>Performer name</span><input name="name" value="' . h($p['name']) . '" maxlength="80" required placeholder="e.g. Antonio Edwards"></label>'
                . '<label class="field wide"><span>Tagline</span><input name="tagline" value="' . h($p['tagline'] ?? '') . '" maxlength="120" placeholder="e.g. Performing at RMS since 2015"></label>'
                . '<label class="field wide"><span>Bio</span><textarea name="bio" rows="4" maxlength="500" placeholder="A short paragraph about their style and background.">' . h($p['bio'] ?? '') . '</textarea></label>'
                . '<div class="field wide"><span>Genres</span><input name="genres" data-tags value="' . h(implode(', ', (array) $p['genres'])) . '" placeholder="Pop, R&B, Broadway" aria-describedby="genreshelp">'
                . '<small class="muted" id="genreshelp">Up to 8, separated by commas. They show as labels under the bio.</small>'
                . ($chips !== '' ? '<div class="tagpick" aria-label="Genres used before">' . $chips . '</div>' : '') . '</div>')
        .   card('On the website', '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($p['active']) ? ' checked' : '') . '> Show this performer on the website</label>'
                . '<p class="muted small">Untick to keep them saved without showing them.</p>')
        . '</div><div class="stack">'
        .   card('Photo', $photoField
                . '<div class="legend-text small"><dl class="specs">'
                . '<div><dt>File</dt><dd>WebP, JPG or PNG, up to 2 MB</dd></div>'
                . '<div><dt>Size</dt><dd>At least 400 px wide. Any aspect ratio works.</dd></div>'
                . '<div><dt>No photo?</dt><dd>They appear in "Coming Soon" with their initials instead.</dd></div>'
                . '</dl></div>')
        .   card('Preview', $preview . '<p class="muted small">This is roughly how the strip looks on the Performers page.</p>')
        . '</div></div>'
        . '<div class="savebar"><a class="btn ghost" href="' . h(admin_url('performers')) . '">Cancel</a><span class="grow"></span>'
        . '<button class="btn" type="submit">' . icon('check', 16) . '<span>Save draft</span></button>'
        . '<button class="btn primary" type="submit" name="publish" value="1">' . icon('external', 16) . '<span>Save &amp; publish</span></button></div></form>';

    $extra = '';
    if (!$isNew) {
        $extra = card('More', '<div class="btns">'
            . form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="id" value="' . h((string) $p['id']) . '"><input type="hidden" name="action" value="duplicate">' . btn_submit('Duplicate', 'sm', '', '', 'plus') . '</form>'
            . form_open(admin_url('performers'), 'inline') . '<input type="hidden" name="id" value="' . h((string) $p['id']) . '"><input type="hidden" name="action" value="delete">'
            . btn_submit('Delete performer', 'sm danger', '', '', 'trash', 'Delete "' . $p['name'] . '"? Publish afterwards to take them off the website.') . '</form></div>');
    }

    $body = '<p class="back"><a href="' . h(admin_url('performers')) . '">' . icon('left', 15) . ' Performers</a></p>'
        . page_head($isNew ? 'Add a performer' : 'Edit performer', $isNew ? 'Fill in the details, add a photo, then save. Publish to put them on the website.' : h($p['name']))
        . $errorHtml . $form . $extra;
    admin_render($isNew ? 'Add a performer' : 'Edit performer', $body, 'performers', ['performers.js']);
}
