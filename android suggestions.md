# Android Suggestions

Use this as the implementation handoff for the Android app changes around PIN UX cleanup, main-screen simplification, and campaign banner rollout.

## Core Direction

- Standardize every PIN experience with full-width segmented PIN boxes.
- Remove `Claim User` and `Logout` from the owner main screen.
- Show campaign content in two ways:
  - inline top banner on the owner main screen
  - full-screen campaign where a higher-priority announcement or offer needs interruption

## 1. PIN UI Cleanup

Apply one reusable PIN component across all PIN screens.

### Required behavior

- PIN boxes should stretch across the available width.
- Each digit cell should have equal width.
- Use numeric keypad only.
- Keep secure masking behavior.
- Support auto-advance when typing.
- Support backspace moving focus left.
- Support paste when possible.
- Show error state cleanly without changing layout drastically.

### Required titles

Use explicit titles above the PIN boxes instead of vague placeholders.

- Owner login: `Enter Owner PIN`
- New owner PIN setup: `Enter New PIN`
- Confirm owner PIN: `Confirm PIN`
- User PIN setup: `Enter User PIN`
- Confirm user PIN: `Confirm PIN`
- Owner PIN verification for Add User flow: `Enter Owner PIN`
- PIN reset: `Enter New PIN` and `Confirm PIN`

### Screens that should use the same PIN component

- owner login
- owner PIN setup
- owner PIN reset
- user PIN setup
- user PIN login
- confirm PIN screens

### Validation notes

- Do not change backend payload expectations for owner login.
- Owner login still sends:

```json
{
  "device_uuid": "stored-device-uuid",
  "pin": "1234"
}
```

- If device UUID is missing locally, show a precise error instead of a generic required-fields error.

## 2. Main Screen Cleanup

On the owner main screen:

- remove `Claim User`
- remove `Logout`

Important:

- do not remove backend support for logout
- do not remove backend support for user-device registration
- if logout still exists elsewhere, keep it in a secondary settings/profile surface
- if Add User is still needed, surface it only inside the guided Add User flow and not as a persistent main action

## 3. Campaign Banner Rollout

Use the existing backend campaign system as the single source for both banner and full-screen campaign delivery.

### Day-one rule

- Owner home screen should request an inline banner campaign first.
- Full-screen campaigns remain available for separate placements and higher-priority cases.

### Owner home banner request

Android should call:

```http
POST /campaigns/resolve
```

with:

```json
{
  "device_uuid": "android-install-uuid",
  "placement": "owner_home"
}
```

### Expected campaign usage

- `screen_type = banner`
  - render inline near the top of the owner main screen
  - non-blocking
- `screen_type = full_screen`
  - use the dedicated full-screen campaign path

### Render mode rules

- `native_json`
  - first-class support for inline banner rendering
  - preferred for owner-home banner
- `hosted_html`
  - allowed only for full-screen or dedicated campaign surfaces
  - do not embed arbitrary hosted HTML inline inside the owner home screen banner area

### Banner UI expectations

- place banner near the top of the owner main screen
- keep it clearly visible but not disruptive
- show title, subtitle/body, CTA, and dismiss action if allowed
- banner should not block calculator or payment interactions
- if banner request fails, show no banner and continue loading the screen normally

## 4. Backend Contract To Use

### Resolve campaign

Request:

```json
{
  "device_uuid": "android-install-uuid",
  "placement": "owner_home"
}
```

Possible banner response:

```json
{
  "success": true,
  "data": {
    "has_campaign": true,
    "placement": "owner_home",
    "campaign": {
      "campaign_key": "festival-banner-2026",
      "name": "Festival Banner",
      "campaign_type": "offer",
      "placement": "owner_home",
      "render_mode": "native_json",
      "screen_type": "banner",
      "is_dismissible": true,
      "priority": 100,
      "device_context": {
        "device_known": true,
        "device_role": "owner"
      },
      "content": {
        "title": "Festival Offer",
        "subtitle": "Special pricing this week",
        "body": "Tap to learn more.",
        "primary_cta": {
          "cta_id": "primary",
          "label": "View Offer",
          "action_url": "https://example.com/offer"
        },
        "secondary_cta": null,
        "theme": {
          "background": "#FFF4E5",
          "foreground": "#5D3A00"
        },
        "payload": null,
        "html_url": null
      },
      "tracking": {
        "campaign_key": "festival-banner-2026",
        "track_endpoint": "/campaigns/events"
      }
    }
  }
}
```

