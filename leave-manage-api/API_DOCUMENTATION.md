# Leave Manage API Documentation

## Routing And Base URL

The repository root `.htaccess` now forwards `leave-manage-api` requests to `leave-manage-api/public/index.php`.

Typical externally reachable base URL:

- `/leave-manage-api`

API base path:

- `/leave-manage-api/api/v1`

Health endpoint:

- `GET /leave-manage-api/`

Bootstrap import admin page:

- `GET /leave-manage-api/admin/bootstrap-import`
- This is an HTML session-based admin page, not a JSON API endpoint.

## Content Type And Authentication

Use JSON request bodies for `POST`, `PUT`, and `PATCH` unless the endpoint explicitly accepts file upload.

```http
Content-Type: application/json
Authorization: Bearer <access_token>
```

Protected routes require a bearer token. Public routes are:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `GET /`
- `GET|POST /admin/bootstrap-import`

## Response Envelopes

Success envelope:

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

Common status codes:

- `200` success
- `201` resource created
- `204` preflight `OPTIONS`
- `401` unauthenticated or invalid token
- `403` authenticated but not authorized
- `404` route or resource not found
- `422` validation or business rule failure
- `500` unexpected server error

## Shared Data Contracts

### User

```json
{
  "id": 1,
  "employee_code": "EMP100",
  "full_name": "Jane Doe",
  "mobile": "9876543210",
  "email": "jane@example.com",
  "role": "super_admin",
  "status": "active",
  "department": {
    "id": 1,
    "name": "Engineering"
  },
  "designation": {
    "id": 2,
    "name": "Manager"
  },
  "approver_group": {
    "id": 1,
    "name": "Default Approvers"
  },
  "joining_date": "2026-05-01",
  "device_uuid": "android-install-uuid",
  "last_login_at": "2026-05-20 07:15:00"
}
```

Notes:

- `role` is one of `super_admin`, `admin`, `staff`.
- `status` is one of `active`, `inactive`.
- `department`, `designation`, and `approver_group` may be `null`.

### Leave Request Summary

```json
{
  "id": 10,
  "employee": {
    "id": 7,
    "full_name": "Jane Doe",
    "employee_code": "EMP100"
  },
  "leave_type": {
    "id": 1,
    "name": "Casual Leave",
    "code": "CL"
  },
  "approver_group": {
    "id": 1,
    "name": "Default Approvers"
  },
  "approved_by_user_id": 2,
  "approved_by_name": "Manager One",
  "start_date": "2026-05-20",
  "end_date": "2026-05-21",
  "duration_mode": "full_day",
  "total_units": 2,
  "reason": "Personal work",
  "contact_during_leave": "9876543210",
  "status": "approved",
  "rejection_reason": null,
  "applied_at": "2026-05-18 11:20:00",
  "reviewed_at": "2026-05-18 14:40:00"
}
```

### Leave Request Detail

```json
{
  "id": 10,
  "employee": {
    "id": 7,
    "full_name": "Jane Doe",
    "employee_code": "EMP100"
  },
  "leave_type": {
    "id": 1,
    "name": "Casual Leave",
    "code": "CL"
  },
  "approver_group": {
    "id": 1,
    "name": "Default Approvers"
  },
  "approved_by_user_id": 2,
  "approved_by_name": "Manager One",
  "start_date": "2026-05-20",
  "end_date": "2026-05-21",
  "duration_mode": "full_day",
  "total_units": 2,
  "reason": "Personal work",
  "contact_during_leave": "9876543210",
  "status": "approved",
  "rejection_reason": null,
  "applied_at": "2026-05-18 11:20:00",
  "reviewed_at": "2026-05-18 14:40:00",
  "days": [
    {
      "leave_date": "2026-05-20",
      "day_part": "full",
      "units": 1
    },
    {
      "leave_date": "2026-05-21",
      "day_part": "full",
      "units": 1
    }
  ],
  "approval_logs": [
    {
      "id": 88,
      "action": "applied",
      "remarks": "Personal work",
      "action_at": "2026-05-18 11:20:00",
      "action_by": {
        "id": 7,
        "full_name": "Jane Doe"
      },
      "approver_group": {
        "id": 1,
        "name": "Default Approvers"
      }
    }
  ]
}
```

