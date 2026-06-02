<?php
declare(strict_types=1);

/**
 * MwasinMarket — Configuration
 * All secrets come from environment variables. No hardcoded passwords.
 */


define('DB_HOST',            $_ENV['DB_HOST']            ?? 'localhost');
define('DB_NAME',            $_ENV['DB_NAME']            ?? 'mwasinmarket');
define('DB_USER',            $_ENV['DB_USER']            ?? 'mwas');
define('DB_PASS',            $_ENV['DB_PASS']            ?? 'Mwangi254.');
define('FRONTEND_URL',       $_ENV['FRONTEND_URL']       ?? '');
define('DEBUG_MODE',         filter_var($_ENV['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN));

define('TOKEN_TTL',          86400);
define('TOKEN_BYTES',        32);
define('MAX_BODY_BYTES',     65536);

define('STICKER_UPLOAD_PATH', $_ENV['STICKER_UPLOAD_PATH'] ?? '/var/www/stickers');
define('STICKER_BASE_URL',    $_ENV['STICKER_BASE_URL']    ?? '');

define('LMSR_B',             1000);
define('LMSR_B_MIN',         50);
define('LMSR_B_MAX',         10000);
define('LMSR_MAX_ODDS',      10.0);
define('FIXED_MIN_OVERROUND', 1.03);

define('DUMMY_HASH', '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');

define('LARGE_BET_THRESHOLD', 50000.0);
define('WAGER_CAP_WARNING_RATIO', 0.95);

if (!DEBUG_MODE) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);

date_default_timezone_set('Africa/Nairobi');

// ---- SMTP (Outlook STARTTLS by default) ----
define('SMTP_HOST',      $_ENV['SMTP_HOST']      ?? 'smtp-mail.outlook.com');
define('SMTP_PORT',      (int)($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_USERNAME',  $_ENV['SMTP_USERNAME']  ?? '');
define('SMTP_PASSWORD',  $_ENV['SMTP_PASSWORD']  ?? '');
define('SMTP_FROM',      $_ENV['SMTP_FROM']      ?? '');
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? 'MwasinMarket');
define('APP_PUBLIC_URL', $_ENV['APP_PUBLIC_URL'] ?? '');
define('EMAIL_TOKEN_TTL',    86400);
define('PASSWORD_RESET_TTL', 3600);

// ---- PayHero (M-Pesa STK deposits) ----
define('PAYHERO_BASE_URL',    $_ENV['PAYHERO_BASE_URL']    ?? 'https://backend.payhero.co.ke/api/v2/payments');
define('PAYHERO_AUTH_TOKEN',  $_ENV['PAYHERO_AUTH_TOKEN']  ?? '');   // the Basic <base64> value, WITHOUT the word "Basic"
define('PAYHERO_CHANNEL_ID',  (int)($_ENV['PAYHERO_CHANNEL_ID'] ?? 0));
define('PAYHERO_PROVIDER',    $_ENV['PAYHERO_PROVIDER']    ?? 'm-pesa');
define('PAYHERO_CALLBACK_URL', $_ENV['PAYHERO_CALLBACK_URL'] ?? '');  // public URL to payment_callback.php
define('PAYHERO_CALLBACK_SECRET', $_ENV['PAYHERO_CALLBACK_SECRET'] ?? ''); // optional shared secret in ?s= or X-Callback-Secret

// ---- Anti-abuse for STK ----
define('APP_SECRET',          $_ENV['APP_SECRET'] ?? '');   // HMAC key for stateless captcha; REQUIRED in prod
define('CAPTCHA_TTL',         300);                          // 5 min to solve
define('STK_MAX_PER_WINDOW',  3);                            // max STK pushes ...
define('STK_WINDOW_SECONDS',  600);                          // ... per user per 10 min
define('DEPOSIT_MIN',         10.0);
define('DEPOSIT_MAX',         300000.0);
define('WITHDRAWAL_MIN',      10.0);
define('WITHDRAWAL_MAX',      150000.0);
