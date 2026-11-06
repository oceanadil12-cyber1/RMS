<?php
/**
 * Events: the weekly lineup and one-night specials on the website's Events page
 * and the home page slider. Add or change events here, then publish. The data and
 * the publishing rules are in admin/inc/events.php.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../events.php';

const EVENT_POSTER_PATH = '#^images/events/[A-Za-z0-9][A-Za-z0-9._-]*\.(webp|jpe?g|png)$#';

function admin_page_events(): void
{
    if (is_post()) admin_events_post();
    $edit = get_str('edit', 10);
    if ($edit !== '') admin_event_form($edit); // ends the request
    admin_events_list();
}

/** One event by id, with its position in the list: [event, position] or [null, null]. */
function admin_event_find(array $data, string $id): array
{
    foreach ($data['events'] as $i => $e) if (($e['id'] ?? '') === $id) return [$e, $i];
    return [null, null];
}

/** "Every Friday" / "Mon, Wed" / "Fri, Oct 31 (one night)" for lists and the activity log. */
function admin_event_when(array $e): string
{
    if (($e['repeat'] ?? 'weekly') === 'once') return events_valid_date((string) $e['date']) ? date('D, M j', strtotime($e['date'] . ' 12:00:00')) . ' (one night)' : 'no date';
    $days = array_values(array_intersect(array_keys(EVENT_DAYS), (array) ($e['days'] ?? [])));
    if (count($days) === 7) return 'Every day';
    return count($days) === 1 ? 'Every ' . EVENT_DAYS[$days[0]] : implode(', ', array_map(fn($d) => substr(EVENT_DAYS[$d], 0, 3), $days));
}

/** The poster as a small picture for lists (a grey box if the path isn't a poster). */
function admin_event_thumb(array $e): string
{
    $p = (string) ($e['poster'] ?? '');
    return preg_match(EVENT_POSTER_PATH, $p)
        ? '<img class="evthumb" src="' . h(SITE_BASE . $p) . '" alt="" width="40" height="50" loading="lazy">'
        : '<span class="evthumb none"></span>';
}

// ---------- saving ----------

/** The tags typed as "Live Music, No Cover" → a clean list (no blanks or repeats). */
function admin_event_tags(string $raw): array
{
    $tags = [];
    foreach (preg_split('/[,\n]+/', $raw) as $t) {
        $t = trim(mb_substr(trim($t), 0, 30));
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tags), true)) $tags[] = $t;
    }
    return $tags;
}

/**
 * The event described by the submitted form, checked. Returns [record, errors].
 * A picture uploaded with the form is stored straight away, so it isn't lost if
 * something else needs fixing (it comes back in poster_path).
 */
function admin_event_from_post(?array $existing): array
{
    $errors = [];
    $title = post_str('title', 80);
    $repeat = ($_POST['repeat'] ?? '') === 'once' ? 'once' : 'weekly';
    $days = array_values(array_intersect(array_keys(EVENT_DAYS), array_filter(is_array($_POST['days'] ?? null) ? $_POST['days'] : [], 'is_string')));
    $date = post_str('date', 10);
    $time = post_str('time', 5);
    $host = post_str('host', 80);
    $style = isset(EVENT_HOST_STYLES[$_POST['hostStyle'] ?? '']) ? (string) $_POST['hostStyle'] : 'hosted';
    $tags = admin_event_tags((string) ($_POST['tags'] ?? ''));

    if ($title === '') $errors[] = 'Give the event a title.';
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $errors[] = 'Choose a start time.';
    if ($repeat === 'weekly' && !$days) $errors[] = 'Choose at least one day of the week.';
    if ($repeat === 'once') {
        if (!events_valid_date($date)) $errors[] = 'Choose the date of the event.';
        elseif ($date < events_tonight() && (!$existing || ($existing['date'] ?? '') !== $date)) $errors[] = 'That date has already passed.';
    }
    if (count($tags) > 6) $errors[] = 'Use up to 6 tags.';

    // The poster: one just uploaded, else the one already chosen, else the event's current one.
    $poster = '';
    $kept = post_str('poster_path', 120);
    if (preg_match(EVENT_POSTER_PATH, $kept) && is_file(dirname(__DIR__, 3) . '/' . $kept)) $poster = $kept;
    elseif ($existing) $poster = (string) ($existing['poster'] ?? '');
    [$uploaded, $uploadError] = events_store_poster($_FILES['poster_file'] ?? [], $title !== '' ? $title : 'event');
    if ($uploadError !== '') $errors[] = $uploadError;
    elseif ($uploaded !== '') $poster = $uploaded;
    if ($poster === '') $errors[] = 'Add a poster picture.';

    return [[
        'id'        => $existing['id'] ?? bin2hex(random_bytes(4)),
        'title'     => $title, 'time' => $time, 'repeat' => $repeat,
        'days'      => $repeat === 'weekly' ? $days : [], 'date' => $repeat === 'once' ? $date : '',
        'host'      => $host, 'hostStyle' => $host === '' ? 'plain' : $style,
        'desc'      => post_str('desc', 220), 'tags' => $tags,
        'poster'    => $poster,
        'alt'       => ($existing && ($existing['title'] ?? '') === $title) ? (string) ($existing['alt'] ?? '') : '',
        'active'    => !empty($_POST['active']),
    ], $errors];
}

