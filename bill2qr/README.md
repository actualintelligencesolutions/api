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
  "platform": "android"
}
```

### Refresh

```json
{
  "refresh_token": "your_refresh_token"
}
```

### Me

Send `Authorization: Bearer <access_token>`.

### Logout

Send `refresh_token` in the JSON body, or send a bearer access token to revoke all active refresh tokens for that device.
