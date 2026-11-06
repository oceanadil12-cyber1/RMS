<?php
/**
 * Knowledge: what the chat assistant knows about the bar, in plain words
 * (data/chatbot-knowledge.md). The assistant repeats it to guests and reads the
 * new text on the next message. Every save keeps the previous text, so a mistake
 * is one click to undo.
 */
if (!defined('ADMIN')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/../../../api/chat-insights.php'; // chat_insights(): the questions it couldn't answer

const KNOWLEDGE_MAX_BYTES = 60000;
const KNOWLEDGE_KEEP = 30; // saved versions

function knowledge_file(): string
{
    return dirname(__DIR__, 3) . '/data/chatbot-knowledge.md';
}

function knowledge_history_dir(): string
{
    return data_dir() . '/knowledge-history';
}

/** Saved earlier versions, newest first: [['file', 'ts', 'bytes'], ...]. */
function knowledge_versions(): array
{
    $out = [];
    foreach (glob(knowledge_history_dir() . '/*.md') ?: [] as $path) {
        if (!preg_match('/^(\d{8})-(\d{6})\.md$/', basename($path), $m)) continue;
        $out[] = ['file' => basename($path), 'ts' => (int) strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2)), 'bytes' => (int) filesize($path)];
    }
    usort($out, fn($a, $b) => strcmp($b['file'], $a['file']));
    return $out;
}

/** Keep the text that's about to be replaced, and only the newest KNOWLEDGE_KEEP versions. */
function knowledge_snapshot(string $current): void
{
    if (trim($current) === '') return;
    $dir = knowledge_history_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/' . date('Ymd-His') . '.md', $current, LOCK_EX);
    foreach (array_slice(knowledge_versions(), KNOWLEDGE_KEEP) as $old) @unlink($dir . '/' . $old['file']);
}

/** Write new knowledge text. Returns '' on success, else the reason it wasn't saved. */
function knowledge_write(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (!mb_check_encoding($text, 'UTF-8')) return 'That text has characters the file can\'t hold. Please retype them.';
    if (trim($text) === '') return 'The knowledge can\'t be empty: the assistant would know nothing about the bar.';
    $text = rtrim($text) . "\n";
    if (strlen($text) > KNOWLEDGE_MAX_BYTES) return 'That\'s too long (over ' . number_format(KNOWLEDGE_MAX_BYTES / 1000) . ' KB). Shorter facts keep the chat fast and cheap.';
    $file = knowledge_file();
    knowledge_snapshot(is_file($file) ? (string) file_get_contents($file) : '');
    if (@file_put_contents($file, $text, LOCK_EX) === false) return 'The file data/chatbot-knowledge.md can\'t be written. Its folder must be writable by the website.';
    return '';
}

function admin_page_knowledge(): void
{
    $file = knowledge_file();
    $current = is_file($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : '';

    if (is_post()) {
        require_csrf();
        switch ($_POST['action'] ?? '') {
            case 'save':
                $text = is_string($_POST['text'] ?? null) ? str_replace(["\r\n", "\r"], "\n", $_POST['text']) : '';
                if (rtrim($text) . "\n" === $current) {
                    flash('No changes to save.');
                } elseif (($err = knowledge_write($text)) !== '') {
                    flash($err, 'err');
                } else {
                    admin_log('knowledge.save', number_format(strlen($text)) . ' characters');
                    flash('Saved. The assistant uses the new text from its next answer.');
                }
                break;
            case 'restore':
                $name = (string) ($_POST['version'] ?? '');
                $path = knowledge_history_dir() . '/' . $name;
                if (preg_match('/^\d{8}-\d{6}\.md$/', $name) && is_file($path) && ($err = knowledge_write((string) file_get_contents($path))) === '') {
                    admin_log('knowledge.restore', $name);
                    flash('Restored the version from ' . fmt_when(strtotime(substr($name, 0, 4) . '-' . substr($name, 4, 2) . '-' . substr($name, 6, 2) . ' ' . substr($name, 9, 2) . ':' . substr($name, 11, 2))) . '.');
                } else {
                    flash($err ?? 'That version wasn\'t found.', 'err');
                }
                break;
        }
        redirect(admin_url('knowledge'));
    }

    // Recent questions the assistant couldn't answer: things worth adding here.
    $unanswered = chat_insights(chat_log_conversations(30))['unanswered'];
    usort($unanswered, fn($a, $b) => strcmp((string) $b['time'], (string) $a['time']));
    $quotes = insight_quotes_simple(array_slice($unanswered, 0, 8));

    $versions = '';
    foreach (array_slice(knowledge_versions(), 0, 10) as $v) {
        $versions .= '<li><span>' . h(fmt_when($v['ts'])) . '<small class="muted"> &middot; ' . h(number_format($v['bytes'] / 1000, 1)) . ' KB</small></span>'
            . form_open(admin_url('knowledge'), 'inline') . '<input type="hidden" name="action" value="restore"><input type="hidden" name="version" value="' . h($v['file']) . '">'
            . btn_submit('Restore', 'sm', '', '', 'refresh', 'Replace the current text with this version? The current text is kept in the history.') . '</form></li>';
    }

    $body = page_head('Chat knowledge', 'What the chat assistant knows about the bar, in plain words: hours, policies, prices that change, FAQs. It repeats this to guests, so keep it accurate. The new text is used from its next answer.')
        . '<div class="split wideleft"><div class="stack">'
        .   card('The assistant\'s facts',
                form_open(admin_url('knowledge')) . '<input type="hidden" name="action" value="save">'
                . '<label class="field wide"><span class="sr">Knowledge text</span><textarea class="mono" name="text" rows="24" spellcheck="true" data-counter="#kcount" data-counter-limit="' . KNOWLEDGE_MAX_BYTES . '">' . h($current) . '</textarea></label>'
                . '<div class="savebar inline"><span class="muted" id="kcount">' . number_format(strlen($current)) . ' characters &middot; about ' . number_format((int) ceil(strlen($current) / 4)) . ' tokens</span>'
                . btn_submit('Save', 'primary', '', '', 'check') . '</div></form>')
        . '</div><div class="stack">'
        .   card('Questions it couldn\'t answer', '<p class="muted small">From the last 30 days of chats. Add the missing facts on the left.</p>' . $quotes)
        .   card('Good to know', '<ul class="tips"><li>The assistant <b>already reads</b> the weekly lineup, the food menu and prices, and Happy Hour on its own. Don\'t copy those in here.</li>'
                . '<li>Lines between <code>&lt;!--</code> and <code>--&gt;</code> are notes for you; the assistant ignores them.</li>'
                . '<li>Short, factual sentences work best.</li></ul>')
        .   ($versions !== '' ? card('Earlier versions', '<ul class="linklist versions">' . $versions . '</ul>') : '')
        . '</div></div>';
    admin_render('Chat knowledge', $body, 'knowledge');
}

/** Unanswered questions as links to their conversations (no period: the list is already the last 30 days). */
function insight_quotes_simple(array $items): string
{
    if (!$items) return '<p class="empty-sm">Nothing it couldn\'t answer lately.</p>';
    $out = '<ul class="quotes">';
    foreach ($items as $i) {
        $out .= '<li><a href="' . h(admin_url('chats', ['c' => $i['cid'], 'days' => 30])) . '">&ldquo;' . h(mb_strimwidth((string) $i['text'], 0, 110, '…')) . '&rdquo;</a>'
            . '<small>' . h(fmt_ago((int) strtotime((string) $i['time']))) . '</small></li>';
    }
    return $out . '</ul>';
}