function admin_events_post(): void
{
    require_csrf();
    $data = events_store();
    $id = preg_match('/^[a-f0-9]{8}$/', (string) ($_POST['id'] ?? '')) ? (string) $_POST['id'] : '';
    [$event, $pos] = admin_event_find($data, $id);
    $back = admin_url('events');

    switch ($_POST['action'] ?? '') {
        case 'save':
            if ($id !== '' && !$event) { flash('That event wasn\'t found.', 'err'); break; }
            [$rec, $errors] = admin_event_from_post($event);
            if ($errors) admin_event_form($id !== '' ? $id : 'new', $rec, $errors); // shows the form again, ends the request
            if ($event) $data['events'][$pos] = $rec; else $data['events'][] = $rec;
            events_save($data);
            admin_log('event.save', $rec['title'] . ' — ' . admin_event_when($rec) . ' ' . fmt_time12($rec['time']) . (empty($rec['active']) ? ' (paused)' : ''));
            if (!empty($_POST['publish'])) {
                $err = events_publish($data);
                if ($err === '') {
                    admin_log('event.publish', plural(count($data['events']), 'event') . ' in the admin');
                    flash('Saved and published. The Events page and the home page slider show it now.');
                } else {
                    flash('Saved, but ' . lcfirst($err), 'err');
                }
            } else {
                flash('Saved. It isn\'t on the website until you publish.');
            }
            break;
        case 'delete':
            if ($event) {
                array_splice($data['events'], $pos, 1);
                events_save($data);
                admin_log('event.delete', (string) $event['title']);
                flash('Deleted "' . $event['title'] . '". Publish to take it off the website.');
            }
            break;
        case 'duplicate':
            if ($event) {
                $copy = ['id' => bin2hex(random_bytes(4)), 'active' => false] + $event;
                array_splice($data['events'], $pos + 1, 0, [$copy]);
                events_save($data);
                admin_log('event.save', $event['title'] . ' — duplicated');
                flash('Duplicated. Change the day or time, then turn it on.');
                $back = admin_url('events', ['edit' => $copy['id']]);
            }
            break;
        case 'toggle':
            if ($event) {
                $data['events'][$pos]['active'] = empty($event['active']);
                events_save($data);
                admin_log('event.save', $event['title'] . (empty($event['active']) ? ' — resumed' : ' — paused'));
                flash('"' . $event['title'] . '" ' . (empty($event['active']) ? 'will be shown' : 'is paused') . '. Publish to update the website.');
            }
            break;
        case 'publish':
            $err = events_publish($data);
            if ($err === '') {
                admin_log('event.publish', plural(count($data['events']), 'event') . ' in the admin');
                flash('Published. The Events page and the home page slider now show this lineup.');
            } else {
                flash($err, 'err');
            }
            break;
        case 'reimport':
            $data = events_import();
            admin_log('event.import', plural(count($data['events']), 'event') . ' read from the page');
            flash('Loaded ' . plural(count($data['events']), 'event') . ' from the Events page.');
            break;
        case 'restore':
            $name = post_str('file', 40);
            $path = events_backup_dir() . '/' . $name;
            $html = preg_match('/^events-\d{8}-\d{6}\.html$/', $name) && is_file($path) ? (string) file_get_contents($path) : '';
            if ($html === '' || events_count_page($html)['days'] !== 7 || events_extract($html) === null) { flash('That earlier copy can\'t be restored.', 'err'); break; }
            events_backup_page((string) @file_get_contents(events_page_file())); // so a restore can be undone too
            if (!events_write_page($html)) { flash('The Events page can\'t be written. Its folder must be writable by the website.', 'err'); break; }
            $data = events_import();
            admin_log('event.restore', $name);
            flash('Restored the earlier copy of the Events page (' . plural(count($data['events']), 'event') . ').');
            break;
    }
    redirect($back);
}

