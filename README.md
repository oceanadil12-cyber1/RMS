# RMS — Website & Web App

The official website and web application for **RMS**, a
restaurant, bar and entertainment venue at 2266 Wilton Drive, Wilton Manors,
FL 33305 (phone (954) 565-2526). Live at **<https://example.com>**.

It is a fast, hand-built site: plain HTML, CSS and JavaScript, with no build
step and no framework. A small PHP + MySQL backend powers six connected systems:

1. **Website & online ordering:** a live Square menu, cart, pickup or delivery,
   tips and card payment (or cash on delivery until Square payments are set up).
2. **Gift cards:** guests buy ready-made or custom-designed gift cards online.
   The team activates, emails, redeems and issues them from the admin.
3. **AI chat assistant:** answers guests' questions about hours, events and the
   menu, and can add food to the cart straight from the chat.
4. **Table Ordering System (TOS):** guests scan the QR code on their table, then
   order from their phone or call a server (`/dine-in/table/N`).
5. **Kitchen Display System (KDS):** a live board of every order (website,
   table, takeaway, delivery, staff) for the kitchen, at `/kds/`.
6. **Admin panel:** a web app at `/admin/` where the team runs everything
   without touching code. It has team accounts with roles (Admin, Staff,
   Kitchen) and a full activity log.

Every page is built for phones, tablets and desktops, including foldables
(Galaxy Z Fold cover and inner screens), flip phones (Galaxy Z Flip, Motorola
razr) and dual-screen devices (Surface Duo).

> **Private repository.** This is client work. Do not make the repository
> public, and never commit `api/config.php`, which holds the Square access
> token, AI API keys, and the admin password.

---

## Table of contents

