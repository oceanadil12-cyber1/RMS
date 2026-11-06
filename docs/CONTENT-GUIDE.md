# Content Guide

How to make common updates. All pages are plain HTML, so open the file in any
code editor (VS Code recommended), edit, save, and refresh the browser.

Preview your changes locally first (see the README, "How to display the website").

## Events (weekly lineup)

**Use the admin panel: Admin → Events** (see [ADMIN.md](ADMIN.md#events-adding-and-publishing)).
Add an event with its title, host, description, tags, time, days (or a single
date) and a poster, then press **Save & publish**. The panel writes the lineup on
`events.html` for you, and the home page's "Upcoming Events" slider and the chat
assistant read it from there, so you never edit the home page for events.

The poster should be **480 × 600 px** (portrait, 4:5); the editor in the panel
crops and resizes any picture to that. On the Events page and the slider the poster
is shown as a square from the bottom of the picture (the top 120 px is cut off,
and the time badge covers the top-left corner), so keep the artwork and title low.

> Editing `events.html` by hand still works, but the panel replaces the text
> between the `admin:events` markers on every publish. Use **Load the page's
> events here** in Admin → Events to bring hand edits into the panel first.

The markup the panel writes, if you ever need to edit by hand: each day is a
`<div class="ev-day" data-day="mon">` block, and each event inside it is one
`<article class="ev-card">`:

```html
<article class="ev-card">
  <div class="ev-poster">
    <img src="images/events/my-new-show.webp" alt="My New Show poster"
         width="480" height="600" loading="lazy" decoding="async">
    <span class="ev-time">9:00 PM</span>
  </div>
  <div class="ev-body">
    <h4>My New Show</h4>
    <p class="ev-host">Hosted by Someone</p>
    <p class="ev-desc">One-line description of the night.</p>
    <ul class="ev-tags"><li>Drag Show</li><li>No Cover</li></ul>
  </div>
</article>
```

- **Add an event:** copy a card into the right day and keep the cards in time order.
- **Remove an event:** delete its whole `<article>…</article>`.
- **Time format:** use `9:00 PM` style. The home slider uses it to work out what's next.
- **Tab counts:** update the "N events" text on that day's tab button near the
  top of the lineup (`<span class="ev-tab-count">`).
- **Poster:** save a 480×600 WebP in `images/events/`.
- **One-night event:** add `data-date="YYYY-MM-DD"` to the `<article>` (and a
  `<span class="ev-date">Fri, Oct 31</span>` badge after the time). The page hides
  it after that night, and the slider and chat show it on that date only.

## Dancers

In `dancers.html`, each dancer is a card with a photo in `images/dancers/`
(600×600 WebP) and a name. Copy a card to add one, delete it to remove one,
and update the `alt` text with the dancer's name.

## Photos

1. Convert the photo to **WebP**. <https://squoosh.app> is free and works in the browser.
2. For the large site photos, export three widths, `-640`, `-1024` and `-1600`,
   and follow the naming in `images/site/`.
3. Replace the file with the same name, or add a new file and update the `src`
   or `srcset` in the page.
4. Keep the `width` and `height` attributes matching the image's proportions.

## Text, phone, address and hours

Text is edited directly in each HTML file. The **header and footer are
repeated on every page**, so a change to the phone number, address, hours or
social links has to be made in all 10 pages. Search the whole folder to find
every copy (in VS Code: Ctrl+Shift+F).

## Menu and prices

**Use the admin panel: Admin → Menu & food.** Turn an item off to hide it, change
its price, make it pickup-only or delivery-only, add a delivery surcharge or a
longer prep time, edit its description and options, or add dishes of your own.
Hours, delivery area and fees are in **Admin → Store settings**, and the Happy
Hour menu is in **Admin → Happy Hour**. Changes are live as soon as you save.
(See [ADMIN.md](ADMIN.md).)

Where the base menu comes from is the `menu_source` setting in `api/config.php`:
`'local'` (the default) uses the saved snapshot `data/menu.json` and never contacts
Square; `'square'` reads the restaurant's Square account live (cached for
`menu_cache_mins`). Either way, the changes made in the admin are applied on top.

`data/menu.json` is also the backup used when Square can't be reached. To refresh
it from Square on a machine with PHP:

```bash
php api/menu.php --snapshot
```

## Forms

Contact, reservation and newsletter forms are `<form data-endpoint="api/…">`
elements, sent by `main.js`. Submissions are emailed to `notify_email` in
`api/config.php` and saved in the site's MySQL database. Read and answer them
in **Admin → Reservations, Messages and Subscribers** (each can be downloaded as
a spreadsheet).

## Chat assistant

The "Ask us" chat answers guests from:

| What | Where it comes from | How to change it |
| --- | --- | --- |
| Hours, policies, reservations, private events, FAQs | `data/chatbot-knowledge.md` | **Admin → Knowledge** (plain text) |
| Weekly show lineup | `events.html` | **Admin → Events** |
| Happy Hour | The Happy Hour settings | **Admin → Happy Hour** |
| Drinks list | `menu.html` | Edit the page as usual |
| Food menu, prices, pickup/delivery | The menu (see above) | **Admin → Menu & food** |

Changes take effect on the next chat message. To see exactly what the
assistant knows, run `php api/chat.php --prompt`. To try a question without the
website, run `php api/chat.php "Are you open now?"`.

If it gives a wrong or vague answer, add the fact in **Admin → Knowledge** in
plain words. That page also lists the recent questions the assistant couldn't
answer.

**Chat history:** in **Admin → Conversations** (sign in with `admin_password` from
`api/config.php`) you can read every conversation, see which ones ordered food or
asked about a reservation, flag one for follow-up, and download a spreadsheet.
Guests give an email (and optionally a name) before chatting, and their IP
address, device and browser are saved with the chat. Chats are deleted
automatically after 120 days (`chat_log_days`).

**Admin → Insights** shows trends:
- what guests ask about
- which foods interest them and which dishes they order through the chat
- which shows and days they ask about
- when they chat
- private-party demand: occasions, group sizes, packages discussed and budget worries, in guests' own words
- questions the assistant couldn't answer (add those facts in **Admin → Knowledge**)

It counts with keyword lists at the top of `api/chat-insights.php`, which you
can extend. The chat window doesn't say that chats are stored, so cover chat
storage (emails and IP addresses) in the site's privacy policy.

## Google reviews and Instagram

These are **Elfsight** widgets (`<div class="elfsight-app-…">`). Their content
and layout are managed in the Elfsight dashboard, not in this code.

## Cache busting

The server tells browsers to keep CSS and JS for a month. Pages link to them
with a version tag:

```html
<link rel="stylesheet" href="css/style.css?v=20260925">
<script src="js/main.js?v=20260925"></script>
```

**Whenever you change `css/style.css` or a `js/` file, change the `?v=` value
to today's date in every page**, so visitors get the new version immediately.
A find-and-replace across the folder does it in one step.

## Before publishing a change

- [ ] Checked the page on desktop and on a phone-width window
- [ ] Bumped `?v=` if CSS or JS changed
- [ ] New images are WebP and have `width`/`height`/`alt`
- [ ] Committed to git with a clear message