Notes:

- `duration_mode` is `full_day` or `half_day`.
- `days[].day_part` is `full`, `first_half`, or `second_half`.
- `status` is `pending`, `approved`, `rejected`, or `cancelled`.

### Leave Balance

```json
{
  "id": 5,
  "user_id": 7,
  "employee_code": "EMP100",
  "full_name": "Jane Doe",
  "leave_type_id": 1,
  "leave_type_name": "Casual Leave",
  "leave_type_code": "CL",
  "period_year": 2026,
  "opening_balance": 10,
  "credited_balance": 2,
  "used_balance": 3,
  "pending_balance": 1,
  "available_balance": 8,
  "updated_at": "2026-05-20 07:30:00"
}
```

### Comp Off Credit

```json
{
  "id": 3,
  "user_id": 7,
  "employee_code": "EMP100",
  "full_name": "Jane Doe",
  "granted_by_user_id": 2,
  "granted_by_name": "Manager One",
  "source_date": "2026-05-10",
  "units": 1,
  "used_units": 0.5,
  "remaining_units": 0.5,
  "expiry_date": "2026-12-31",
  "status": "active",
  "notes": "Weekend deployment support"
}
```

### Holiday

```json
{
  "id": 4,
  "holiday_date": "2026-08-15",
  "name": "Independence Day",
  "holiday_type": "public",
  "location_code": "BLR",
  "is_optional": false
}
```

### Pagination

List endpoints return:

```json
{
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 57,
    "total_pages": 3
  }
}
```

## Public Endpoints

### `GET /`

Returns API status information.

Response:

```json
{
  "success": true,
  "data": {
    "name": "Leave Manage PHP API",
    "status": "ok",
    "version": "v1"
  }
}
```

### `POST /api/v1/auth/login`

Authenticates a user by mobile number and PIN.

Request:

```json
{
  "mobile": "9876543210",
  "pin": "1234",
  "device_uuid": "android-install-uuid"
}
```

Validation and rules:

- `mobile` required, 10 to 15 digits after stripping non-digits
- `pin` required, 4 to 6 digits
- `device_uuid` optional string, max 191 chars
- user must exist and be `active`

Response `200`:

```json
{
  "success": true,
  "data": {
    "access_token": "jwt",
    "refresh_token": "refresh-token",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {}
  }
}
```

### `POST /api/v1/auth/refresh`

Returns a new access token from a valid refresh token.

Request:

```json
{
  "refresh_token": "refresh-token"
}
```

Validation and rules:

- `refresh_token` required
- token must exist, not be revoked, not be expired
- owning user must still be `active`

Response `200`:

