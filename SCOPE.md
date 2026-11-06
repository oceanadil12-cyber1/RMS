# Project Scope — RMS Website & Web App

**Version:** 3.0
**Date:** October 1, 2026
**Client:** RMS
**Location:** 2266 Wilton Drive, Wilton Manors, FL 33305
**Repository:** Private — github.com/oceanadil12-cyber1/rms

---

## 1. Project Overview

A complete website and web application for RMS — a
restaurant, bar, and live entertainment venue in Wilton Manors, South Florida.

The project delivers three integrated systems:

1. **Public Website** — a fast, hand-built site for guests (menu, events,
   entertainment, reservations, contact, gallery)
2. **AI Chat Assistant** — a conversational assistant on every page that answers
   guest questions and can take food orders
3. **Admin Panel** — a password-protected back office for the restaurant team to
   manage orders, guests, events, and site content
4. **Kitchen Display System (KDS)** — a real-time order display for kitchen staff

**Tech stack:** Plain HTML, CSS, vanilla JavaScript (no build step, no framework).
PHP 7.4+ backend with a MySQL 8 database (MariaDB 10.2+ also works) for orders,
reservations, messages, newsletter sign-ups, chats, the activity log and settings.
Menu data and page content stay in files (JSON, Markdown, HTML).

---

## 2. Public Website

### 2.1 Pages

| Page | File | Description |
| --- | --- | --- |
| **Home** | `index.html` | Hero image slider, upcoming-events slider, awards section, Google reviews widget, Instagram feed |
| **Menu & Ordering** | `menu.html` | Live menu from Square, full cart and checkout, pickup/delivery, card or cash payment |
| **Events** | `events.html` | Weekly entertainment lineup with day tabs (opens on today), one-night specials with auto-expiry |
| **Entertainment** | `entertainment.html` | Shows and performers overview |
| **Performers** | `performers.html` | Individual performer profiles with photos and show schedules |
| **Dancers** | `dancers.html` | Dancer roster with photos |
| **Gallery** | `gallery.html` | Instagram feed with paginated "See More Posts" loading |
| **Private Events** | `private-events.html` | Event hosting packages and enquiry information |
| **About** | `about.html` | Venue story and award recognitions |
| **Contact** | `contact.html` | Contact form, reservation request form, interactive map, hours |
| **404** | `404.html` | Branded error page |

### 2.2 Design & Performance

- **Responsive:** mobile-first, works on phones, tablets, and desktop
- **Images:** all WebP format, served in 3 widths (640/1024/1600) via `srcset`
- **Fonts:** self-hosted Inter and Playfair Display (woff2, no external requests)
- **Icons:** inline SVG masks (not Font Awesome), defined in `css/style.css`
- **Lazy loading:** images and Elfsight widgets load on scroll
- **Caching:** long-lived browser cache with `?v=YYYYMMDD` cache-busting
- **No build step:** what is in the repository is exactly what is uploaded to the server

### 2.3 Third-Party Widgets

- **Elfsight:** Google reviews widget and Instagram feed
- **Square Web Payments SDK:** hosted payment field on the menu page (card details never touch the server)

---

## 3. Online Ordering System

**Files:** `menu.html`, `js/order.js`, `api/menu.php`, `api/checkout.php`, `api/menu-overrides.php`

### 3.1 Menu

- Live menu fetched from the restaurant's Square Online store via `api/menu.php`
- Cached for 15 minutes; falls back to `data/menu.json` if Square is unreachable
- Categories, items with photos, sizes/variations, and modifier options
- Admin can hide items, change prices, edit descriptions, and add custom dishes

### 3.2 Cart & Checkout Flow

1. Guest browses menu by category, taps items to add to cart
2. Items with options (sizes, extras) show a selection modal
3. Cart persists in `localStorage` across page refreshes
4. Guest chooses **Pickup** or **Delivery** fulfillment
5. Delivery validates ZIP code against allowed area, enforces minimum subtotal
6. Server re-prices every item from the live menu (tamper-proof)
7. Tax, delivery fee, and tip are calculated server-side
8. Card payment processed through Square Web Payments SDK
9. **Cash-on-delivery mode:** when no Square payment credentials are configured, the system accepts orders without card payment — guests pay in cash

### 3.3 Per-Item Rules (set via Admin Panel)

