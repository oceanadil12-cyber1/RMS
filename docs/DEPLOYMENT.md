# Deployment

The site is built for a standard **Apache / cPanel host with PHP 8.1+ and a MySQL
database** (MySQL 8.0.13+ or MariaDB 10.2+), which is how most shared hosting,
including a hosting.com reseller account, is set up. The `.htaccess` files handle
security, caching, clean URLs and the 404 page. It runs at a domain's root or in
a subfolder (`https://example.com/rms/`): every link and rule works out the
site's own folder.

## What to upload

**Easiest:** double-click `Make Upload Package.bat`. It builds the folder
`RMS Website - For Developer` next to the repository with one ZIP,
`rms-website-upload.zip` (exactly the files for the server), plus the
step-by-step guide `HOW-TO-GO-LIVE.md` (the same text as [GO-LIVE.md](GO-LIVE.md)).
Hand that folder to whoever puts the site live.

The ZIP also carries **`api/site-settings.php`**, made from this computer's
`api/config.php` by `make-site-settings.php`: the admin password, the notification
email and the chat assistant's provider and keys. Left out: the database login
(each server connects its own), `data_dir`, the Ollama settings (this computer
only) and a Gmail-type `from_email` (the server sends as `website@its-domain`).
So the package is **private**: send it only to the person putting the site live.
`api/.htaccess` blocks the file from the web.

Uploading by hand instead: upload the **contents** of this repository to the web
root (usually `public_html/`), **except**:

| Don't upload | Why |
| --- | --- |
| `.git/` | Repository history. It's also blocked by `.htaccess` as a safety net |
| `docs/`, `README.md` | Developer notes. `.md` files are blocked anyway |
| `*.bat`, `*.ps1`, `make-site-settings.php`, `.gitignore`, `.gitattributes` | Only used on your own computer |

Must upload: all `*.html`, `404.php`, `css/`, `js/`, `images/`, `fonts/`, `data/`,
`api/`, `admin/`, `kds/`, `dine-in/`, every `.htaccess` and `robots.txt`. Without
the package, create `api/site-settings.php` from `api/config.example.php` (or put
the same settings in `api/config.php`).

> Dot-files like `.htaccess` are hidden by default in some FTP programs and in
> the cPanel File Manager. Turn on "show hidden files" and make sure they get uploaded.

## Settings: three layers

`config()` in `api/lib.php` reads, first one wins:

1. `api/config.php`: this server's own settings. The admin panel's first-run
   screen writes the `db_*` values here, keeping the file's other settings (and a
   copy of the earlier file as `api/.config-before-<date>.php`). A blank value
   counts as not set.
2. `api/site-settings.php`: the owner's settings from the upload package.
3. The defaults in `config()`.

## First-time setup

1. **Upload** the ZIP and *Extract* it (cPanel File Manager), or upload by hand as above.
2. **Open `/admin/` and sign in.** While the database isn't connected (or the saved
   details are turned down), the panel shows **Connect the database**.
