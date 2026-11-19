## 1. Settings dialog

- [x] 1.1 Add `src/components/PersonalPreferences.vue` with the four preferences as drawn on
      HmMijnInstellingen (language select, default agent select with `inputLabel`, two switches),
      reading and writing through `GET`/`PUT /api/preferences/{key}` (REQ-PREF-001, REQ-PREF-002).
      Verify: Vitest test that each control reads and writes its key.
- [x] 1.2 Add an `NcAppSettingsSection id="preferences"` for it in the `#user-settings` slot of
      `src/App.vue`, above "Talk delivery". Verify: Vitest or e2e test that the section renders.
- [x] 1.3 Add the Dutch strings to `l10n/nl.json` and `l10n/nl.js` in the board's wording.
      Verify: `npm run lint` exits 0.

## 2. Server use of the preferences

- [x] 2.1 Answer language: pass the `answer_language` preference of the run's person into the
      context the engine assembles. Verify: unit test of the context assembly.
- [x] 2.2 Default agent: the chat reads `default_agent` when no agent is chosen. Verify: Vitest test.
- [x] 2.3 Ask before sending: when `ask_before_send` is on, the approval check adds an approval for
      outbound mail and send tools in runs started by that person, never removing a required one
      (REQ-PREF-002). Verify: unit tests for on, off with a required approval, and off without.
      Built: `PersonalPreferences` reads `pref_ask-before-send` (the settings dialog sends the key
      `ask-before-send`; the endpoint drops underscores), `OutboundToolRule` picks the turn's send tools,
      and `FacadeToolInvoker` asks after every other gate. Tests: `FacadeToolInvokerAskBeforeSendTest`.
- [x] 2.4 Run done notification: when `notify_run_done` is on, a finished run sends a Nextcloud
      notification to the person who started it. Verify: unit test of the notifier call.

## 3. Verification

- [x] 3.1 `@spec` tags on every new method (gate 16) and an e2e test in
      `tests/e2e/personal-preferences.spec.ts` for the scenarios without an `@e2e exclude` (gate 19).
      Build round 2: `RunDoneNotifier::sendRunDone()` was the one new method without `@spec`; the e2e covers the dialog, save-and-reopen and the default agent (linted, not run: needs an instance). The two run scenarios are `@e2e exclude` with the unit tests that cover them.