- **Pickup only:** item cannot be ordered for delivery
- **Delivery only:** item cannot be ordered for pickup
- **Delivery surcharge:** extra fee for delivery orders
- **Custom prep time:** overrides the default preparation time

### 3.4 Server-Side Safety

- Every item and option re-priced from the live menu
- Items no longer on the menu are rejected
- Orders capped at 50 line items
- Required options enforced
- Fulfillment type restrictions enforced
- Idempotency key prevents double-charging on retries

---

## 4. AI Chat Assistant

**Files:** `js/chat.js`, `api/chat.php`, `api/chat-claude.php`, `api/chat-order.php`, `api/chat-cards.php`, `api/chat-log.php`, `api/chat-insights.php`, `data/chatbot-knowledge.md`

### 4.1 Overview

A floating "Ask us" chat button appears on every page. The assistant answers
guest questions about hours, events, the menu, reservations, private events,
and more in natural language.

### 4.2 Knowledge Sources

| Topic | Source | Update method |
| --- | --- | --- |
| Hours, policies, reservations, FAQs | `data/chatbot-knowledge.md` | Admin Panel → Knowledge |
| Weekly show lineup | `events.html` | Admin Panel → Events |
| Happy Hour and drinks | `menu.html` | Edit page / Admin |
| Food menu, prices, pickup/delivery | Square | Change in Square |
| Current time and tonight's shows | Injected per request | Automatic |

### 4.3 AI Providers

- **Free tier:** Groq, Gemini, Mistral (rate-limited)
- **Local:** Ollama (for development and testing)
- **Paid:** Claude via Anthropic API (requires PHP 8.1+)
- **Fallback chain:** if one provider fails, the next in `chat_fallback` is tried

### 4.4 Food Ordering via Chat

The AI uses an `add_to_order` tool to add dishes to the guest's cart directly
from the conversation. It matches dish, size, and option names against the live
menu. Ambiguous or missing items are asked back. The chat shares the same cart
as `menu.html` — guests click "Review & pay" to open the normal checkout.

### 4.5 Interactive Cards & Suggestions

Replies include:
- **Show cards** with poster, time, and host
- **Party package cards** for private events
- **Food order cards** for items added to cart
- Up to 3 follow-up suggestion buttons

### 4.6 Protections

- Same-site JSON only
- 30 messages per visitor per 10 minutes
- `chat_daily_limit` caps total messages per day site-wide (default 500)
- Chat history saved in the database (`chat_sessions`, `chat_messages`)

---

## 5. Admin Panel

**Files:** `admin/index.php`, `admin/inc/`, `admin/inc/pages/`, `admin/assets/`
**URL:** `/admin/`

### 5.1 Authentication & Security

- Password-protected (12+ character password in `api/config.php`)
- PHP session cookies (HttpOnly, SameSite)
- CSRF token on every form
- Rate-limited sign-in (10 attempts per 15 minutes)
- `noindex`, `no-cache`, and Content-Security-Policy headers
- All guest text HTML-escaped; spreadsheet downloads neutralise formulas
- Uploaded posters validated as real 480 x 600 images
- 12-hour idle timeout

### 5.2 Sections

| Section | File | Capabilities |
| --- | --- | --- |
| **Dashboard** | `dashboard.php` | Action items (new orders, reservations, unread messages), 2-week activity chart, live status (pickup/delivery/Happy Hour on/off), setup health checks |
| **Orders** | `orders.php` | Order queue with status workflow (New → Preparing → Ready → Completed), team notes, printable ticket, sales totals, spreadsheet export |
| **Dine-In** | `dine-in.php` | Waiter ordering interface — select table, browse menu, build order, add special instructions, submit to KDS |
| **Reservations** | `reservations.php` | Table request management — confirm or decline with ready-made email, notes, upcoming and past views |
| **Messages** | `messages.php` | Contact-form inbox — unread/replied/archived, reply by email, delete spam |
| **Subscribers** | `subscribers.php` | Newsletter sign-up list — search, export CSV, remove |
| **Conversations** | `chats.php` | Full chat history with returning-guest and reservation-request flags, follow-up flag, notes, spreadsheet export |
| **Insights** | `insights.php` | Chat analytics (topics, foods, shows, timing) and order analytics (sales, busiest times, best sellers) |
| **Knowledge** | `knowledge.php` | Edit chatbot knowledge base with version history and one-click restore |
| **Events** | `events.php` | Add/edit weekly and one-night events with poster upload (cropping editor, size guide), publish to Events page and home slider |
| **Menu & Food** | `menu.php` | Hide items, change prices, pickup/delivery-only flags, delivery surcharge, prep time, descriptions, custom dishes |
| **Happy Hour** | `happy-hour.php` | Happy Hour menu, start/end times, scrolling ticker |
| **Store Settings** | `settings.php` | Business details, hours, delivery area (ZIPs), fees, minimums, prep time |
| **Activity Log** | `activity.php` | Audit trail of sign-ins and changes |

