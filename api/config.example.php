<?php
/**
 * Private settings for the RMS website: the reference list.
 *
 * On your own computer: copy this file to config.php (same folder) and fill it in.
 *
 * On the live server you normally don't need it. The upload package (Make Upload
 * Package.bat) carries your settings in api/site-settings.php, and the admin
 * panel's first-run screen (/admin/) saves the database details in config.php.
 * A setting in the server's config.php wins over site-settings.php; blank values
 * are ignored. See docs/DEPLOYMENT.md.
 *
 * Never share config.php or commit it anywhere: it holds the database
 * password, the admin password and the chat API keys.
 */
return [
    // =====================================================================
    // 1. FILL THESE IN TO GO LIVE
    // =====================================================================

    // ---------- MySQL database (MySQL 8.0.13+ or MariaDB 10.2+) ----------
    // Create the database and a user in cPanel → MySQL Databases, add the user
    // to the database with ALL PRIVILEGES, and copy the full names here (cPanel
    // puts the account name in front, e.g. 'cpuser_rms'). The tables are
    // created automatically on the first visit (or import api/setup.sql in
    // phpMyAdmin). On cPanel keep 'localhost'; on Windows use '127.0.0.1'.
    'db_host' => 'localhost',
    'db_name' => '',          // e.g. 'cpuser_rms'
    'db_user' => '',          // e.g. 'cpuser_rms'
    'db_pass' => '',
    'db_port' => 3306,

    // Password for the admin panel (/admin/) and the kitchen display (/kds/):
    // orders, reservations, messages, chats, menu, events, settings. Use a long
    // password (12+ characters) nobody else knows. Empty = panel switched off.
    // The older name for this setting, 'chat_admin_password', still works.
    'admin_password' => '',

    // Where new orders, contact messages and reservation requests are emailed.
    'notify_email'   => '',

    // Sender address for those emails. Use an address on the website's own
    // domain (e.g. website@example.com) so they don't land in spam;
    // creating that mailbox in cPanel → Email Accounts helps too.
    'from_email'     => '',

    // ---------- Chat assistant ("Ask us" button) ----------
    // Which AI answers the chat:
    //   'groq'    — free plan (about 1,000 questions/day), very fast.
    //               Key from https://console.groq.com/keys
    //   'mistral' — free plan with high limits (about 1 question per second).
    //               Key from https://console.mistral.ai/api-keys
    //   'gemini'  — small free plan (about 20 questions/day).
    //               Key from https://aistudio.google.com/apikey
    //   'claude'  — Anthropic; paid. Needs the Anthropic SDK (see docs/DEPLOYMENT.md).
    //   'ollama'  — runs on your own computer only; for local testing.
    'chat_provider'     => 'groq',

    // AIs to ask, in order, when the first one fails (free limit reached, busy
    // or down), e.g. 'mistral,gemini'. Leave empty for none. Each needs its key.
    'chat_fallback'     => '',

    // Paste the key for each AI named above.
    'groq_api_key'      => '',
    'mistral_api_key'   => '',
    'gemini_api_key'    => '',
    'anthropic_api_key' => '',

    // =====================================================================
    // 2. ALREADY SET FOR LAUNCH (change only if you mean to)
    // =====================================================================

    // How online orders are paid: 'cod' = cash on delivery / pay at pickup,
    // no online charge. 'card' charges through Square and needs section 4.
    'checkout_mode'   => 'cod',

    // Where the food menu comes from: 'local' = data/menu.json, managed in
    // Admin → Menu & food (no Square needed), or 'square' = the live Square store.
    'menu_source'     => 'local',

    // =====================================================================
    // 3. CHAT DETAILS
    // =====================================================================

    // Models. Groq: turn on Zero Data Retention in the Groq console
    // (Settings → Data Controls). Mistral: turn off "Anonymous improvement
    // data" in admin.mistral.ai (API → Privacy). Gemini's free plan may use
    // chats to improve Google's products. Claude options, most capable to
    // cheapest: 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'.
    'groq_model'        => 'openai/gpt-oss-120b',
    'mistral_model'     => 'ministral-14b-latest',
    'gemini_model'      => 'gemini-3.8-flash',
    'claude_model'      => 'claude-opus-5',

    // Ollama (local testing only; the model must be downloaded first:
    // ollama pull llama3.2:3b).
    'ollama_url'        => 'http://localhost:11434',
    'ollama_model'      => 'llama3.2:3b',

    // Most chat messages the whole site will answer per day (0 = no limit).
    // A safety net against spam running up an AI bill.
    'chat_daily_limit'  => 500,

    // Save every chat (question, answer, dishes ordered, token counts, and the
    // email/name the guest gave) in the database, for Admin → Conversations.
    'chat_log'          => true,

    // Days of chat history the admin's chat pages look back over.
    'chat_log_days'     => 120,

    // =====================================================================
    // 4. SQUARE (only for card payments or a live Square menu; not needed for
    //    cash on delivery). From https://developer.squareup.com → your app.
    // =====================================================================

    // 'sandbox' for testing (test cards, no real money), 'production' for real.
    'environment'    => 'sandbox',

    // Credentials → Application ID (sandbox IDs start with "sandbox-sq0idb-",
    // production with "sq0idp-").
    'application_id' => '',

    // Credentials → Access token. It can take payments: keep it secret.
    'access_token'   => '',

    // Locations. The restaurant's production location is 9E9MT9QP4KND5;
    // sandbox has its own test location ID.
    'location_id'    => '9E9MT9QP4KND5',

    // Minutes to reuse the Square menu before reading it again ('square' menu only).
    'menu_cache_mins' => 15,

    // =====================================================================
    // 5. ADVANCED
    // =====================================================================

    // Folder for sign-in sessions, admin working files and the backup of guest
    // records kept while the database is down. Leave empty for api/data/ (kept
    // private by api/.htaccess). On hosts that ignore .htaccess (e.g. Nginx),
    // use a folder OUTSIDE the web root, e.g. '/home/USERNAME/rms-data'.
    'data_dir'       => '',
];