// ---------- the list ----------

function admin_events_list(): void
{
    $data = events_store();
    $events = $data['events'];
    $state = events_state($data);
    $problems = events_problems($events);
    $tonight = events_tonight();
    $view = get_choice('view', ['week', 'special', 'all'], 'week');

    // Banner: is the website showing what's here?
    $publishForm = fn($label, $class) => form_open(admin_url('events')) . '<input type="hidden" name="action" value="publish">' . btn_submit($label, $class, '', '', 'external') . '</form>';
    $reimport = form_open(admin_url('events'), 'inline') . '<input type="hidden" name="action" value="reimport">'
        . btn_submit('Load the page\'s events here', 'sm', '', '', 'refresh', 'Replace what is in the admin with the events on the page now? Changes here that are not published will be lost.') . '</form>';
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
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>The Events page was edited outside this admin.</b><p class="muted">Publishing will replace those edits with the lineup below. To keep them instead, load the page\'s events here first.</p></div>'
                . '<div class="callout-act">' . $reimport . $publishForm('Publish anyway', 'sm') . '</div></div>';
            break;
        default:
            $banner = '<div class="callout red">' . icon('alert', 18) . '<div><b>Publishing isn\'t possible yet.</b><p class="muted">' . h($state['detail']) . ' The Events page needs the two admin:events marker comments (see docs/ADMIN.md).</p></div></div>';
    }
    if ($problems) {
        $banner .= '<div class="callout red">' . icon('alert', 18) . '<div><b>' . h(plural(count($problems), 'event')) . ' can\'t be published yet</b><ul class="linklist">'
            . implode('', array_map(fn($t, $p) => '<li>' . h($t) . ' <span class="muted">' . h($p) . '</span></li>', array_keys($problems), $problems)) . '</ul></div></div>';
    }

    // The three views.
    $tabs = tabs_html([
        ['label' => 'Weekly lineup', 'href' => admin_url('events'), 'on' => $view === 'week'],
        ['label' => 'Special dates', 'href' => admin_url('events', ['view' => 'special']), 'on' => $view === 'special',
            'count' => count(array_filter($events, fn($e) => ($e['repeat'] ?? '') === 'once' && ($e['date'] ?? '') >= $tonight))],
        ['label' => 'All events', 'href' => admin_url('events', ['view' => 'all']), 'on' => $view === 'all', 'count' => count($events)],
    ]);

    if ($view === 'week') {
        $cols = '';
        foreach (EVENT_DAYS as $k => $name) {
            $list = [];
            foreach (array_values($events) as $i => $e) {
                if (($e['repeat'] ?? 'weekly') === 'weekly' && in_array($k, (array) ($e['days'] ?? []), true)) $list[] = ['sort' => [events_sort_minutes((string) $e['time']), $i], 'e' => $e];
            }
            usort($list, fn($a, $b) => $a['sort'] <=> $b['sort']);
            $items = '';
            foreach ($list as $row) {
                $e = $row['e'];
                $off = empty($e['active']);
                $items .= '<li class="evitem' . ($off ? ' is-off' : '') . '"><a href="' . h(admin_url('events', ['edit' => $e['id']])) . '">' . admin_event_thumb($e)
                    . '<span class="evmain"><b class="evtime">' . h(fmt_time12((string) $e['time'])) . '</b><span class="evtitle">' . h($e['title']) . '</span>'
                    . (events_host_text($e) !== '' ? '<small>' . h(events_host_text($e)) . '</small>' : '') . ($off ? '<span class="badge tone-muted">Paused</span>' : '') . '</span></a></li>';
            }
            $active = count(array_filter($list, fn($r) => !empty($r['e']['active'])));
            $cols .= '<section class="wday"><header><h3>' . h($name) . '</h3><span class="muted">' . h(plural($active, 'event')) . '</span>'
                . '<a class="btn sm ghost" href="' . h(admin_url('events', ['edit' => 'new', 'day' => $k])) . '" title="Add an event on ' . h($name) . '" aria-label="Add an event on ' . h($name) . '">' . icon('plus', 15) . '</a></header>'
                . ($items !== '' ? '<ul class="evlist">' . $items . '</ul>' : '<p class="evnone">Nothing yet</p>') . '</section>';
        }
        $content = '<div class="weekgrid">' . $cols . '</div>';
    } else {
        $rows = '';
        $pick = $view === 'special' ? array_filter($events, fn($e) => ($e['repeat'] ?? '') === 'once') : $events;
        usort($pick, function ($a, $b) use ($view) {
            if ($view === 'special') return [$a['date'], $a['time']] <=> [$b['date'], $b['time']];
            return [($a['repeat'] ?? '') === 'once' ? 1 : 0, events_sort_minutes((string) $a['time'])] <=> [($b['repeat'] ?? '') === 'once' ? 1 : 0, events_sort_minutes((string) $b['time'])];
        });
        foreach ($pick as $e) {
            $past = ($e['repeat'] ?? '') === 'once' && ($e['date'] ?? '') < $tonight;
            $badge = empty($e['active']) ? badge('Paused', 'muted') : ($past ? badge('Past', 'muted') : badge('On website', 'good'));
            $rows .= '<tr class="' . (empty($e['active']) || $past ? 'is-off' : '') . '"><td>' . admin_event_thumb($e) . '</td>'
                . '<td><a class="row-link" href="' . h(admin_url('events', ['edit' => $e['id']])) . '"><b>' . h($e['title']) . '</b></a>'
                . (events_host_text($e) !== '' ? '<div class="cell-sub">' . h(events_host_text($e)) . '</div>' : '') . '</td>'
                . '<td class="nowrap">' . h(admin_event_when($e)) . '<div class="cell-sub">' . h(fmt_time12((string) $e['time'])) . '</div></td>'
                . '<td>' . implode(' ', array_map(fn($t) => '<span class="chip">' . h($t) . '</span>', (array) ($e['tags'] ?? []))) . '</td>'
                . '<td>' . $badge . '</td>'
                . '<td class="act">' . form_open(admin_url('events'), 'inline') . '<input type="hidden" name="id" value="' . h($e['id']) . '"><input type="hidden" name="action" value="toggle">'
                . btn_submit(empty($e['active']) ? 'Resume' : 'Pause', 'sm ghost') . '</form></td></tr>';
        }
        $content = $rows === ''
            ? empty_state($view === 'special' ? 'No special dates yet' : 'No events yet', $view === 'special' ? 'Add a one-night event, like a themed party, and it shows on its date only.' : 'Add your first event.', 'calendar')
            : '<div class="table"><table class="tbl"><thead><tr><th></th><th>Event</th><th>When</th><th>Tags</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    // Earlier copies of the page.
    $copies = '';
    foreach (array_slice(events_backups(), 0, 10) as $b) {
        $copies .= '<li><span>' . h(fmt_when($b['ts'])) . '<small class="muted"> &middot; ' . h(number_format($b['bytes'] / 1000, 1)) . ' KB</small></span>'
            . form_open(admin_url('events'), 'inline') . '<input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="' . h($b['file']) . '">'
            . btn_submit('Restore', 'sm', '', '', 'refresh', 'Put the Events page back to how it was then? Today\'s page is saved first, so you can undo this.') . '</form></li>';
    }

    $body = page_head('Events', 'The weekly lineup and one-night specials on the Events page and the home page slider. Add or change events here, then publish them to the website.',
            btn_link('View events page', SITE_BASE . 'events.html', 'sm', 'external', ' target="_blank" rel="noopener"')
            . btn_link('Add event', admin_url('events', ['edit' => 'new']), 'primary', 'plus'))
        . $banner
        . '<div class="toolbar">' . $tabs . '</div>'
        . $content
        . ($copies !== '' ? '<details class="card addcard"><summary><span>' . icon('refresh', 16) . ' Earlier copies of the Events page</span><small>Saved automatically before each publish</small></summary><div class="card-body"><ul class="linklist versions">' . $copies . '</ul></div></details>' : '');
    admin_render('Events', $body, 'events');
}