1. [Screenshots](#screenshots)
2. [How to display the website](#how-to-display-the-website)
3. [Pages](#pages)
4. [Online ordering system](#online-ordering-system)
5. [Gift cards](#gift-cards)
6. [Table Ordering System (TOS)](#table-ordering-system-tos)
7. [Kitchen Display System (KDS)](#kitchen-display-system-kds)
8. [AI chat assistant](#ai-chat-assistant)
9. [Admin panel](#admin-panel)
10. [Team accounts and roles](#team-accounts-and-roles)
11. [Project structure](#project-structure)
12. [Tech at a glance](#tech-at-a-glance)
13. [Security](#security)
14. [Configuration reference](#configuration-reference)
15. [Working with this repository](#working-with-this-repository)
16. [Documentation index](#documentation-index)
17. [Version history](#version-history)

---

## Screenshots

All screenshots are in [`docs/screenshots/`](docs/screenshots). Admin and
kitchen screenshots use local test data. Email addresses, phone numbers, IP
addresses and staff names in them are replaced with sample values.

### Website

Phone view (390 px). Click a page name for the full desktop page.

<table>
<tr>
<td align="center"><a href="docs/screenshots/website/index.webp"><b>Home</b></a><br><img src="docs/screenshots/website/mobile/index.webp" width="180" alt="Home page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/menu.webp"><b>Menu & ordering</b></a><br><img src="docs/screenshots/website/mobile/menu.webp" width="180" alt="Menu page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/events.webp"><b>Events</b></a><br><img src="docs/screenshots/website/mobile/events.webp" width="180" alt="Events page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/entertainment.webp"><b>Entertainment</b></a><br><img src="docs/screenshots/website/mobile/entertainment.webp" width="180" alt="Entertainment page on a phone"></td>
</tr>
<tr>
<td align="center"><a href="docs/screenshots/website/performers.webp"><b>Performers</b></a><br><img src="docs/screenshots/website/mobile/performers.webp" width="180" alt="Performers page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/dancers.webp"><b>Meet Our Dancers</b></a><br><img src="docs/screenshots/website/mobile/dancers.webp" width="180" alt="Dancers page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/gallery.webp"><b>Gallery</b></a><br><img src="docs/screenshots/website/mobile/gallery.webp" width="180" alt="Gallery page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/private-events.webp"><b>Private Events</b></a><br><img src="docs/screenshots/website/mobile/private-events.webp" width="180" alt="Private events page on a phone"></td>
</tr>
<tr>
<td align="center"><a href="docs/screenshots/website/gift-cards.webp"><b>Gift Cards</b></a><br><img src="docs/screenshots/website/mobile/gift-cards.webp" width="180" alt="Gift cards page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/about.webp"><b>About</b></a><br><img src="docs/screenshots/website/mobile/about.webp" width="180" alt="About page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/contact.webp"><b>Contact</b></a><br><img src="docs/screenshots/website/mobile/contact.webp" width="180" alt="Contact page on a phone"></td>
<td align="center"><a href="docs/screenshots/website/404.webp"><b>Not found (404)</b></a><br><img src="docs/screenshots/website/mobile/404.webp" width="180" alt="404 page on a phone"></td>
</tr>
</table>

<details>
<summary><b>Desktop: full pages (1440 px)</b></summary>

| Home | Menu & ordering |
| --- | --- |
| <img src="docs/screenshots/website/index.webp" alt="Home page, desktop"> | <img src="docs/screenshots/website/menu.webp" alt="Menu page, desktop"> |
| **Events** | **Entertainment** |
| <img src="docs/screenshots/website/events.webp" alt="Events page, desktop"> | <img src="docs/screenshots/website/entertainment.webp" alt="Entertainment page, desktop"> |
| **Performers** | **Meet Our Dancers** |
| <img src="docs/screenshots/website/performers.webp" alt="Performers page, desktop"> | <img src="docs/screenshots/website/dancers.webp" alt="Dancers page, desktop"> |
| **Gallery** | **Private Events** |
| <img src="docs/screenshots/website/gallery.webp" alt="Gallery page, desktop"> | <img src="docs/screenshots/website/private-events.webp" alt="Private events page, desktop"> |
| **Gift Cards** | **About** |
| <img src="docs/screenshots/website/gift-cards.webp" alt="Gift cards page, desktop"> | <img src="docs/screenshots/website/about.webp" alt="About page, desktop"> |
| **Contact** | **Not found (404)** |
| <img src="docs/screenshots/website/contact.webp" alt="Contact page, desktop"> | <img src="docs/screenshots/website/404.webp" alt="404 page, desktop"> |

</details>

### Table Ordering System (TOS)

A guest's path after scanning the table's QR code: welcome, menu, a dish, and
the order.

<table>
<tr>
<td align="center"><b>1. Welcome</b><br><img src="docs/screenshots/tos/mobile-1-welcome.webp" width="190" alt="Table ordering welcome screen"></td>
<td align="center"><b>2. Menu</b><br><img src="docs/screenshots/tos/mobile-2-menu.webp" width="190" alt="Table ordering menu"></td>
<td align="center"><b>3. Choose options</b><br><img src="docs/screenshots/tos/mobile-3-item.webp" width="190" alt="Dish options"></td>
<td align="center"><b>4. Your order</b><br><img src="docs/screenshots/tos/mobile-4-cart.webp" width="190" alt="The guest's order before sending"></td>
</tr>
</table>

<details>
<summary><b>Desktop and tablet view</b></summary>

| Welcome | Menu |
| --- | --- |
| <img src="docs/screenshots/tos/desktop-1-welcome.webp" alt="Table ordering welcome, desktop"> | <img src="docs/screenshots/tos/desktop-2-menu.webp" alt="Table ordering menu, desktop"> |
| **Choose options** | **Your order** |
| <img src="docs/screenshots/tos/desktop-3-item.webp" alt="Dish options, desktop"> | <img src="docs/screenshots/tos/desktop-4-cart.webp" alt="Order, desktop"> |

</details>

### Kitchen Display System (KDS)

| Kitchen board (TV / desktop) | Tablet |
| --- | --- |
| <img src="docs/screenshots/kds/board.webp" alt="Kitchen display board"> | <img src="docs/screenshots/kds/board-tablet.webp" alt="Kitchen display on a tablet"> |
| **Sign in** (username and password) | |
| <img src="docs/screenshots/kds/login.webp" alt="Kitchen display sign-in"> | |

### Admin panel

| Sign in | Dashboard |
| --- | --- |
| <img src="docs/screenshots/admin/login.webp" alt="Admin sign-in"> | <img src="docs/screenshots/admin/dashboard.webp" alt="Admin dashboard"> |
| **Orders** | **Dine-In Order** (staff ordering) |
| <img src="docs/screenshots/admin/orders.webp" alt="Orders"> | <img src="docs/screenshots/admin/dine-in.webp" alt="Dine-in order"> |
| **QR codes: table codes** | **QR codes: takeaway code** |
| <img src="docs/screenshots/admin/table-qr.webp" alt="Table QR codes"> | <img src="docs/screenshots/admin/table-qr-takeaway.webp" alt="Takeaway QR code"> |
| **Waiter Assistance** | **Reservations** |
| <img src="docs/screenshots/admin/waiter-assistance.webp" alt="Waiter assistance"> | <img src="docs/screenshots/admin/reservations.webp" alt="Reservations"> |
| **Gift cards** | **Messages** |
| <img src="docs/screenshots/admin/gift-cards.webp" alt="Gift cards"> | <img src="docs/screenshots/admin/messages.webp" alt="Messages"> |
| **Subscribers** | **Conversations** |
| <img src="docs/screenshots/admin/subscribers.webp" alt="Subscribers"> | <img src="docs/screenshots/admin/chats.webp" alt="Chat conversations"> |
| **Insights** | **Knowledge** |
| <img src="docs/screenshots/admin/insights.webp" alt="Insights"> | <img src="docs/screenshots/admin/knowledge.webp" alt="Knowledge"> |
| **Chat settings** | **Events** |
| <img src="docs/screenshots/admin/chat-settings.webp" alt="Chat settings"> | <img src="docs/screenshots/admin/events.webp" alt="Events"> |
| **Performers** | **Menu & food** |
| <img src="docs/screenshots/admin/performers.webp" alt="Performers"> | <img src="docs/screenshots/admin/menu.webp" alt="Menu and food"> |
| **Happy Hour** | **Store settings** |
| <img src="docs/screenshots/admin/happy-hour.webp" alt="Happy Hour"> | <img src="docs/screenshots/admin/settings.webp" alt="Store settings"> |
| **Activity log** | **Team accounts** |
| <img src="docs/screenshots/admin/activity.webp" alt="Activity log"> | <img src="docs/screenshots/admin/users.webp" alt="Team accounts"> |
| **Change password** | **On a phone** |
| <img src="docs/screenshots/admin/password.webp" alt="Change password"> | <img src="docs/screenshots/admin/mobile-dashboard.webp" width="190" alt="Admin dashboard on a phone"> <img src="docs/screenshots/admin/mobile-orders.webp" width="190" alt="Admin orders on a phone"> |

---

## How to display the website

Pick the option that fits what you need.

| Option | What works | Needs |
| --- | --- | --- |
| **A. Double-click `Start Website.bat`** | Everything | Windows + XAMPP (or Node.js for a basic preview) |
| **B. PHP built-in server** | Everything: live menu, ordering, gift cards, chat, table ordering, KDS, admin, forms | PHP 7.4+ |
| **C. Static server (Node.js)** | All pages and design; menu from the saved snapshot | Node.js |
| **D. Open `index.html` directly** | Quick look only | Nothing |

### A. Easiest on Windows: double-click the launcher

1. Open the project folder.
2. Double-click **`Start Website.bat`**.
3. Your browser opens **<http://localhost:3456>**.
4. Keep the black window open while you browse. Close it to stop the site.

The launcher uses PHP from XAMPP (`C:\xampp\php\php.exe`) or from your `PATH`,
so everything works. If it can't find PHP, it falls back to Node.js
(option C). It also starts the local MySQL database (below) when it's installed.

### The database (MySQL)

Orders, reservations, messages, newsletter sign-ups, gift cards, chats, waiter
calls, team accounts, the activity log and the admin's settings are kept in
**MySQL 8** (MariaDB 10.2+ also works). On the office PC, MySQL 8.4 lives in
`D:\Adil\MySQL` and both launchers start it. On a new computer:

1. Install MySQL 8 and create a database and a user for it.
2. Create the tables: `mysql -u USER -p DBNAME < api/setup.sql`. (The site also
   applies `setup.sql` by itself whenever the file changes, and adds any new
   columns.)
3. Fill in `db_host` (`127.0.0.1` on Windows), `db_name`, `db_user` and `db_pass`
   in `api/config.php`.

If the database can't be reached, guest orders and messages are kept in
`api/data/unsaved/` and the admin dashboard offers to move them in once it's back.

### B. PHP built-in server (full site)

From the project folder, run:

```bash
php -S localhost:3456 -t . .router.php
```

On Windows with XAMPP:

```bash
C:\xampp\php\php.exe -S localhost:3456 -t . .router.php
```

Then open <http://localhost:3456>. `.router.php` gives the built-in server the
same clean addresses and blocked files as `.htaccess` does on Apache (for example
`/dine-in/table/5`).

- The **menu page** loads the live menu from the restaurant's Square Online store.
- **Ordering, payment and gift cards** also need Square credentials in
  `api/config.php`. Without them, checkout takes cash-on-delivery orders and gift
  cards are taken as requests. See [ORDERING-SETUP.md](ORDERING-SETUP.md).
- **Chat assistant** needs an AI provider configured in `api/config.php`.
- **Admin panel and KDS** need `admin_password` set in `api/config.php`. Open
  <http://localhost:3456/admin/> or <http://localhost:3456/kds/> and sign in with
  the username **admin** and that password.
- **Table ordering:** open <http://localhost:3456/dine-in/table/5> (any table 1–999).
- **Forms** save submissions to the MySQL database (see above). Email
  notifications need a mail server, so they normally only arrive on the live host.

### C. Static server with Node.js (design preview)

```bash
npx serve . -l 3456
```

Then open <http://localhost:3456>. All pages, images, events, sliders and the
gallery display normally. The menu falls back to `data/menu.json`, and forms,
checkout, chat and the admin panel show a friendly "please call us" message,
because they need PHP.

### D. Open the file directly

Double-click `index.html`. The pages display, but browsers block some features
on `file://` pages (the home page's upcoming-events slider, the menu). Use A, B
or C for a real preview.

### Putting it on the internet

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** and
**[docs/GO-LIVE.md](docs/GO-LIVE.md)**. In short: upload the site files to a PHP
host (cPanel or similar), create `api/config.php`, and turn on HTTPS.
`Make Upload Package.bat` builds the ready-to-upload folder.

---

## Pages

| Page | Address | Highlights |
| --- | --- | --- |
| Home | `index.html` | Hero slider, upcoming-events slider, awards, Google reviews, Instagram |
| Menu & ordering | `menu.html` | Live Square menu, Happy Hour, cart, pickup/delivery, tips, payment |
| Events | `events.html` | Weekly lineup with day tabs (opens on today) |
| Entertainment | `entertainment.html` | Shows and performers |
| Performers | `performers.html` | Performer profiles (managed in the admin) |
| Meet Our Dancers | `dancers.html` | Dancer roster |
| Private Events | `private-events.html` | Event hosting and enquiries |
| Gallery | `gallery.html` | Instagram feed with "See More Posts" paging |
| Gift Cards | `gift-cards.html` | Buy a gift card: ready-made designs or your own photo and words, any amount within limits, deliver now or on a chosen date |
| About | `about.html` | Story, awards |
| Contact | `contact.html` | Contact and reservation forms, map, hours, careers |
| Not found | `404.html` | Branded error page |
| Table ordering | `/dine-in/table/N` | Guests at table N order or call a server (see [TOS](#table-ordering-system-tos)) |
| Kitchen display | `/kds/` | Live order board for the kitchen (see [KDS](#kitchen-display-system-kds)) |
| Admin panel | `/admin/` | The team's back office (see [Admin panel](#admin-panel)) |

---

## Online ordering system

The menu page (`menu.html` + `js/order.js` + `api/checkout.php`) provides a
complete ordering flow:

### How it works

1. **Menu loads** from the restaurant's Square Online store via `api/menu.php`.
   The menu is cached for 15 minutes. If Square is unreachable, it falls back to
   `data/menu.json`. The admin's changes (hidden items, prices, scheduled
   specials) are applied on top.
2. **Guest browses** the menu by category, taps items to add them to the cart.
   Items with options (sizes, extras) show a modal for choosing.
3. **Cart** is saved in `localStorage` (`rms-cart-v1`) and persists across
   page refreshes within the same browser.
4. **Fulfillment:** guest chooses **Pickup** (the default) or **Delivery**. Each
   can be switched off in Store settings.
   - Pickup: must be during kitchen hours and meet the pickup minimum.
   - Delivery: must be during delivery hours, the ZIP must be in the allowed
     list, and the subtotal must meet the delivery minimum.
5. **Per-item rules** (set in the Admin Panel):
   - **Pickup only:** item cannot be ordered for delivery.
   - **Delivery only:** item cannot be ordered for pickup.
   - **Delivery surcharge:** extra fee added to the delivery total for that item.
   - **Prep time:** overrides the default prep time for that item.
   - **Scheduled specials:** shown only on chosen days, times or dates.
6. **Checkout:** the server re-prices every item from the live menu (tampered
   prices are ignored), calculates tax, delivery fee and tip, and charges the
   card through Square Web Payments SDK.
7. **Cash on delivery mode:** when no Square payment credentials are configured,
   the system switches to cash-on-delivery: no card charge, guests pay in cash
   on pickup or delivery. Tax is added in person.
8. **Takeaway QR code:** Admin → QR codes → Takeaway code prints a card whose
   code opens this ordering page, for the bar, the front door or flyers.

Every order (website, table, staff) appears on the [KDS](#kitchen-display-system-kds)
and in Admin → Orders.

### Key safety checks (server-side)

- Every item and option is re-priced from the live menu.
- Items no longer on the menu are rejected.
- Orders are capped at 50 lines.
- Required options are enforced.
- Pickup-only items are blocked from delivery orders.
- Delivery-only items are blocked from pickup orders.
- Pickup or delivery orders are refused when that option is switched off.
- The `idempotencyKey` prevents double-charging on retries.

---

## Gift cards

The **Gift Cards** page (`gift-cards.html` + `js/gift-cards.js` +
`api/gift-cards.php`, logic in `api/gift-cards-lib.php`) sells gift cards online.

- **Designs:** choose one of the restaurant's designs, or a **custom card** with
  the buyer's own photo and words (the admin can switch custom cards off).
- **Amount:** preset amounts or a custom amount within the limits set in the admin.
- **Delivery:** emailed to the recipient now, or on a chosen date, with a
  personal message. Each card has its own page where the recipient sees the
  card, its code and the balance.
- **With Square set up:** the buyer pays by card, and the card is created in
  Square (Gift Cards API), so it can be redeemed on the Square POS.
- **Without Square payments:** the page takes a *request*. The team calls the
  buyer, takes payment, then presses **Mark paid & activate** in the admin and
  the card is emailed.
- **Admin → Gift cards:** list and search cards, redeem what a guest spent,
  adjust or void a card, resend the email, change the recipient, **issue a card**
  for any amount (sold at the bar, comps, prizes), and edit designs and settings.

Every change to a card is recorded in `gift_card_activity`.

---

## Table Ordering System (TOS)

Each table has its own address, **`/dine-in/table/N`** (tables 1–999), opened by
scanning the QR code on the table (`dine-in/index.php` +
`dine-in/assets/dine-in.js`).

1. The guest enters their **first name**.
2. **Order by Yourself** opens the menu (the same live menu and scheduled
   specials as the website), with options and special instructions per dish.
3. **Place Order** sends the order (`api/dine-in-order.php`) straight to the
   kitchen display and Admin → Orders as **dine-in, table N**, with the guest's name.
4. **Call the Waiter** (`api/waiter-call.php`) shows a request with a live
   timer in Admin → **Waiter Assistance**. Each table can have only one open
   request at a time, and staff resolve it once they have attended.

The cart lives only in the page's memory (no `localStorage`), so the next guest
at the table never sees the last guest's order. Busy-bar limits cap how much one
table can send at once.

**QR codes:** Admin → **QR codes** prints a branded card for each table (4 per
page, or 1 per page for table tents), plus a single **takeaway** code. The QR
codes are generated inside the admin (`admin/inc/qr.php`), with no outside
service, and each downloads as an SVG for a print shop. If the site's address
changes, update **Website address** on that page and reprint.

---

## Kitchen Display System (KDS)

The kitchen's live board at **`/kds/`** (`kds/index.php` + `kds/assets/kds.js`,
data from `kds/api.php` / `api/kds.php`). It suits a kitchen TV, a desktop or a
tablet.

- **Sign in** with a username and password: the owner account, or any team
  account (Admin, Staff or **Kitchen**). The header shows who is signed in.
- **Every order type in one place:** website pickup and delivery, table
  (dine-in) orders, and staff orders from Admin → Dine-In Order. The board
  refreshes every 3 seconds.
- **Ticket flow:** each ticket shows the type, table or guest name, items with
  options and notes. **Start** begins a prep timer and **Complete** finishes the
  ticket. A ticket that runs past its prep time turns **Overdue**.
  **Recall** brings a completed ticket back.
- **Counters** for Queue, Preparing, Overdue and Done; **filters** (active, by
  type, queue, preparing, overdue, completed) and **sort** (oldest or newest first).
- **Settings:** prep time for dine-in, delivery and pickup orders, and sound
  notifications for new orders.
- Every start and complete is written to the Activity log under the signed-in
  username.

---

## AI chat assistant

The "Ask us" floating chat button appears on every page (`js/chat.js` +
`api/chat.php`). It answers guests' questions in natural language. Its look and
behaviour are set in Admin → **Chat settings**.

### What it knows

| Topic | Source | How to update |
| --- | --- | --- |
| Hours, policies, reservations, private events, FAQs | `data/chatbot-knowledge.md` | Admin → Knowledge (or edit the file) |
| Weekly show lineup | Events | Admin → Events |
| Happy Hour and drinks | Happy Hour menu | Admin → Happy Hour |
| Food menu, prices, pickup/delivery | Square + admin changes | Square, Admin → Menu & food |
| Current time and tonight's shows | Injected per request | Automatic |

### How it works

- The browser sends the full conversation (last 12 messages, each capped at
  600 characters). The server keeps no chat state.
- The system prompt is assembled on every request. Core rules and FAQ are
  always included. The weekly lineup, drinks and food menu are added only when
  relevant (keyword matching), keeping token usage low (1,700-4,000 tokens).
- **AI providers** (configured in `api/config.php`):
  - **Free:** Groq, Gemini, Mistral (rate-limited free tiers)
  - **Local:** Ollama (for development and testing)
  - **Paid:** Claude (Anthropic API, needs PHP 8.1+ and Composer)
  - Fallback chain: if one provider fails, the next in `chat_fallback` is tried.

### Food ordering in the chat

When the food menu is relevant, the AI can use the `add_to_order` tool to add
dishes to the guest's cart directly from the conversation. The tool matches
dish, size and option names against the live menu. Anything missing or ambiguous
is asked back. The chat adds items to the same cart as the menu page, and
"Review & pay" opens the normal checkout. Nothing is charged in the chat.

### Cards and suggestions

Replies include interactive cards (show cards with poster, time and host;
party package cards) and up to three follow-up suggestion buttons. Cards are
built from the site's own pages, not from the AI's text.

### Protections

- Same-site JSON only, 30 messages per visitor per 10 minutes.
- `chat_daily_limit` caps total messages per day for the whole site (default
  500), preventing spam from running up the bill.
- Chat history is saved in the database (`chat_sessions` and `chat_messages`)
  for Admin → Conversations and Insights.

---

## Admin panel

A web app at **`/admin/`** for the restaurant team, and the one place to see what
guests sent the website and to control what it shows. Sign in with a
**username and password** (see [Team accounts](#team-accounts-and-roles)). It works
on phones, tablets and foldables too, and every setting has an **(i)** help tip
(hover on a computer, tap on a phone). The full guide is
**[docs/ADMIN.md](docs/ADMIN.md)**.

| Section | What it does |
| --- | --- |
| **Dashboard** | What needs attention now (new orders, reservation requests, unread messages), the last two weeks, what the website is showing right now, setup checks, recent activity (admins only) |
| **Orders** | Every order as it comes in: one-tap status (New → Preparing → Ready → Completed), team notes, printable ticket, sales totals, spreadsheet export |
| **Dine-In Order** | Staff place dine-in (table number), takeaway or delivery (phone and address) orders straight to the kitchen display, from the full menu or one category |
| **QR codes** | **Table codes:** a printable card per table that opens `/dine-in/table/N`. **Takeaway code:** one code, as many copies as you like, that opens online ordering for pickup. Both sizes print cleanly (4 per page or 1 per page) and download as SVG |
| **Waiter Assistance** | Live "Call the Waiter" requests from tables, with timers; resolve each when attended |
| **Reservations** | Table requests from the reservation form: confirm or decline with a ready-made email, notes, upcoming and past views |
| **Gift cards** | Online sales and requests, activate and email, redeem, adjust or void, issue a card, designs and settings |
| **Messages** | The contact-form inbox: unread, replied, archived; reply by email; delete spam |
| **Subscribers** | Newsletter sign-ups: search, export, remove |
| **Conversations** | Every chat with the assistant, with returning-guest and reservation-request flags, a follow-up flag and notes, and a spreadsheet export |
| **Insights** | What guests ask and order in the chat, and how orders are going (sales, busiest times, best sellers) |
| **Knowledge** | Edit what the chat assistant knows (`data/chatbot-knowledge.md`), with one-click restore of earlier versions |
| **Chat settings** | The chat's look and behaviour: on/off, button, auto-open, welcome text, the details form, suggested questions, with a live preview |
| **Events** | Add weekly or one-night events with a poster (a size guide and cropping editor), host, description and tags, then publish them to the Events page and the home page slider |
| **Performers** | The performer profiles shown on the website |
| **Menu & food** | Hide items, change prices (with the "Last price" before each change), pickup-only or delivery-only, delivery surcharge, prep time, edit descriptions and options, add your own dishes, and **schedule specials** to show only on chosen days, times or dates |
| **Happy Hour** | The Happy Hour menu ("Add a new item", "Last price"), its times and days, and the scrolling ticker |
| **Store settings** | Business details, hours, delivery area and fees, Take Away and Delivery on/off |
| **Activity log** *(admins)* | Who signed in and exactly what they changed ("Wings: price $6.99 → $7.49"), with the username, a filter by person, and optional email alerts for every change |
| **Team accounts** *(admins)* | Add, edit, switch off or delete team members, set their role, reset passwords |
| **Change password** *(admins)* | The owner's team password (also used by the KDS), or an admin's own password |

The sidebar shows live counts of new orders, reservation requests and messages,
and a bell shows alerts as they arrive. Statuses, notes and flags that the team
adds are kept beside the guests' records (an order, reservation or message is
never edited), and every change is logged. `api/chats.php`, the old address of
the chat history page, forwards to the panel, so existing bookmarks keep working.

---

## Team accounts and roles

Each person gets their own **username and password** (Admin → Team accounts), so
the Activity log shows exactly who did what.

| Role | Admin panel | Kitchen display | Activity log, Team accounts, Change password |
| --- | --- | --- | --- |
| **Admin** | Yes | Yes | Yes |
| **Staff** | Yes | Yes | No (hidden, and refused if the address is typed in) |
| **Kitchen** | No (refused at sign-in) | Yes | No |

- **Only admins** create, edit, delete or switch off accounts and change
  passwords, including Staff and Kitchen passwords.
- Changing someone's password, role or on/off state signs them out at once.
- The **owner account** (username `admin` + the team password from
  `api/config.php` or Change password) always works and isn't listed, so nobody
  can be locked out.
- Passwords are stored as hashes in the `admin_users` table; the code is in
  `api/team-users.php`. Ten failed sign-ins in 15 minutes lock sign-in for a while.

---

## Project structure

```
rms/
├── *.html                 Site pages (edit these directly; events.html's lineup is written by the admin)
├── admin/                 Admin panel (see docs/ADMIN.md)
│   ├── index.php          Sign-in, routing (?page=orders …), admin-only page guard
│   ├── assets/            admin.css, admin.js (help tips, live counts), events.js (poster editor),
│   │                      dine-in.js, chat-settings.js, performers.js, waiter-assistance.js
│   └── inc/               Shared code (auth, layout, ui, store, qr.php QR generator),
│                          plus one file per section in pages/
├── dine-in/               Table Ordering System: index.php (/dine-in/table/N) and assets/
├── kds/                   Kitchen Display System: index.php, api.php and assets/
├── css/style.css          All styling, including icons (inline SVG)
├── js/                    Page scripts (no libraries)
│   ├── main.js            Shared: header, mobile nav, hero slider, lazy load, forms
│   ├── order.js           Ordering flow: menu, cart, checkout, payment
│   ├── gift-cards.js      Gift card designer and purchase
│   ├── chat.js            AI chat assistant: button, panel, cards, food-to-cart
│   ├── events.js          Weekly lineup day tabs
│   ├── upcoming.js        Home page upcoming-events slider
│   ├── instagram-more.js  Gallery "See More Posts" paging
│   └── elfsight-badge.js  Elfsight widget badge cleanup
├── images/                WebP images: site/, events/, dancers/, giftcards/, awards, hero
├── fonts/                 Self-hosted Inter + Playfair Display (woff2)
├── data/
│   ├── menu.json          Menu snapshot (backup if Square is unreachable)
│   └── chatbot-knowledge.md  FAQ and facts for the chat assistant
├── api/                   PHP backend
│   ├── config.example.php Template for the private api/config.php
│   ├── config.php         (private, git-ignored) Square keys, AI keys, admin password
│   ├── lib.php            Shared helpers: config, Square API, JSON, rate limiting, CSV, email, caching
│   ├── db.php             MySQL connection, query helpers, automatic schema updates
│   ├── setup.sql          Creates the database tables
│   ├── menu.php           GET — live menu from Square (cached 15 min)
│   ├── menu-overrides.php Local menu adjustments and store settings
│   ├── checkout.php       POST — validate cart, calculate totals, charge card or take COD order
│   ├── gift-cards.php     Gift card purchase, request and lookup (public)
│   ├── gift-cards-lib.php Gift card settings, codes, Square Gift Cards API, emails
│   ├── dine-in-order.php  POST — table orders from the TOS
│   ├── waiter-call.php    POST — "Call the Waiter" from the TOS
│   ├── kds.php            Kitchen display data and actions
│   ├── team-users.php     Team accounts, roles, sign-in and sessions
│   ├── chat.php           POST — AI chat assistant endpoint
│   ├── chat-claude.php    Claude (Anthropic API) provider for the chat
│   ├── chat-order.php     add_to_order tool: match dishes to live menu, build cart
│   ├── chat-cards.php     Show/package cards and follow-up suggestions
│   ├── chat-settings.php  Chat look and behaviour (from Admin → Chat settings)
│   ├── chat-log.php       Chat logging to the database
│   ├── chat-insights.php  Analytics: topic/food/show/time breakdowns from logs
│   ├── chats.php          Old chat-history address; forwards to admin/
│   ├── happy-hour.php     Happy Hour data read/write
│   ├── contact.php        POST — contact and reservation forms
│   ├── subscribe.php      POST — newsletter sign-up (database + Square Customers)
│   ├── cache/             Auto-created: menu cache, rate-limit counters
│   └── data/              Auto-created: sign-in sessions, admin working files, unsaved/ backup
├── docs/                  Developer documentation
│   ├── screenshots/       README screenshots: website/, tos/, kds/, admin/
│   ├── ADMIN.md           Admin panel guide
│   ├── ARCHITECTURE.md    How the site is built
│   ├── CONTENT-GUIDE.md   How to change events, dancers, photos, text, hours, menu
│   ├── API.md             PHP endpoint reference
│   ├── DEPLOYMENT.md      How to publish and update the live site
│   └── GO-LIVE.md         One-page go-live guide for hosting.com (goes in the upload package)
├── .htaccess              Apache: security, caching, clean URLs (/dine-in/table/N), 404 page
├── .router.php            The same rules for PHP's built-in server
├── robots.txt
├── ORDERING-SETUP.md      Square ordering setup guide for the owner
├── Start Website.bat      Windows one-click launcher (also starts the local MySQL)
├── Make Upload Package.bat  Builds the hand-off folder for the web developer (docs/GO-LIVE.md)
└── Save to GitHub.bat     Windows one-click git commit and push
```

---

## Tech at a glance

| Layer | Technology |
| --- | --- |
| **Front end** | HTML5, one CSS file, vanilla JavaScript (ES2017+). No build tools or npm packages. |
| **Back end** | PHP 7.4+ with `curl`, `mbstring` and `pdo_mysql`. MySQL 8 (or MariaDB 10.2+) holds orders, reservations, messages, gift cards, chats, team accounts and settings; the menu comes from `data/menu.json` or Square. |
| **Ordering & gift cards** | Square (menu feed, orders, payments, gift cards, customer directory). Card details go straight into Square's hosted field and never touch this server. |
| **QR codes** | Generated in PHP inside the admin (ISO 18004, byte mode, error correction M), output as SVG. No outside service. |
| **AI Chat** | Groq, Gemini, Mistral (free), Ollama (local), or Claude (paid). Tool-use for food ordering. |
| **Widgets** | Elfsight (Google reviews and Instagram feed). |
| **Devices** | Responsive from 260 px (flip-phone cover screens) to wide desktops, including foldables and dual-screen phones. |
| **Performance** | WebP images in 640/1024/1600 widths, lazy loading, self-hosted fonts, long-lived caching with `?v=` cache-busting. |

---

## Security

- `api/.htaccess` blocks `config.php`, `lib.php`, `db.php`, `team-users.php`,
  `gift-cards-lib.php`, `cache/` and `data/` from the web, and the root
  `.htaccess` blocks `setup.sql`. `.router.php` applies the same rules locally.
- The root `.htaccess` blocks `.md` files, dot-files and the `.git` folder,
  disables directory listings and sets security headers.
- The endpoints only accept same-site JSON POSTs and are rate-limited.
- Checkout and table orders **re-price every item on the server** from the live
  menu, so a tampered browser can't change prices.
- Card details go straight into Square's hosted field and never touch this
  server.
- The admin panel and KDS need a username and password, use PHP session cookies
  (`HttpOnly`, `SameSite`), sign people out when their password, role or account
  changes, put a CSRF token on every admin form, rate-limit sign-in attempts
  (10 per 15 minutes), and send `noindex`, no-cache and Content-Security-Policy
  headers. Role checks run on the server for every page and form, not only in
  the menu. Uploaded posters are checked to be real 480 × 600 images.
- All guest text in the admin panel is HTML-escaped, and spreadsheet downloads
  neutralise formulas.
- Chat conversations need no account: guests give an email (and optionally a
  name) before chatting, and their IP address, device and browser are saved with
  the chat. Chats stay in the database until they are deleted.

---

## Configuration reference

All configuration lives in `api/config.php` (not committed to git). Copy
`api/config.example.php` and fill in the values.

| Key | Purpose | Required |
| --- | --- | --- |
| `db_host`, `db_port` | MySQL server (`localhost` on cPanel, `127.0.0.1` on Windows; port 3306) | Yes |
| `db_name`, `db_user`, `db_pass` | The MySQL database and the user the site connects as | Yes |
| `environment` | `sandbox` (test) or `production` (real money) | For payments |
| `application_id` | Square Application ID | For payments |
| `access_token` | Square access token (secret) | For payments and gift cards |
| `location_id` | Square location ID | For payments |
| `menu_cache_mins` | Menu cache duration in minutes (default 15) | No |
| `notify_email` | Where contact/reservation emails are sent | For email |
| `from_email` | Sender address (use the site's domain) | For email |
| `data_dir` | Sign-in sessions, admin working files and the database-down backup (default `api/data/`) | No |
| `chat_provider` | AI provider: `groq`, `gemini`, `mistral`, `ollama`, `claude` | For chat |
| `chat_fallback` | Array of fallback providers tried in order | No |
| `groq_api_key` | Groq API key | For Groq chat |
| `gemini_api_key` | Gemini API key | For Gemini chat |
| `mistral_api_key` | Mistral API key | For Mistral chat |
| `anthropic_api_key` | Anthropic API key | For Claude chat |
| `claude_model` | Claude model name | For Claude chat |
| `chat_log` | Enable/disable chat logging (default on) | No |
| `chat_log_days` | Days to keep chat logs (default 120) | No |
| `chat_daily_limit` | Max messages per day site-wide (default 500) | No |
| `admin_password` | The owner's team password for the admin panel and KDS (username `admin`), 12+ characters (empty = both disabled). The older name `chat_admin_password` still works | For admin and KDS |

Settings changed in the admin (store settings, gift cards, chat settings, Happy
Hour, the QR codes' website address) are saved in the database's `settings`
table, not in `config.php`.

---

## Working with this repository

```bash
git clone https://github.com/oceanadil12-cyber1/rms.git
```

Then start it with one of the options in
[How to display the website](#how-to-display-the-website).

### Saving your work to GitHub

- **Working with Claude Code:** changes are committed and pushed automatically
  every time Claude finishes a reply. A user-level Stop hook in
  `~/.claude/hooks/rms-auto-push.sh` does this.
- **Editing by hand:** double-click **`Save to GitHub.bat`** to commit and push everything.
- Either way, `api/config.php` and customer data (`api/data/`) are never uploaded.
- Releases are tagged (`v6.2`, `v6.1` …); see [Version history](#version-history).

After editing `css/style.css` or any `js/` file, bump the `?v=YYYYMMDD` value
where the pages link to it, so visitors get the new version (see
[docs/CONTENT-GUIDE.md](docs/CONTENT-GUIDE.md#cache-busting)).

---

## Documentation index

| Document | Read it when you want to... |
| --- | --- |
| **This README** | understand the whole project: scope, features, screenshots, architecture |
| [docs/ADMIN.md](docs/ADMIN.md) | use or extend the admin panel: orders, gift cards, QR codes, team accounts, events and posters, menu, settings |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | understand how the site is built, page by page and script by script |
| [docs/CONTENT-GUIDE.md](docs/CONTENT-GUIDE.md) | change events, dancers, photos, text, hours or the menu |
| [docs/API.md](docs/API.md) | work on the PHP endpoints (menu, checkout, gift cards, chat, forms) |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | publish or update the live site |
| [docs/GO-LIVE.md](docs/GO-LIVE.md) | hand the site to the web host (hosting.com) |
| [ORDERING-SETUP.md](ORDERING-SETUP.md) | connect Square payments (restaurant owner's steps) |

---

## Version history

| Version | Date | Highlights |
| --- | --- | --- |
| **v6.2** | 2026-11-04 | **Gift cards** (online sales with ready-made or custom designs, scheduled delivery, Square Gift Cards, admin activate/redeem/issue); **team accounts** with usernames and roles (Admin, Staff, Kitchen) on the admin and KDS, admin-only pages, usernames in the Activity log; **QR codes** for every table and a takeaway code, generated in the admin; every page optimised for phones, tablets, foldables, flip phones and dual-screen devices; new help tips across the admin; header logo fix; screenshots of every page in this README |
| **v6.1** | 2026-11-03 | Scheduled specials: menu items can show only on chosen days, between set times or for a date range (hidden everywhere else: website, checkout, table ordering, staff orders, chat); Happy Hour days (e.g. Mon–Fri) |
| **v6.0** | 2026-11-03 | Change password, Take Away on/off, staff takeaway and delivery orders, detailed activity log with email alerts, "Last price", Happy Hour "Add a new item", new drinks menu |
| **v5.2** | 2026-10-16 | Upload-and-go hand-off (any folder, database connected from the admin, one-click on cPanel), full QA fixes (online ordering restored, guest order email, busy-bar table limits), chat opens by itself, SEOBERG credits, Admin → Chat settings |
| **v5.1** | 2026-10-14 | MySQL database, hosting.com upload package, QA fixes, phone and tablet admin layout |
| **v5.0** | 2026-10-05 | Admin notifications, dine-in guest name, data protection |
| **v4.0** | 2026-10-02 | MySQL database, migration and admin overhaul |
| **v3.0** | 2026-10-01 | AI chatbot, admin panel and KDS (kitchen display) |
| **v2.0** | 2026-09-29 | AI Chat & Admin Panel [Web App] — delivery-only fulfillment rule, comprehensive project documentation |
| **v1.0** | 2026-09-26 | First official version: all pages, Square ordering, AI chat, admin panel |

---

This is a **private** repository. All rights reserved.
RMS.
