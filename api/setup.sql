-- RMS — MySQL database setup
-- Run once in phpMyAdmin (cPanel → phpMyAdmin → your database → Import) or via:
--   mysql -u USER -p DBNAME < setup.sql
-- Needs MySQL 8.0.13+ or MariaDB 10.2+ (TEXT defaults are written as DEFAULT ('')).
-- Safe to re-run: every table is CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Contact form submissions
CREATE TABLE IF NOT EXISTS contacts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name        VARCHAR(80)  NOT NULL,
    email       VARCHAR(120) NOT NULL,
    phone       VARCHAR(30)  NOT NULL DEFAULT '',
    subject     VARCHAR(40)  NOT NULL DEFAULT 'general',
    message     TEXT         NOT NULL,
    status      ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
    note        TEXT         NOT NULL DEFAULT (''),
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    INDEX idx_received (received),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Newsletter subscribers
CREATE TABLE IF NOT EXISTS subscribers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signed_up   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email       VARCHAR(120) NOT NULL,
    page        VARCHAR(60)  NOT NULL DEFAULT '',
    UNIQUE KEY  uq_email (email),
    INDEX idx_signed_up (signed_up)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservations
CREATE TABLE IF NOT EXISTS reservations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    name            VARCHAR(80)  NOT NULL,
    email           VARCHAR(120) NOT NULL,
    phone           VARCHAR(30)  NOT NULL DEFAULT '',
    reservation_date DATE        NOT NULL,
    reservation_time VARCHAR(10) NOT NULL,
    guests          VARCHAR(10)  NOT NULL,
    area            VARCHAR(40)  NOT NULL DEFAULT '',
    notes           TEXT         NOT NULL DEFAULT (''),
    status          ENUM('pending','confirmed','declined','cancelled') NOT NULL DEFAULT 'pending',
    note            TEXT         NOT NULL DEFAULT (''),
    ip              VARCHAR(45)  NOT NULL DEFAULT '',
    INDEX idx_received (received),
    INDEX idx_date     (reservation_date),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders (online + dine-in)
