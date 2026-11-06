# API Reference

PHP endpoints in `api/`. They all return JSON. Errors look like
`{ "ok": false, "error": "Message shown to the customer" }` and come with a
matching HTTP status code.

**Requirements:** PHP 7.4+ with the `curl` and `mbstring` extensions. `api/cache/`
and `api/data/` must be writable, and are created automatically.

## Configuration: `api/config.php`

Copy `api/config.example.php` to `api/config.php`. That file is **git-ignored and
must stay private**.

| Key | Meaning |
| --- | --- |
| `environment` | `sandbox` (test cards) or `production` (real money) |
| `application_id` | Square Application ID for that environment |
| `access_token` | Square access token. **Secret**: it can take payments |
| `location_id` | Square location. Production is `9E9MT9QP4KND5` |
| `menu_cache_mins` | How long to reuse the menu before re-reading Square (default 15) |
| `notify_email` | Where contact and reservation messages are emailed |
| `from_email` | Sender address. Use one on the site's own domain |
| `data_dir` | Where CSVs are saved. Empty means `api/data/`. On Nginx hosts, use a folder outside the web root |
| `admin_password` | Password for the admin panel at `/admin/` (see [ADMIN.md](ADMIN.md)). Empty turns the panel off. The older name `chat_admin_password` also works |

With no `config.php`, the menu still works, because it uses the public store
feed. Checkout replies "Online payment is being set up", and forms still save to CSV.

## Shared protections (`lib.php`)

- **Same-site only:** POST endpoints require `Content-Type: application/json`,
  and requests from other websites are rejected (403).
- **Rate limiting (per visitor IP):** forms allow 8 submissions per 10 minutes,
  cash orders 12 per 15 minutes, and payments 8 attempts per 15 minutes (a guard
  against card testing). Table ordering counts per table, because guests on the
  venue Wi-Fi share one address: 8 orders per table per 10 minutes and 4 waiter
  calls per table per 5 minutes (with roomy caps of 80 and 60 per address).
  Past that, the visitor gets HTTP 429 ("Too many attempts…").
- **Input cleaning:** every field is trimmed and length-limited before it is
  saved or emailed.

## `GET api/menu.php`

Returns the live menu built from the restaurant's Square Online store feed:
categories, items, sizes, option groups, prices (in cents), pickup and delivery
hours, prep time, delivery ZIPs and minimums.

- Cached in `api/cache/` for `menu_cache_mins`.
- If Square can't be reached, it serves the last cached copy (even if expired),
  then `data/menu.json`. If none of those exist, it returns **503**.
- The response also includes `paymentsEnabled` and, when that's true, the
  public `applicationId` that the card field needs.
- From the command line, `php api/menu.php --snapshot` rewrites
  `data/menu.json` from the live data.

## `POST api/checkout.php`

Used by `js/order.js`. The `action` field chooses what happens:

```json
{ "action": "quote", "cart": [...], "fulfillment": {...} }

{ "action": "pay", "cart": [...], "fulfillment": {...}, "customer": {...},
  "tip": 350, "sourceId": "<card token from Square Web Payments SDK>",
  "idempotencyKey": "<uuid>" }
```

Each cart line looks like
`{ "itemId": "1513", "variationId": "<square id>", "qty": 2, "modifiers": ["<choice id>"], "note": "..." }`.
Prices are never read from the browser.

- **quote:** validates the cart and returns the subtotal, tax, delivery fee and
  total, calculated by Square.
- **pay:** runs the same checks, creates the Square order (it appears in the
  POS like any online order) and charges the card. The `idempotencyKey` means a
  double-click or retry can't charge twice.
