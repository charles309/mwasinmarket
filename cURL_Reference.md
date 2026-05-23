# MwasinMarket — Complete cURL Reference

**Version:** 5.2 (single-file production build)
**File:** drop `api.php` into webroot. Apply `schema.sql` to MySQL 8.0+.
**Base URL:** `https://yourdomain.com/api.php`
**Routing:** `?route=<name>` (query string)
**Auth:** `Authorization: Bearer <64-hex-token>` (only where stated)
**Body:** JSON, `Content-Type: application/json`. Max **64 KB**.
**Discovery:** `GET ?route=routes` returns the live catalogue of every route.

> **What changed vs v4.6:**
> - Internal fields (`odds_mode`, `max_odds`, `pause_reason`, `b`) are hidden from public market responses (admin reports still expose them).
> - `login` accepts `username`, `email`, `phone`, or generic `identifier`.
> - `market_history` is grouped by outcome with `series` arrays.
> - `admin_stats` and `admin_market_report` are restructured into nested dashboard blocks.
> - `admin_reseed_odds` accepts outcomes by `name` OR `outcome_id`.
> - New routes: `routes`, `request_email_verification`, `verify_email`, `request_password_reset`, `reset_password`, plus the full M-Pesa, SMS, sticker, reactions, messaging, ban, and maintenance suites.
> - Every response now carries an `X-Request-ID` header (echoed from `X-Request-ID` request header if you supply a valid one) for log correlation.
> - HSTS + `X-Frame-Options: DENY` + `Permissions-Policy` headers on HTTPS.

---

## Contents

