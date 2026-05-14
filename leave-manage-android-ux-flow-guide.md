# Leave-Manage Android UX Flow Guide

Use this as the Android implementation handoff for the leave-management app experience.

This guide focuses on:
- screen flow
- user roles
- reusable UI components
- loading and error behavior
- API-driven app states

The backend source of truth is the new `leave-manage-api`.

## Core UX Direction

- Keep the app simple, fast, and low-friction for daily staff use.
- Make login and leave actions feel trustworthy and easy to understand.
- Show only the actions relevant to the logged-in role.
- Prefer guided flows over crowded dashboards.
- Reduce admin confusion by separating review actions from setup actions.

## Supported Roles

- `staff`
- `admin`
- `super_admin`

## App Architecture Direction

- Use one authenticated app shell after login.
- Use role-based navigation and visibility.
- Keep reusable components shared across staff, admin, and super admin flows.
- Treat API responses as the source of truth for balances, requests, approvals, and holidays.

## Main Navigation Model

### Staff bottom navigation

- `Home`
- `Leaves`
- `Holidays`
- `Profile`

### Admin bottom navigation

- `Home`
- `Approvals`
- `Team`
- `Profile`

### Super admin bottom navigation

- `Home`
- `Approvals`
- `Admin`
- `Profile`

Do not overload the main nav with too many items.

## Reusable UI Components

Build these as shared components early:

- `PinInputView`
  - segmented PIN boxes
  - numeric only
  - auto-advance
  - error state
- `AppTopBar`
  - screen title
  - optional back button
  - optional action icon
- `SummaryCard`
  - used for balances, pending counts, and quick stats
- `StatusChip`
  - `pending`, `approved`, `rejected`, `cancelled`
- `PrimaryButton`
- `SecondaryButton`
- `EmptyStateView`
- `ErrorStateView`
- `ShimmerLoadingView`
- `LeaveRequestCard`
- `ApprovalCard`
- `HolidayCard`
- `SectionHeaderRow`
- `FilterBottomSheet`
- `ConfirmationDialog`

## Authentication Flow

### 1. Splash screen

Purpose:
- check whether access token exists
- attempt refresh if needed
- route user to login or app shell

Behavior:
- show centered logo and short loading state
- do not keep the user here for long
- if refresh succeeds, go directly to role-based home
- if refresh fails, clear session and go to login

### 2. Login screen

Fields:
- mobile number
- PIN

Actions:
- `Login`

Behavior:
- mobile number should use numeric keypad
- PIN should use reusable segmented input
- disable submit while request is in flight
- show field-level validation first
- show one clean API error message if login fails

Request:

```json
{
  "mobile": "9876543210",
  "pin": "1234",
  "device_uuid": "android-install-uuid"
}
```

Success behavior:
- persist tokens
- persist minimal user session
- route based on `role`

Error behavior:
- invalid login: `Invalid mobile or PIN`
- inactive user: show blocked-account style message
- network failure: show retry CTA

### 3. Change PIN screen

Fields:
- current PIN
- new PIN
- confirm new PIN

Behavior:
- use same PIN component
- validate `new PIN != current PIN`
- confirm values match before API call

## Role-Based Home Flow

## Staff Home

Purpose:
- quick overview of leave status
- fast access to apply leave
- visibility into balances and upcoming holidays

Sections:
- greeting with employee name
- balance summary cards
- pending / approved / rejected quick counts
- upcoming holidays
- recent leave requests
- primary CTA: `Apply Leave`

Suggested layout order:
1. greeting and profile summary
2. leave balances
3. quick actions
4. recent requests
5. upcoming holidays

Quick actions:
- `Apply Leave`
- `My Leaves`
- `Holidays`

## Admin Home

Purpose:
- daily review surface for pending approvals
- quick visibility into team activity

Sections:
- greeting
- pending approvals count
- requests awaiting action
- recent team leave activity
- upcoming holidays

Quick actions:
- `Review Approvals`
- `View Team Leaves`
- `Grant Comp Off`

## Super Admin Home

Purpose:
- control center for approval and master-data management

Sections:
- greeting
- pending approvals count
- active approver groups count
- active staff count
- upcoming holidays
- recent admin activity

Quick actions:
- `Review Approvals`
- `Manage Users`
- `Manage Approver Groups`
- `Manage Leave Types`

## Leave Request Flow

### 1. Apply Leave screen

Fields:
- leave type
- start date
- end date
- duration mode
  - `Full day`
  - `Half day`
- day part
  - show only when `Half day`
  - `First half`
  - `Second half`
- reason
- contact during leave

Behavior:
- half-day should force same start and end date
- total leave units should preview live before submit
- show balance hint after leave type selection
- if approver group is missing, block submit and show a clear explanation

Live helper text examples:
- `Available balance: 6.5 days`
- `This request will use 0.5 day`
- `Approver group: Bangalore Admin Review`

Primary CTA:
- `Submit Leave Request`

Success behavior:
- show success confirmation
- route to leave request detail

