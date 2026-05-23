-- ============================================================
-- MwasinMarket — Complete SQL Schema (v5.0)
-- MySQL 8.0+ · InnoDB · utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username             VARCHAR(30)  NOT NULL,
    email                VARCHAR(254) NOT NULL,
    phone                VARCHAR(20)  NOT NULL,
    full_name            VARCHAR(120) NOT NULL DEFAULT '',
    password_hash        VARCHAR(255) NOT NULL,
    role                 ENUM('user','admin') NOT NULL DEFAULT 'user',
    balance              DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    locked_balance       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    bonus_balance        DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_wagered        DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    total_wins           DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    verified             TINYINT(1) NOT NULL DEFAULT 0,
    email_verified       TINYINT(1) NOT NULL DEFAULT 0,
    phone_verified       TINYINT(1) NOT NULL DEFAULT 0,
    is_suspended         TINYINT(1) NOT NULL DEFAULT 0,
    messaging_restricted TINYINT(1) NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username),
    UNIQUE KEY uniq_email    (email),
    UNIQUE KEY uniq_phone    (phone),
    KEY idx_role             (role),
    KEY idx_is_suspended     (is_suspended),
    CONSTRAINT chk_users_balance        CHECK (balance        >= 0),
    CONSTRAINT chk_users_locked         CHECK (locked_balance >= 0),
    CONSTRAINT chk_users_bonus          CHECK (bonus_balance  >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. auth_tokens
-- ============================================================
CREATE TABLE IF NOT EXISTS auth_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token       CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (token),
    KEY idx_user_expires  (user_id, expires_at),
    KEY idx_expires       (expires_at),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. markets
-- ============================================================
CREATE TABLE IF NOT EXISTS markets (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    market_id         VARCHAR(64) NOT NULL,
    title             VARCHAR(200) NOT NULL DEFAULT '',
    image_url         VARCHAR(500) NOT NULL DEFAULT '',
    source            VARCHAR(120) NOT NULL DEFAULT '',
    question          VARCHAR(500) NOT NULL,
    category          VARCHAR(60)  NOT NULL,
    market_type       ENUM('binary','categorical') NOT NULL DEFAULT 'binary',
    odds_mode         ENUM('lmsr','fixed') NOT NULL DEFAULT 'lmsr',
    status            ENUM('open','paused','closed','resolved','voided') NOT NULL DEFAULT 'open',
    close_time        DATETIME NULL,
    pause_reason      VARCHAR(500) NULL,
    void_reason       VARCHAR(500) NULL,
    resolve_time      DATETIME NULL,
    b                 DECIMAL(12,4) NOT NULL DEFAULT 1000.0000,
    min_stake         DECIMAL(18,2) NOT NULL DEFAULT 10.00,
    max_stake         DECIMAL(18,2) NOT NULL DEFAULT 100000.00,
    max_total_wagered DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    max_odds          DECIMAL(10,4) NULL,
    total_bets        INT UNSIGNED  NOT NULL DEFAULT 0,
    total_wagered     DECIMAL(20,2) NOT NULL DEFAULT 0.00,
    is_archived       TINYINT(1) NOT NULL DEFAULT 0,
    created_by        BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_market_id (market_id),
    KEY idx_status            (status),
    KEY idx_category          (category),
    KEY idx_close_time        (close_time),
    KEY idx_is_archived       (is_archived),
    KEY idx_status_archived   (status, is_archived),
    CONSTRAINT fk_markets_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_markets_b           CHECK (b >= 1),
    CONSTRAINT chk_markets_min_stake   CHECK (min_stake > 0),
    CONSTRAINT chk_markets_max_stake   CHECK (max_stake >= min_stake)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. outcomes
-- ============================================================
CREATE TABLE IF NOT EXISTS outcomes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    market_id   BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(100) NOT NULL,
    shares      DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    fixed_odds  DECIMAL(10,4) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_market (market_id),
    CONSTRAINT fk_outcomes_market FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. bets
-- ============================================================
CREATE TABLE IF NOT EXISTS bets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slip_id       VARCHAR(20) NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    market_id     BIGINT UNSIGNED NOT NULL,
    outcome_id    BIGINT UNSIGNED NOT NULL,
    is_bonus_bet  TINYINT(1) NOT NULL DEFAULT 0,
    shares        DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
    stake         DECIMAL(18,2) NOT NULL,
    odds_at_entry DECIMAL(10,4) NOT NULL,
    possible_win  DECIMAL(18,2) NOT NULL,
    payout        DECIMAL(18,2) NULL,
    prob_before   DECIMAL(12,8) NOT NULL DEFAULT 0,
    prob_after    DECIMAL(12,8) NOT NULL DEFAULT 0,
    status        ENUM('open','won','lost','void') NOT NULL DEFAULT 'open',
    void_reason   VARCHAR(500) NULL,
    expires_at    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slip (slip_id),
    KEY idx_user_status   (user_id, status),
    KEY idx_market_status (market_id, status),
    KEY idx_outcome       (outcome_id),
    KEY idx_created_at    (created_at),
    KEY idx_market_created(market_id, created_at),
    CONSTRAINT fk_bets_user    FOREIGN KEY (user_id)    REFERENCES users(id),
    CONSTRAINT fk_bets_market  FOREIGN KEY (market_id)  REFERENCES markets(id),
    CONSTRAINT fk_bets_outcome FOREIGN KEY (outcome_id) REFERENCES outcomes(id),
    CONSTRAINT chk_bets_stake  CHECK (stake > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. balance_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS balance_transactions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    type           ENUM('deposit','bonus','withdrawal','adjustment','bet_placed','bet_won','bet_lost','bet_voided','bet_revoked') NOT NULL,
    amount         DECIMAL(18,2) NOT NULL,
    balance_before DECIMAL(18,2) NOT NULL,
    balance_after  DECIMAL(18,2) NOT NULL,
    reference_id   VARCHAR(64) NULL,
    market_id      BIGINT UNSIGNED NULL,
    note           VARCHAR(300) NOT NULL DEFAULT '',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_type         (type),
    KEY idx_market       (market_id),
    KEY idx_reference    (reference_id),
    CONSTRAINT fk_bt_user   FOREIGN KEY (user_id)   REFERENCES users(id),
    CONSTRAINT fk_bt_market FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. market_snapshots
-- ============================================================
CREATE TABLE IF NOT EXISTS market_snapshots (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    market_id   BIGINT UNSIGNED NOT NULL,
    outcome_id  BIGINT UNSIGNED NOT NULL,
    probability DECIMAL(12,8) NOT NULL DEFAULT 0,
    odds        DECIMAL(10,4) NOT NULL DEFAULT 0,
    shares      DECIMAL(20,8) NOT NULL DEFAULT 0,
    volume      DECIMAL(20,2) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_market_created  (market_id, created_at),
    KEY idx_outcome_created (outcome_id, created_at),
    CONSTRAINT fk_snap_market  FOREIGN KEY (market_id)  REFERENCES markets(id)  ON DELETE CASCADE,
    CONSTRAINT fk_snap_outcome FOREIGN KEY (outcome_id) REFERENCES outcomes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. audit_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    action      VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id   BIGINT UNSIGNED NULL,
    meta        JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin            (admin_id),
    KEY idx_action           (action),
    KEY idx_target           (target_type, target_id),
    KEY idx_created_at       (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. login_attempts
-- ============================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_identifier_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. deposits
-- ============================================================
CREATE TABLE IF NOT EXISTS deposits (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(18,2) NOT NULL,
    phone               VARCHAR(20) NOT NULL,
    status              ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    external_reference  VARCHAR(64) NULL,
    checkout_request_id VARCHAR(100) NULL,
    provider            VARCHAR(30) NOT NULL DEFAULT 'mpesa',
    note                VARCHAR(300) NOT NULL DEFAULT '',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_external_reference (external_reference),
    KEY idx_user_created  (user_id, created_at),
    KEY idx_checkout      (checkout_request_id),
    KEY idx_status        (status),
    CONSTRAINT fk_deposits_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT chk_dep_amt CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. withdrawals
-- ============================================================
CREATE TABLE IF NOT EXISTS withdrawals (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    amount             DECIMAL(18,2) NOT NULL,
    phone              VARCHAR(20) NOT NULL,
    status             ENUM('pending','approved','processing','completed','failed','rejected') NOT NULL DEFAULT 'pending',
    external_reference VARCHAR(64) NULL,
    rejected_reason    VARCHAR(500) NULL,
    approved_by        BIGINT UNSIGNED NULL,
    provider           VARCHAR(30) NOT NULL DEFAULT 'mpesa',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at        DATETIME NULL,
    completed_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_status       (status),
    CONSTRAINT fk_wd_user     FOREIGN KEY (user_id)     REFERENCES users(id),
    CONSTRAINT fk_wd_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_wd_amt CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. reactions
-- ============================================================
CREATE TABLE IF NOT EXISTS reactions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    market_id  BIGINT UNSIGNED NOT NULL,
    reaction   ENUM('heart') NOT NULL DEFAULT 'heart',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_market_reaction (user_id, market_id, reaction),
    KEY idx_market (market_id),
    CONSTRAINT fk_react_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_react_market FOREIGN KEY (market_id) REFERENCES markets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. stickers
-- ============================================================
CREATE TABLE IF NOT EXISTS stickers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(80)  NOT NULL,
    filename        VARCHAR(64)  NOT NULL,
    mime_type       VARCHAR(20)  NOT NULL,
    file_size       INT UNSIGNED NOT NULL DEFAULT 0,
    category        VARCHAR(40)  NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_pack_default TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by     BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_filename (filename),
    KEY idx_category_active  (category, is_active),
    CONSTRAINT fk_stickers_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. messages
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_user_id         BIGINT UNSIGNED NOT NULL,
    to_user_id           BIGINT UNSIGNED NOT NULL,
    body                 TEXT NOT NULL,
    sticker_id           BIGINT UNSIGNED NULL,
    is_read              TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by_sender    TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_from_to_created (from_user_id, to_user_id, created_at),
    KEY idx_to_unread       (to_user_id, is_read),
    KEY idx_sticker         (sticker_id),
    CONSTRAINT fk_msg_from    FOREIGN KEY (from_user_id) REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_msg_to      FOREIGN KEY (to_user_id)   REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_msg_sticker FOREIGN KEY (sticker_id)   REFERENCES stickers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. sms_log
-- ============================================================
CREATE TABLE IF NOT EXISTS sms_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    phone       VARCHAR(20) NOT NULL,
    message     VARCHAR(160) NOT NULL,
    status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    provider    VARCHAR(40) NOT NULL DEFAULT 'africastalking',
    provider_id VARCHAR(100) NULL,
    sent_by     BIGINT UNSIGNED NULL,
    error       VARCHAR(300) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_status       (status),
    KEY idx_created_at   (created_at),
    CONSTRAINT fk_sms_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sms_sent_by FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. user_bans
-- ============================================================
CREATE TABLE IF NOT EXISTS user_bans (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    banned_by  BIGINT UNSIGNED NOT NULL,
    reason     VARCHAR(500) NOT NULL,
    banned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lifted_at  DATETIME NULL,
    lifted_by  BIGINT UNSIGNED NULL,
    lift_note  VARCHAR(300) NULL,
    PRIMARY KEY (id),
    KEY idx_user_lifted (user_id, lifted_at),
    CONSTRAINT fk_bans_user    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bans_banned  FOREIGN KEY (banned_by) REFERENCES users(id),
    CONSTRAINT fk_bans_lifted  FOREIGN KEY (lifted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. system_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS system_settings (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(60) NOT NULL,
    value      TEXT NOT NULL,
    message    VARCHAR(300) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_key (`key`),
    CONSTRAINT fk_ss_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type       VARCHAR(60) NOT NULL,
    target_id  BIGINT UNSIGNED NULL,
    message    VARCHAR(300) NOT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_read_created (is_read, created_at),
    KEY idx_type         (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. email_verification_tokens
-- ============================================================
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token       CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_evt_token (token),
    KEY idx_evt_user (user_id),
    KEY idx_evt_expires (expires_at),
    CONSTRAINT fk_evt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. password_reset_tokens
-- ============================================================
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    token         CHAR(64) NOT NULL,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    requested_ip  VARCHAR(45) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_prt_token (token),
    KEY idx_prt_user (user_id),
    KEY idx_prt_expires (expires_at),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA — Pack 1234: 32 stickers across 4 categories
-- Physical files must be deployed separately to STICKER_UPLOAD_PATH.
-- ============================================================

INSERT IGNORE INTO stickers (name, filename, mime_type, file_size, category, is_active, is_pack_default, uploaded_by, created_at) VALUES
-- Sports (8)
('trophy',   'pack1234_sports_trophy.webp',   'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('ball',     'pack1234_sports_ball.webp',     'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('whistle',  'pack1234_sports_whistle.webp',  'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('boot',     'pack1234_sports_boot.webp',     'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('medal',    'pack1234_sports_medal.webp',    'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('fire',     'pack1234_sports_fire.webp',     'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('goal',     'pack1234_sports_goal.webp',     'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
('stadium',  'pack1234_sports_stadium.webp',  'image/webp', 0, 'Sports',    1, 1, NULL, NOW()),
-- Finance (8)
('chart_up',   'pack1234_finance_chart_up.webp',   'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('chart_down', 'pack1234_finance_chart_down.webp', 'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('money_bag',  'pack1234_finance_money_bag.webp',  'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('coins',      'pack1234_finance_coins.webp',      'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('bank',       'pack1234_finance_bank.webp',       'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('rocket',     'pack1234_finance_rocket.webp',     'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('crash',      'pack1234_finance_crash.webp',      'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
('bull',       'pack1234_finance_bull.webp',       'image/webp', 0, 'Finance',   1, 1, NULL, NOW()),
-- Politics (8)
('vote',      'pack1234_politics_vote.webp',      'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('flag',      'pack1234_politics_flag.webp',      'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('handshake', 'pack1234_politics_handshake.webp', 'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('podium',    'pack1234_politics_podium.webp',    'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('ballot',    'pack1234_politics_ballot.webp',    'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('wave',      'pack1234_politics_wave.webp',      'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('crown',     'pack1234_politics_crown.webp',     'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
('scale',     'pack1234_politics_scale.webp',     'image/webp', 0, 'Politics',  1, 1, NULL, NOW()),
-- Reactions (8)
('heart',     'pack1234_reactions_heart.webp',    'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('clap',      'pack1234_reactions_clap.webp',     'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('shock',     'pack1234_reactions_shock.webp',    'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('laugh',     'pack1234_reactions_laugh.webp',    'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('cry',       'pack1234_reactions_cry.webp',      'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('think',     'pack1234_reactions_think.webp',    'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('salute',    'pack1234_reactions_salute.webp',   'image/webp', 0, 'Reactions', 1, 1, NULL, NOW()),
('facepalm',  'pack1234_reactions_facepalm.webp', 'image/webp', 0, 'Reactions', 1, 1, NULL, NOW());

-- ============================================================
-- Default system settings row for maintenance_mode
-- ============================================================
INSERT IGNORE INTO system_settings (`key`, value, message, updated_at) VALUES
('maintenance_mode', '0', NULL, NOW());
