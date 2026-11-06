## 1. Settings dialog

- [ ] 1.1 Add `src/components/PersonalPreferences.vue` with the four preferences as drawn on
      HmMijnInstellingen (language select, default agent select with `inputLabel`, two switches),
      reading and writing through `GET`/`PUT /api/preferences/{key}` (REQ-PREF-001, REQ-PREF-002).
      Verify: Vitest test that each control reads and writes its key.
- [ ] 1.2 Add an `NcAppSettingsSection id="preferences"` for it in the `#user-settings` slot of
      `src/App.vue`, above "Talk delivery". Verify: Vitest or e2e test that the section renders.
- [ ] 1.3 Add the Dutch strings to `l10n/nl.json` and `l10n/nl.js` in the board's wording.
      Verify: `npm run lint` exits 0.

## 2. Server use of the preferences

- [ ] 2.1 Answer language: pass the `answer_language` preference of the run's person into the
      context the engine assembles. Verify: unit test of the context assembly.
- [ ] 2.2 Default agent: the chat reads `default_agent` when no agent is chosen. Verify: Vitest test.
- [ ] 2.3 Ask before sending: when `ask_before_send` is on, the approval check adds an approval for
      outbound mail and send tools in runs started by that person, never removing a required one
      (REQ-PREF-002). Verify: unit tests for on, off with a required approval, and off without.
- [ ] 2.4 Run done notification: when `notify_run_done` is on, a finished run sends a Nextcloud
      notification to the person who started it. Verify: unit test of the notifier call.

## 3. Verification

- [ ] 3.1 `@spec` tags on every new method (gate 16) and an e2e test in
      `tests/e2e/personal-preferences.spec.ts` for the scenarios without an `@e2e exclude` (gate 19).
