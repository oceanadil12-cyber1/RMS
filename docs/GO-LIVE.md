# Putting the RMS website live

About 5 minutes in cPanel, with no files to edit and no database work. The owner's
settings (admin password, notification email, chat assistant keys) are already
inside the package, and the website creates its own MySQL database.

## What's in this folder

| Item | Use it for |
| --- | --- |
| `rms-website-upload.zip` | The whole website. Upload it in cPanel and **Extract** it |
| `HOW-TO-GO-LIVE.md` | This guide |
| `VERSION.txt` | Which version the package was built from |

**Private:** the ZIP holds the admin password and the chat API keys (in
`api/site-settings.php`, which the site blocks from the web). Don't pass it on.

## Steps

1. **Upload.** cPanel → *File Manager* → open the domain's folder (`public_html`
   for the main domain) → *Upload* `rms-website-upload.zip` → right-click it →
   *Extract* → delete the zip. `index.html`, `admin/` and `api/` must sit directly
   in that folder, not inside another folder. (The site also works from a
   subfolder, e.g. for a preview, but the live site belongs at the domain root.)

2. **Open the admin.** Go to `https://DOMAIN/admin/` and sign in with the admin
   password (the owner gives it to you).

3. **Click "Create the database for me".** The website makes its own MySQL
   database and user in the hosting account (named like `cpuser_rms`), creates
   its tables and saves everything. That's it: the website is live.

   *Only if that button isn't there* (a few hosts don't allow it) or it shows a red
   message: in cPanel → *MySQL Databases*, create a database, create a user with a
   password, and *Add User To Database* with **ALL PRIVILEGES**. Then type those
   three into the same screen and click *Connect and finish*.

## Quick check

- [ ] Home, Menu, Events and Contact pages load, also on a phone
- [ ] Menu → add a dish → place a **pickup** order: it shows in **Admin → Orders** and on the **kitchen display** (`/kds/`, same password)
- [ ] `https://DOMAIN/dine-in/table/1` → order something: it reaches the kitchen display
- [ ] The "Ask us" chat answers a question
- [ ] `/api/config.php` and `/api/site-settings.php` show **403 Forbidden**

Afterwards mark the test orders **Cancelled** in Admin → Orders.

## Hosting settings worth a look

These belong to the hosting account, not the website, and are usually already right:

- **HTTPS:** AutoSSL normally issues the certificate by itself. To send every visit
  to https, turn on cPanel → *Domains* → **Force HTTPS Redirect**.
- **PHP 8.1 or newer** (8.2 recommended): cPanel → *MultiPHP Manager*.
- **Email:** cPanel → *Email Deliverability* → *Repair* if it shows a problem, so
  order emails don't land in spam.

## Later updates: never overwrite these on the server

`api/config.php` (the database details), `api/data/`, `events.html`,
`performers.html`, `images/events/`, `images/performers/`, `images/chat/` and
`data/chatbot-knowledge.md`. The team changes them from the admin panel. Back up
the database regularly: cPanel → *Backup* → *Download a MySQL Database Backup*.

## If something doesn't work

| What you see | Fix |
| --- | --- |
| "500 Internal Server Error" | PHP is too old: cPanel → *MultiPHP Manager* → PHP 8.2 |
| The Connect screen shows a red message | It says what's wrong; if the one-click button failed, use the cPanel steps under step 3 |
| `/admin/` shows a file list or "Not Found" | The hidden `.htaccess` files are missing: File Manager → *Settings* → *Show Hidden Files*, then extract the ZIP again |
| `/dine-in/table/5` shows "Not Found" | Same as above: `.htaccess` missing, or the host has `mod_rewrite` off |
| Emails don't arrive | Check *Email Deliverability* and the spam folder |

More detail for developers: `docs/DEPLOYMENT.md` in the project repository.