// ---------- the form ----------

/** Tags already used on events, most used first, as clickable suggestions. */
function admin_event_tag_suggestions(array $events): array
{
    $count = [];
    foreach ($events as $e) foreach ((array) ($e['tags'] ?? []) as $t) $count[$t] = ($count[$t] ?? 0) + 1;
    arsort($count);
    return array_slice(array_keys($count), 0, 14);
}

/**
 * The add / edit form. $posted = the values to show instead of the saved ones
 * (after a mistake), $errors = what to fix. Ends the request.
 */
function admin_event_form(string $edit, ?array $posted = null, array $errors = []): void
{
    $data = events_store();
    $isNew = $edit === 'new';
    [$saved] = $isNew ? [null] : admin_event_find($data, $edit);
    if (!$isNew && !$saved) {
        admin_render('Event', '<p class="back"><a href="' . h(admin_url('events')) . '">' . icon('left', 15) . ' Events</a></p>' . empty_state('Event not found', 'It may have been deleted.', 'calendar'), 'events');
    }
    $day = get_choice('day', array_keys(EVENT_DAYS), '');
    $preDate = events_valid_date(get_str('date', 10)) ? get_str('date', 10) : '';
    $e = $posted ?? $saved ?? [
        'id' => '', 'title' => '', 'time' => '', 'repeat' => $preDate !== '' ? 'once' : 'weekly', 'days' => $day !== '' ? [$day] : [], 'date' => $preDate,
        'host' => '', 'hostStyle' => 'hosted', 'desc' => '', 'tags' => [], 'poster' => '', 'alt' => '', 'active' => true,
    ];
    $poster = (string) ($e['poster'] ?? '');
    $hasPoster = preg_match(EVENT_POSTER_PATH, $poster) && is_file(dirname(__DIR__, 3) . '/' . $poster);
    $days = (array) ($e['days'] ?? []);
    $once = ($e['repeat'] ?? 'weekly') === 'once';

    // Day buttons.
    $pills = '';
    foreach (EVENT_DAYS as $k => $name) {
        $pills .= '<label class="daypill"><input type="checkbox" name="days[]" value="' . $k . '"' . (in_array($k, $days, true) ? ' checked' : '') . '><span title="' . h($name) . '">' . substr($name, 0, 3) . '</span></label>';
    }
    // Host style.
    $styles = '';
    foreach (EVENT_HOST_STYLES as $k => $label) $styles .= '<option value="' . $k . '"' . (($e['hostStyle'] ?? 'hosted') === $k ? ' selected' : '') . '>' . h($label) . '</option>';
    // Tag suggestions.
    $chips = '';
    foreach (admin_event_tag_suggestions($data['events']) as $t) $chips .= '<button type="button" class="chip tagchip" data-tag="' . h($t) . '">' . h($t) . '</button>';

    $errorHtml = '';
    foreach ($errors as $msg) $errorHtml .= '<div class="flash err" role="alert">' . icon('alert', 16) . '<span>' . h($msg) . '</span></div>';

    // What guests will see: the same card as the page, in the same square poster window.
    $host0 = events_host_text($e);
    $preview = '<div class="pv" data-preview><div class="pv-poster">'
        . '<img data-pv-img src="' . ($hasPoster ? h(SITE_BASE . $poster) : '') . '" alt=""' . ($hasPoster ? '' : ' hidden') . '>'
        . '<canvas data-pv-canvas width="480" height="480" hidden></canvas>'
        . '<span class="pv-empty" data-pv-empty' . ($hasPoster ? ' hidden' : '') . '>Poster preview</span>'
        . '<span class="pv-time" data-pv-time>' . h(preg_match('/^\d{2}:\d{2}$/', (string) $e['time']) ? fmt_time12($e['time']) : '7:00 PM') . '</span>'
        . '<span class="pv-date" data-pv-date' . ($once && events_valid_date((string) $e['date']) ? '' : ' hidden') . '>' . h($once && events_valid_date((string) $e['date']) ? date('D, M j', strtotime($e['date'] . ' 12:00:00')) : '') . '</span></div>'
        . '<div class="pv-body"><h4 data-pv-title>' . h($e['title'] !== '' ? $e['title'] : 'Event title') . '</h4>'
        . '<p class="pv-host" data-pv-host' . ($host0 === '' ? ' hidden' : '') . '>' . h($host0) . '</p>'
        . '<p class="pv-desc" data-pv-desc>' . h($e['desc'] !== '' ? $e['desc'] : 'A short description of the night appears here.') . '</p>'
        . '<ul class="pv-tags" data-pv-tags>' . implode('', array_map(fn($t) => '<li>' . h($t) . '</li>', (array) $e['tags'])) . '</ul></div></div>';

    // The poster size guide: what to upload and what part of it shows where.
    $legend = '<div class="legend"><div class="legend-figure" aria-hidden="true"><div class="lf-frame">'
        . '<div class="lf-crop"><span>Cut off on the Events page</span></div>'
        . '<div class="lf-badge">7:00 PM</div>'
        . '<div class="lf-safe"><b>Always visible</b><span>Keep the artwork and the title here</span></div></div>'
        . '<span class="lf-dim lf-w">480 px</span><span class="lf-dim lf-h">600 px</span></div>'
        . '<div class="legend-text"><h3>Poster size guide</h3><dl class="specs">'
        . '<div><dt>Size</dt><dd><b>480 × 600 px</b>, portrait (4:5)</dd></div>'
        . '<div><dt>File</dt><dd>WebP, JPG or PNG, up to 1.5 MB</dd></div>'
        . '<div><dt>Events page &amp; home slider</dt><dd>shown as a square from the bottom of the picture, so the top 120 px is cut off and the time badge covers the top-left corner</dd></div>'
        . '<div><dt>Chat assistant</dt><dd>shows the whole picture, small</dd></div></dl>'
        . '<p class="muted small">Any picture works: the editor above crops and resizes it to 480 × 600 for you. Drag the picture to choose what shows.</p></div></div>';

    $posterField = '<div class="poster-field" data-poster data-w="' . EVENT_POSTER_W . '" data-h="' . EVENT_POSTER_H . '">'
        . '<div class="poster-stage" data-stage tabindex="0" aria-label="Poster editor: drag to move the picture, use plus and minus to zoom">'
        .   '<img class="poster-current" data-current src="' . ($hasPoster ? h(SITE_BASE . $poster) : '') . '" alt="Current poster"' . ($hasPoster ? '' : ' hidden') . '>'
        .   '<canvas class="poster-canvas" data-canvas width="' . EVENT_POSTER_W . '" height="' . EVENT_POSTER_H . '" hidden></canvas>'
        .   '<div class="poster-empty" data-empty' . ($hasPoster ? ' hidden' : '') . '>' . icon('plus', 22) . '<span>Drop a picture here</span></div>'
        .   '<div class="g-top" data-guide hidden><span>Cut off on the Events page</span></div><div class="g-badge" data-guide hidden>time</div>'
        . '</div>'
        . '<div class="poster-tools"><label class="btn sm" for="poster_file">' . icon('plus', 15) . '<span data-choose>' . ($hasPoster ? 'Replace picture' : 'Choose a picture') . '</span></label>'
        . '<input class="sr" type="file" id="poster_file" name="poster_file" accept="image/webp,image/jpeg,image/png">'
        . '<label class="zoom" data-zoomwrap hidden>Zoom <input type="range" min="1" max="4" step="0.01" value="1" data-zoom aria-label="Zoom"></label>'
        . '<label class="check"><input type="checkbox" data-guides checked> Show guides</label></div>'
        . '<p class="poster-msg" data-msg role="status">' . ($hasPoster ? 'This is the current poster. Choose a picture to replace it.' : 'No picture yet. Choose one from your computer or phone.') . '</p></div>';

    $form = '<form class="eventform" data-event-form method="post" enctype="multipart/form-data" action="' . h(admin_url('events')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . h($isNew ? '' : (string) $e['id']) . '">'
        . '<input type="hidden" name="poster_path" value="' . h($hasPoster ? $poster : '') . '">'
        . '<div class="split wideleft"><div class="stack">'
        .   card('What', '<label class="field wide"><span>Event title</span><input name="title" value="' . h($e['title']) . '" maxlength="80" required placeholder="e.g. Drag Bingo"></label>'
                . '<div class="field wide"><span>Hosted by</span><div class="hostrow"><select name="hostStyle" aria-label="How to show the host">' . $styles . '</select>'
                . '<input name="host" value="' . h($e['host']) . '" maxlength="80" placeholder="e.g. London Adour" aria-label="Host name"></div>'
                . '<small class="muted">Shown under the title. Leave the name empty for no host line.</small></div>'
                . '<label class="field wide"><span>Description</span><textarea name="desc" rows="3" maxlength="220" placeholder="One or two short sentences about the night.">' . h($e['desc']) . '</textarea></label>'
                . '<div class="field wide"><span>Tags</span><input name="tags" data-tags value="' . h(implode(', ', (array) $e['tags'])) . '" placeholder="Live Music, No Cover" aria-describedby="tagshelp">'
                . '<small class="muted" id="tagshelp">Up to 6, separated by commas. They show as small labels on the card.</small>'
                . ($chips !== '' ? '<div class="tagpick" aria-label="Tags used before">' . $chips . '</div>' : '') . '</div>')
        .   card('When', '<div class="field wide"><span>How often</span><div class="seg" role="radiogroup">'
                . '<label><input type="radio" name="repeat" value="weekly"' . ($once ? '' : ' checked') . '><span>Every week</span></label>'
                . '<label><input type="radio" name="repeat" value="once"' . ($once ? ' checked' : '') . '><span>One night only</span></label></div></div>'
                . '<div class="field wide" data-when="weekly"><span>Day(s) of the week</span><div class="daypicker" role="group" aria-label="Days of the week">' . $pills . '</div>'
                . '<small class="muted">Pick more than one day for an event that repeats on several days.</small></div>'
                . '<div class="field wide" data-when="once"><span>Date</span><div class="daterow"><input type="date" name="date" value="' . h((string) $e['date']) . '"'
                . ((string) $e['date'] === '' || (string) $e['date'] >= events_tonight() ? ' min="' . h(events_tonight()) . '"' : '') . '>'
                . '<b data-weekday class="muted"></b></div><small class="muted">It shows on the Events page for that date only, and disappears afterwards.</small></div>'
                . '<label class="field sm"><span>Starts at</span><input type="time" name="time" value="' . h((string) $e['time']) . '" required></label>'
                . '<small class="muted hint">Add several events on the same day at different times: they are listed in time order. After-midnight shows belong to the night before.</small>')
        .   card('On the website', '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($e['active']) ? ' checked' : '') . '> Show this event on the website</label>'
                . '<p class="muted small">Untick to keep it saved without showing it (a seasonal show, say).</p>')
        . '</div><div class="stack">'
        .   card('Poster', $posterField . $legend)
        .   card('Preview', $preview . '<p class="muted small">This is how the card looks on the Events page.</p>')
        . '</div></div>'
        . '<div class="savebar"><a class="btn ghost" href="' . h(admin_url('events')) . '">Cancel</a><span class="grow"></span>'
        . '<button class="btn" type="submit">' . icon('check', 16) . '<span>Save draft</span></button>'
        . '<button class="btn primary" type="submit" name="publish" value="1">' . icon('external', 16) . '<span>Save &amp; publish</span></button></div></form>';

    // Copy / delete an existing event.
    $extra = '';
    if (!$isNew) {
        $extra = card('More', '<div class="btns">'
            . form_open(admin_url('events'), 'inline') . '<input type="hidden" name="id" value="' . h((string) $e['id']) . '"><input type="hidden" name="action" value="duplicate">' . btn_submit('Duplicate', 'sm', '', '', 'plus') . '</form>'
            . form_open(admin_url('events'), 'inline') . '<input type="hidden" name="id" value="' . h((string) $e['id']) . '"><input type="hidden" name="action" value="delete">'
            . btn_submit('Delete event', 'sm danger', '', '', 'trash', 'Delete "' . $e['title'] . '"? The poster picture is kept. Publish afterwards to take it off the website.') . '</form></div>');
    }

    $body = '<p class="back"><a href="' . h(admin_url('events')) . '">' . icon('left', 15) . ' Events</a></p>'
        . page_head($isNew ? 'Add an event' : 'Edit event', $isNew ? 'Fill in the details, add a poster, then save. Publish to put it on the website.' : h($e['title']))
        . $errorHtml . $form . $extra;
    admin_render($isNew ? 'Add an event' : 'Edit event', $body, 'events', ['events.js']);
}
