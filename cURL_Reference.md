# MwasinMarket — cURL Reference

**Version:** 5.0
**Base URL:** `https://yourdomain.com/api/index.php`
**Routing:** Query string parameter — `?route=<name>`
**Auth:** `Authorization: Bearer <64-hex-token>` (where required)
**Body:** JSON, `Content-Type: application/json`. Max 64 KB.

## Response shape

```json
{ "success": true,  "message": "Optional", "data": { ... } }
{ "success": true,  "data": [...], "meta": { "total": N, "page": 1, "limit": 20, "pages": N } }
{ "success": false, "error": "Human message" }
{ "success": false, "error": "Validation failed", "details": ["field"] }
```

Maintenance error adds `"maintenance": true`.

## Status codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 400 | Malformed JSON body |
| 401 | Missing / invalid / expired token |
| 402 | Insufficient balance |
| 403 | Forbidden (wrong role / suspended / restricted) |
| 404 | Route or resource not found |
| 409 | Business conflict |
| 413 | Body too large |
| 422 | Validation failed |
| 429 | Rate limited |
| 500 | Internal server error |
| 503 | Market paused or maintenance mode |

---

# SYSTEM

## `GET ?route=` (root)
API identity.
```bash
curl https://yourdomain.com/api/index.php
```

## `GET ?route=health`
Auth: Admin token (or DEBUG_MODE on).
```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api/index.php?route=health"
```

---

# AUTHENTICATION

## `POST ?route=register`
No auth. Rate-limited 20/IP/hour.

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"username":"charles","email":"c@example.com","phone":"+254712345678","password":"strongpass","full_name":"Charles N."}' \
  "https://yourdomain.com/api/index.php?route=register"
```

Errors: 422 invalid fields · 409 username/email/phone taken · 429 rate limited.

## `POST ?route=login`
No auth. Rate-limited 10 failures/IP/15min.

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"identifier":"charles","password":"strongpass"}' \
  "https://yourdomain.com/api/index.php?route=login"
```

Errors: 401 bad credentials · 403 suspended.

## `POST ?route=admin_login`
Same as login but requires `role=admin`.

## `POST ?route=logout`
Auth: User. Revokes the current token.
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api/index.php?route=logout"
```

---

# USER

## `GET ?route=profile`
Auth: User. Returns the user record + bet stats.

## `GET ?route=my_bets`
Auth: User. Query params: `status`, `history`, `page`, `limit`.

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://yourdomain.com/api/index.php?route=my_bets&status=open&page=1&limit=20"
```

---

# MARKETS (PUBLIC)

## `GET ?route=markets`
Params: `category`, `source`, `market_type`, `status` (active/open/paused/closed/resolved/voided/all), `include_resolved`, `include_archived`, `page`, `limit`.

Hidden: `odds_mode`, `max_odds`, `pause_reason`, `b`, internal id.

## `GET ?route=market&market_id=<mid>`
Single market with live odds.

## `GET ?route=market_history&market_id=<mid>`
Params: `outcome_id`, `limit` (10–500). Time-series for chart.

---

# BETTING

## `POST ?route=bet`
Auth: User. Rate limit: 30 bets/user/60s.

```bash
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"market_id":"a1b2c3d4e5f67890","outcome_id":7,"amount":500}' \
  "https://yourdomain.com/api/index.php?route=bet"
```

Optional: `"use_bonus": true` to spend bonus balance.

Success (201):
```json
{
  "success": true,
  "message": "Bet placed successfully",
  "data": {
    "slip_id": "BET_A1B2C3D4",
    "bet_id": 102,
    "market_id": "a1b2c3d4e5f67890",
    "outcome_id": 7,
    "outcome_name": "YES",
    "shares": 712.413,
    "stake": 500,
    "odds_at_entry": 1.4248,
    "possible_win": 712.40,
    "prob_before": 0.701,
    "prob_after": 0.708,
    "is_bonus_bet": false,
    "expires_at": "2026-06-01 12:00:00",
    "balance_after": 4500.00
  }
}
```

