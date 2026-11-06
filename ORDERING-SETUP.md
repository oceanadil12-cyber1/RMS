# Online Ordering — Setup

The menu page (`menu.html`) has a built-in ordering system: live menu from Square,
item options, cart, pickup/delivery, tips and card payment. Orders are created in
the restaurant's Square account and show up in the Square POS like any online order.

It needs **one thing before it can take payments**: Square API credentials from
someone who can log in to the restaurant's Square account.

## 1. Upload

Upload the whole site to the cPanel host, including the `api/` and `data/` folders.
Requirements: PHP 7.4 or newer with the `curl` and `mbstring` extensions (standard on cPanel).
The `api/cache/` folder is created automatically and must be writable.

## 2. Get Square credentials (restaurant owner, ~10 minutes)

1. Go to <https://developer.squareup.com> and sign in with the **restaurant's Square login**.
2. Click **Create an application** → name it "RMS Website".
3. Open the app. On **Credentials**:
   - **Sandbox** tab → copy the *Sandbox Application ID* and *Sandbox Access token* (for testing).
   - **Production** tab → copy the *Application ID* and *Access token* (for real orders).
4. On **Locations**, copy the sandbox location ID (production is `9E9MT9QP4KND5`).

## 3. Add the credentials

1. In `api/`, copy `config.example.php` to **`config.php`**.
2. Paste the **sandbox** values, keep `'environment' => 'sandbox'`, save.
3. Open the menu page, add items and pay with Square's test card
   `4111 1111 1111 1111`, any future expiry, CVV `111`, ZIP `33305`.
   The test order appears in the Square **Sandbox** dashboard.
4. When happy, switch to the **production** values and `'environment' => 'production'`.

Keep `config.php` private — never email it or put it in a public repository.
(`api/.htaccess` already blocks it from being downloaded on Apache/cPanel.)

## 4. Contact, reservation & newsletter forms

- Set `'notify_email'` in `config.php` to the address that should receive contact
  messages and reservation requests, and `'from_email'` to an address on the
  website's domain (e.g. `website@example.com`).
- Every submission is also saved in the site's MySQL database and shows in the
  admin panel (Reservations, Messages, Subscribers).
- With Square credentials set, newsletter sign-ups are also added to the Square
  Customer Directory, so they can be emailed with Square Marketing.
- The forms need PHP, so on a plain static preview (e.g. Netlify Drop) they show
  "Sorry, that didn't go through — please call us".

## 5. Apple Pay / Google Pay (optional, later)

Card payments work out of the box. Apple Pay needs the domain registered in the
Square Developer Dashboard; these can be added once the site is live.

## How it works

| File | What it does |
| --- | --- |
| `api/menu.php` | Reads the live menu (items, sizes, options, prices, hours, delivery ZIP) from the restaurant's Square Online store; cached 15 min. Falls back to `data/menu.json`. |
| `api/checkout.php` | Re-prices the cart on the server, checks required options, hours, delivery ZIP/minimum, then creates the Square order and charges the card. |
| `js/order.js` | The ordering interface on the menu page. |
| `data/menu.json` | Menu snapshot used if Square can't be reached. Refresh with `php api/menu.php --snapshot`. |

Menu changes made in Square (prices, new items, sold-out) appear on the website
automatically within 15 minutes. Pickup hours, prep time (25 min), delivery ZIP
(33305) and delivery minimum ($5) also come from the Square Online settings.