```json
{
  "success": true,
  "data": {
    "access_token": "jwt",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

### `POST /api/v1/auth/logout`

Revokes refresh tokens. You can send either a `refresh_token`, a bearer token, or both.

Request:

```json
{
  "refresh_token": "refresh-token"
}
```

Validation and rules:

- at least one of `refresh_token` or bearer token must be present
- if a bearer token is valid, all active refresh tokens for that user are revoked

Response `200`:

```json
{
  "success": true,
  "data": {
    "message": "Logged out successfully."
  }
}
```

## Protected Auth Endpoints

### `GET /api/v1/auth/me`

Returns the authenticated user.

Response `200`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

### `POST /api/v1/auth/change-pin`

Request:

```json
{
  "current_pin": "1234",
  "new_pin": "5678"
}
```

Validation and rules:

- both fields required, 4 to 6 digits
- `new_pin` must differ from `current_pin`
- `current_pin` must match the stored PIN

Response `200`:

```json
{
  "success": true,
  "data": {
    "message": "PIN updated successfully."
  }
}
```

## Dashboard And Self-Service Endpoints

### `GET /api/v1/dashboard`

Returns dashboard aggregates for the authenticated user.

Response `200`:

```json
{
  "success": true,
  "data": {
    "profile": {
      "id": 1,
      "employee_code": "EMP100",
      "full_name": "Jane Doe",
      "mobile": "9876543210",
      "email": "jane@example.com",
      "role": "staff",
      "status": "active"
    },
    "balances": [],
    "recent_leaves": [],
    "pending_approvals_count": 0,
    "upcoming_holidays": []
  }
}
```

Notes:

- `balances` uses the leave balance contract
- `recent_leaves` uses the leave request summary contract
- `upcoming_holidays` returns up to 5 future holidays

### `GET /api/v1/me/profile`

Returns the authenticated user profile.

Response `200`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

### `GET /api/v1/me/leave-balances`

Query params:

- `period_year` optional integer, defaults to current UTC year

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "period_year": 2026
  }
}
```

### `GET /api/v1/me/leave-requests`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `pending`, `approved`, `rejected`, `cancelled`

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

### `POST /api/v1/me/leave-requests`

Creates a leave request.

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

Validation and rules:

- `leave_type_id` required integer
- `start_date` and `end_date` required in `YYYY-MM-DD`
- `duration_mode` required: `full_day` or `half_day`
- `reason` required string, max `5000`
- `contact_during_leave` optional string, max `150`
- `day_part` optional enum: `first_half`, `second_half`
- `start_date` must be `<= end_date`
- leave cannot span multiple calendar years in v1
- `half_day` requires same `start_date` and `end_date`
- `day_part` is required for `half_day`
- leave type must exist and be `active`
- authenticated user must be `active`
- user must have an active approver group
- requested dates must not overlap mandatory holidays
- requested dates must not overlap existing `pending` or `approved` leave
- available balance must be sufficient

Response `201`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

### `GET /api/v1/me/leave-requests/{id}`

Returns a detailed leave request, including day breakdown and approval logs.

Response `200`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

Notes:

- only the owner can fetch this endpoint

### `POST /api/v1/me/leave-requests/{id}/cancel`

Cancels the user’s own request.

Validation and rules:

- only `pending` or `approved` requests can be cancelled
- past approved leave cannot be cancelled
- cancelling a pending request restores `pending_balance`
- cancelling an approved request restores `used_balance`
- comp-off usage linked to the leave is reversed on approved cancellation

Response `200`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

### `GET /api/v1/me/holidays`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `50`, max `100`
- `year` optional integer
- `location_code` optional string, max `50`

Filtering behavior:

- when `location_code` is provided, results include holidays where `location_code` matches or is `null`

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

### `GET /api/v1/me/comp-off`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `active`, `expired`, `consumed`, `cancelled`

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

## Approval Endpoints

Roles:

- `admin`
- `super_admin`

Notes:

- `super_admin` can approve any pending leave when `ALLOW_SUPER_ADMIN_APPROVAL_OVERRIDE=true`
- otherwise the actor must be an active member of the leave request’s approver group

### `GET /api/v1/approvals/pending`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

### `GET /api/v1/approvals/{id}`

Returns detailed leave request information for review.

Response `200`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

### `POST /api/v1/approvals/{id}/approve`

Request:

```json
{
  "remarks": "Approved"
}
```

Validation and rules:

- `remarks` optional string, max `1000`
- request must exist and currently be `pending`
- actor must be allowed to review it
- balance moves from `pending_balance` to `used_balance`
- when leave type has `allow_comp_off = true`, available comp-off credits are consumed in expiry order

Response `200`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

### `POST /api/v1/approvals/{id}/reject`

Request:

```json
{
  "remarks": "Insufficient team coverage"
}
```

Validation and rules:

- `remarks` required string, max `1000`
- request must exist and currently be `pending`
- actor must be allowed to review it
- balance moves from `pending_balance` back to `available_balance`

Response `200`:

```json
{
  "success": true,
  "data": {
    "leave_request": {}
  }
}
```

## Admin Endpoints

### Users

Allowed role:

- `super_admin`

#### `GET /api/v1/admin/users`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `active`, `inactive`
- `role` optional enum: `super_admin`, `admin`, `staff`
- `search` optional string, max `100`

Search matches:

- `full_name`
- `mobile`
- `employee_code`

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

#### `POST /api/v1/admin/users`

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

Validation and rules:

- `employee_code`, `full_name`, `mobile`, `role`, `pin` required
- `email`, `department_id`, `designation_id`, `approver_group_id`, `joining_date` optional
- `role` enum: `super_admin`, `admin`, `staff`
- `status` optional enum: `active`, `inactive`, default `active`
- referenced department and designation must exist
- referenced approver group must exist and be `active`

Response `201`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

#### `GET /api/v1/admin/users/{id}`

Response `200`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

#### `PUT /api/v1/admin/users/{id}`

Full replacement using the same contract as create, except `pin` is optional.

Response `200`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

#### `PATCH /api/v1/admin/users/{id}`

Partial update. Any supplied field from the user contract is merged onto the existing record. `pin` remains optional.

Response `200`:

```json
{
  "success": true,
  "data": {
    "user": {}
  }
}
```

### Departments, Designations, Leave Types

Allowed roles:

- `admin`
- `super_admin`

Shared list query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `active`, `inactive`
- `search` optional string, max `100`

Search behavior:

- matches `name` or `code`

#### Departments

- `GET /api/v1/admin/departments`
- `POST /api/v1/admin/departments`
- `GET /api/v1/admin/departments/{id}`
- `PUT /api/v1/admin/departments/{id}`
- `PATCH /api/v1/admin/departments/{id}`

Department request contract:

```json
{
  "name": "Engineering",
  "code": "ENG",
  "status": "active"
}
```

Department item:

```json
{
  "id": 1,
  "name": "Engineering",
  "code": "ENG",
  "status": "active"
}
```

#### Designations

- `GET /api/v1/admin/designations`
- `POST /api/v1/admin/designations`
- `GET /api/v1/admin/designations/{id}`
- `PUT /api/v1/admin/designations/{id}`
- `PATCH /api/v1/admin/designations/{id}`

Designation request contract matches departments.

#### Leave Types

- `GET /api/v1/admin/leave-types`
- `POST /api/v1/admin/leave-types`
- `GET /api/v1/admin/leave-types/{id}`
- `PUT /api/v1/admin/leave-types/{id}`
- `PATCH /api/v1/admin/leave-types/{id}`

Leave type request contract:

```json
{
  "code": "CL",
  "name": "Casual Leave",
  "description": "General purpose leave",
  "unit": "day",
  "yearly_quota": 12,
  "carry_forward_allowed": true,
  "max_carry_forward": 5,
  "requires_approval": true,
  "allow_comp_off": false,
  "status": "active"
}
```

Validation and rules:

- `unit` currently only supports `day`
- boolean fields accept `true/false`, `1/0`, `yes/no`

Leave type item:

```json
{
  "id": 1,
  "code": "CL",
  "name": "Casual Leave",
  "description": "General purpose leave",
  "unit": "day",
  "yearly_quota": 12,
  "carry_forward_allowed": true,
  "max_carry_forward": 5,
  "requires_approval": true,
  "allow_comp_off": false,
  "status": "active"
}
```

### Approver Groups

Allowed role:

- `super_admin`

#### `GET /api/v1/admin/approver-groups`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `active`, `inactive`
- `search` optional string, max `100`

Response item:

```json
{
  "id": 1,
  "name": "Default Approvers",
  "code": "DEFAULT",
  "description": "Primary approval chain",
  "status": "active",
  "active_member_count": 2
}
```

#### `POST /api/v1/admin/approver-groups`

Request:

```json
{
  "name": "Default Approvers",
  "code": "DEFAULT",
  "description": "Primary approval chain",
  "status": "active"
}
```

Response `201`:

```json
{
  "success": true,
  "data": {
    "group": {}
  }
}
```

#### `GET /api/v1/admin/approver-groups/{id}`

#### `PUT /api/v1/admin/approver-groups/{id}`

Full replacement using the same contract as create.

#### `PATCH /api/v1/admin/approver-groups/{id}`

Partial update using any subset of the create contract.

All three return:

```json
{
  "success": true,
  "data": {
    "group": {}
  }
}
```

#### `GET /api/v1/admin/approver-groups/{id}/members`

Response item:

```json
{
  "id": 3,
  "user_id": 2,
  "full_name": "Manager One",
  "mobile": "9876543211",
  "role": "admin",
  "member_role": "primary",
  "status": "active"
}
```

#### `POST /api/v1/admin/approver-groups/{id}/members`

Request:

```json
{
  "user_id": 2,
  "member_role": "primary",
  "status": "active"
}
```

Validation and rules:

- `user_id` required
- `member_role` optional enum: `member`, `primary`, default `member`
- `status` optional enum: `active`, `inactive`, default `active`
- user must exist
- user role must be `admin` or `super_admin`

Response `201`:

```json
{
  "success": true,
  "data": {
    "member": {}
  }
}
```

#### `PATCH /api/v1/admin/approver-groups/{id}/members/{memberId}`

Request:

```json
{
  "member_role": "member",
  "status": "inactive"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "member": {}
  }
}
```

### Holidays

Allowed roles:

- `admin`
- `super_admin`

#### `GET /api/v1/admin/holidays`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `50`, max `100`
- `year` optional integer
- `location_code` optional string, max `50`

Response:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

#### `POST /api/v1/admin/holidays`

Request:

```json
{
  "holiday_date": "2026-08-15",
  "name": "Independence Day",
  "holiday_type": "public",
  "location_code": "BLR",
  "is_optional": false
}
```

Validation and rules:

- `holiday_date` required `YYYY-MM-DD`
- `name` required, max `150`
- `holiday_type` required string, max `50`
- `location_code` optional string, max `50`
- `is_optional` required boolean-like value, default `false`

Response `201`:

```json
{
  "success": true,
  "data": {
    "holiday": {}
  }
}
```

#### `GET /api/v1/admin/holidays/{id}`

#### `PUT /api/v1/admin/holidays/{id}`

#### `PATCH /api/v1/admin/holidays/{id}`

The update routes use the same contract as create. All three return:

```json
{
  "success": true,
  "data": {
    "holiday": {}
  }
}
```

### Leave Requests

Allowed roles:

- `admin`
- `super_admin`

#### `GET /api/v1/admin/leave-requests`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `status` optional enum: `pending`, `approved`, `rejected`, `cancelled`
- `user_id` optional integer

Response:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

### Leave Balances

Allowed roles:

- `admin`
- `super_admin`

#### `GET /api/v1/admin/leave-balances`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `period_year` optional integer
- `user_id` optional integer
- `leave_type_id` optional integer

Response:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

#### `POST /api/v1/admin/leave-balances/adjust`

Request:

```json
{
  "user_id": 7,
  "leave_type_id": 1,
  "period_year": 2026,
  "adjustment_type": "credited",
  "amount": 1.5,
  "remarks": "Manual grant approved by HR"
}
```

Validation and rules:

