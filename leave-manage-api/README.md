# Leave-Manage PHP API

Framework-free PHP API for the leave-management Android app.

## Structure

- `public/index.php`: single HTTP entrypoint
- `config/`: env and PDO setup
- `src/`: services, auth, helpers
- `database/schema.sql`: initial MySQL schema
- `database/seed/bootstrap_super_admin.sql.example`: first-user bootstrap example
- `database/imports/`: bootstrap notes for Excel-based data loading

## Setup

1. Copy `.env.example` to `.env` and fill in your MySQL and JWT values.
2. Run `database/schema.sql` on your MySQL database.
3. Create the first `super_admin` user manually, for example from `database/seed/bootstrap_super_admin.sql.example`.
4. Point your web root to `public/`.

Example local serve command if PHP is installed:

```bash
php -S localhost:8000 -t public
```

## Main Endpoints

### Auth

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/change-pin`

### Staff

- `GET /api/v1/dashboard`
- `GET /api/v1/me/profile`
- `GET /api/v1/me/leave-balances`
- `GET /api/v1/me/leave-requests`
- `POST /api/v1/me/leave-requests`
- `GET /api/v1/me/leave-requests/{id}`
- `POST /api/v1/me/leave-requests/{id}/cancel`
- `GET /api/v1/me/holidays`
- `GET /api/v1/me/comp-off`

### Approvals

- `GET /api/v1/approvals/pending`
- `GET /api/v1/approvals/{id}`
- `POST /api/v1/approvals/{id}/approve`
- `POST /api/v1/approvals/{id}/reject`

### Admin

- `GET|POST /api/v1/admin/users`
- `GET|PUT|PATCH /api/v1/admin/users/{id}`
- `GET|POST /api/v1/admin/departments`
- `GET|PUT|PATCH /api/v1/admin/departments/{id}`
- `GET|POST /api/v1/admin/designations`
- `GET|PUT|PATCH /api/v1/admin/designations/{id}`
- `GET|POST /api/v1/admin/leave-types`
- `GET|PUT|PATCH /api/v1/admin/leave-types/{id}`
- `GET|POST /api/v1/admin/approver-groups`
- `GET|PUT|PATCH /api/v1/admin/approver-groups/{id}`
- `GET|POST /api/v1/admin/approver-groups/{id}/members`
- `PATCH /api/v1/admin/approver-groups/{id}/members/{memberId}`
- `GET|POST /api/v1/admin/holidays`
- `GET|PUT|PATCH /api/v1/admin/holidays/{id}`
- `GET /api/v1/admin/leave-requests`
- `GET /api/v1/admin/leave-balances`
- `POST /api/v1/admin/leave-balances/adjust`
- `GET|POST /api/v1/admin/comp-off`
- `GET|PUT|PATCH /api/v1/admin/comp-off/{id}`

## Notes

- PINs are stored as password hashes.
- Refresh tokens are stored as SHA-256 hashes.
- Leave apply, approval, rejection, cancellation, and comp-off consumption use DB transactions.
- Android should call only these API endpoints, never MySQL directly.