### 5.3 Live Notifications

- Sidebar badges show live counts (new orders, reservation requests, messages)
- Counts refresh every 30 seconds
- Page title shows the total count
- Pop-up notifications when new items arrive
- Designed for a tablet left open on the Orders page

---

## 6. Kitchen Display System (KDS)

**Files:** `kds/index.php`, `kds/api.php`, `kds/assets/kds.js`, `kds/assets/kds.css`, `api/kds.php`
**URL:** `/kds/`

### 6.1 Overview

A standalone, dark-themed display designed for kitchen staff. It shows all
incoming orders (dine-in, delivery, pickup) as cards on a grid with live timers
and a simple status workflow.

### 6.2 Features

- **Order cards:** each order displayed with type badge (dine-in/delivery/pickup), table number, customer name, item list, notes, and elapsed timer
- **Status workflow:** Queue → Preparing (timer starts) → Ready/Completed
- **Recall:** move a completed order back to preparing
- **Filters:** Active, All Types, Dine-In, Delivery, Pickup, Queue Only, Preparing Only, Overdue Only, Completed
- **Sorting:** Oldest First, Newest First, Overdue First, By Type
- **Summary bar:** live counters for Queue, Preparing, Overdue, and Done
- **Sound notifications:** two-tone chime when new orders arrive (toggleable)
- **Auto-polling:** orders refresh every 3 seconds via API

### 6.3 Settings

| Setting | Default | Range |
| --- | --- | --- |
| Dine-In Prep Time | 15 minutes | 1–120 min |
| Delivery Prep Time | 20 minutes | 1–120 min |
| Pickup Prep Time | 15 minutes | 1–120 min |
| Sound Notifications | Enabled | On/Off |

### 6.4 Security

- Password-protected (same password as Admin Panel, separate session)
- 12-hour idle timeout
- Rate-limited sign-in (10 attempts per 15 minutes)
- Same-site POST requests only
- Security headers: noindex, no-cache, CSP, X-Frame-Options DENY

### 6.5 API Endpoints

| Action | Description |
| --- | --- |
| `orders` | Fetch all today's orders with KDS metadata |
| `start` | Begin preparation (timer starts) |
| `complete` | Mark order done |
| `recall` | Move completed order back to preparing |
| `settings-get` | Read current KDS settings |
| `settings-save` | Update KDS settings |
| `counts` | Badge-style counters only |

---

## 7. Dine-In Ordering (Admin → KDS Integration)

**Files:** `admin/inc/pages/dine-in.php`, `admin/inc/dine-in-api.php`, `admin/assets/dine-in.js`

A waiter-facing ordering interface within the Admin Panel:

1. Select a **table number** (1–999)
2. Browse the **live menu** by category
3. Add items with variations, modifiers, and special notes
4. Submit the order — it appears on the **KDS** automatically
5. Order is recorded as a dine-in type with the table number

---

## 8. Backend Architecture

### 8.1 API Endpoints

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `api/menu.php` | GET | Live menu from Square (cached 15 min) |
| `api/checkout.php` | POST | Validate cart, calculate totals, charge card |
| `api/chat.php` | POST | AI chat assistant |
| `api/contact.php` | POST | Contact and reservation form submissions |
| `api/subscribe.php` | POST | Newsletter sign-up (CSV + Square Customers) |
| `api/happy-hour.php` | GET/POST | Happy Hour data read/write |
| `api/kds.php` | POST | KDS order management and settings |
| `api/chats.php` | — | Legacy redirect to Admin Panel |

### 8.2 Data Storage (MySQL + files)

Tables are created by `api/setup.sql`; the connection lives in `api/db.php`
(login in `api/config.php`, `db_*`). Times are stored in New York time.