Errors: 401 auth · 402 insufficient balance · 403 suspended · 404 market/outcome · 409 status invalid / wager cap / max_odds · 422 validation · 429 rate · 503 paused.

---

# ADMIN — MARKET LIFECYCLE

All require admin token.

## `POST ?route=admin_create_market`
```json
{
  "question": "Will Newcastle beat Arsenal on 2026-06-01?",
  "category": "sports",
  "source": "Premier League",
  "title": "Newcastle vs Arsenal",
  "image_url": "https://...",
  "market_type": "binary",
  "odds_mode": "lmsr",
  "b": 1000,
  "max_odds": 10.0,
  "min_stake": 10,
  "max_stake": 100000,
  "max_total_wagered": 0,
  "close_time": "2026-06-01 12:00:00",
  "market_id": "a1b2c3d4e5f67890",
  "outcomes": [
    { "name": "Newcastle wins", "odds": 2.10 },
    { "name": "Arsenal wins or draw", "odds": 1.90 }
  ]
}
```
Fixed markets must have `Σ(1/odds) ≥ 1.03`.

## `POST ?route=admin_pause_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "pause_reason": "Awaiting confirmation from source" }
```

## `POST ?route=admin_resume_market`
```json
{ "market_id": "a1b2c3d4e5f67890" }
```

## `POST ?route=admin_force_close_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "reason": "Breaking news" }
```

## `POST ?route=admin_reopen_market`
```json
{ "market_id": "a1b2c3d4e5f67890" }
```

## `POST ?route=admin_settle_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "winning_outcome_id": 7 }
```

## `POST ?route=admin_void_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "reason": "Cancelled match" }
```

## `POST ?route=admin_void_bets_by_time`
Voids all `open` bets placed after `cutoff_time`. Works even on resolved markets.

```bash
curl -X POST \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "market_id": "a1b2c3d4e5f67890",
    "cutoff_time": "2026-05-10 14:32:00",
    "reason": "Match was cancelled at 14:30 — bets after this time are invalid"
  }' \
  "https://yourdomain.com/api/index.php?route=admin_void_bets_by_time"
```

Success:
```json
{
  "success": true,
  "message": "Bets voided successfully",
  "data": {
    "market_id": "a1b2c3d4e5f67890",
    "cutoff_time": "2026-05-10 14:32:00",
    "reason": "Match was cancelled at 14:30...",
    "bets_voided": 12,
    "total_refunded": 8400.00,
    "affected_users": 9
  }
}
```

Errors: 404 no bets after cutoff · 409 market already voided · 422 invalid cutoff (must be in past) or reason too short.

## `POST ?route=admin_void_bet`
```json
{ "slip_id": "BET_A1B2C3D4", "reason": "Bet placed in error" }
```

## `POST ?route=admin_archive_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "archive": true }
```

## `POST ?route=admin_edit_market`
```json
{ "market_id": "a1b2c3d4e5f67890", "question": "Updated question?", "category": "sports", "source": "Premier League", "title": "...", "image_url": "..." }
```

---

# ADMIN — ODDS & PRICING

## `POST ?route=admin_adjust_limits`
```json
{ "market_id": "a1b2c3d4e5f67890", "min_stake": 50, "max_stake": 200000, "max_total_wagered": 500000, "max_odds": 8 }
```

## `POST ?route=admin_extend_close_time`
```json
{ "market_id": "a1b2c3d4e5f67890", "close_time": "2026-06-02 12:00:00" }
```

## `POST ?route=admin_set_liquidity`
```json
{ "market_id": "a1b2c3d4e5f67890", "b": 2000 }
```

## `POST ?route=admin_reseed_odds`
```json
{
  "market_id": "a1b2c3d4e5f67890",
  "force": false,
  "outcomes": [
    { "outcome_id": 7, "odds": 2.00 },
    { "outcome_id": 8, "odds": 2.05 }
  ]
}
```

## `POST ?route=admin_set_odds_mode`
```json
{ "market_id": "a1b2c3d4e5f67890", "odds_mode": "fixed", "force": false }
```

---

# ADMIN — REPORTS

## `GET ?route=admin_stats`
Dashboard totals.

## `GET ?route=admin_market_report&market_id=<mid>`
Deep single-market analytics.

## `GET ?route=admin_notifications`
Params: `unread=1`, `page`, `limit`.

## `POST ?route=admin_mark_notifications_read`
```json
{ "notification_ids": [1,2,3] }
```
or
```json
{ "all": true }
```

---

# PAYMENTS

## `POST ?route=deposit_request`
Auth: User.
```bash
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount":500,"phone":"+254712345678"}' \
  "https://yourdomain.com/api/index.php?route=deposit_request"
