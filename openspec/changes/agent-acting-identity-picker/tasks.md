## 1. Server guard

- [ ] 1.1 Add `"agent.set-acting-user": ["admin"]` to `lib/actions.seed.json`. A seed only reaches an
      empty matrix (`InitializeActions`), and a missing entry already defaults to `["admin"]`, so no
      repair step is needed. Verify: `ActionAuthServiceTest` covers the default for the new name.
- [ ] 1.2 Add `lib/Listener/AgentActingUserGuardListener.php` for OpenRegister `ObjectCreatingEvent`
      and `ObjectUpdatingEvent`, registered in `lib/AppInfo/Application.php`, acting only on the
      hermiq `agent` schema: when the new `actingUser` is non-empty and differs from the old value,
      require `ActionAuthService::can($user, 'agent.set-acting-user')` and an existing, enabled
      user, else `stopPropagation()` with an error naming the reason (REQ-AGID-002, design D2).
      Verify: unit tests for allowed, missing action, disabled user, unchanged value, and clearing.

## 2. Edit page

- [ ] 2.1 Add the "Namens wie werkt de agent" choice to `src/modals/AgentFormModal.vue` as drawn on
      HmAgentBewerken: two radio options, a user picker (`NcSelectUsers` or `NcSelect` with
      `inputLabel`) under the service account option, `actingUser` in the save payload (empty for the
      first option) (REQ-AGID-001). Verify: Vitest test that each option produces the right payload.
- [ ] 2.2 Disable the service account option for a viewer without `agent.set-acting-user`, read
      through `ActionAuthService::can()` via initial state or an existing endpoint, with the sentence
      that an administrator sets it (REQ-AGID-002). Verify: Vitest test with the right withheld.
- [ ] 2.3 Show a declared `actingUser` that no longer resolves with the warning of REQ-AGID-003; the
      resolution comes from the server (enabled or not), never guessed in the browser. Verify: Vitest test.
- [ ] 2.4 Add the Dutch strings to `l10n/nl.json` and `l10n/nl.js` in the board's wording.
      Verify: `npm run lint` exits 0.

## 3. Verification

- [ ] 3.1 `@spec` tags on every new method (gate 16) and an e2e test in
      `tests/e2e/agent-acting-identity.spec.ts` for the scenarios without an `@e2e exclude` (gate 19).
