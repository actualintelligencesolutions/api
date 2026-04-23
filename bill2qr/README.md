# Bill2QR PHP Auth API

Minimal framework-free PHP API for the backend-owned master credential used by the Bill2QR app.

## Backend Intent

This backend is intentionally the source of truth only for the device owner/master profile:

- `device_uuid` is persisted by the app and sent on every auth call.
- the backend stores the owner/master PIN only
- the frontend may keep a separate local user PIN for day-to-day unlock
- `business_name` remains local to the app in this pass
- UPI updates stay owner-gated on the backend

That means the app can support a dual-pin UX without forcing the backend to store a second app-user credential yet.

## Setup

1. Copy `.env.example` to `.env` and fill in your MySQL and JWT values.
2. Run `database/schema.sql` against your MySQL database for a fresh install.
3. If you already have an older Bill2QR database, apply `database/migrations/20260423_add_owner_pin_hash.sql`.
4. Serve the API with your preferred PHP web server, pointing the document root to `public/`.

Example with PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

## Endpoints

- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/reset-pin`
- `POST /auth/update-upi`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`

## Request Examples

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

### Refresh

Request:

```json
{
  "refresh_token": "your_refresh_token"
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

The app’s separate user-pin forgot flow should remain local-only for now and should not call this API.

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