1.  [System & discovery](#1-system--discovery)
2.  [Authentication](#2-authentication)
3.  [Email verification & password reset](#3-email-verification--password-reset)
4.  [User](#4-user)
5.  [Markets — Public](#5-markets--public)
6.  [Betting](#6-betting)
7.  [Admin — Market lifecycle](#7-admin--market-lifecycle)
8.  [Admin — Odds & pricing](#8-admin--odds--pricing)
9.  [Admin — Reporting](#9-admin--reporting)
10. [Payments — M-Pesa](#10-payments--mpesa)
11. [Admin — Users & finance](#11-admin--users--finance)
12. [Social — Reactions](#12-social--reactions)
13. [Social — Messages](#13-social--messages)
14. [Social — Stickers](#14-social--stickers)
15. [Social — SMS](#15-social--sms)
16. [Admin — User controls](#16-admin--user-controls)
17. [Maintenance mode](#17-maintenance-mode)
18. [Notifications](#18-notifications)
19. [Response envelope & error format](#19-response-envelope--error-format)
20. [HTTP status codes](#20-http-status-codes)
21. [Market state machine](#21-market-state-machine)
22. [Pricing — LMSR & fixed](#22-pricing--lmsr--fixed)
23. [Security model](#23-security-model)
24. [Environment variables](#24-environment-variables)
25. [Deployment notes (Nginx / Apache)](#25-deployment-notes-nginx--apache)
26. [Database schema overview](#26-database-schema-overview)

---

## 1. System & discovery

### Root (API identity)
```bash
curl "https://yourdomain.com/api.php"
```
```json
{
  "success": true,
  "data": {
    "name": "MwasinMarket API",
    "version": "5.2",
    "time": "2026-05-23 10:00:00 UTC",
    "status": "online",
    "docs": "?route=routes"
  }
}
```

### Routes catalogue (machine-readable)
```bash
curl "https://yourdomain.com/api.php?route=routes"
```
Returns `data.routes[]` of `{ route, method, auth, description }` for every endpoint. Use this in your frontend code generator or Postman setup.

### Health check
```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=health"
```
Admin token required in production. In `DEBUG_MODE` the route is open.

---

## 2. Authentication

### Register
Rate limited **20 per IP per hour**.

```bash
curl -X POST "https://yourdomain.com/api.php?route=register" \
  -H "Content-Type: application/json" \
  -d '{
    "username":  "charles",
    "email":     "charles@example.com",
    "phone":     "+254712345678",
    "full_name": "Charles N.",
    "password":  "SecurePass123"
  }'
```

| Field      | Required | Validation                              |
|------------|----------|-----------------------------------------|
| username   | yes      | 3–30 chars, `^[a-zA-Z0-9_-]+$`           |
| email      | yes      | Valid email, ≤254 chars                  |
| phone      | yes      | `^\+254\d{9}$` (Kenyan)                  |
| password   | yes      | 8–200 chars                              |
| full_name  | no       | ≤120 chars                               |

```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "user_id": 5,
    "username": "charles",
    "email": "charles@example.com",
    "phone": "+254712345678",
    "role": "user",
    "token": "a1b2...64hex...",
    "expires_in": 86400
  }
}
```
Errors: `409` duplicate · `422` validation · `429` rate-limited.

### Login
Rate limited **10 failures / IP / 15 min**. Successful logins clear the IP counter. Accepts `username`, `email`, `phone`, or generic `identifier`.

```bash
# By username
curl -X POST "https://yourdomain.com/api.php?route=login" \
  -H "Content-Type: application/json" \
  -d '{ "username": "charles", "password": "SecurePass123" }'

# By email
curl -X POST "https://yourdomain.com/api.php?route=login" \
  -H "Content-Type: application/json" \
  -d '{ "email": "charles@example.com", "password": "SecurePass123" }'

# By phone
curl -X POST "https://yourdomain.com/api.php?route=login" \
  -H "Content-Type: application/json" \
  -d '{ "phone": "+254712345678", "password": "SecurePass123" }'

# Generic
curl -X POST "https://yourdomain.com/api.php?route=login" \
  -H "Content-Type: application/json" \
  -d '{ "identifier": "charles", "password": "SecurePass123" }'
```

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user_id": 5,
    "username": "charles",
    "email": "charles@example.com",
    "phone": "+254712345678",
    "role": "user",
    "balance": 1500.00,
    "locked_balance": 200.00,
    "bonus_balance": 0.00,
    "token": "a1b2c3...",
    "expires_in": 86400,
    "expires_at": "2026-05-24 10:00:00"
  }
}
```
Errors: `401` bad credentials · `403` suspended · `429` rate.

### Admin login
Same shape as login but enforces `role = admin`.
```bash
curl -X POST "https://yourdomain.com/api.php?route=admin_login" \
  -H "Content-Type: application/json" \
  -d '{ "username": "admin", "password": "Admin123!" }'
```

### Logout
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=logout"
```

---

## 3. Email verification & password reset

These use the configured SMTP server (Outlook STARTTLS by default). Token = 64 hex chars; verification tokens last **24 h**, reset tokens **1 h**.

### Request email verification
Auth required. Rate limited **5/IP/hour**.
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=request_email_verification"
```

### Verify email
Public — the link/token from the email.
```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{ "token": "a1b2...64hex..." }' \
  "https://yourdomain.com/api.php?route=verify_email"
```

### Request password reset
Public. Always returns success — no email enumeration.
```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{ "email": "charles@example.com" }' \
  "https://yourdomain.com/api.php?route=request_password_reset"
```

### Reset password
Public. Token + new password. Revokes ALL existing tokens for the user.
```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{ "token": "a1b2...", "new_password": "NewStrongPass!2026" }' \
  "https://yourdomain.com/api.php?route=reset_password"
```
Errors: `401` invalid/expired · `409` already used · `422` bad token / password too short.

---

## 4. User

### Profile
Responsible-gambling note: `total_wagered` and `bet_stats.total_stake` are deliberately **not** included in the user-facing profile so users aren't anchored to a "loss count" that motivates chasing losses. Admins still see them via `admin_credit_user` and `admin_pending_withdrawals` user vetting blocks.

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=profile"
```
```json
{
  "success": true,
  "data": {
    "user_id": 5, "username": "charles", "email": "charles@example.com",
    "phone": "+254712345678", "full_name": "Charles N.", "role": "user",
    "verified": false, "email_verified": false, "phone_verified": false,
    "is_suspended": false, "messaging_restricted": false,
    "balance": 1500.00, "locked_balance": 200.00,
    "available_balance": 1300.00, "bonus_balance": 0.00,
    "total_wins": 9450.00,
    "member_since": "2026-04-16 08:00:00",
    "bet_stats": { "total": 12, "open": 3, "won": 5, "lost": 3, "void": 1,
                   "total_payout": 9450.00 }
  }
}
```

### My bets
```bash
# Open bets only (default)
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=my_bets"

# Filter
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=my_bets&status=won"

# Full history + pagination
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=my_bets&history=1&page=2&limit=50"
```

| Param   | Default | Notes                          |
|---------|---------|--------------------------------|
| status  | (open)  | `open` `won` `lost` `void`      |
| history | `0`     | `1` = all-time                  |
| page    | `1`     | ≥1                              |
| limit   | `20`    | 1–100                           |

```json
{
  "success": true,
  "data": [{
    "slip_id": "BET_A1B2C3D4",
    "market_id": "a1b2c3d4e5f67890",
    "question": "Will Newcastle beat Bournemouth?",
    "market_status": "open",
    "outcome_id": 5,
    "outcome": "Yes",
    "outcome_name": "Yes",
    "is_bonus_bet": false,
    "shares": 712.413,
    "stake": 500.00,
    "odds": 1.4248,
    "odds_at_entry": 1.4248,
    "possible_win": 712.40,
    "payout": null,
    "status": "open",
    "void_reason": null,
    "expires_at": "2026-04-22 12:00:00",
    "placed_at": "2026-04-19 14:30:00",
    "created_at": "2026-04-19 14:30:00"
  }],
  "meta": { "total": 12, "page": 1, "limit": 20, "pages": 1 }
}
```
`odds` and `odds_at_entry` are both shown for compatibility. They are identical and locked at placement — never change.

---

## 5. Markets — Public

### List
```bash
curl "https://yourdomain.com/api.php?route=markets"
# Filters:
curl "https://yourdomain.com/api.php?route=markets&category=sports"
curl "https://yourdomain.com/api.php?route=markets&market_type=binary"
curl "https://yourdomain.com/api.php?route=markets&status=active"      # open + paused
curl "https://yourdomain.com/api.php?route=markets&include_resolved=1"
curl "https://yourdomain.com/api.php?route=markets&include_archived=1"
curl "https://yourdomain.com/api.php?route=markets&limit=10&page=2"
```

| Param            | Default  | Values                                                 |
|------------------|----------|--------------------------------------------------------|
| status           | active   | `active` `open` `paused` `closed` `resolved` `voided` `all` |
| category         | (all)    | any string                                             |
| source           | (all)    | source label                                           |
| market_type      | (all)    | `binary` `categorical`                                 |
| include_resolved | `0`      | `1` includes resolved/voided                           |
| include_archived | `0`      | `1` includes archived                                  |
| page, limit      | 1, 20    |                                                        |

```json
{
  "success": true,
  "data": [{
    "id": "pm_a1b2c3d4e5f67890",
    "market_id": "a1b2c3d4e5f67890",
    "source": "local",
    "question": "Will Newcastle beat Bournemouth?",
    "title": "Newcastle vs Bournemouth",
    "image_url": "",
    "category": "sports",
    "market_type": "binary",
    "status": "open",
    "close_time": "2026-04-20 14:00:00",
    "resolve_time": null,
    "void_reason": null,
    "min_stake": 10.00,
    "max_stake": 100000.00,
    "max_total_wagered": 0.00,
    "total_bets": 38,
    "total_wagered": 12500.00,
    "is_archived": false,
    "outcomes": [
      { "outcome_id": 5, "name": "Yes", "probability": 0.701, "odds": 1.4271 },
      { "outcome_id": 6, "name": "No",  "probability": 0.299, "odds": 3.3445 }
    ],
    "created_at": "2026-04-19 08:00:00"
  }],
  "meta": { "total": 4, "page": 1, "limit": 20, "pages": 1 }
}
```
Hidden from this response: `odds_mode`, `max_odds`, `pause_reason`, `b`.

### Single market
```bash
curl "https://yourdomain.com/api.php?route=market&market_id=a1b2c3d4e5f67890"
```

### Market history — odds chart
```bash
curl "https://yourdomain.com/api.php?route=market_history&market_id=a1b2c3d4e5f67890&limit=200"
curl "https://yourdomain.com/api.php?route=market_history&market_id=a1b2c3d4e5f67890&outcome_id=5"
```
```json
{
  "success": true,
  "data": {
    "market_id": "a1b2c3d4e5f67890",
    "outcomes": [
      {
        "outcome_id": 5, "outcome_name": "Yes",
        "series": [
          { "time": "2026-04-19 08:00:00", "odds": 1.43, "volume": 0.00 },
          { "time": "2026-04-19 10:15:00", "odds": 1.45, "volume": 500.00 }
        ]
      },
      { "outcome_id": 6, "outcome_name": "No", "series": [ ... ] }
    ],
    "count": 2
  }
}
```

---

## 6. Betting

### Place a bet
Auth required. Rate limited **30 bets / user / 60 s**.

```bash
curl -X POST "https://yourdomain.com/api.php?route=bet" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "outcome_id": 5, "amount": 500 }'

# Spend bonus credit instead of cash
curl -X POST "https://yourdomain.com/api.php?route=bet" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "outcome_id": 5, "amount": 100, "use_bonus": true }'
```

```json
{
  "success": true,
  "message": "Bet placed successfully",
  "data": {
    "slip_id": "BET_A1B2C3D4",
    "bet_id": 102,
    "market_id": "a1b2c3d4e5f67890",
    "outcome_id": 5,
    "outcome_name": "Yes",
    "shares": 712.413,
    "stake": 500.00,
    "odds_at_entry": 1.4248,
    "possible_win": 712.40,
    "prob_before": 0.701,
    "prob_after": 0.708,
    "is_bonus_bet": false,
    "expires_at": "2026-04-22 12:00:00",
    "balance_after": 4500.00
  }
}
```
Errors: `401` auth · `402` insufficient · `403` suspended · `404` not found · `409` status/cap/max_odds · `422` validation · `429` rate · `503` paused.
When odds exceed `max_odds`: `"This selection is not currently available for betting."` (the cap value is never revealed to users).

---

## 7. Admin — Market lifecycle

All admin routes require `Authorization: Bearer $ADMIN_TOKEN`.

### Create market — LMSR
```bash
curl -X POST "https://yourdomain.com/api.php?route=admin_create_market" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{
    "question":    "Will Kenya inflation fall below 4% by December 2026?",
    "category":    "finance",
    "market_type": "binary",
    "odds_mode":   "lmsr",
    "b":           1000,
    "max_odds":    10.0,
    "close_time":  "2026-12-01 00:00:00",
    "outcomes": [
      { "name": "Yes", "odds": 2.50 },
      { "name": "No",  "odds": 1.67 }
    ]
  }'
```

### Create market — Fixed odds
```bash
curl -X POST "https://yourdomain.com/api.php?route=admin_create_market" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{
    "question":    "Will Newcastle beat Bournemouth?",
    "category":    "sports",
    "market_type": "binary",
    "odds_mode":   "fixed",
    "outcomes": [
      { "name": "Yes", "odds": 1.43 },
      { "name": "No",  "odds": 3.33 }
    ]
  }'
```
Fixed markets must satisfy `Σ(1/odds) ≥ 1.03` (≥ 3 % house margin). The error includes the exact implied sum if it fails.

### Categorical — LMSR with cap
```bash
curl -X POST "https://yourdomain.com/api.php?route=admin_create_market" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{
    "question":    "Who wins the 2027 Nairobi gubernatorial race?",
    "category":    "politics",
    "market_type": "categorical",
    "odds_mode":   "lmsr",
    "b":           2000,
    "max_odds":    15.0,
    "outcomes": [
      { "name": "Candidate A", "odds": 2.50 },
      { "name": "Candidate B", "odds": 3.33 },
      { "name": "Candidate C", "odds": 5.00 },
      { "name": "Other",       "odds": 10.00 }
    ]
  }'
```

| Field             | Required | Default  | Notes                                                |
|-------------------|----------|----------|------------------------------------------------------|
| question          | yes      |          | 5–500 chars                                          |
| category          | yes      |          | 2–60 chars                                           |
| outcomes          | yes      |          | 2 (binary) or 2–20 (categorical); each `{name,odds}` |
| market_type       | no       | binary   | `binary` / `categorical`                             |
| odds_mode         | no       | lmsr     | `lmsr` / `fixed`                                     |
| source, title, image_url | no |          | metadata                                             |
| market_id         | no       | auto hex | custom external ID, must be unique                   |
| close_time        | no       | null     | `Y-m-d H:i:s`, future                                |
| b                 | no       | 1000     | LMSR liquidity (50–10000)                            |
| max_odds          | no       | 10.0     | LMSR only, 1.5–1000                                  |
| min_stake         | no       | 10       |                                                      |
| max_stake         | no       | 100000   |                                                      |
| max_total_wagered | no       | 0        | 0 = unlimited                                        |

### Pause / resume / force-close / reopen
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "pause_reason": "Awaiting confirmation" }' \
  "https://yourdomain.com/api.php?route=admin_pause_market"

curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890" }' \
  "https://yourdomain.com/api.php?route=admin_resume_market"

curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "reason": "Breaking news" }' \
  "https://yourdomain.com/api.php?route=admin_force_close_market"

curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890" }' \
  "https://yourdomain.com/api.php?route=admin_reopen_market"
```

### Settle (permanent)
Pays winners (`possible_win` → balance), marks losers (stake kept by house), unlocks every affected user's `locked_balance`, updates `total_wins`, writes a `bet_won` / `bet_lost` ledger row per bet with **accurate balance_before/balance_after** snapshots, fires an SMS to every real-money winner, and writes an audit row with totals.

```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "winning_outcome_id": 5 }' \
  "https://yourdomain.com/api.php?route=admin_settle_market"
```
```json
{
  "success": true,
  "message": "Market settled",
  "data": {
    "market_id": "a1b2c3d4e5f67890",
    "status": "resolved",
    "winning_outcome": { "outcome_id": 5, "name": "Yes" },
    "winners_count": 14,
    "losers_count": 24,
    "bets_settled": 38,
    "total_payout": 10850.00,
    "total_payout_real": 10100.00,
    "total_payout_bonus": 750.00,
    "resolved_at": "2026-05-23 10:30:00"
  }
}
```
Concurrency: market and all affected user rows are locked `FOR UPDATE`. A double-call from two admin sessions hits the `resolved/voided` check and returns `409` on the second call.

### Void market (full refund, permanent)
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "reason": "Match cancelled" }' \
  "https://yourdomain.com/api.php?route=admin_void_market"
```

### Void bets by time
For breaking news after an open window. Works on any market status (targets bets, not market).
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "market_id":   "a1b2c3d4e5f67890",
    "cutoff_time": "2026-05-10 14:32:00",
    "reason":      "Match cancelled at 14:30 — bets after this time refunded."
  }' \
  "https://yourdomain.com/api.php?route=admin_void_bets_by_time"
```

### Void a single bet
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "slip_id": "BET_A1B2C3D4", "reason": "Bet placed in error" }' \
  "https://yourdomain.com/api.php?route=admin_void_bet"
```

### Archive / edit
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "archive": true }' \
  "https://yourdomain.com/api.php?route=admin_archive_market"

curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "question": "Updated?", "category": "sports" }' \
  "https://yourdomain.com/api.php?route=admin_edit_market"
```

---

## 8. Admin — Odds & pricing

### Adjust limits
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "min_stake": 25, "max_stake": 200000, "max_total_wagered": 500000, "max_odds": 8 }' \
  "https://yourdomain.com/api.php?route=admin_adjust_limits"
```

### Extend close time
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "close_time": "2026-04-30 18:00:00" }' \
  "https://yourdomain.com/api.php?route=admin_extend_close_time"
```

### Liquidity (LMSR only)
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "b": 2000 }' \
  "https://yourdomain.com/api.php?route=admin_set_liquidity"
```

### Reseed odds
Accepts outcomes by **`outcome_id`** OR **`name`** (case-insensitive). Requires `force: true` if any open bets exist.
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "market_id": "a1b2c3d4e5f67890",
    "force": true,
    "outcomes": [
      { "name": "Yes", "odds": 1.60 },
      { "name": "No",  "odds": 2.50 }
    ]
  }' \
  "https://yourdomain.com/api.php?route=admin_reseed_odds"
```

### Switch odds mode
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "odds_mode": "fixed", "force": false }' \
  "https://yourdomain.com/api.php?route=admin_set_odds_mode"
```

---

## 9. Admin — Reporting

### Dashboard stats
```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_stats"
```
```json
{
  "success": true,
  "data": {
    "users":      { "total": 25, "verified": 18, "admins": 2, "suspended": 0, "active_today": 7, "created_today": 1 },
    "markets":    { "total": 12, "open": 5, "paused": 1, "closed": 2, "resolved": 3, "voided": 1, "created_today": 2 },
    "financials": {
      "total_bets": 312, "total_wagered": 485000.00,
      "total_payouts": 321000.00, "total_refunds": 14000.00,
      "house_profit": 150000.00, "bets_today": 18, "wagered_today": 9200.00
    },
    "top_bettors": [
      { "username": "charles", "total_wagered": 45000.00, "total_wins": 38000.00, "bet_count": 87 }
    ],
    "generated_at": "2026-05-23 10:00:00"
  }
}
```

### Single-market report
```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_market_report&market_id=a1b2c3d4e5f67890"
```
```json
{
  "success": true,
  "data": {
    "market": {
      "market_id": "a1b2c3d4e5f67890",
      "question": "Will Newcastle beat Bournemouth?",
      "category": "sports", "market_type": "binary", "odds_mode": "lmsr",
      "status": "open",
      "b": 1000.0, "min_stake": 10.00, "max_stake": 100000.00,
      "max_total_wagered": 0.00, "max_odds": 10.0,
      "total_bets": 38, "total_wagered": 12500.00,
      "pause_reason": null, "void_reason": null,
      "close_time": "2026-04-20 14:00:00", "resolve_time": null
    },
    "live_odds": [
      { "outcome_id": 5, "name": "Yes", "probability": 0.701, "odds": 1.4271 },
      { "outcome_id": 6, "name": "No",  "probability": 0.299, "odds": 3.3445 }
    ],
    "bet_summary": {
      "total_bets": 38, "open": 38, "won": 0, "lost": 0, "void": 0,
      "total_staked": 12500.00, "stake_at_risk": 12500.00,
      "max_liability": 16750.00, "house_profit": 0.00
    },
    "volume_by_outcome": [
      { "outcome_id": 5, "outcome_name": "Yes", "bet_count": 25, "total_staked": 9200.00, "open_liability": 13156.00 }
    ],
    "graph_summary": [
      { "outcome_id": 5, "outcome_name": "Yes", "first_at": "...", "last_at": "...",
        "opening_odds": 1.43, "current_odds": 1.43, "snapshot_count": 26 }
    ],
    "audit_log": [
      { "id": 101, "admin_id": 1, "admin": "admin", "action": "create_market",
        "meta": { "odds_mode": "lmsr", "b": 1000, "max_odds": 10.0 },
        "at": "2026-04-19 08:00:00" }
    ]
  }
}
```

---

## 10. Payments — M-Pesa

### Initiate deposit (STK Push)
Auth required. Amount **10–300,000 KES**. Phone `^\+254\d{9}$`.
```bash
curl -X POST "https://yourdomain.com/api.php?route=deposit_request" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{ "amount": 500, "phone": "+254712345678" }'
```
Response includes `checkout_request_id` — poll the next route.

### Poll deposit status
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=payment_status&checkout_request_id=ws_CO_..."
```

### Request withdrawal
Auth required. Amount **10–150,000 KES**. Locks balance immediately; an SMS confirmation is sent.
```bash
curl -X POST "https://yourdomain.com/api.php?route=withdrawal_request" \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{ "amount": 500, "phone": "+254712345678" }'
```

### M-Pesa webhook (Safaricom → us)
No bearer auth — protected by Safaricom **IP allowlist** (`MPESA_IP_WHITELIST`) and optional `X-Mpesa-Secret` header (`MPESA_WEBHOOK_SECRET`). Always responds `HTTP 200` with `{"ResultCode":0,"ResultDesc":"Accepted"}` regardless of internal outcome.

---

## 11. Admin — Users & finance

### Credit / debit user
```bash
# Deposit
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "amount": 1000.00, "type": "deposit", "note": "M-Pesa ref: QGH7T2R" }' \
  "https://yourdomain.com/api.php?route=admin_credit_user"

# Bonus
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "amount": 250.00, "type": "bonus", "note": "Welcome bonus" }' \
  "https://yourdomain.com/api.php?route=admin_credit_user"

# Debit (negative)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "amount": -500.00, "type": "withdrawal", "note": "Manual cash payout" }' \
  "https://yourdomain.com/api.php?route=admin_credit_user"
```
`type` is one of `deposit / bonus / withdrawal / adjustment`. Magnitude ≤ 10,000,000. Cannot pull balance below zero.

### Pending withdrawals (full user vetting block)
Every pending withdrawal returns a complete `user` block: identity, KYC flags, balance breakdown, lifetime stats, deposit/withdrawal history, ban history, recent bets, and any other pending withdrawals from the same user — everything an admin needs to make an informed approve/reject decision.

```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_pending_withdrawals&page=1&limit=20"
```

```json
{
  "success": true,
  "data": [
    {
      "withdrawal_id": 5,
      "amount": 1500.00,
      "phone": "+254712345678",
      "status": "pending",
      "requested_at": "2026-05-23 10:14:00",
      "user": {
        "user_id": 5,
        "username": "charles",
        "email": "charles@example.com",
        "phone": "+254712345678",
        "full_name": "Charles N.",
        "role": "user",
        "verified": true,
        "email_verified": true,
        "phone_verified": true,
        "is_suspended": false,
        "messaging_restricted": false,
        "balance": 1800.00,
        "locked_balance": 1500.00,
        "available_balance": 300.00,
        "bonus_balance": 50.00,
        "total_wagered": 6800.00,
        "total_wins": 9450.00,
        "member_since": "2026-04-16 08:00:00",
        "bet_stats":         { "total": 12, "open": 3, "won": 5, "lost": 3, "void": 1, "total_stake": 6800.00, "total_payout": 9450.00 },
        "deposit_stats":     { "total": 8, "completed": 7, "total_completed": 12000.00, "last_deposit": "2026-05-20 15:11:00" },
        "withdrawal_stats":  { "total": 4, "completed": 2, "pending": 1, "rejected": 1, "total_completed": 3500.00, "last_withdrawal": "2026-05-18 09:02:00" },
        "net_position": 8500.00,
        "ban_history": [],
        "recent_bets": [
          { "slip_id": "BET_A1B2C3D4", "market_id": 12, "stake": 500.00, "status": "open", "created_at": "2026-05-22 18:30:00" }
        ],
        "pending_withdrawals": [
          { "id": 5, "amount": 1500.00, "phone": "+254712345678", "status": "pending", "created_at": "2026-05-23 10:14:00" }
        ]
      }
    }
  ],
  "meta": { "total": 1, "page": 1, "limit": 20, "pages": 1 }
}
```

### Approve withdrawal
The response includes the same full `user` vetting block, plus the B2C disbursement result.
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "withdrawal_id": 5, "note": "Verified KYC" }' \
  "https://yourdomain.com/api.php?route=admin_approve_withdrawal"
```
```json
{
  "success": true,
  "message": "Withdrawal approved",
  "data": {
    "withdrawal_id": 5,
    "status": "completed",
    "amount": 1500.00,
    "phone": "+254712345678",
    "approved_at": "2026-05-23 10:20:00",
    "note": "Verified KYC",
    "b2c": { "ok": true, "conversation_id": "AG_20260523_..." },
    "user": { "user_id": 5, "username": "charles", "balance": 300.00, "...": "(full vetting block)" }
  }
}
```

### Reject withdrawal
Same full user block, with the rejection reason recorded and the locked balance unlocked.
```bash
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "withdrawal_id": 5, "reason": "Phone number mismatch — please contact support" }' \
  "https://yourdomain.com/api.php?route=admin_reject_withdrawal"
```
```json
{
  "success": true,
  "message": "Withdrawal rejected",
  "data": {
    "withdrawal_id": 5,
    "status": "rejected",
    "amount": 1500.00,
    "phone": "+254712345678",
    "reason": "Phone number mismatch — please contact support",
    "rejected_at": "2026-05-23 10:20:00",
    "user": { "user_id": 5, "username": "charles", "balance": 1800.00, "...": "(full vetting block)" }
  }
}
```

### Payment report
```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_payment_report"
```

---

## 12. Social — Reactions

```bash
# Toggle heart
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "market_id": "a1b2c3d4e5f67890", "reaction": "heart" }' \
  "https://yourdomain.com/api.php?route=react"

# Count (public; adds user_has_hearted if authed)
curl "https://yourdomain.com/api.php?route=reactions&market_id=a1b2c3d4e5f67890"
```

---

## 13. Social — Messages

```bash
# Send
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "to_user_id": 5, "body": "Hey, what about the Newcastle market?", "sticker_id": 12 }' \
  "https://yourdomain.com/api.php?route=send_message"

# Inbox
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=inbox&page=1&limit=20"

# Conversation (marks received as read)
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api.php?route=conversation&user_id=5&page=1&limit=50"

# Soft-delete own message (hard-deletes only when both parties have deleted)
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "message_id": 45 }' \
  "https://yourdomain.com/api.php?route=delete_message"
```

---

## 14. Social — Stickers

```bash
# Public — list all active stickers grouped by folder
curl "https://yourdomain.com/api.php?route=stickers"

# Admin — upload (multipart/form-data, NOT JSON)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -F "sticker=@./trophy.webp" -F "name=trophy" -F "category=Sports" \
  "https://yourdomain.com/api.php?route=admin_upload_sticker"

# Admin — soft delete
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "sticker_id": 15 }' \
  "https://yourdomain.com/api.php?route=admin_delete_sticker"
```
Upload rules: MIME verified by `finfo_file()` — `image/png`, `image/gif`, `image/webp` only. Max **512 KB**, max **512×512 px**, filename always server-generated.

---

## 15. Social — SMS

```bash
# Single user
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "message": "Your withdrawal is ready." }' \
  "https://yourdomain.com/api.php?route=admin_send_sms"

# Broadcast (max 500 per call)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "broadcast": true, "filter": { "role": "user" }, "message": "New market open!" }' \
  "https://yourdomain.com/api.php?route=admin_send_sms"

# History
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_sms_history&status=sent"
```

---

## 16. Admin — User controls

```bash
# Ban (cannot ban another admin)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "reason": "Fraudulent activity" }' \
  "https://yourdomain.com/api.php?route=admin_ban_user"

# Unban
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "note": "Appeal resolved" }' \
  "https://yourdomain.com/api.php?route=admin_unban_user"

# Restrict messaging (keeps user logged in)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "user_id": 5, "restricted": true, "reason": "Spamming users" }' \
  "https://yourdomain.com/api.php?route=admin_restrict_messaging"
```

---

## 17. Maintenance mode

```bash
# Enable (or change message)
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "enabled": true, "message": "We will be back in 30 minutes." }' \
  "https://yourdomain.com/api.php?route=admin_maintenance"

# Disable
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "enabled": false }' \
  "https://yourdomain.com/api.php?route=admin_maintenance"

# Current state
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_maintenance"
```
When enabled, every route returns `HTTP 503` with `"maintenance": true` EXCEPT `admin_login`, `admin_maintenance`, `health`, `routes`.

---

## 18. Notifications

```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  "https://yourdomain.com/api.php?route=admin_notifications&unread=1"

# Mark some / all read
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "notification_ids": [1, 2, 3] }' \
  "https://yourdomain.com/api.php?route=admin_mark_notifications_read"

curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{ "all": true }' \
  "https://yourdomain.com/api.php?route=admin_mark_notifications_read"
```

---

## 19. Response envelope & error format

Success:
```json
{ "success": true, "message": "Optional", "data": { ... } }
{ "success": true, "data": [...], "meta": { "total": N, "page": 1, "limit": 20, "pages": N } }
```

Error:
```json
{ "success": false, "error": "Human message" }
{ "success": false, "error": "Validation failed", "details": ["field"] }
```
Maintenance adds `"maintenance": true`.
Every response has an `X-Request-ID` header — quote it when reporting bugs.

---

## 20. HTTP status codes

| Code | Meaning                                              |
|------|------------------------------------------------------|
| 200  | Success                                              |
| 201  | Created (register, admin_create_market, bet, …)      |
| 400  | Malformed JSON body                                  |
| 401  | Missing / invalid / expired token                    |
| 402  | Insufficient balance                                 |
| 403  | Wrong role / suspended / messaging-restricted        |
| 404  | Route or resource not found                          |
| 409  | Business conflict (status, duplicate, cap reached)   |
| 413  | Payload too large (>64 KB)                           |
| 422  | Validation failed (`details` array present)          |
| 429  | Rate limited                                         |
| 500  | Internal server error                                |
| 502  | Upstream failure (M-Pesa / SMTP)                     |
| 503  | Market paused or maintenance mode                    |

---

## 21. Market state machine

```
        create
          ↓
       ┌──────┐
  ┌────│ open │──────────────────────────┐
  │    └──┬───┘                          │
  ↓       │  force_close / auto-close    ↓
┌──────┐  ↓                       ┌──────────┐
│paused│ ┌──────┐                 │ resolved │ ← terminal
└──┬───┘ │closed│ ←─────────────→ └──────────┘
   │     └──┬───┘  settle/void   ┌──────────┐
   │        │ reopen             │  voided  │ ← terminal
   └────────┘                    └──────────┘
    resume
```

| Transition                | Route                                              |
|---------------------------|----------------------------------------------------|
| open → paused             | `admin_pause_market`                               |
| open → closed             | `admin_force_close_market` / auto on close_time    |
| open|paused|closed → resolved | `admin_settle_market`                          |
| open|paused|closed → voided   | `admin_void_market`                            |
| paused → open             | `admin_resume_market`                              |
| closed → open             | `admin_reopen_market` (clears expired close_time)  |
| resolved|voided → archived| `admin_archive_market`                             |

---

## 22. Pricing — LMSR & fixed

### EU decimal odds
| Odds  | Implied probability | Return on KES 100 |
|-------|---------------------|--------------------|
| 1.43  | 70%                 | KES 143            |
| 2.00  | 50%                 | KES 200            |
| 3.33  | 30%                 | KES 333            |
| 10.0  | 10%                 | KES 1,000          |

### LMSR `b` parameter
| b     | KES to move 50/50 → 60/40 | Best for                 |
|-------|---------------------------|--------------------------|
| 50    | ~35 KES                   | Testing only             |
| 100   | ~70 KES                   | Tiny markets             |
| 500   | ~350 KES                  | Small markets            |
| 1000  | ~700 KES                  | **Default**              |
| 2000  | ~1,400 KES                | High-volume              |
| 10000 | ~7,000 KES                | Maximum stability        |

### LMSR `max_odds` cap
When an outcome's LMSR price drifts above `max_odds`, bets on it are rejected with: `"This selection is not currently available for betting."`
The cap value is **never** revealed to end users.
Default 10.0 — adjust via `admin_adjust_limits`. Range 1.5–1000.

### LMSR vs Fixed
| Scenario                                | Mode      |
|-----------------------------------------|-----------|
| Sports betting with known lines         | fixed     |
| Exact odds with no drift                | fixed     |
| Crowd-sourced price discovery           | lmsr      |
| Political / general forecasting         | lmsr      |
| High volume with stable prices          | lmsr (high b) |

### Fixed-market overround
`Σ(1/odds)` must be **≥ 1.03** (3 % minimum house margin). Otherwise `admin_create_market` / `admin_reseed_odds` rejects with the exact implied sum.

---

## 23. Security model

- **SQL injection:** every user-bound query uses `->prepare()->execute()`. No `->query()` is ever called with user input.
- **CSRF:** none of the routes use cookies — bearer-token only — so CSRF is structurally impossible.
- **Body bomb:** 64 KB hard cap before `json_decode`. Multipart uploads ≤ 512 KB (sticker only).
- **Token:** 64 hex chars = 32 bytes entropy. Revoked on logout, ban, and password reset.
- **Password:** bcrypt cost 12. Dummy hash compared on missing-user login → no enumeration via timing.
- **Rate limits:** register 20/IP/hr; login 10 fail/IP/15 min; bet 30/user/60 s; email/reset 5/IP/hr.
- **Suspend / restrict:** banning revokes all tokens; restricted users keep betting but lose messaging.
- **Email enumeration:** `request_password_reset` always replies "sent" regardless of whether the address exists.
- **Concurrency:** every write transaction uses `FOR UPDATE` on the rows it mutates; balance deductions use `WHERE balance >= stake` guards with `rowCount() = 0` failure paths.
- **Anti-arbitrage:** LMSR `max_odds` cap, fixed-market 3 % overround floor, no sell/cash-out route, bonus winnings go to `bonus_balance` (cannot be withdrawn without admin approval), `max_total_wagered` ceiling.
- **M-Pesa:** webhook restricted by IP allowlist + optional secret; `external_reference` UNIQUE — duplicate callbacks are no-ops.
- **Audit / ledger:** every admin action writes `audit_logs`, every balance change writes `balance_transactions`; both happen **after** the financial commit so logging failures cannot roll back money.
- **Response headers:** `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: DENY`, `Permissions-Policy: interest-cohort=()`, plus HSTS on HTTPS, plus `X-Request-ID` on every response.

---

## 24. Environment variables

```
# REQUIRED
DB_HOST                   localhost
DB_NAME                   mwasinmarket
DB_USER                   root
DB_PASS                   <strong-password>           ← required, no default
FRONTEND_URL              https://app.mwasinmarket.com (CORS)
APP_PUBLIC_URL            https://app.mwasinmarket.com (used in email links)

# OPTIONAL / FEATURE
DEBUG_MODE                false
STICKER_UPLOAD_PATH       /var/www/stickers
STICKER_BASE_URL          https://cdn.mwasinmarket.com/stickers

# SMS (Africa's Talking)
SMS_PROVIDER              africastalking
SMS_API_KEY               <…>
SMS_USERNAME              <…>
SMS_SENDER_ID             MwasinMkt

# M-Pesa
MPESA_CONSUMER_KEY        <…>
MPESA_CONSUMER_SECRET     <…>
MPESA_SHORTCODE           174379
MPESA_PASSKEY             <…>
MPESA_CALLBACK_URL        https://api.mwasinmarket.com/api.php?route=mpesa_webhook
MPESA_B2C_URL             https://api.safaricom.co.ke/mpesa/b2c/v1/paymentrequest
MPESA_IP_WHITELIST        196.201.214.200,196.201.214.206,196.201.213.114
MPESA_WEBHOOK_SECRET      <optional shared secret in X-Mpesa-Secret>

# SMTP (Outlook example — works with the credentials you provided)
SMTP_HOST                 smtp-mail.outlook.com
SMTP_PORT                 587
SMTP_USERNAME             no-reply@mombasa.go.ke
SMTP_PASSWORD             <password>
SMTP_FROM                 no-reply@mombasa.go.ke
SMTP_FROM_NAME            MwasinMarket
```

---

## 25. Deployment notes (Nginx / Apache)

### Nginx + PHP-FPM
The `Authorization` header is dropped by default. Add to your PHP location block:
```nginx
location ~ \.php$ {
    fastcgi_pass    unix:/run/php/php8.1-fpm.sock;
    fastcgi_index   index.php;
    include         fastcgi_params;
    fastcgi_param   SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param   HTTP_AUTHORIZATION $http_authorization;
}

# Optional: pretty URLs at /api/* → api.php?route=*
location /api/ {
    rewrite ^/api/?$              /api.php                 last;
    rewrite ^/api/([a-zA-Z0-9_-]+)/?$ /api.php?route=$1    last;
}
```

### Apache
The bundled fallback chain already handles Apache CGI/FastCGI shared hosts. For pretty URLs add to `.htaccess`:
```apache
RewriteEngine On
RewriteRule ^api/?$                     api.php                 [L]
RewriteRule ^api/([a-zA-Z0-9_-]+)/?$    api.php?route=$1        [L,QSA]
```

### PHP config
```ini
post_max_size = 1M
upload_max_filesize = 1M
max_execution_time = 30
memory_limit = 128M
```

---

## 26. Database schema overview

Twenty InnoDB tables. All `utf8mb4_unicode_ci`. Run `schema.sql` once on a fresh MySQL 8.0+ database.

| #  | Table                          | Purpose                                                |
|----|--------------------------------|--------------------------------------------------------|
| 1  | `users`                        | Account, balances, verification, suspension            |
| 2  | `auth_tokens`                  | Active 64-hex bearer tokens (24 h TTL)                 |
| 3  | `markets`                      | All markets with full lifecycle metadata               |
| 4  | `outcomes`                     | 2–20 outcomes per market (LMSR shares / fixed odds)    |
| 5  | `bets`                         | All bets — `odds_at_entry` locked at placement         |
| 6  | `balance_transactions`         | Immutable financial ledger                             |
| 7  | `market_snapshots`             | Odds time-series for charts                            |
| 8  | `audit_logs`                   | Append-only admin/system action log                    |
| 9  | `login_attempts`               | Rate-limit storage (IP-based)                          |
| 10 | `deposits`                     | M-Pesa deposit requests (UNIQUE `external_reference`)  |
| 11 | `withdrawals`                  | Withdrawal lifecycle (pending → completed / rejected)  |
| 12 | `reactions`                    | Heart toggles per user × market                        |
| 13 | `stickers`                     | Sticker library (soft-delete only)                     |
| 14 | `messages`                     | Direct messages (soft-delete on each side)             |
| 15 | `sms_log`                      | Sent SMS audit (Africa's Talking)                      |
| 16 | `user_bans`                    | Ban history (supports multiple ban/unban cycles)       |
| 17 | `system_settings`              | Key-value (maintenance mode, etc.)                     |
| 18 | `notifications`                | Admin alert queue                                      |
| 19 | `email_verification_tokens`    | 24 h tokens for email verification                     |
| 20 | `password_reset_tokens`        | 1 h tokens for password reset                          |

All FK with sensible `ON DELETE` (CASCADE for child-of-account, SET NULL for nullable references). CHECK constraints prevent negative balances. Every column that's filtered or sorted in code has an explicit index.
