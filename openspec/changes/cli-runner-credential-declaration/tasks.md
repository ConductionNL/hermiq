# Tasks: cli-runner-credential-declaration

Two declarative JSON edits across **two repositories**, in a mandatory order: the OpenRegister catalogue
entry (Task 1) MUST land before the Hermiq manifest declaration (Task 2), because
`CredentialController::create()` rejects an unregistered provider with a 400
(`lib/Controller/CredentialController.php:264-266`). Expect **two PRs**, one per repo.

No PHP, no Vue, no new tests to author — `CredentialBrokerService`'s inject-only branch already exists and
is already covered. Task 3 verifies the existing behaviour against the new entry rather than adding code.

## Implementation Tasks

### Task 1: Register the anthropic-cli inject-only provider in OpenRegister's catalogue
- **spec_ref**: `openspec/changes/cli-runner-credential-declaration/specs/agent-credentials/spec.md#requirement-anthropic-cli-credential-provider-is-registered-as-inject-only`
- **repo**: `openregister` — **PR 1, MUST land first**
- **files**: `lib/Settings/credential-providers.json`
- **acceptance_criteria**:
  - GIVEN the catalogue WHEN `anthropic-cli` is added THEN it carries `inject_only: true` and NO `baseUrl` and NO `allowRules`
  - GIVEN the new entry WHEN reviewed THEN its `$comment` records that the secret leaves OR into the calling app, names the bounding guards (owner + allowedApps), and states the personal-scope-only ToS constraint
  - GIVEN the new entry WHEN compared to the five `generic-*` entries (lines 255-304) THEN it matches the house style: `identifier`, `title`, `$comment`, `inject_only`, `authScheme`, in that key order
  - GIVEN the file WHEN the entry is added THEN `version` is bumped `1.4.0` → `1.5.0` and the file is still valid JSON
  - GIVEN the file WHEN scanned THEN it contains no secret value — `{secret}` is a placeholder only
  - GIVEN `anthropic` and `anthropic-oauth` WHEN this task completes THEN both are byte-for-byte unchanged
- [ ] Implement
- [ ] Test

### Task 2: Declare the anthropic-cli credential in Hermiq's manifest
- **spec_ref**: `openspec/changes/cli-runner-credential-declaration/specs/agent-credentials/spec.md#requirement-manifest-declared-credential-requirements`
- **repo**: `hermiq` — **PR 2, only after PR 1 has merged**
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `credentials[]` WHEN the entry is added THEN it is `{provider: "anthropic-cli", reason: <text>, scopes: []}`, matching the shape at `src/manifest.json:7-35`
  - GIVEN the `reason` WHEN read by a user THEN it is human-readable English, names the CLI execution mode it serves, and explains why the token cannot be proxied
  - GIVEN `provider` WHEN compared to the catalogue THEN it matches `identifier` byte-for-byte — `ProviderCatalogue::get()` is an exact key lookup
  - GIVEN the file WHEN the entry is added THEN it is still valid JSON and the five existing entries are unchanged
- [ ] Implement
- [ ] Test

### Task 3: Verify the broker's existing guards against the new provider
- **spec_ref**: `openspec/changes/cli-runner-credential-declaration/specs/agent-credentials/spec.md#requirement-the-inject-only-anthropic-cli-credential-is-never-proxied`
- **repo**: both — verification only, no code change expected
- **files**: `lib/Settings/credential-providers.json`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an `anthropic-cli` credential WHEN a caller attempts a proxied request THEN the broker denies it at `CredentialBrokerService.php:190-192`, for every method and path
  - GIVEN an `anthropic-cli` credential owned by the acting user and allowing `hermiq` WHEN `resolveInjectable()` is called THEN it returns the raw secret only after Guard 1 (`:255-258`) and Guard 2 (`:260-261`) both pass
  - GIVEN a credential owned by another user OR one not allowing `hermiq` WHEN resolved THEN the broker denies and returns no secret
  - GIVEN an `anthropic-oauth` credential WHEN `resolveInjectable()` is called THEN it still returns `null` — the proxy providers stay zero-knowledge
  - GIVEN the Credentials tab WHEN a user opens hermiq Settings THEN `anthropic-cli` is offered with its declared reason, and saving a credential succeeds
  - GIVEN this task WHEN it finds a gap THEN a code change is OUT OF SCOPE here — raise it, do not widen this `kind: config` change
- [ ] Implement
- [ ] Test

## Quality checklist

- No PHPUnit tests are authored by this change: it adds no business logic. `CredentialBrokerService`'s inject-only branch and both guards are pre-existing and already covered — Task 3 verifies against that existing coverage rather than duplicating it.
- No Newman tests: no endpoint is added or modified. `GET /api/credentials/providers` and `POST /api/credentials` gain a new data value, not a new shape.
- No Playwright tests: no UI change. The Credentials tab renders the new row through the existing shared component with no Vue edit. Every spec scenario carries a reason-bearing `@e2e exclude`.
- No i18n strings: the manifest `reason` is authored in English as the source language (ADR-007/ADR-025) and flows through the existing credential surface's translation path. No new translation seam.
- No seed data and no migration: a credential-provider registration is NOT an OpenRegister schema (`credential-providers.json:2`), so ADR-001's seed-data requirement does not apply. No tables, columns, schemas or data transformations — `migration.md` is deliberately skipped.
- Both JSON files MUST remain valid JSON — `ProviderCatalogue::load()` validates only the top-level `providers` map, so a malformed entry surfaces at the consuming call site rather than at load.
- Never store a secret in either file. `{secret}` is a placeholder the broker substitutes from the vault at call time.
- Landing order is mandatory: `openregister` first, then `hermiq`. Reverse order gives the user a 400 on save.
- `openspec validate --strict` passes.