- `user_id`, `leave_type_id`, `period_year`, `adjustment_type`, `amount` required
- `adjustment_type` enum: `opening`, `credited`, `used`, `pending`
- `amount` numeric and may be negative if the resulting balance field does not go below `0`
- referenced user and leave type must exist
- `remarks` optional string, max `1000`
- when `remarks` is provided, an `approval_logs` audit entry is written with action `balance_adjusted`

Response `200`:

```json
{
  "success": true,
  "data": {
    "balance": {}
  }
}
```

### Comp Off Credits

Allowed roles:

- `admin`
- `super_admin`

#### `GET /api/v1/admin/comp-off`

Query params:

- `page` optional integer, default `1`
- `per_page` optional integer, default `20`, max `100`
- `user_id` optional integer
- `status` optional enum: `active`, `expired`, `consumed`, `cancelled`

Response:

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

#### `POST /api/v1/admin/comp-off`

Request:

```json
{
  "user_id": 7,
  "source_date": "2026-05-10",
  "units": 1,
  "expiry_date": "2026-12-31",
  "status": "active",
  "notes": "Weekend deployment support"
}
```

Validation and rules:

- `user_id`, `source_date`, `units`, `expiry_date` required
- `units` must be greater than `0`
- `status` optional enum: `active`, `expired`, `consumed`, `cancelled`, default `active`
- `notes` optional string, max `1000`
- user must exist

Response `201`:

```json
{
  "success": true,
  "data": {
    "comp_off_credit": {}
  }
}
```

#### `GET /api/v1/admin/comp-off/{id}`

Response `200`:

```json
{
  "success": true,
  "data": {
    "comp_off_credit": {}
  }
}
```

#### `PUT /api/v1/admin/comp-off/{id}`

#### `PATCH /api/v1/admin/comp-off/{id}`

Request contract:

```json
{
  "source_date": "2026-05-10",
  "units": 1,
  "used_units": 0.5,
  "expiry_date": "2026-12-31",
  "status": "active",
  "notes": "Weekend deployment support"
}
```

Validation and rules:

- `used_units` required on update
- `used_units` cannot exceed `units`

Response `200`:

```json
{
  "success": true,
  "data": {
    "comp_off_credit": {}
  }
}
```

### Bootstrap Import API

Allowed role:

- `super_admin`

#### `POST /api/v1/admin/bootstrap-import`

Two supported input forms:

1. JSON body

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

2. Multipart upload with file field `import_file`

Validation and rules:

- payload sections must be arrays when present
- uploaded file must be a valid `.json` file
- every row inside each section must be a JSON object
- import runs in a transaction
- records are upserted by internal logic rather than blindly inserted

Response `200`:

```json
{
  "success": true,
  "data": {
    "message": "Bootstrap JSON imported successfully.",
    "import_run_id": 12,
    "summary": {
      "departments": 2,
      "designations": 3,
      "approver_groups": 1,
      "approver_group_members": 2,
      "users": 12,
      "leave_types": 4,
      "holidays": 10,
      "leave_balances": 36
    }
  }
}
```

## Important Business Rules Summary

- Login only works for users with `status = active`.
- Bearer token routes always re-check that the user is still active.
- Leave requests cannot span multiple years in v1.
- Mandatory holidays block leave applications.
- Existing pending or approved leave blocks overlapping requests.
- Leave approval and rejection both mutate leave balances immediately.
- Approved leave cancellation restores used balance and reverses comp-off usage.
- Approver group members must be users with role `admin` or `super_admin`.
- Many admin list endpoints accept `page` and `per_page`, with `per_page` capped at `100`.

## Known Implementation Notes

- `GET /api/v1/me/holidays` and `GET /api/v1/admin/holidays` share the same list behavior.
- `GET /api/v1/me/comp-off` is self-filtered to the authenticated user.
- Several service methods contain unreachable fallback 404 branches after an earlier null check; the effective behavior today may be `500` in those specific null-load-after-query cases. The contracts above describe the intended resource shapes, while actual not-found handling may be stricter in some paths until those branches are cleaned up.