```

Amount range: 10–300,000 KES. Phone must match `^\+254\d{9}$`.

## `GET ?route=payment_status&checkout_request_id=<id>`
Auth: User. Returns deposit status (pending/completed/failed).

## `POST ?route=withdrawal_request`
Auth: User. Amount range: 10–150,000 KES.
```json
{ "amount": 500, "phone": "+254712345678" }
```

## `POST ?route=mpesa_webhook`
No auth token — IP whitelist + (optional) secret header `X-Mpesa-Secret`.
Daraja STK callback receiver. Always responds with HTTP 200 and `{ "ResultCode": 0, "ResultDesc": "Accepted" }`.

## `POST ?route=admin_credit_user`
Auth: Admin.
```json
{ "user_id": 5, "amount": 500, "type": "bonus", "note": "Welcome credit" }
```
`type`: deposit · bonus · withdrawal · adjustment.
Negative amount = debit (cannot reduce below zero).

## `GET ?route=admin_pending_withdrawals`
Auth: Admin. Paginated.

## `POST ?route=admin_approve_withdrawal`
Auth: Admin.
```json
{ "withdrawal_id": 5, "note": "Approved" }
```

## `POST ?route=admin_reject_withdrawal`
Auth: Admin.
```json
{ "withdrawal_id": 5, "reason": "Suspicious activity" }
```

## `GET ?route=admin_payment_report`
Auth: Admin. Aggregates + daily volumes + top depositors.

---

# SOCIAL — REACTIONS

## `POST ?route=react`
Auth: User. Toggle heart on / off.
```json
{ "market_id": "a1b2c3d4e5f67890", "reaction": "heart" }
```
Only `heart` is accepted — anything else returns 422.

Response:
```json
{
  "success": true,
  "data": {
    "market_id": "a1b2c3d4e5f67890",
    "reaction": "heart",
    "active": true,
    "total_hearts": 47
  }
}
```

## `GET ?route=reactions&market_id=<mid>`
Public. If authenticated, includes `user_has_hearted`.

```json
{
  "success": true,
  "data": {
    "market_id": "a1b2c3d4e5f67890",
    "total_hearts": 47,
    "user_has_hearted": true
  }
}
```

---

# SOCIAL — MESSAGES

## `POST ?route=send_message`
Auth: User (not suspended, not messaging-restricted).
```json
{ "to_user_id": 5, "body": "Hey, what do you think about the Newcastle market?", "sticker_id": 12 }
```
`sticker_id` is optional.

Errors: 403 suspended / restricted / recipient unavailable · 404 recipient/sticker missing · 422 body length / sticker_id invalid.

## `GET ?route=inbox`
Auth: User. Conversations grouped by other party.
Params: `page`, `limit`.

## `GET ?route=conversation&user_id=<other>`
Auth: User. Full thread newest-first. Marks all received as read.
Params: `page`, `limit` (default 50).

## `POST ?route=delete_message`
Auth: User.
```json
{ "message_id": 45 }
```
Soft delete from the calling user's side. When both sides delete, hard delete.

---

# SOCIAL — STICKERS

## `GET ?route=stickers`
Public. Returns active stickers grouped by category folder.

```json
{
  "success": true,
  "data": {
    "folders": {
      "Sports":   [ { "sticker_id": 1, "name": "trophy", "category": "Sports", "filename": "...", "url": "https://cdn.../trophy.webp" } ],
      "Finance":  [ ... ],
      "Politics": [ ... ],
      "Reactions":[ ... ]
    }
  }
}
```

## `POST ?route=admin_upload_sticker`
Auth: Admin. **multipart/form-data** (NOT JSON).
Fields:
- `sticker` (file) — required
- `name` (string, 1–80 chars) — required
- `category` (string, 1–40 chars) — required

```bash
curl -X POST \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -F "sticker=@./trophy.webp" \
  -F "name=trophy" \
  -F "category=Sports" \
  "https://yourdomain.com/api/index.php?route=admin_upload_sticker"
