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
- `POST /auth/register`
- `POST /auth/login`
- `POST /device/claim-user`
- `POST /auth/reset-pin`
- `POST /auth/update-upi`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`

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
