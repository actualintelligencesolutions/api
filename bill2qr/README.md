# Bill2QR PHP Auth API

Minimal framework-free PHP API for owner-controlled device management in the Bill2QR app.

## Backend Intent

This backend remains intentionally owner-credential-centric:

- `device_uuid` is persisted by Android and sent on every relevant call.
- the backend stores only the owner/master credential.
- secondary user PIN remains local-only in Android for now.
- a device can be classified as either:
  - `owner`: may register, login, reset owner PIN, and update UPI
  - `user`: permanently restricted from owner setup/login and owner-only mutations

This lets the app enforce a dual-experience UX while the backend remains the source of truth for owner identity and device restriction.

## Setup

1. Copy `.env.example` to `.env` and fill in your MySQL and JWT values.
2. Run `database/schema.sql` against your MySQL database for a fresh install.
3. If you already have an older Bill2QR database, apply:
   - `database/migrations/20260423_add_upi_and_recovery_phone.sql`
   - `database/migrations/20260423_add_owner_pin_hash.sql` if your DB still needs it
   - `database/migrations/20260423_add_device_restriction_fields.sql`
   - `database/migrations/20260423_add_device_claim_grants.sql`
   - `database/migrations/20260423_add_campaigns.sql`
   - `database/migrations/20260505_add_deletion_requests.sql`
4. Serve the API with your preferred PHP web server, pointing the document root to `public/`.

Example with PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

## Endpoints

- `POST /device/check-eligibility`
- `POST /device/check-upi-association`
- `POST /device/verify-owner-for-claim`
- `POST /device/register-user-device`
- `POST /campaigns/resolve`
- `GET /campaigns/html`
- `POST /campaigns/events`
- `POST /auth/register`
- `POST /auth/login`
- `POST /device/claim-user`
- `POST /auth/reset-pin`
- `POST /auth/update-upi`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`
- `GET /account-deletion`
- `POST /account-deletion/requests`
- `GET /account-deletion/admin`
- `POST /account-deletion/admin/review`

## Route Intent

- `POST /device/check-eligibility`
  - tells Android whether this `device_uuid` should see owner login, user login, or the unknown-device UPI lookup flow
- `POST /device/check-upi-association`
  - used only for unknown devices
  - checks whether the entered `upi_id` belongs to an existing root owner account
- `POST /device/verify-owner-for-claim`
  - used only for unknown devices
  - verifies the owner PIN and returns a short-lived claim grant instead of a full owner session
- `POST /device/register-user-device`
  - consumes a claim grant and registers the current unknown device as a restricted user device
- `POST /campaigns/resolve`
  - resolves the best active campaign for a placement and device context
  - enforces audience filters, cooldowns, and per-device impression caps
- `GET /campaigns/html`
  - serves hosted full-screen HTML for campaigns with `render_mode = hosted_html`
  - sends a restrictive CSP so the HTML can remain content-focused and low-risk
- `POST /campaigns/events`
  - records campaign engagement events such as `impression`, `click`, `dismiss`, and `close`
- `POST /auth/register`
  - registers or updates an owner device only
  - explicitly rejects user-classified devices
- `POST /auth/login`
  - owner-PIN login only
  - explicitly rejects user-classified devices
- `POST /device/claim-user`
  - authenticated owner action
  - converts another `device_uuid` into a permanently restricted user device bound to that owner
- `POST /auth/reset-pin`
  - owner PIN reset only
  - user-pin forgot flow must remain local-only in Android
- `POST /auth/update-upi`
  - owner-only UPI update
- `GET /account-deletion`
  - public HTML page for the Google Play account deletion URL
  - explains deletion steps, data deleted, retained audit data, and retention period
- `POST /account-deletion/requests`
  - accepts public deletion requests for either full account deletion or activity-history-only deletion
  - supports HTML form posts and JSON requests
- `GET /account-deletion/admin`
  - protected admin review page for pending and completed deletion requests
- `POST /account-deletion/admin/review`
  - protected admin action endpoint for approve, reject, and complete actions

## Request Examples

### Check Device Eligibility

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "platform": "android"
}
```

Unknown device response:

```json
{
  "success": true,
  "data": {
    "device_known": false,
    "setup_allowed": false,
    "device_role": null,
    "next_step": "upi_lookup",
    "owner_binding_status": "unbound"
  }
}
```

User device response:

```json
{
  "success": true,
  "data": {
    "device_known": true,
    "setup_allowed": false,
    "device_role": "user",
    "next_step": "user_login",
    "owner_binding_status": "bound"
  }
}
```