| Data | Format | Location |
| --- | --- | --- |
| Orders (online + dine-in), with status, notes and KDS times | MySQL | `orders` table |
| Chat logs | MySQL | `chat_sessions` + `chat_messages` tables |
| Contact form submissions | MySQL | `contacts` table |
| Reservation requests | MySQL | `reservations` table |
| Newsletter subscribers | MySQL | `subscribers` table |
| "Call a waiter" requests | MySQL | `waiter_calls` table |
| Admin activity log | MySQL | `admin_activity` table |
| KDS settings, Happy Hour, menu overrides, store settings | MySQL (JSON values) | `settings` table |
| Guest records kept while the database is down | JSONL | `api/data/unsaved/` (moved in from the admin dashboard) |
| Events | JSON | `api/data/events.json` |
| Menu cache | JSON | `api/cache/menu.json` |
| Rate-limit counters | JSON | `api/cache/rate/` |
| Geo cache | JSON | `api/cache/geo/` |
| Chatbot knowledge | Markdown | `data/chatbot-knowledge.md` |

### 8.3 Shared Libraries

- `api/lib.php` — config loading, Square API calls, JSON responses, same-site checks, rate limiting, CSV handling, email, caching
- `admin/inc/store.php` — data access layer for orders, metadata, events
- `admin/inc/bootstrap.php` — admin panel initialization
- `admin/inc/auth.php` — session and authentication
- `admin/inc/layout.php` — admin page shell, sidebar, navigation
- `admin/inc/ui.php` — shared admin UI components

---

## 9. Security Summary

| Layer | Measures |
| --- | --- |
| **Server files** | `.htaccess` blocks `config.php`, `lib.php`, `cache/`, `data/`, `.md` files, dot-files, `.git` |
| **API endpoints** | Same-site JSON POST only, rate-limited |
| **Payments** | Server re-prices every item; card details go to Square's hosted field (never touch this server); idempotency keys |
| **Admin Panel** | Password, session cookies (HttpOnly, SameSite), CSRF tokens, rate-limited login, CSP headers, noindex, no-cache |
| **KDS** | Same protections as Admin (separate session), X-Frame-Options DENY |
| **User input** | HTML-escaped everywhere; spreadsheet exports neutralise formulas |
| **Chat** | 30 msg/visitor/10min, daily site-wide limit, auto-delete logs after 120 days |
| **Secrets** | `api/config.php` is git-ignored; never committed |

---

## 10. Configuration

All configuration lives in `api/config.php` (not committed to git). Template: `api/config.example.php`.

| Key | Purpose |
| --- | --- |
| `environment` | `sandbox` or `production` |
| `application_id` | Square Application ID |
| `access_token` | Square access token |
| `location_id` | Square location ID |
| `menu_cache_mins` | Menu cache duration (default 15) |
| `notify_email` | Where form submissions are emailed |
| `from_email` | Sender address |
| `chat_provider` | AI provider: groq, gemini, mistral, ollama, claude |
| `chat_fallback` | Fallback provider chain |
| `groq_api_key` | Groq API key |
| `gemini_api_key` | Gemini API key |
| `mistral_api_key` | Mistral API key |
| `anthropic_api_key` | Anthropic API key |
| `claude_model` | Claude model name |
| `chat_log` | Enable/disable chat logging |
| `chat_log_days` | Days to keep logs (default 120) |
| `chat_daily_limit` | Max messages per day (default 500) |
| `admin_password` | Admin + KDS password (12+ chars) |

---

## 11. Project Structure

