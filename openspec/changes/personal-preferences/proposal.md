---
kind: code
depends_on: []
---

# A person keeps their own agent preferences in the settings dialog

## Why

| row | capability | hermiq today |
|---|---|---|
| `op-preferences` | Set my own preferences for how agents work for me. | partial: `lib/Controller/PreferencesController.php` stores per-user key/value pairs through `IConfig` (`GET`/`PUT /api/preferences/{key}`, `appinfo/routes.php`). The only preference wired to a screen is the default Talk room in `src/components/TalkDeliverySettings.vue`, shown in the "Talk delivery" section of the user settings dialog (`src/App.vue`, `#user-settings` slot). |

The board `hermiq/HmMijnInstellingen` draws hermiq's own settings dialog with a "Voorkeuren" section
("Hoe agents voor jou werken."): answer language, the default agent in the chat, asking before an
agent mails or sends something on my behalf, and a notification when a run is done.

## What changes

- New capability `personal-preferences`:
  - REQ-PREF-001: preferences are kept per person through the existing preferences endpoint and are
    set only in hermiq's user settings dialog. No personal settings page and no in-app route.
  - REQ-PREF-002: the Voorkeuren section as drawn, with four preferences. The ask-before-sending
    preference only ever adds an approval; it never removes one an agent or administrator requires.
- `openspec/parity/capabilities.json` already points `op-preferences` at REQ-PREF-001.

## Non-goals

- No `/settings/user/hermiq` page. User configuration lives in the app's settings dialog.
- No change to the Talk delivery section; it stays where it is.
- No organisation-wide defaults for these preferences in this change.
