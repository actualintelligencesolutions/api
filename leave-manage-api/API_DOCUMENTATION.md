# Leave-Manage API Documentation

Base path:

- `/api/v1`

Response envelope:

```json
{
  "success": true,
  "data": {}
}
```

Error envelope:

```json
{
  "success": false,
  "error": {
    "message": "Validation failed.",
    "details": {}
  }
}
```

## Authentication

Bearer token:

```http
Authorization: Bearer <access_token>
```

## Auth Endpoints

### `POST /api/v1/auth/login`

Request:

```json
{
  "mobile": "9876543210",
  "pin": "1234",
  "device_uuid": "android-install-uuid"
}
```

Success:

```json
{
  "success": true,
  "data": {
    "access_token": "jwt",
    "refresh_token": "token",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {
      "id": 1,
      "employee_code": "EMP100",
      "full_name": "Jane Doe",
      "mobile": "9876543210",
      "role": "super_admin",
      "status": "active"
    }
  }
}
```

### `POST /api/v1/auth/refresh`

Request:

```json
{
  "refresh_token": "token"
}
```

### `POST /api/v1/auth/logout`

Request:

```json
{
  "refresh_token": "token"
}
```

### `GET /api/v1/auth/me`

Returns the authenticated user.

### `POST /api/v1/auth/change-pin`

Request:

```json
{
  "current_pin": "1234",
  "new_pin": "5678"
}
```

## Dashboard And Self-Service

### `GET /api/v1/dashboard`

Returns:
- profile
- balances
- recent leaves
- pending approvals count
- upcoming holidays

### `GET /api/v1/me/profile`

Returns the authenticated user profile.

### `GET /api/v1/me/leave-balances`

Query params:
- `period_year` optional

### `GET /api/v1/me/leave-requests`

Query params:
- `page`
- `per_page`
- `status`

### `POST /api/v1/me/leave-requests`

Request:

```json
{
  "leave_type_id": 1,
  "start_date": "2026-05-20",
  "end_date": "2026-05-20",
  "duration_mode": "half_day",
  "day_part": "first_half",
  "reason": "Medical appointment",
  "contact_during_leave": "9876543210"
}
```

Notes:
- `duration_mode` is `full_day` or `half_day`
- `day_part` is required for `half_day`

### `GET /api/v1/me/leave-requests/{id}`

Returns leave request detail, leave-day breakdown, and approval logs.

### `POST /api/v1/me/leave-requests/{id}/cancel`

Cancels eligible pending or future approved leave.

### `GET /api/v1/me/holidays`

Query params:
- `page`
- `per_page`
- `year`
- `location_code`

### `GET /api/v1/me/comp-off`

Query params:
- `page`
- `per_page`
- `status`

## Approval Endpoints

Roles:
- `admin`
- `super_admin`

### `GET /api/v1/approvals/pending`

Returns requests the actor can approve or reject.

### `GET /api/v1/approvals/{id}`

Returns approval detail for one leave request.

### `POST /api/v1/approvals/{id}/approve`

Request:

```json
{
  "remarks": "Approved"
}
```

### `POST /api/v1/approvals/{id}/reject`

Request:

```json
{
  "remarks": "Insufficient team coverage"
}
```

## Admin Endpoints

## Users

### `GET /api/v1/admin/users`

Query params:
- `page`
- `per_page`
- `status`
- `role`
- `search`

### `POST /api/v1/admin/users`

Request:

```json
{
  "employee_code": "EMP200",
  "full_name": "John Smith",
  "mobile": "9876543211",
  "email": "john@example.com",
  "role": "staff",
  "pin": "1234",
  "status": "active",
  "department_id": 1,
  "designation_id": 1,
  "approver_group_id": 1,
  "joining_date": "2026-05-15"
}
```

### `GET /api/v1/admin/users/{id}`
### `PUT /api/v1/admin/users/{id}`
### `PATCH /api/v1/admin/users/{id}`

## Master Data

### Departments

- `GET /api/v1/admin/departments`
- `POST /api/v1/admin/departments`
- `GET /api/v1/admin/departments/{id}`
- `PUT /api/v1/admin/departments/{id}`
- `PATCH /api/v1/admin/departments/{id}`

Create/update request:

```json
{
  "name": "Operations",
  "code": "OPS",
  "status": "active"
}
```

### Designations

