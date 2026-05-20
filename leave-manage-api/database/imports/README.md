# Imports

Use this folder for one-time bootstrap assets or scripts when loading:

- staff master data from Excel into `users`
- holiday calendar into `holidays`
- approver groups and group members
- opening leave balances for the current year
- JSON bootstrap payloads for the super admin upload flow

Recommended order:

1. Create departments and designations.
2. Create approver groups.
3. Import users.
4. Link users to approver groups.
5. Import holidays.
6. Import opening balances.

## Browser Upload Flow

The app now includes a PHP admin page for super admins:

- `GET /admin/bootstrap-import`

This page lets a `super_admin`:

1. log in with mobile + PIN
2. upload one `.json` bootstrap file
3. import it in a single DB transaction

If any row is invalid, the whole import is rolled back.

## API Upload Flow

The backend also exposes:

- `POST /api/v1/admin/bootstrap-import`

Auth:

- bearer token
- `super_admin` only

Accepted request styles:

1. `multipart/form-data` with file field `import_file`
2. JSON body with top-level `payload`
3. JSON body with top-level `staff_master` and/or `holiday_calendar`
4. Single raw JSON file upload for `staff_master.json` or `holiday_calendar.json`

See `bootstrap-payload.example.json` for the supported shape.
There is also a ready-to-edit example for this deployment flow:

- `subansiri-bootstrap.example.json`
- root `staff_master.json`
- root `holiday_calendar.json`

## Raw Source Support

The importer now supports two raw source shapes directly:

### `staff_master.json`

Top-level array of staff rows with fields such as:

- `employee_id`
- `name`
- `mobile`
- `pin`
- `role`
- `department`
- `designation`
- `casual_leave`
- `sick_leave`
- `earned_leave`
- `c_off`
- `status`
- `email`
- `approval_enabled`

Normalization rules:

- `employee_id` becomes `employee_code`
- blank departments become `null`
- designation names are converted into generated designation codes
- one default approver group is created and all `admin` / `super_admin` users become members
- leave balances become opening balances for the current UTC year
- leave types are auto-created as `CL`, `SL`, `EL`, and `COFF`

### `holiday_calendar.json`

Top-level array of holiday rows with fields:

- `holiday_date`
- `holiday_name`
- `notes`

Normalization rules:

- ISO datetimes like `2026-01-01T00:00:00` are trimmed to `2026-01-01`
- `holiday_name` becomes `name`
- `notes` becomes `holiday_type`
- `location_code` defaults to `null`
- `is_optional` defaults to `0`
