<?php
/**
 * Gift cards: shared by the public endpoint (api/gift-cards.php) and the admin
 * panel (Admin → Gift cards). Private: blocked by api/.htaccess and .router.php.
 *
 * Two kinds of card, both kept in the gift_cards table:
 *   'square'  sold on the website while Square card payments are set up. The card
 *             is created and loaded in Square (code = its 16-digit GAN), so the bar
 *             redeems it on the Square POS like any Square gift card.
 *   'local'   bought while card payments are off (a request the team takes payment
 *             for), or issued by the team (comps, prizes, custom amounts). The
 *             balance lives here and is redeemed in Admin → Gift cards.
 *
 * The designs, preset amounts and limits are a 'gift-cards' row in the settings
 * table, edited in Admin → Gift cards → Designs & settings.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/db.php';

const GIFTCARD_STATUSES = [
    'pending' => ['Awaiting payment', 'warn'],
    'active'  => ['Active',           'good'],
    'used'    => ['Fully used',       'muted'],
    'void'    => ['Void',             'crit'],
    'error'   => ['Needs attention',  'crit'],
];
const GIFTCARD_PHOTO_MAX_BYTES = 1500000;   // a customer's own picture (resized in the browser first)
const GIFTCARD_DESIGN_MAX_BYTES = 4000000;  // a design uploaded in the admin
const GIFTCARD_DESIGN_DIR = 'images/giftcards';

/** The settings the site starts with, before anyone saves Designs & settings. */
function giftcards_defaults(): array
{
    return [
        'enabled'           => true,
        'useSquare'         => true,   // sell through Square whenever card payments are set up
        'amounts'           => [2500, 5000, 10000, 15000],
        'min'               => 1000,
        'max'               => 50000,
        'allowCustomAmount' => true,
        'allowCustomDesign' => true,
        'designs'           => [
            ['id' => 'holiday-green', 'name' => 'Holiday Green', 'title' => 'Happy Holidays',  'image' => GIFTCARD_DESIGN_DIR . '/holiday-green.svg', 'active' => true],
            ['id' => 'festive-red',   'name' => 'Festive Red',   'title' => 'Merry & Bright',  'image' => GIFTCARD_DESIGN_DIR . '/festive-red.svg',   'active' => true],
            ['id' => 'classic-black', 'name' => 'Classic Black', 'title' => 'Cheers to You',   'image' => GIFTCARD_DESIGN_DIR . '/classic-black.svg', 'active' => true],
            ['id' => 'gold-edition',  'name' => 'Gold Edition',  'title' => 'Happy New Year',  'image' => GIFTCARD_DESIGN_DIR . '/gold-edition.svg',  'active' => true],
        ],
    ];
}

function giftcards_settings(): array
{
    static $s = null;
    if ($s === null) {
        try {
            $saved = db_setting('gift-cards', []);
        } catch (Throwable $e) {
            $saved = [];
        }
        $s = (is_array($saved) ? $saved : []) + giftcards_defaults();
    }
    return $s;
}

function giftcards_settings_save(array $s): void
{
    db_setting_save('gift-cards', array_intersect_key($s, giftcards_defaults()));
}

/** 'square' (pay by card online, card created in Square) or 'request' (no online payment: the team takes payment). */
function giftcards_mode(): string
{
    return payments_enabled() && !empty(giftcards_settings()['useSquare']) ? 'square' : 'request';
}

function giftcards_design(string $id): ?array
{
    foreach (giftcards_settings()['designs'] as $d) if (($d['id'] ?? '') === $id) return $d;
    return null;
}

/** "7123456789012345" → "7123 4567 8901 2345". */
function giftcards_format_code(string $code): string
{
    return trim(chunk_split($code, 4, ' '));
}

/** What someone types as a code: digits only. */
function giftcards_clean_code(string $code): string
{
    return substr(preg_replace('/\D/', '', $code), 0, 32);
}

/** A new, unused 16-digit code for a local card (starts with 77 so it never looks like a Square GAN or a bank card). */
function giftcards_new_code(): string
{
    do {
        $code = '77';
        for ($i = 0; $i < 14; $i++) $code .= (string) random_int(0, 9);
    } while (giftcards_find('code', $code));
    return $code;
}