CREATE TABLE IF NOT EXISTS orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    number          CHAR(6)      NOT NULL,
    payment         VARCHAR(30)  NOT NULL DEFAULT 'cash on delivery',
    order_type      VARCHAR(20)  NOT NULL DEFAULT 'pickup',
    table_number    SMALLINT UNSIGNED DEFAULT NULL,
    name            VARCHAR(80)  NOT NULL DEFAULT '',
    phone           VARCHAR(30)  NOT NULL DEFAULT '',
    email           VARCHAR(120) NOT NULL DEFAULT '',
    address         TEXT         NOT NULL DEFAULT (''),
    order_notes     TEXT         NOT NULL DEFAULT (''),
    items           JSON         NOT NULL,
    subtotal        INT          NOT NULL DEFAULT 0,
    delivery_fee    INT          NOT NULL DEFAULT 0,
    total           INT          NOT NULL DEFAULT 0,
    status          ENUM('new','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new',
    note            TEXT         NOT NULL DEFAULT (''),
    status_history  JSON         DEFAULT NULL,
    kds_started     DATETIME     DEFAULT NULL,
    kds_duration    INT          DEFAULT NULL,
    kds_completed   DATETIME     DEFAULT NULL,
    ip              VARCHAR(45)  NOT NULL DEFAULT '',
    UNIQUE KEY uq_number   (number),
    INDEX idx_created      (created_at),
    INDEX idx_status       (status),
    INDEX idx_order_type   (order_type),
    INDEX idx_date_status  (created_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat sessions
CREATE TABLE IF NOT EXISTS chat_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cid         VARCHAR(40)  NOT NULL,
    started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME     DEFAULT NULL,
    name        VARCHAR(80)  NOT NULL DEFAULT '',
    email       VARCHAR(120) NOT NULL DEFAULT '',
    phone       VARCHAR(30)  NOT NULL DEFAULT '',
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    device      VARCHAR(20)  NOT NULL DEFAULT '',
    browser     VARCHAR(30)  NOT NULL DEFAULT '',
    flagged     TINYINT(1)   NOT NULL DEFAULT 0,
    note        TEXT         NOT NULL DEFAULT (''),
    UNIQUE KEY uq_cid      (cid),
    INDEX idx_started      (started_at),
    INDEX idx_email        (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat messages (turns within a session)
CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    question        TEXT         NOT NULL,
    reply           TEXT         NOT NULL,
    ordered         JSON         DEFAULT NULL,
    shows           JSON         DEFAULT NULL,
    provider        VARCHAR(20)  NOT NULL DEFAULT '',
    model           VARCHAR(60)  NOT NULL DEFAULT '',
    input_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
    cache_read      INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
    seconds         DECIMAL(6,1) NOT NULL DEFAULT 0,
    INDEX idx_session (session_id),
    INDEX idx_sent    (sent_at),
    CONSTRAINT fk_chat_session FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events (managed from admin)
CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day_name    VARCHAR(20)  NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    event_date  DATE         DEFAULT NULL,
    time_label  VARCHAR(30)  NOT NULL DEFAULT '',
    name        VARCHAR(120) NOT NULL,
    host        VARCHAR(120) NOT NULL DEFAULT '',
    description TEXT         NOT NULL DEFAULT (''),
    tags        JSON         DEFAULT NULL,
    image       VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_day (day_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin activity log
CREATE TABLE IF NOT EXISTS admin_activity (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    logged_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    action      VARCHAR(40)  NOT NULL,
    username    VARCHAR(30)  NOT NULL DEFAULT '',
    detail      VARCHAR(300) NOT NULL DEFAULT '',
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    INDEX idx_logged (logged_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key-value settings (admin-meta, kds-settings, happy-hour, menu-overrides, store-settings)
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(60)  NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NOT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Call a waiter" requests from the dine-in page
CREATE TABLE IF NOT EXISTS waiter_calls (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    table_number  SMALLINT UNSIGNED NOT NULL,
    customer_name VARCHAR(40)  NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'active',
    resolved_at   DATETIME     DEFAULT NULL,
    ip            VARCHAR(45)  NOT NULL DEFAULT '',
    INDEX idx_status_table (status, table_number),
    INDEX idx_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gift cards sold on gift-cards.html or issued from Admin → Gift cards. 'square'
-- cards live in Square (code = its GAN, redeemed on the POS); 'local' cards are
-- kept here only (bought while card payments are off, or issued by the team) and
-- are redeemed in the admin panel. Amounts are in cents.
CREATE TABLE IF NOT EXISTS gift_cards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    code            VARCHAR(32)  NOT NULL,
    token           CHAR(32)     NOT NULL,
    source          ENUM('local','square') NOT NULL DEFAULT 'local',
    origin          ENUM('online','admin') NOT NULL DEFAULT 'online',
    status          ENUM('pending','active','used','void','error') NOT NULL DEFAULT 'pending',
    design          VARCHAR(40)  NOT NULL DEFAULT '',
    design_title    VARCHAR(60)  NOT NULL DEFAULT '',
    photo           VARCHAR(40)  NOT NULL DEFAULT '',
    amount          INT          NOT NULL DEFAULT 0,
    balance         INT          NOT NULL DEFAULT 0,
    buyer_name      VARCHAR(80)  NOT NULL DEFAULT '',
    buyer_email     VARCHAR(120) NOT NULL DEFAULT '',
    buyer_phone     VARCHAR(30)  NOT NULL DEFAULT '',
    recipient_name  VARCHAR(80)  NOT NULL DEFAULT '',
    recipient_email VARCHAR(120) NOT NULL DEFAULT '',
    message         TEXT         NOT NULL DEFAULT (''),
    deliver_on      DATE         DEFAULT NULL,
    sent_at         DATETIME     DEFAULT NULL,
    square_card_id  VARCHAR(64)  NOT NULL DEFAULT '',
    square_payment_id VARCHAR(64) NOT NULL DEFAULT '',
    receipt_url     VARCHAR(255) NOT NULL DEFAULT '',
    note            TEXT         NOT NULL DEFAULT (''),
    ip              VARCHAR(45)  NOT NULL DEFAULT '',
    UNIQUE KEY uq_code  (code),
    UNIQUE KEY uq_token (token),
    INDEX idx_created   (created_at),
    INDEX idx_status    (status),
    INDEX idx_deliver   (status, sent_at, deliver_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to a gift card's balance or state (issued, activated, redeemed, adjusted, voided, emailed)
CREATE TABLE IF NOT EXISTS gift_card_activity (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gift_card_id    INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    type            VARCHAR(20)  NOT NULL,
    amount          INT          NOT NULL DEFAULT 0,
    balance_after   INT          NOT NULL DEFAULT 0,
    detail          VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_card (gift_card_id),
    CONSTRAINT fk_gift_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team accounts for the admin panel and kitchen display (Admin → Team accounts).
-- The owner signs in as "admin" with the team password, which is not stored here.
CREATE TABLE IF NOT EXISTS admin_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    username        VARCHAR(30)  NOT NULL,
    name            VARCHAR(80)  NOT NULL DEFAULT '',
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','staff','kitchen') NOT NULL DEFAULT 'staff',
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    last_login      DATETIME     DEFAULT NULL,
    created_by      VARCHAR(30)  NOT NULL DEFAULT '',
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
