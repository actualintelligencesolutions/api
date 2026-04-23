# Bill2QR PHP Auth API

Minimal framework-free PHP API for device-linked PIN authentication.

## Setup

1. Copy `.env.example` to `.env` and fill in your MySQL and JWT values.
2. Run `database/schema.sql` against your MySQL database.
3. Serve the API with your preferred PHP web server, pointing the document root to `public/`.

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

### Register

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "pin": "1234",
  "device_name": "Pixel 8",
  "platform": "android",
  "upi_id": "merchant@okaxis",
  "recovery_phone": "9876543210"
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
      "device_name": "Pixel 8",
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

### Login

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "pin": "1234"
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
      "device_name": "Pixel 8",
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
      "device_name": "Pixel 8",
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

### Reset PIN

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "current_pin": "1234",
  "new_pin": "5678"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "PIN reset successful.",
    "device": {
      "id": 1,
      "device_uuid": "android-install-uuid",
      "device_name": "Pixel 8",
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

### Update UPI

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "recovery_phone": "9876543210",
  "new_upi_id": "newmerchant@okicici"
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
      "device_name": "Pixel 8",
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
      "device_name": "Pixel 8",
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
