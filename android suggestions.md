# Android Suggestions

Use this as the implementation handoff for the Android app integrating with the Bill2QR backend.

## Core Direction

- Persist a stable `device_uuid` on first app launch and reuse it forever.
- Before showing any setup or login screen, always call:

```http
POST /device/check-eligibility
```

- Route the UI strictly from the backend response:
  - `owner_login` -> show owner PIN login
  - `user_login` -> show restricted user login
  - `upi_lookup` -> ask for UPI ID to discover whether this device belongs under an existing owner account

## Hard Routing Rule

- If backend already recognizes this `device_uuid`, never show setup on this device.
- This remains true even after:
  - reinstall
  - app data clear
  - local logout
- Setup is allowed only when:
  - backend says the device is unknown
  - user enters a UPI ID
  - backend confirms that no owner account exists for that UPI

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

Known owner response:

```json
{
  "success": true,
  "data": {
    "device_known": true,
    "setup_allowed": false,
    "device_role": "owner",
    "next_step": "owner_login",
    "owner_binding_status": "self"
  }
}
```

Known user response:

```json
{
  "success": true,
  "data": {
    "device_known": true,
    "setup_allowed": false,
    "device_role": "user",
    "next_step": "user_login",
    "owner_binding_status": "bound"
  }
}
```

Unknown device response:

```json
{
  "success": true,
  "data": {
    "device_known": false,
    "setup_allowed": false,
    "device_role": null,
    "next_step": "upi_lookup",
    "owner_binding_status": "unbound"
  }
}
```

### 2. Check UPI Association For Unknown Device

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "upi_id": "merchant@okaxis",
  "platform": "android"
}
```

If UPI is linked to an existing owner account:

```json
{
  "success": true,
  "data": {
    "association_found": true,
    "next_step": "owner_pin_for_user_claim"
  }
}
```

If UPI is not linked to any owner account:

```json
{
  "success": true,
  "data": {
    "association_found": false,
    "next_step": "owner_setup"
  }
}
```

### 3. Verify Owner PIN For User Claim

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "upi_id": "merchant@okaxis",
  "owner_pin": "4321"
}
```

Success response:

```json
{
  "success": true,
  "data": {
    "claim_grant": "CLAIM_GRANT_TOKEN",
    "expires_in": 300,
    "next_step": "register_user_device"
  }
}
```

Important:
- this is not a normal owner session
- do not treat this as owner access
- use it only for user-device registration

### 4. Register User Device

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "platform": "android",
  "claim_grant": "CLAIM_GRANT_TOKEN"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "message": "Device registered as a restricted user device.",
    "device": {
      "id": 2,
      "device_uuid": "android-install-uuid",
      "device_role": "user",
      "owner_device_id": 1,
      "upi_id": "merchant@okaxis"
    }
  }
}
```

### 5. Register Owner Device

Use this only when:
- device is unknown
- entered UPI is not associated with any owner account

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

## Recommended Android Flow

### A. Launch on any device

1. Create or load `device_uuid`
2. Call `/device/check-eligibility`
3. Route by response:
   - `owner_login` -> owner PIN screen
   - `user_login` -> user PIN screen
   - `upi_lookup` -> UPI entry screen

### B. Unknown device with existing owner account

1. Show UPI input
2. Submit to `/device/check-upi-association`
3. If `association_found = true`, show owner PIN verification screen
4. Submit to `/device/verify-owner-for-claim`
5. If valid, receive `claim_grant`
6. Call `/device/register-user-device`
7. After registration succeeds:
   - save local-only `user_name`
   - save local-only `user_pin`
   - never show owner setup on this device
   - future launches must go through backend eligibility and land on `user_login`

### C. Unknown device with no owner account for that UPI

1. Show UPI input
2. Submit to `/device/check-upi-association`
3. If `association_found = false`, show owner setup
4. Submit owner setup to `/auth/register`
5. Store returned owner tokens and owner device metadata

### D. Later relaunch on claimed user device

1. Load `device_uuid`
2. Call `/device/check-eligibility`
3. Backend returns `user_login`
4. Show only local user PIN login
5. Do not show owner setup or owner login

## Important UI And State Rules

- Never show setup for a device already known to backend
- Never show owner login for a known user device
- Never show user setup for a known owner device
- Hide owner-only edit/profile actions on user devices
- Hide UPI setup and update screens on user devices
- Keep `business_name` local-only in this phase
- Keep local `user_name` and `user_pin` fully local-only
- Do not call backend `reset-pin` for local user-pin reset
- Treat backend `device_role` as authoritative even if local app data was cleared
- Treat claim grant as single-purpose and short-lived
- Do not persist claim grant longer than necessary

## Good Prompt For Codex On Android Repo

```text
Integrate the Bill2QR Android app with the Bill2QR backend using device eligibility plus UPI-based unknown-device discovery.

Requirements:
- Generate and persist a stable device_uuid on first app launch
- Before showing setup/login, call POST /device/check-eligibility
- Routing rules:
  - known owner -> owner_login
  - known user -> user_login
  - unknown device -> upi_lookup
- Never show setup for any backend-known device, even after reinstall or local reset
- For unknown devices, prompt for UPI ID and call POST /device/check-upi-association
- If UPI is associated with an existing owner account:
  - collect owner PIN
  - call POST /device/verify-owner-for-claim
  - use returned claim_grant to call POST /device/register-user-device
  - then collect local user_name and local user_pin
- If UPI is not associated with an existing owner account:
  - continue to owner setup
  - call POST /auth/register
- Map the install-time Name field to backend device_name
- Keep business_name local-only
- Keep user_name and user_pin local-only
- Use backend only for owner/master credential flows and device registration
- Hide owner-only UPI editing on user devices
- Never call backend reset-pin for local user-pin forgot flow
- Keep code aligned with the existing Android architecture and networking stack

After implementation, summarize:
1. files changed
2. exact backend payload mappings
3. launch routing logic
4. any remaining backend assumptions
```