3. **Click "Create the database for me".** On cPanel the site runs cPanel's own
   command-line tool (`uapi`, as the account's user, so no password is needed):
   it creates a database and a user named after the account (`cpuser_rms`, or
   `cpuser_rms2` if taken) with a random 32-character password and All
   Privileges, creates the 10 tables from `api/setup.sql` and saves the details in
   `api/config.php`. The button only appears where PHP may run `exec()` as the
   account's user, and never while saved details exist and MySQL is simply not
   answering (so an outage can't switch the site to an empty database).
   Otherwise, or if cPanel refuses (e.g. the database limit is reached): create
   the database and a user in cPanel → *MySQL Databases* (*Add User to Database*
   with **All Privileges**) and type them into the same screen. It explains a
   wrong password, a wrong name or missing privileges in plain words. If the user
   has no CREATE rights, *phpMyAdmin* → the database → *Import* → `api/setup.sql`
   does the same, then connect again. On a hosting.com reseller account, the
   site's own cPanel account has to exist first (WHM → *Create a New Account*).
4. **Enable HTTPS.** Once the SSL certificate is active (usually free with
   AutoSSL in cPanel), turn on cPanel → *Domains* → *Force HTTPS Redirect*, or
   uncomment the five HTTPS lines in the root `.htaccess`.
5. **robots.txt**: once the site is live, add the `Sitemap:` line with the real
   domain if you create a sitemap.
6. **Card payments** (later, optional): the site launches as cash on delivery.
   For Square, add the keys (see [ORDERING-SETUP.md](../ORDERING-SETUP.md)) and
   set `checkout_mode` to `card`.
7. **Chat assistant**: see below.

**Backups:** cPanel → *Backup* → *Download a MySQL Database Backup* (or phpMyAdmin →
*Export*) saves every order, reservation, message and chat. Take one before each
update and now and then.

## Chat assistant on the live site

Shared hosting can't run an AI model itself, so the live site uses a cloud AI.

**Free options (Mistral, Groq, Gemini):** the upload package carries the chat
settings from your local `api/config.php` (`chat_provider`, `chat_fallback` and
the keys) in `api/site-settings.php`, so there is nothing to copy. No Composer is needed. Use a
Groq account in the restaurant's name, and turn on Zero Data Retention in the
Groq console (Settings → Data Controls). The free plans allow a few questions
per minute across all the fallbacks. Check the chat history
(Admin → Conversations) now and then. If guests often hit the limit, switch to a paid plan.

**Paid option (Claude):**

1. The restaurant creates an account at <https://console.anthropic.com>,
   adds a card, buys some credit and **sets a monthly spend limit**. Then it
   creates an API key.
2. Check the host runs **PHP 8.1 or newer** (cPanel → *Select PHP Version*).
3. Install the Anthropic SDK: in cPanel's *Terminal*, `cd public_html/api`
   and run `composer require anthropic-ai/sdk`. Without Terminal, run
   the same command in `api/` on your computer and upload the `api/vendor/`
   folder it creates.
4. In your local `api/config.php`, set `'chat_provider' => 'claude'`, paste the key into
   `'anthropic_api_key'` and choose `'claude_model'`, then build and upload a new package (or put the same lines in the server's `api/config.php`).
5. Ask the chat a few questions on the live site. Check the replies in the
   chat history (Admin → Conversations), which also shows the token counts behind the bill.

`chat_daily_limit` in `config.php` caps how many messages the site answers per
day (default 500), so spam can't run up the bill.

## Admin panel on the live site

The admin password is your local `admin_password` (or the older `chat_admin_password`),
carried by the package; it must be long (12+ characters). Open `https://DOMAIN/admin/`. Keep the password private: the panel shows every
order, message and conversation, and can change the menu and the website. Turn on
HTTPS first (step 4 above) so the password isn't sent in the clear. The panel is
never indexed and needs no other setup; see [ADMIN.md](ADMIN.md).

`notify_email` comes from the package too, so new orders and messages are emailed
as well as shown in the panel. Leave `from_email` empty (the site sends as
`website@` its own domain) or set it to a mailbox on that domain.

**Events** are published by the panel by rewriting part of `events.html` and saving
posters in `images/events/`, so those two must be writable by the website (they
are on ordinary cPanel hosting). **Never upload your own copy of `events.html` or
`images/events/` over the live ones** once the team is using the panel.

## Updating the live site

1. Make and preview the change locally.
2. If CSS or JS changed, bump `?v=` in the pages (see the Content Guide).
3. Commit to git: `git add -A && git commit -m "Describe the change" && git push`
4. Upload **only the changed files** to the server, overwriting the old ones.

**Never overwrite on the server:** `api/config.php` (live keys and the database
login), the live database (never import a local copy over it), `api/data/` (sign-ins
and what the admin panel saves), `api/cache/`, `events.html`, `performers.html`, `images/events/`, `images/performers/`, `images/chat/` and
`data/chatbot-knowledge.md` (the team changes them from the panel). A new package's
`api/site-settings.php` may replace the old one: it carries your current local settings. Re-importing
`api/setup.sql` is safe: it only adds tables that are missing.

## Checklist after deploying

- [ ] Home page loads over `https://` with no browser warnings
- [ ] Admin → Dashboard → *Setup checks* shows **Database connected** with the MySQL/MariaDB version
- [ ] Menu page shows the menu and the cart works
- [ ] A test order (sandbox) goes through and shows in the Square dashboard
- [ ] Contact form sends and the email arrives at `notify_email`
- [ ] `https://DOMAIN/api/config.php` is **blocked** (403), not downloadable
- [ ] `https://DOMAIN/api/data/` and `https://DOMAIN/api/setup.sql` are **blocked** (403)
- [ ] `https://DOMAIN/admin/` shows the sign-in page, and `https://DOMAIN/admin/inc/store.php` is **blocked** (403)
- [ ] Signing in to the admin works, and a test event can be published and appears on the Events page
- [ ] The "Ask us" chat answers a question, and `https://DOMAIN/api/vendor/` and
      `https://DOMAIN/data/chatbot-knowledge.md` are **blocked**
- [ ] A made-up URL shows the branded 404 page
- [ ] The site looks right on a phone

## Other hosting

- **Nginx or hosts without `.htaccess`:** recreate the rules yourself. Deny
  `api/config.php`, `api/site-settings.php`, `api/lib.php`, `api/cache/`, `api/data/`, `admin/inc/` and
  `/.git`, make `index.php` the index of `admin/`, and set `data_dir` in
  `config.php` to a folder outside the web root.
- **Static-only hosts (Netlify Drop, GitHub Pages):** the pages display, but
  the live menu, ordering and forms need PHP and won't work. That's fine for a
  design preview only. Don't use GitHub Pages on this private repository, because
  the published site would be public.
