# Android Suggestions

Use this as the implementation handoff for the Android app integrating with the Bill2QR backend.

## Core Direction

- Persist a stable `device_uuid` on first app launch and reuse it forever.
- Before showing any setup or login screen, call:

```http
POST /device/check-eligibility
```

- Branch the UI strictly from the backend response:
  - `owner_setup` -> show generic owner setup with UPI fields
  - `owner_login` -> show owner PIN login
  - `user_login` -> show restricted user login/unlock flow only

## Backend Contract

Base URL:

```text
https://api.actualintelligencesolutions.in/bill2qr
```

### 1. Check Device Eligibility

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "platform": "android"
}
```

Possible response:

```json
{
  "success": true,
  "data": {
    "setup_allowed": false,
    "device_role": "user",
    "next_step": "user_login",
    "owner_binding_status": "bound"
  }
}
```

### 2. Register Owner Device

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "device_name": "Owner Name",
  "platform": "android",
  "upi_id": "merchant@okaxis",
  "recovery_phone": "9876543210",
  "owner_pin": "4321"
}
```

Notes:
- map the install-time `name` field to backend `device_name`
- keep `business_name` local-only for now

### 3. Owner Login

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "pin": "4321"
}
```

### 4. Claim A Second Device As User

Use this after an owner-authenticated session decides the current install should become a restricted user device.

Request:

Header:

```http
Authorization: Bearer OWNER_ACCESS_TOKEN
```

Body:

```json
{
  "device_uuid": "second-device-uuid",
  "platform": "android"
}
```

After this succeeds:
- treat that device as permanently user-restricted
- future launches must call eligibility again
- if eligibility returns `user_login`, never show generic owner setup or owner login
- do not keep using owner access/refresh tokens on that device as the primary unlock path

### 5. Owner-Only UPI Update

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "new_upi_id": "newmerchant@okicici",
  "owner_pin": "4321"
}
```

## Recommended Android Flow

### Fresh owner install

1. Create/load `device_uuid`
2. Call `/device/check-eligibility`
3. If `next_step = owner_setup`, show setup screen:
   - name
   - upi id
   - business name
   - recovery mobile number
   - master pin
4. Submit owner setup with:
   - `device_uuid`
   - `device_name`
   - `platform`
   - `upi_id`
   - `recovery_phone`
   - `owner_pin`
5. Store returned access/refresh token and device metadata

### Second device that should become a user device

1. Create/load `device_uuid`
2. Call `/device/check-eligibility`
3. If not yet claimed, app may temporarily allow owner authentication flow
4. After owner is authenticated, call `/device/claim-user` with this second device’s `device_uuid`
5. Once claim succeeds:
   - save a local-only `user_name`
   - save a local-only `user_pin`
   - clear any owner-first onboarding state on that device
   - never show generic owner setup again on this device

### Later relaunch on a user device

1. Load `device_uuid`
2. Call `/device/check-eligibility`
3. If `next_step = user_login`, show only the user-facing unlock screen
4. Do not call owner login
5. Do not show UPI edit UI
6. Keep user forgot-pin/reset local-only

## Important UI/State Rules

- Hide owner-only edit/profile actions on user devices
- Hide UPI setup and update screens on user devices
- Keep `business_name` local-only in this phase
- Keep local `user_pin` completely separate from backend owner auth
- Do not call backend `reset-pin` for user local-pin reset
- Always re-check eligibility after reinstall or app state reset
- Treat `device_role = user` from backend as authoritative, even if local app state was cleared

## Good Prompt For Codex On Android Repo

```text
Integrate the Bill2QR Android app with the Bill2QR backend using a device eligibility preflight flow.

Requirements:
- Generate and persist a stable device_uuid on first app launch
- Before showing setup/login, call POST /device/check-eligibility
- Route UI strictly from next_step:
  - owner_setup
  - owner_login
  - user_login
- Map the install-time Name field to backend device_name
- Keep business_name local-only
- Keep local user_name and user_pin local-only
- Use backend only for owner/master credential flows
- Hide owner-only UPI editing on user devices
- Add the claim-user flow:
  - after owner-authenticated action on a second device, call POST /device/claim-user
  - after claim, treat device as permanently restricted
- Never show generic owner setup when next_step = user_login
- Never call backend reset-pin for local user-pin forgot flow
- Keep code aligned with the existing Android architecture and networking stack

After implementation, summarize:
1. files changed
2. exact backend payload mappings
3. screen-routing logic from check-eligibility
4. any remaining backend assumptions
```