- **place** (cash on delivery, the default until a payment processor is chosen):
  runs the same checks, then saves the order to the `orders` table, emails
  `notify_email` (and the guest a copy, if they gave an email) and returns a
  six-character order number. Nothing is charged.
  If the database is down the order goes to `api/data/unsaved/orders.jsonl`
  instead; if neither that nor the email works, it returns 500 with a "please
  call us" message rather than a fake confirmation. The admin panel's **Orders**
  section and the KDS read the same table. It takes at most 12 orders per 15
  minutes from one visitor.

Server-side checks, which never trust the browser:

- Every item and option is **re-priced from the live menu**, and required options are enforced.
- Items no longer on the menu are rejected, and orders are capped at 50 lines.
- **Pickup:** the kitchen must be open (restaurant time zone) and the order must meet the pickup minimum.
- **Delivery:** delivery must be enabled, the ZIP must be in the allowed list,
  the delivery minimum must be met, and delivery must be open.
- **Customer:** a name, a phone number with 10+ digits and a valid email are required.

Returns 405 for non-POST requests, 503 if the menu is unavailable or payments
aren't configured, and 400 with a customer-friendly message for validation errors.

## `POST api/contact.php`

Handles contact messages and reservation requests.

```json
{ "type": "contact", "name": "...", "email": "...", "phone": "...",
  "subject": "general", "message": "..." }
{ "type": "reservation", "name": "...", "email": "...", "phone": "...",
  "date": "YYYY-MM-DD", "time": "...", "guests": "...", "area": "...", "notes": "..." }
```

- Reservations need a valid phone number, a date from today onward, a time and a party size.
- Each submission is saved to the `contacts` or `reservations` table (or, if the
  database is down, to `api/data/unsaved/`) and emailed to `notify_email`.
  The admin panel's Messages and Reservations sections read these tables.
- It succeeds if either the save or the email works. If both fail, it returns
  500 with a "please call us" message.

## `POST api/subscribe.php`

Handles newsletter sign-ups: `{ "email": "...", "page": "..." }`. The `page`
field records which page the visitor signed up from.

- Saves the address to the `subscribers` table (an address already there is kept once).
- When Square is configured, also adds the person to the **Square Customer
  Directory**, so the restaurant can email them with Square Marketing.

## `GET` / `POST api/gift-cards.php`

The Gift Cards page (`gift-cards.html`, `js/gift-cards.js`). The shared code is in
`api/gift-cards-lib.php` (private). Amounts are in cents.

- `GET` returns the designs, amounts and limits set in Admin → Gift cards, and
  `mode`: `square` when card payments are set up (Square application ID, access
  token and location in `api/config.php`), otherwise `request`. It also emails
  any cards whose delivery day has come (there's no cron job; the admin panel
  does the same).
- `GET ?photo=<id>` serves a picture a buyer uploaded for their own design.
- `POST { "action": "buy", ... }`: in `square` mode it charges the card
  (Payments API), creates a digital gift card in Square and loads it
  (`/v2/gift-cards`, then an `ACTIVATE` activity), saves it and emails it. If
  Square takes the payment but doesn't create the card, the card is saved as
  *Needs attention* and the team is emailed (Admin → **Retry in Square**). In
  `request` mode it saves a request the team takes payment for.
- `POST { "action": "balance", "code": "..." }` returns a card's balance (Square
  cards sold at the bar too). Limited to 10 tries per 10 minutes per visitor.
- `POST { "action": "view", "token": "..." }` returns the card behind an emailed
  link (`gift-cards.html#card=<token>`), with its code once it's active.

## `GET api/chat-settings.php`

How the chat looks and behaves, as JSON (title, button, position, opening by
itself, the contact form, suggested questions, team phone and hours, notice).
Set in Admin → Chat settings, stored in the `settings` table, read by `js/chat.js`
before it shows anything. Browsers may keep it for 60 seconds. Without a database
it returns the defaults, which are what the chat always did.

## `POST api/chat.php`

The "Ask us" chat assistant, used by `js/chat.js`.