```

Validation: MIME must be image/png, image/gif, image/webp (verified with `finfo_file()`). Size ≤ 512 KB. Dimensions ≤ 512×512. Filename is server-generated.

## `POST ?route=admin_delete_sticker`
Auth: Admin. Soft delete (sets `is_active=0`).
```json
{ "sticker_id": 15 }
```

---

# SOCIAL — SMS

## `POST ?route=admin_send_sms`
Auth: Admin. Message ≤ 160 chars.

Single user:
```json
{ "user_id": 5, "message": "Your withdrawal is ready." }
```

Broadcast (max 500 per call):
```json
{ "broadcast": true, "filter": { "role": "user" }, "message": "New market open!" }
```

Response:
```json
{
  "success": true,
  "message": "SMS dispatched",
  "data": {
    "queued": 1,
    "sent": 1,
    "failed": 0,
    "recipients": ["+254712345678"]
  }
}
```

## `GET ?route=admin_sms_history`
Auth: Admin. Params: `page`, `limit`, `status` (queued/sent/failed).

---

# SOCIAL — ADMIN USER CONTROLS

## `POST ?route=admin_ban_user`
Auth: Admin. Cannot ban admins.
```json
{ "user_id": 5, "reason": "Fraudulent activity" }
```
- Sets `is_suspended = 1`
- Revokes all tokens
- Inserts `user_bans` row

## `POST ?route=admin_unban_user`
```json
{ "user_id": 5, "note": "Appeal resolved" }
```

## `POST ?route=admin_restrict_messaging`
```json
{ "user_id": 5, "restricted": true, "reason": "Spamming users" }
```
- Sets `messaging_restricted = 1` or 0
- User can still bet, deposit, withdraw

---

# SOCIAL — MAINTENANCE MODE

## `POST ?route=admin_maintenance`
Auth: Admin.
```json
{ "enabled": true, "message": "We'll be back in 30 minutes. Upgrading the odds engine." }
```

When enabled, ALL routes return HTTP 503 with:
```json
{ "success": false, "error": "We'll be back in 30 minutes. Upgrading the odds engine.", "maintenance": true }
```
EXCEPT: `admin_login`, `admin_maintenance`, `health`.

## `GET ?route=admin_maintenance`
Auth: Admin. Returns current state.
```json
{ "success": true, "data": { "enabled": true, "message": "...", "set_at": "..." } }
```

---

# KEY BUSINESS RULES

1. Odds at entry are locked forever.
2. Resolved and voided markets are permanent.
3. No sell / cash-out.
4. Fixed markets require `Σ(1/odds) ≥ 1.03`.
5. Admins cannot ban admins.
6. Maintenance mode never blocks `admin_login` / `admin_maintenance` / `health`.
7. Void-by-time works on any market status (targets bets, not market).
8. Bonus wins credit `bonus_balance`, never `balance`.
9. Stickers are soft-deleted only.
10. Audit / ledger writes never roll back financial commits.