/** A card by 'id', 'code', 'token' or 'square_payment_id'; null if there's none. */
function giftcards_find(string $column, $value): ?array
{
    if (!in_array($column, ['id', 'code', 'token', 'square_payment_id'], true) || $value === '' || $value === null) return null;
    $stmt = db()->prepare("SELECT * FROM gift_cards WHERE `$column` = ? LIMIT 1");
    $stmt->execute([$value]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Insert a card (defaults filled in) and note it in its history. Returns the saved row. */
function giftcards_create(array $row, string $detail): array
{
    $row += [
        'code' => giftcards_new_code(), 'token' => bin2hex(random_bytes(16)), 'ip' => client_ip(),
    ];
    if (!isset($row['balance'])) $row['balance'] = in_array($row['status'] ?? '', ['active'], true) ? (int) $row['amount'] : 0;
    $id = db_insert('gift_cards', $row);
    giftcards_activity($id, 'issue', (int) $row['amount'], (int) $row['balance'], $detail);
    return giftcards_find('id', $id);
}

function giftcards_activity(int $cardId, string $type, int $amount, int $balanceAfter, string $detail = ''): void
{
    db_insert('gift_card_activity', [
        'gift_card_id' => $cardId, 'type' => $type, 'amount' => $amount,
        'balance_after' => $balanceAfter, 'detail' => mb_substr($detail, 0, 255),
    ]);
}

function giftcards_history(int $cardId): array
{
    $stmt = db()->prepare('SELECT * FROM gift_card_activity WHERE gift_card_id = ? ORDER BY id DESC');
    $stmt->execute([$cardId]);
    return $stmt->fetchAll();
}

// ---------- pictures ----------

/** Where customers' own pictures are kept (private; served through api/gift-cards.php?photo=). */
function giftcards_photo_dir(): string
{
    return data_dir() . '/gift-card-photos';
}

/**
 * Check a customer's picture sent as a data: URL (the page resizes it to a JPEG first).
 * Returns [bytes, ''] or ['', reason].
 */
function giftcards_photo_decode(string $dataUrl): array
{
    if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return ['', 'Please choose a JPG, PNG or WebP picture for your card.'];
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || $bytes === '') return ['', 'That picture could not be read. Please choose another.'];
    if (strlen($bytes) > GIFTCARD_PHOTO_MAX_BYTES) return ['', 'That picture is too large. Please choose a smaller one.'];
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 200 || $info[1] < 120 || $info[0] > 2400 || $info[1] > 2400) {
        return ['', 'That picture could not be used. Please choose another.'];
    }
    return [$bytes, ''];
}

/** Save checked picture bytes; returns the picture's id ('' if the folder can't be written). */
function giftcards_photo_save(string $bytes): string
{
    $dir = giftcards_photo_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $id = bin2hex(random_bytes(16));
    return @file_put_contents("$dir/$id.jpg", $bytes, LOCK_EX) === strlen($bytes) ? $id : '';
}

function giftcards_photo_file(string $id): string
{
    return preg_match('/^[a-f0-9]{32}$/', $id) ? giftcards_photo_dir() . "/$id.jpg" : '';
}

/** The picture on a card, as a path from the site's root ('' = none). */
function giftcards_card_image(array $card): string
{
    if ($card['photo'] !== '') return 'api/gift-cards.php?photo=' . $card['photo'];
    $d = giftcards_design($card['design']);
    return $d['image'] ?? '';
}

// ---------- links and emails ----------

/** This website's full address ("https://example.com/"). */
function giftcards_site_url(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . site_base();
}

/** The page that shows the card with its code and balance (the token is the secret). */
function giftcards_view_url(array $card): string
{
    return giftcards_site_url() . 'gift-cards.html#card=' . $card['token'];
}

/**
 * Email the card to its recipient (or to the buyer when it's for themselves) and note
 * it. False when there's no address or the mail server refused it.
 */
function giftcards_send(array $card): bool
{
    $to = $card['recipient_email'] !== '' ? $card['recipient_email'] : $card['buyer_email'];
    if ($to === '') return false;
    $store = "RMS";
    $amount = '$' . number_format($card['amount'] / 100, 2);
    $gift = $card['recipient_email'] !== '' && $card['buyer_name'] !== '';
    $intro = ($card['recipient_name'] !== '' ? 'Hi ' . $card['recipient_name'] . "!\n\n" : '')
        . ($gift ? $card['buyer_name'] . " sent you a $amount gift card to $store!" : "Here is your $amount gift card to $store.");
    $fields = [];
    if ($gift && trim($card['message']) !== '') $fields['Message'] = '"' . trim($card['message']) . '"';
    $fields += [
        'Amount'    => $amount,
        'Card code' => giftcards_format_code($card['code']),
        'See card'  => giftcards_view_url($card),
        'To use it' => 'Show the code (or the card page) to your server or bartender. It works on food and drinks, and any balance left stays on the card.',
        'Find us'   => '2266 Wilton Drive, Wilton Manors, FL 33305 · (954) 565-2526',
    ];
    $ok = mail_guest($to, $gift ? $card['buyer_name'] . " sent you an RMS gift card" : "Your RMS gift card", $intro, $fields);
    if ($ok) {
        db_update('gift_cards', ['sent_at' => date('Y-m-d H:i:s')], ['id' => $card['id']]);
        giftcards_activity((int) $card['id'], 'email', 0, (int) $card['balance'], 'Emailed to ' . $to);
    }
    return $ok;
}

/**
 * Email the active cards whose delivery day has come. There's no cron job on the
 * host, so this runs (at most every 5 minutes) whenever someone opens the gift card
 * page or the admin panel.
 */