No-banner response:

```json
{
  "success": true,
  "data": {
    "has_campaign": false,
    "placement": "owner_home"
  }
}
```

### Track campaign events

Android should log banner engagement using:

```http
POST /campaigns/events
```

Examples:

Banner impression:

```json
{
  "campaign_key": "festival-banner-2026",
  "device_uuid": "android-install-uuid",
  "event_type": "impression",
  "metadata": {
    "placement": "owner_home",
    "screen_type": "banner"
  }
}
```

Banner click:

```json
{
  "campaign_key": "festival-banner-2026",
  "device_uuid": "android-install-uuid",
  "event_type": "click",
  "cta_id": "primary",
  "metadata": {
    "placement": "owner_home",
    "screen_type": "banner"
  }
}
```

Banner dismiss:

```json
{
  "campaign_key": "festival-banner-2026",
  "device_uuid": "android-install-uuid",
  "event_type": "dismiss",
  "metadata": {
    "placement": "owner_home",
    "screen_type": "banner"
  }
}
```

## 5. Recommended Android Flow

### A. Owner main screen load

1. Load owner home screen.
2. Call `/campaigns/resolve` with:
   - `device_uuid`
   - `placement = owner_home`
3. If `has_campaign = false`, show no banner.
4. If `has_campaign = true` and `screen_type = banner`, render the inline banner.
5. If user taps CTA, track click and route accordingly.
6. If banner is dismissible and user dismisses it, track dismiss and hide it locally for the current render/session.

### B. Full-screen campaign flow

1. For placements intended to allow interruption, call `/campaigns/resolve` with that placement.
2. If response returns `screen_type = full_screen`, show the dedicated campaign screen.
3. If `render_mode = hosted_html`, open the dedicated hosted HTML campaign surface.
4. Track impression, click, dismiss, and close as appropriate.

### C. Add User flow remains guided

- Keep Add User as a guided flow after UPI association and owner PIN verification.
- Do not expose `Claim User` as a persistent main-screen action.

## 6. Important UI And State Rules

- Main owner screen should not show `Claim User`.
- Main owner screen should not show `Logout`.
- Inline banner is owner-only in v1.
- Banner fetch failure must never block the main screen.
- Hosted HTML must not be embedded as an inline banner.
- PIN titles should always be visible and screen-specific.
- PIN component should have a consistent look across login, setup, and reset flows.

## 7. Good Prompt For Codex On Android Repo

```text
Implement the following Android changes for Bill2QR:

1. Replace all PIN inputs with a reusable segmented PIN component.
   Requirements:
   - full-width
   - equal-width digit boxes
   - secure numeric input
   - auto-advance and backspace support
   - visible title above the PIN field

2. Use these titles where appropriate:
   - Enter Owner PIN
   - Enter New PIN
   - Confirm PIN
   - Enter User PIN

3. Remove Claim User and Logout from the owner main screen only.
   - Do not remove backend capability for either flow.
   - Keep logout only in a secondary settings/profile area if needed.

4. Add owner-home inline campaign banner support using the existing backend campaign API.
   - On owner main screen load, call POST /campaigns/resolve with:
     {
       "device_uuid": "...",
       "placement": "owner_home"
     }
   - If response returns a banner campaign, render it inline near the top.
   - Support title, subtitle/body, CTA, theme, and dismiss.
   - Track impression, click, and dismiss using POST /campaigns/events.

5. Keep full-screen campaign support for non-banner placements.
   - hosted_html is allowed only for full-screen/dedicated campaign surfaces
   - do not embed hosted HTML inline inside the main screen banner

6. Keep the main screen usable even if campaign fetch fails.

After implementation, summarize:
1. files changed
2. PIN component API and where it is used
3. where Claim User and Logout were removed from
4. exact owner_home campaign request/response handling
5. any remaining backend assumptions
```