```json
{ "messages": [ { "role": "user", "content": "What's on tonight?" },
                { "role": "assistant", "content": "..." },
                { "role": "user", "content": "..." } ] }
→ { "ok": true, "reply": "..." }
```

- The browser sends the whole conversation, because the server keeps no chat
  state. The server keeps the last 12 messages and limits each guest message to
  600 characters.
- The system prompt is built on every request. It always includes the rules
  in `chat.php`, `data/chatbot-knowledge.md` and Happy Hour from `menu.html`.
  The weekly lineup (`events.html`), drinks (`menu.html`) and food menu
  (`load_menu()`) are added only when the guest's last two messages mention
  them (keyword lists in `CHAT_TOPICS`). This keeps a request to roughly
  1,700–4,000 tokens, which matters on free plans that limit tokens per minute.
  The current time and tonight's shows go on the newest message instead.
- `chat_provider` picks the AI: `mistral`, `groq` or `gemini` (free plans), `ollama`
  (local), or `claude` (`chat-claude.php`; needs PHP 8.1+ and `api/vendor/`
  from Composer). If it fails, each AI in `chat_fallback` is tried in order.
  A specific model is written as `groq:openai/gpt-oss-20b`. Each Groq model
  has its own free limit.
- **Food ordering:** when the food menu is in the prompt, the AI is offered the
  `add_to_order` tool (`chat-order.php`, OpenAI-style providers only). The
  request also carries the guest's cart (`cart`, same line format as
  `js/order.js`), so dishes aren't added twice. `resolve_order()` matches dish,
  size and option names against the live menu and adds only fully specified
  dishes. Anything missing or ambiguous goes back to the AI, which asks the
  guest. The reply then includes `cart` (lines plus `label` and `total`).
  `js/chat.js` adds them to the site's cart through `window.rmsOrder` (menu
  page) or localStorage, and "Review & pay" opens the normal checkout
  (`menu.html#order`). Nothing is charged in the chat, and checkout re-prices
  everything.
- **Cards and suggestions** (`chat-cards.php`): the reply also carries `cards`
  (`shows`: shows the reply names, with day, time, host and poster from
  `events.html`; `packages`: the party packages from `private-events.html` when
  the reply goes through them) and `suggestions` (up to three follow-up
  questions). Cards come from the site's own pages, never from the AI's words.
  "Talk to a person" is handled in the browser and doesn't call the AI.
- Protections: same-site JSON only, 30 messages per visitor per 10 minutes, and
  `chat_daily_limit` messages per day for the whole site (then 503).
- When `chat_log` is on, every exchange is saved (`chat-log.php`) to the
  database: one `chat_sessions` row per conversation id (`cid`, made per browser
  tab by `chat.js`) with the guest's email and optional name, their IP address,
  device and browser, and one `chat_messages` row per question with the reply,
  the dishes added, the shows shown, the AI used, token counts and seconds.

- Errors return 502 with a "please call us" message. On `localhost` the reply
  also includes `detail` with the real cause.
- CLI: `php api/chat.php --prompt` prints the prompt, and
  `php api/chat.php "question"` asks one question.
- The weekly lineup in the prompt is read from `events.html`. A card with a
  `data-date` is a one-night event: it is left out once its night is over
  (nights run until 4 AM) and is marked "one night only".

## `GET api/chats.php` (moved)

The chat history page is now part of the **admin panel** at `/admin/`
(Conversations and Insights; see [ADMIN.md](ADMIN.md)). `api/chats.php` is only a
redirect, so old links and bookmarks still land in the right place: `?c=<id>`,
`?view=insights`, `?view=food`, `?view=settings`, `?view=happy` and `?csv=1`
forward to their pages in the panel.

## Testing locally

```bash
php -S localhost:3456 -t .
curl http://localhost:3456/api/menu.php
```

For payments, use `environment => 'sandbox'` and Square's test card
`4111 1111 1111 1111`, any future expiry, CVV `111`, ZIP `33305`.