function giftcards_send_due(bool $force = false): int
{
    $stamp = CACHE_DIR . '/gift-cards-sent.txt';
    if (!$force && is_file($stamp) && time() - filemtime($stamp) < 300) return 0;
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    @touch($stamp);
    $stmt = db()->prepare("SELECT * FROM gift_cards WHERE status = 'active' AND sent_at IS NULL AND (deliver_on IS NULL OR deliver_on <= ?)
        AND (recipient_email <> '' OR buyer_email <> '') ORDER BY id LIMIT 20");
    $stmt->execute([date('Y-m-d')]);
    $sent = 0;
    foreach ($stmt->fetchAll() as $card) if (giftcards_send($card)) $sent++;
    return $sent;
}

/** A card as the public page shows it (no buyer details, no team notes). */
function giftcards_public(array $card, bool $withCode): array
{
    $d = giftcards_design($card['design']);
    $gift = $card['recipient_email'] !== '' || $card['origin'] === 'admin'; // the buyer's name and message are for someone else
    return [
        'code'      => $withCode ? giftcards_format_code($card['code']) : null,
        'last4'     => substr($card['code'], -4),
        'amount'    => (int) $card['amount'],
        'balance'   => (int) $card['balance'],
        'status'    => $card['status'],
        'image'     => giftcards_card_image($card),
        'custom'    => $card['design'] === 'custom' || $card['photo'] !== '', // the words go on top of the picture
        'title'     => $card['design_title'] !== '' ? $card['design_title'] : (string) ($d['title'] ?? ''),
        'to'        => $card['recipient_name'],
        'from'      => $gift ? $card['buyer_name'] : '',
        'message'   => $gift ? $card['message'] : '',
        'deliverOn' => $card['deliver_on'],
        'square'    => $card['source'] === 'square',
    ];
}

// ---------- Square ----------

/**
 * Create a digital gift card in Square and load it with $amount, paid by $paymentId.
 * $key makes retries safe (Square returns the same card). Returns [['id', 'gan'], ''] or [null, reason].
 */
function giftcards_square_issue(int $amount, string $paymentId, string $key): array
{
    $loc = config()['location_id'];
    [$status, $res] = square_request('POST', '/v2/gift-cards', [
        'idempotency_key' => "gc-$key",
        'location_id'     => $loc,
        'gift_card'       => ['type' => 'DIGITAL'],
    ]);
    if ($status !== 200 || empty($res['gift_card']['id'])) return [null, square_error_message($res)];
    $gc = $res['gift_card'];
    [$status, $res] = square_request('POST', '/v2/gift-cards/activities', [
        'idempotency_key'    => "gca-$key",
        'gift_card_activity' => [
            'type'         => 'ACTIVATE',
            'location_id'  => $loc,
            'gift_card_id' => $gc['id'],
            'activate_activity_details' => [
                'amount_money'                 => ['amount' => $amount, 'currency' => 'USD'],
                'buyer_payment_instrument_ids' => [$paymentId],
                'reference_id'                 => substr("web-$key", 0, 40),
            ],
        ],
    ]);
    if ($status !== 200) return [null, square_error_message($res)];
    return [['id' => $gc['id'], 'gan' => (string) $gc['gan']], ''];
}

/** A Square card's balance and state by its code, or null when Square doesn't know it. */
function giftcards_square_lookup(string $gan): ?array
{
    [$status, $res] = square_request('POST', '/v2/gift-cards/from-gan', ['gan' => $gan]);
    if ($status !== 200 || empty($res['gift_card'])) return null;
    $gc = $res['gift_card'];
    return ['id' => $gc['id'], 'balance' => (int) ($gc['balance_money']['amount'] ?? 0), 'state' => (string) ($gc['state'] ?? '')];
}

/** Bring a Square card's saved balance up to date (when Square answers). Returns the card. */
function giftcards_square_refresh(array $card): array
{
    if ($card['source'] !== 'square' || !payments_enabled()) return $card;
    $sq = giftcards_square_lookup($card['code']);
    if (!$sq) return $card;
    $status = $sq['state'] === 'DEACTIVATED' ? 'void' : ($sq['balance'] > 0 ? 'active' : ($card['status'] === 'active' ? 'used' : $card['status']));
    if ($sq['balance'] !== (int) $card['balance'] || $status !== $card['status']) {
        db_update('gift_cards', ['balance' => $sq['balance'], 'status' => $status], ['id' => $card['id']]);
        $card['balance'] = $sq['balance'];
        $card['status'] = $status;
    }
    return $card;
}

/** Take $amount off a Square card (for spending done outside the POS). Returns '' or the reason it failed. */
function giftcards_square_redeem(array $card, int $amount, string $note): string
{
    [$status, $res] = square_request('POST', '/v2/gift-cards/activities', [
        'idempotency_key'    => 'gcr-' . bin2hex(random_bytes(12)),
        'gift_card_activity' => [
            'type'         => 'REDEEM',
            'location_id'  => config()['location_id'],
            'gift_card_id' => $card['square_card_id'],
            'redeem_activity_details' => [
                'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
                'reference_id' => substr(preg_replace('/[^A-Za-z0-9 -]/', '', $note) ?: 'admin', 0, 40),
            ],
        ],
    ]);
    return $status === 200 ? '' : square_error_message($res);
}
