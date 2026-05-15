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

See `bootstrap-payload.example.json` for the supported shape.