- `GET /api/v1/admin/designations`
- `POST /api/v1/admin/designations`
- `GET /api/v1/admin/designations/{id}`
- `PUT /api/v1/admin/designations/{id}`
- `PATCH /api/v1/admin/designations/{id}`

### Leave Types

- `GET /api/v1/admin/leave-types`
- `POST /api/v1/admin/leave-types`
- `GET /api/v1/admin/leave-types/{id}`
- `PUT /api/v1/admin/leave-types/{id}`
- `PATCH /api/v1/admin/leave-types/{id}`

Request:

```json
{
  "code": "CL",
  "name": "Casual Leave",
  "description": "General short leave",
  "unit": "day",
  "yearly_quota": 12,
  "carry_forward_allowed": true,
  "max_carry_forward": 5,
  "requires_approval": true,
  "allow_comp_off": false,
  "status": "active"
}
```

## Approver Groups

- `GET /api/v1/admin/approver-groups`
- `POST /api/v1/admin/approver-groups`
- `GET /api/v1/admin/approver-groups/{id}`
- `PUT /api/v1/admin/approver-groups/{id}`
- `PATCH /api/v1/admin/approver-groups/{id}`

Request:

```json
{
  "name": "Bangalore Admins",
  "code": "BLR-ADMINS",
  "description": "Primary Bangalore approver group",
  "status": "active"
}
```

Members:

- `GET /api/v1/admin/approver-groups/{id}/members`
- `POST /api/v1/admin/approver-groups/{id}/members`
- `PATCH /api/v1/admin/approver-groups/{id}/members/{memberId}`

Request:

```json
{
  "user_id": 1,
  "member_role": "primary",
  "status": "active"
}
```

## Holidays

- `GET /api/v1/admin/holidays`
- `POST /api/v1/admin/holidays`
- `GET /api/v1/admin/holidays/{id}`
- `PUT /api/v1/admin/holidays/{id}`
- `PATCH /api/v1/admin/holidays/{id}`

Request:

```json
{
  "holiday_date": "2026-08-15",
  "name": "Independence Day",
  "holiday_type": "public",
  "location_code": null,
  "is_optional": false
}
```

## Leave Requests Admin View

### `GET /api/v1/admin/leave-requests`

Query params:
- `page`
- `per_page`
- `status`
- `user_id`

## Leave Balances

### `GET /api/v1/admin/leave-balances`

Query params:
- `page`
- `per_page`
- `period_year`
- `user_id`
- `leave_type_id`

### `POST /api/v1/admin/leave-balances/adjust`

Request:

```json
{
  "user_id": 2,
  "leave_type_id": 1,
  "period_year": 2026,
  "adjustment_type": "credited",
  "amount": 1.5,
  "remarks": "Manual correction"
}
```

`adjustment_type`:
- `opening`
- `credited`
- `used`
- `pending`

## Comp Off

- `GET /api/v1/admin/comp-off`
- `POST /api/v1/admin/comp-off`
- `GET /api/v1/admin/comp-off/{id}`
- `PUT /api/v1/admin/comp-off/{id}`
- `PATCH /api/v1/admin/comp-off/{id}`

Create request:

```json
{
  "user_id": 2,
  "source_date": "2026-05-10",
  "units": 1,
  "expiry_date": "2026-12-31",
  "notes": "Weekend support"
}
```

## Bootstrap Import

Roles:
- `super_admin` only

### Browser page

- `GET /admin/bootstrap-import`

Use this for PHP-based upload and import from a browser.

### API endpoint

- `POST /api/v1/admin/bootstrap-import`

Accepted input:

1. `multipart/form-data` with file field `import_file`
2. JSON body with:

```json
{
  "payload": {
    "departments": [],
    "designations": [],
    "approver_groups": [],
    "users": [],
    "leave_types": [],
    "holidays": [],
    "leave_balances": []
  }
}
```

Import behavior:
- all sections are optional
- all present sections must be arrays
- import runs inside one DB transaction
- if any row fails validation, the full import is rolled back
- approver-group members must be `admin` or `super_admin`

Reference file:
- [database/imports/bootstrap-payload.example.json](/Volumes/StudioSSD/Users/cainedaniel/Drive/Actual%20Inteligence%20Solutions/api/leave-manage-api/database/imports/bootstrap-payload.example.json)

## Roles And Rules

- `staff` can use self-service endpoints only
- `admin` can review approvals assigned through approver groups
- `super_admin` can manage users, approver groups, master data, balances, comp off, and bootstrap import
- `super_admin` may approve by override when enabled in env
