# Architecture

How the RMS website is put together.

## Overview

```
Browser ──► static pages (HTML/CSS/JS/images)          served as plain files
   │
   ├──► api/menu.php ──► Square Online store feed      live menu (cached 15 min)
   │         └─ fallback: data/menu.json
   ├──► api/checkout.php ──► Square Orders + Payments API
   ├──► api/contact.php ──► MySQL (contacts, reservations) + email
   ├──► api/subscribe.php ──► MySQL (subscribers) + Square Customers
   ├──► api/chat.php ──► Ollama (local testing) or Claude API (live)
   │         └─ facts: data/chatbot-knowledge.md + events.html + menu.html + Square menu
   │
   ├──► admin/ (the team's panel, password) ──► MySQL, api/data/*, events.html, images/events/
   │
   ├──► Square Web Payments SDK (card field, loaded on the menu page)
   └──► Elfsight widgets (Google reviews, Instagram feed)
```

There is **no build step**. What's in the repository is exactly what gets
uploaded to the server.

## Pages

Every page is a standalone HTML file with the same header, navigation and
footer, copied into each page. **Change the header or footer on every page**
when you edit them.

| File | Scripts loaded | Notes |
| --- | --- | --- |
| `index.html` | `main.js`, `elfsight-badge.js`, `upcoming.js` | Hero slider; upcoming-events slider built from `events.html` |
| `menu.html` | `main.js`, `order.js` | Ordering UI renders into `#order-menu` |
| `events.html` | `main.js`, `events.js` | The single source of the weekly lineup (its tabs, day panels and week-at-a-glance are written by Admin → Events) |
| `gallery.html` | `main.js`, `instagram-more.js` | Instagram feed through Elfsight |
| `contact.html` | `main.js` | Forms post to `api/contact.php` |
| other pages | `main.js` | Static content |

## JavaScript (`js/`)

| File | Purpose |
| --- | --- |
| `main.js` | Shared by every page: header style on scroll, mobile nav, hero slider, lazy loading for background images and Elfsight widgets, and AJAX form submission (any `<form data-endpoint="…">`) |
| `order.js` | The whole ordering flow: menu, item options, cart (saved in `localStorage` as `rms-cart-v1`), pickup/delivery, tip, quote and Square card payment |
| `events.js` | Turns the weekly lineup on `events.html` into day tabs and opens on today's day in restaurant time (America/New_York). It also removes one-night events (`data-date`) once their night is over and fixes the tab counts |
| `upcoming.js` | Home page slider. It fetches `events.html`, reads the lineup and shows events starting with the next one (a one-night event only on its own date). It pauses on hover and respects reduced motion |
| `instagram-more.js` | Gallery paging: shows 20 posts, then 8 more per click |
| `elfsight-badge.js` | Tidies the Elfsight widget badge |
| `chat.js` | Loaded on every page. Adds the "Ask us" chat button and panel, which post to `api/chat.php`. (It can also sit inside a page: add an empty `<div data-chat-embed>` and optional `[data-ask]` buttons. No page uses this at the moment.) It renders cards (shows, party packages, food order, "Talk to our team") and follow-up suggestions from the reply. The conversation is kept for the browser tab (`sessionStorage`, `rms-chat-v1`). Dishes ordered in the chat go into the same cart as `order.js` (through `window.rmsOrder`, or `localStorage` on other pages) |

All scripts are plain ES2017+ with no dependencies. Each one checks for its
container element and exits quietly on pages that don't have it.

## CSS (`css/style.css`)

One stylesheet for the whole site:

- `@font-face` rules for the self-hosted fonts in `fonts/`
- the layout and components for every page
- **icons**: classes like `fas fa-phone` are *not* Font Awesome. Each one is
  defined near the end of the file as an inline SVG mask. To add an icon, copy
  an existing rule and paste in the new SVG path.

## Images (`images/`)

| Folder | Contents |
| --- | --- |
| `images/site/` | Photos in three widths (`-640`, `-1024`, `-1600`) used with `srcset` |
| `images/events/` | Event posters (480×600) |
| `images/dancers/` | Dancer photos (600×600) |
| `images/` | Logo, hero banners, award badges, favicons |

All photos are **WebP**. Keep the `width`/`height` attributes on `<img>` tags
so the page doesn't jump while images load.

## Back end (`api/`)

PHP with no framework, and a MySQL 8 database (MariaDB 10.2+ also works). See
[API.md](API.md) for each endpoint.

- `lib.php` holds the shared helpers: config loading, Square requests, JSON
  responses, same-site checks, rate limiting, saving records (`save_row`, with a
  backup file when the database is down), email and caching.
- `db.php` opens the MySQL connection (PDO, utf8mb4, session time zone set to
  New York so stored times match the site) and has small insert/update/settings
  helpers. `setup.sql` creates the tables; it is safe to run again.
- `config.php` (private, not in git) holds the Square credentials and email settings.
- `cache/` is created automatically for the menu cache and rate-limit counters.
- `data/` is created automatically for sign-in sessions, the admin's working
  files (events, performers, backups) and `unsaved/`, where guest records wait
  if the database can't be reached.

## Admin panel (`admin/`)

The team's control panel, at `/admin/`: one PHP entry point (`admin/index.php`),
one file per section in `admin/inc/pages/`, and shared code in `admin/inc/`. It
reuses the `api/` libraries, so it edits exactly what the website reads (menu
overrides, store settings, Happy Hour) and reads exactly what the website writes
(orders, form submissions, chats). The guests' own words are never edited; the
team's statuses and notes are saved in their own columns of the same rows. The **Events** section is the one
place that writes into the site itself: it regenerates the lineup in `events.html`
between two comment markers (the day tabs, the home slider and the chat assistant
all read that markup) and saves poster pictures in `images/events/`. The panel
has its own stylesheet and scripts in `admin/assets/` and is documented in
[ADMIN.md](ADMIN.md).

## Security

- `api/.htaccess` blocks `config.php`, `lib.php`, `cache/` and `data/` from the web.
- The root `.htaccess` blocks `.md` files, dot-files and the `.git` folder,
  disables directory listings and sets security headers.
- The endpoints only accept same-site JSON POSTs and are rate-limited.
- Checkout **re-prices every cart item on the server** from the live menu, so
  a tampered browser can't change prices.
- Card details go straight into Square's hosted field and never touch this server.
- The admin panel needs a password, checks a CSRF token on every save, escapes
  everything guests typed, and sends no-index, no-cache and
  Content-Security-Policy headers (details in [ADMIN.md](ADMIN.md#security)).