```
rms/
├── *.html                    Public pages (11 pages + 404)
├── css/style.css             All styling (single file)
├── js/                       Client scripts (no dependencies)
│   ├── main.js               Shared: header, nav, hero slider, lazy load, forms
│   ├── order.js              Ordering: menu, cart, checkout, payment
│   ├── chat.js               AI chat: button, panel, cards, food-to-cart
│   ├── events.js             Weekly lineup day tabs
│   ├── upcoming.js           Home page events slider
│   ├── instagram-more.js     Gallery paging
│   └── elfsight-badge.js     Widget cleanup
├── images/                   WebP images (site/, events/, dancers/, hero, awards)
├── fonts/                    Self-hosted Inter + Playfair Display (woff2)
├── data/
│   ├── menu.json             Menu snapshot (Square fallback)
│   └── chatbot-knowledge.md  AI knowledge base
├── api/                      PHP backend
│   ├── config.php            (git-ignored) credentials and settings
│   ├── config.example.php    Template for config.php
│   ├── lib.php               Shared helpers
│   ├── menu.php              Live menu from Square
│   ├── menu-overrides.php    Menu adjustments (admin-controlled)
│   ├── checkout.php          Cart validation, payment
│   ├── chat.php              AI chat endpoint
│   ├── chat-claude.php       Claude provider
│   ├── chat-order.php        Food ordering tool
│   ├── chat-cards.php        Show/package cards
│   ├── chat-log.php          Chat logging
│   ├── chat-insights.php     Chat analytics
│   ├── happy-hour.php        Happy Hour data
│   ├── contact.php           Forms (contact + reservation)
│   ├── subscribe.php         Newsletter sign-up
│   ├── kds.php               KDS API
│   └── chats.php             Legacy redirect
├── admin/                    Admin Panel
│   ├── index.php             Entry point, routing
│   ├── assets/               admin.css, admin.js, events.js, dine-in.js
│   └── inc/                  Shared code + pages/
│       ├── bootstrap.php     Init
│       ├── auth.php          Session auth
│       ├── layout.php        Page shell, sidebar
│       ├── store.php         Data access
│       ├── ui.php            UI components
│       ├── events.php        Events publish logic
│       ├── dine-in-api.php   Dine-in order API
│       └── pages/            One file per section (12 sections)
├── kds/                      Kitchen Display System
│   ├── index.php             KDS app (login + display)
│   ├── api.php               KDS-specific API
│   └── assets/               kds.css, kds.js
├── docs/                     Developer documentation
│   ├── ARCHITECTURE.md       How the site is built
│   ├── ADMIN.md              Admin panel guide
│   ├── API.md                PHP endpoint reference
│   ├── CONTENT-GUIDE.md      How to update content
│   └── DEPLOYMENT.md         Publishing and hosting
├── .htaccess                 Apache: security, caching, clean URLs
├── robots.txt                Search engine directives
├── ORDERING-SETUP.md         Square setup guide for the owner
├── Start Website.bat         Windows one-click launcher
└── Save to GitHub.bat        Windows one-click git push
```

---

## 12. Deployment

- **Host:** any PHP 7.4+ web host (cPanel, shared hosting, VPS)
- **Requirements:** PHP with `curl` and `mbstring` extensions, Apache with `.htaccess` support
- **MySQL database** (MySQL 8.0.13+ or MariaDB 10.2+): create it in cPanel and import `api/setup.sql`
- **No build step** — upload files as-is
- **HTTPS required** for payment and security headers
- **Full guide:** `docs/DEPLOYMENT.md`

---

## 13. Version History

| Version | Date | Highlights |
| --- | --- | --- |
| **v5.2** | 2026-10-16 | Upload-and-go hand-off (any folder, database connected from the admin, one-click on cPanel), full QA fixes (online ordering restored, guest order email, busy-bar table limits), chat opens by itself, SEOBERG credits, Admin → Chat settings |
| **v5.1** | 2026-10-14 | MySQL database, hosting.com upload package, QA fixes, phone and tablet admin layout |
| **v5.0** | 2026-10-05 | Admin notifications, dine-in guest name, data protection |
| **v4.0** | 2026-10-02 | MySQL database, migration and admin overhaul |
| **v3.0** | 2026-10-01 | AI Chatbot, Admin Panel, and KDS — Kitchen Display System |
| **v2.0** | 2026-09-29 | AI Chat & Admin Panel [Web App] — delivery-only rules, comprehensive docs |
| **v1.0** | 2026-09-26 | First official version: all pages, Square ordering, AI chat, admin panel |

---

## 14. Repository & Workflow

- **Repository:** Private GitHub repo (`oceanadil12-cyber1/rms`)
- **Branch:** `main`
- **Auto-push:** changes committed and pushed automatically after every Claude Code reply
- **Manual push:** `Save to GitHub.bat` for hand edits
- **Versions:** git tags + GitHub Releases
- **Git-ignored:** `api/config.php` (credentials), `api/data/` (customer submissions)

---

*This is a private repository. All rights reserved. RMS.*