### 2. Leave request detail screen

Sections:
- request status
- leave type
- dates and duration
- reason
- approver group
- approval timeline

Actions:
- if `pending`: `Cancel Request`
- if `approved` and future leave: `Cancel Request`
- otherwise: no destructive action

### 3. My Leaves listing

Tabs or chips:
- `All`
- `Pending`
- `Approved`
- `Rejected`
- `Cancelled`

Each card shows:
- leave type
- dates
- unit count
- status
- submitted date

Empty states:
- no leave requests yet
- no requests in this filter

## Approval Flow

### 1. Pending approvals screen

Audience:
- `admin`
- `super_admin`

Purpose:
- show requests the user can act on

List item content:
- employee name
- leave type
- dates
- total units
- applied date
- short reason preview

Filters:
- pending only by default
- optional date range
- optional employee search later

### 2. Approval detail screen

Sections:
- employee summary
- leave request details
- leave day breakdown
- current balance snapshot if available
- prior approval log

Primary actions:
- `Approve`
- `Reject`

Reject behavior:
- require remarks

Approve behavior:
- optional remarks

Post-action behavior:
- show success toast/snackbar
- remove from pending list
- update counts immediately

## Holiday Flow

### Holiday listing screen

Audience:
- all roles

Sections:
- current month holidays
- upcoming holidays

Card content:
- holiday name
- date
- holiday type
- optional tag

Filters:
- year
- location later if needed

## Comp Off Flow

### Staff comp off view

Purpose:
- show earned credits and expiry clearly

Each card shows:
- source date
- total units
- used units
- remaining units
- expiry date
- status

### Admin comp off flow

Entry:
- from admin home or team member detail

Fields:
- staff member
- source date
- units
- expiry date
- notes

Primary CTA:
- `Grant Comp Off`

## Admin Management Flow

## Team management

### User list screen

Audience:
- `super_admin`

Features:
- search by name, employee code, mobile
- filter by role
- filter by active or inactive
- add user CTA

### User create/edit screen

Fields:
- employee code
- full name
- mobile
- email
- role
- PIN for create
- status
- department
- designation
- approver group
- joining date

Behavior:
- PIN field required on create
- PIN field optional on edit
- approver group required for staff in operational usage, even if backend allows null during setup

## Approver group management

### Approver group list screen

Audience:
- `super_admin`

Each row shows:
- group name
- code
- status
- active member count

Actions:
- add group
- open group detail

### Approver group detail screen

Sections:
- group info
- member list
- assigned employees count later if needed

Member actions:
- add member
- activate/inactivate member
- mark primary member if that becomes meaningful in UI

Important UX note:
- v1 approval rule is still `any one active member can approve`
- do not imply multi-step approval in the UI

## Leave type management

Fields:
- code
- name
- description
- yearly quota
- carry forward allowed
- max carry forward
- requires approval
- allow comp off
- status

## Holiday management

Fields:
- holiday date
- name
- holiday type
- location code
- optional holiday toggle

## Dashboard Loading and State Rules

For every major screen, support these states:

- initial loading
- loaded
- empty
- inline error
- retry

Rules:
- never show a blank white screen while loading
- keep pull-to-refresh on list screens
- keep cached visible data on soft refresh when possible
- use blocking full-screen loaders only on first load or critical submits

## Form UX Rules

- validate locally before API calls
- highlight only the fields that failed
- keep error copy plain and human
- keep submit buttons disabled during in-flight requests
- prevent double-submit on leave apply, approve, reject, and create actions

## Feedback Patterns

Use:
- toast/snackbar for short success feedback
- inline error text for field validation
- confirmation dialog for cancellation, approval, rejection, and destructive admin updates

Suggested confirmation copy:

- Cancel leave:
  - `Are you sure you want to cancel this leave request?`
- Approve leave:
  - `Approve this leave request?`
- Reject leave:
  - `Reject this leave request? This action requires remarks.`

## Suggested Screen Inventory

- Splash
- Login
- Change PIN
- Staff Home
- Admin Home
- Super Admin Home
- My Profile
- My Leave Balances
- My Leaves List
- Leave Request Detail
- Apply Leave
- Holidays List
- My Comp Off
- Pending Approvals List
- Approval Detail
- User List
- User Create/Edit
- Approver Group List
- Approver Group Detail
- Add Approver Group Member
- Leave Type List
- Leave Type Create/Edit
- Holiday List Admin
- Holiday Create/Edit
- Leave Balances Admin List
- Balance Adjustment Dialog
- Comp Off List Admin
- Grant Comp Off

## API Mapping Guide

### Auth

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/change-pin`

### Staff self-service

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

## Recommended UX Principles for v1

- Make the happy path extremely clear.
- Keep admin controls powerful but not cluttered.
- Prefer visibility over cleverness.
- Use status colors consistently across the app.
- Avoid deep nesting in navigation.
- Keep request history and approval history transparent.
- Let staff feel confident before they submit leave.
- Let approvers act quickly without losing context.
