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

### Register / Login

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

### Refresh

```json
{
  "refresh_token": "your_refresh_token"
}
```

### Reset PIN

```json
{
  "device_uuid": "android-install-uuid",
  "current_pin": "1234",
  "new_pin": "5678"
}
```

### Update UPI

```json
{
  "device_uuid": "android-install-uuid",
  "recovery_phone": "9876543210",
  "new_upi_id": "newmerchant@okicici"
}
```

### Me

Send `Authorization: Bearer <access_token>`.

### Logout

Send `refresh_token` in the JSON body, or send a bearer access token to revoke all active refresh tokens for that device.