Owner device response:

```json
{
  "success": true,
  "data": {
    "device_known": true,
    "setup_allowed": false,
    "device_role": "owner",
    "next_step": "owner_login",
    "owner_binding_status": "self"
  }
}
```

### Check UPI Association

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "upi_id": "merchant@okaxis",
  "platform": "android"
}
```

Associated owner response:

```json
{
  "success": true,
  "data": {
    "association_found": true,
    "next_step": "owner_pin_for_user_claim"
  }
}
```

No owner found response:

```json
{
  "success": true,
  "data": {
    "association_found": false,
    "next_step": "owner_setup"
  }
}
```

If a new device skips this lookup and tries `/auth/register` with an already-associated UPI anyway, backend rejects the request with a conflict message instructing the client to continue with Add User flow instead of creating another owner account.

### Verify Owner For Claim

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "upi_id": "merchant@okaxis",
  "owner_pin": "4321"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "claim_grant": "CLAIM_GRANT_TOKEN",
    "expires_in": 300,
    "next_step": "register_user_device"
  }
}
```

### Register User Device

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "platform": "android",
  "claim_grant": "CLAIM_GRANT_TOKEN"
}
```

## Campaign Screen V1

This backend now supports an ethical remote campaign screen system for offers and announcements.

Guardrails built into this v1:

- campaigns are scheduled and status-controlled from the database
- campaigns can target `all`, `owner`, `user`, or `unknown` audiences
- campaigns can be frequency-capped per device
- campaigns can be cooled down to avoid repeated interruptions
- hosted HTML is delivered only from this backend and served with a restrictive CSP
- engagement tracking is explicit and limited to campaign events

Recommended usage:

- use `render_mode = native_json` for most campaigns
- use `render_mode = hosted_html` only when a richer full-screen layout is genuinely needed
- avoid showing campaign screens in critical task flows such as payment confirmation

## Account Deletion URL

This backend now includes a public account deletion workflow suitable for a Google Play store listing.

Configuration:

- `ACCOUNT_DELETION_AUDIT_RETENTION_DAYS`
  - number of days minimal audit metadata is retained after full deletion completion
- `ACCOUNT_DELETION_ADMIN_TOKEN`
  - shared secret for the admin review path
  - supported via HTTP Basic auth password or Bearer token
- `ACCOUNT_DELETION_SUPPORT_CONTACT`
  - optional text shown on the public deletion page

Deletion behavior:

- `account_delete`
  - deletes the root owner account, linked user-device records, refresh tokens, claim grants, and campaign/activity history
- `activity_delete`
  - deletes campaign/activity history only and leaves the account active
- after completion, the `deletion_requests` row is minimized into an audit record by masking identifiers, hashing identifiers, and clearing the public notes field

### Resolve Active Campaign

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "placement": "app_open"
}
```

No campaign response:

```json
{
  "success": true,
  "data": {
    "has_campaign": false,
    "placement": "app_open"
  }
}
```

Hosted HTML campaign response:

```json
{
  "success": true,
  "data": {
    "has_campaign": true,
    "placement": "app_open",
    "campaign": {
      "campaign_key": "summer-offer-2026",
      "name": "Summer Offer",
      "campaign_type": "offer",
      "placement": "app_open",
      "render_mode": "hosted_html",
      "screen_type": "full_screen",
      "is_dismissible": true,
      "priority": 100,
      "device_context": {
        "device_known": true,
        "device_role": "owner"
      },
      "content": {
        "title": "Festival Offer",
        "subtitle": "Flat discount this week",
        "body": "Show this only when relevant.",
        "primary_cta": {
          "cta_id": "primary",
          "label": "Learn More",
          "action_url": "https://example.com/offer"
        },
        "secondary_cta": null,
        "theme": {
          "background": "#111111",
          "foreground": "#ffffff"
        },
        "payload": null,
        "html_url": "https://api.actualintelligencesolutions.in/bill2qr/campaigns/html?campaign_key=summer-offer-2026&device_uuid=android-install-uuid"
      },
      "tracking": {
        "campaign_key": "summer-offer-2026",
        "track_endpoint": "/campaigns/events"
      }
    }
  }
}
```

### Track Campaign Event

Request:

```json
{
  "campaign_key": "summer-offer-2026",
  "device_uuid": "android-install-uuid",
  "event_type": "impression",
  "cta_id": null,
  "dwell_time_ms": 0,
  "metadata": {
    "placement": "app_open"
  }
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "Campaign event tracked."
  }
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "Device registered as a restricted user device.",
    "device": {
      "id": 2,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "user",
      "owner_device_id": 1,
      "is_active": true,
      "created_at": "2026-04-23 06:00:00",
      "updated_at": "2026-04-23 06:00:00",
      "last_login_at": null
    }
  }
}
```

### Register Owner Device

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "device_name": "Teat",
  "platform": "android",
  "upi_id": "merchant@okaxis",
  "recovery_phone": "9876543210",
  "owner_pin": "4321"
}
```

Important:

- owner registration is allowed only when the entered `upi_id` is not already associated with another active root owner device
- if a different root owner already owns that `upi_id`, backend returns `409` with this message:

```text
This UPI ID is already associated with an existing account. Continue with Add User flow on this device instead of creating a new owner setup.
```

Response:

```json
{
  "success": true,
  "data": {
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "owner",
      "owner_device_id": null,
      "is_active": true,
      "created_at": "2026-04-23 05:25:32",
      "updated_at": "2026-04-23 05:25:32",
      "last_login_at": null
    },
    "tokens": {
      "access_token": "ACCESS_TOKEN",
      "token_type": "Bearer",
      "expires_in": 900,
      "refresh_token": "REFRESH_TOKEN"
    }
  }
}
```

### Login With Owner PIN

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "pin": "4321"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "owner",
      "owner_device_id": null,
      "is_active": true,
      "created_at": "2026-04-23 05:25:32",
      "updated_at": "2026-04-23 05:30:12",
      "last_login_at": "2026-04-23 05:30:12"
    },
    "tokens": {
      "access_token": "ACCESS_TOKEN",
      "token_type": "Bearer",
      "expires_in": 900,
      "refresh_token": "REFRESH_TOKEN"
    }
  }
}
```

### Claim Another Device As User

Request:

Send `Authorization: Bearer <owner_access_token>`.

```json
{
  "device_uuid": "second-device-uuid",
  "platform": "android"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "Device claimed as a restricted user device.",
    "device": {
      "id": 2,
      "device_uuid": "second-device-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "user",
      "owner_device_id": 1,
      "is_active": true,
      "created_at": "2026-04-23 06:00:00",
      "updated_at": "2026-04-23 06:00:00",
      "last_login_at": null
    }
  }
}
```

### Reset Owner PIN

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "new_pin": "5678",
  "confirm_pin": "5678"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "Owner PIN reset successful for registered device.",
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "owner",
      "owner_device_id": null,
      "is_active": true,
      "created_at": "2026-04-23 05:25:32",
      "updated_at": "2026-04-23 05:40:00",
      "last_login_at": "2026-04-23 05:30:12"
    },
    "tokens": {
      "access_token": "ACCESS_TOKEN",
      "token_type": "Bearer",
      "expires_in": 900,
      "refresh_token": "REFRESH_TOKEN"
    }
  }
}
```

The app’s separate user-pin forgot flow should remain local-only for now and must not call this API.

### Update UPI

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "new_upi_id": "newmerchant@okicici",
  "owner_pin": "4321"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "UPI ID updated successfully.",
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "newmerchant@okicici",
      "recovery_phone": "9876543210",
      "device_role": "owner",
      "owner_device_id": null,
      "is_active": true,
      "created_at": "2026-04-23 05:25:32",
      "updated_at": "2026-04-23 05:45:00",
      "last_login_at": "2026-04-23 05:30:12"
    }
  }
}
```

### Me

Request:

Send `Authorization: Bearer <access_token>`.

Response:

```json
{
  "success": true,
  "data": {
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Teat",
      "platform": "android",
      "upi_id": "merchant@okaxis",
      "recovery_phone": "9876543210",
      "device_role": "owner",
      "owner_device_id": null,
      "is_active": true,
      "created_at": "2026-04-23 05:25:32",
      "updated_at": "2026-04-23 05:45:00",
      "last_login_at": "2026-04-23 05:30:12"
    }
  }
}
```

### Logout

Request:

Send `refresh_token` in the JSON body, or send a bearer access token to revoke all active refresh tokens for that device.

Response:

```json
{
  "success": true,
  "data": {
    "message": "Logged out successfully."
  }
}
```

## Error Response Shape

All errors return this JSON structure:

```json
{
  "success": false,
  "error": {
    "message": "Human-readable error message",
    "details": []
  }
}
```
