# Tasks: agent-credentials

## Implementation Tasks

### Task 1: Declare hermiq's credential needs in the manifest
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-manifest-declared-credential-requirements`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN hermiq's manifest WHEN validated THEN it declares `credentials: [{provider:"openai",...}, {provider:"fireworks",...}, {provider:"github",...}]`, each with a `reason`
  - GIVEN the manifest change WHEN `npm run check:specs` runs THEN it passes unchanged
- [x] Implement
- [x] Test

### Task 2: Add the "Agent credentials" Settings section + component
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces`
- **files**: `src/manifest.json` (Settings page `config.sections[]`), `src/components/settings/AgentCredentialsSettings.vue` (new), `src/customComponents.js`
- **acceptance_criteria**:
  - GIVEN a user opens hermiq's Settings page WHEN the "Agent credentials" section renders THEN it mounts `CnCredentials` with `scope="personal"` (labelled "Your credentials") and `scope="organisation"` (labelled "Organisation credentials"), both `appId="hermiq"` and `appCredentials` sourced from the injected manifest's `credentials[]`
  - GIVEN a non-admin organisation member WHEN they view the organisation section THEN they can read metadata but a create/delete attempt is rejected by the existing OpenRegister guard (no new client-side gating added)
- [x] Implement
- [x] Test (compile-verified — `npm run lint`/`check:specs` clean; the reject-path itself is `CredentialController`'s existing, unmodified server-side guard, already covered by OpenRegister's own test suite; live browser coverage deferred to the playwright-regression-coverage change)

### Task 3: Add CredentialScopeResolver
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **files**: `lib/Service/Credential/CredentialScopeResolver.php` (new), `tests/Unit/Service/Credential/CredentialScopeResolverTest.php` (new)
- **acceptance_criteria**:
  - GIVEN a personal credential (provider match, `hermiq` in `allowedApps`, owner = acting user) WHEN `resolve()` is called THEN it returns that credential's uuid, preferring it over any organisation match
  - GIVEN no personal match but an organisation match (provider match, `hermiq` allowed, `organisation` = the given org) WHEN `resolve()` is called THEN it returns the organisation credential's uuid
  - GIVEN no personal and no organisation match WHEN `resolve()` is called THEN it returns `null`
  - GIVEN a credential for a different provider, or not allowing `hermiq` WHEN `resolve()` is called THEN it is never returned
- [x] Implement
- [x] Test

### Task 4: Wire the resolver into ProviderFactory
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN a nullable-defaulted `?CredentialScopeResolver $credentialResolver` constructor param WHEN `createChatDriver()` is called with a non-null `$organisation` and the `openai`/`fireworks` branches run THEN the resolver's non-null result overrides `openaiConfig`/`fireworksConfig`'s configured `credentialId`
  - GIVEN `$organisation === null`, OR the resolver is not injected, OR the resolver returns `null` WHEN `createChatDriver()` runs THEN behaviour is byte-for-byte identical to before this change (existing `ProviderFactoryTest` cases stay green unmodified)
- [x] Implement
- [x] Test

### Task 5: Translations
- **spec_ref**: `openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the new Settings section strings ("Agent credentials", "Your credentials", "Organisation credentials", their descriptions) WHEN the app renders in Dutch THEN every string has a matching `nl.json` translation (English keys, per ADR-005)
- [x] Implement (hermiq's own new strings — "Agent credentials" + the intro paragraph — in `l10n/en.json`/`l10n/nl.json`; "Your credentials"/"Organisation credentials" are `CnCredentials`' own internal strings, already translated in the shared `nextcloud-vue` library and reused unmodified, not duplicated here)
- [x] Test (JSON validity + `npm run check:specs` clean)

### Task 6: Version bump + OpenRegister min-version note
- **spec_ref**: `openspec/changes/agent-credentials/design.md#security-considerations`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN this change ships new served assets and a new OpenRegister-version dependency (organisation-scope credentials) WHEN `appinfo/info.xml` is reviewed THEN `<version>` is bumped (0.1.59 → 0.1.60) and the description's documented OpenRegister minimum reads 0.2.17-unstable.14 or higher, in both `lang="en"` and `lang="nl"`
- [x] Implement (HEAD had already moved past 0.1.59 by the time this change was built — actual bump is 0.1.63 → 0.1.64, per the "verify at HEAD" rule; the OpenRegister minimum is 0.2.17-unstable.14 as specified, bumped in both `lang="en"`/`lang="nl"` description CDATA, the XML comment, AND `CheckOpenRegisterCompatibility::MIN_OPENREGISTER_VERSION` — OpenRegister's own `appinfo/info.xml` confirms it is at exactly 0.2.17-unstable.14 at HEAD)
- [x] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — `CredentialScopeResolverTest`, extended `ProviderFactoryTest`
- No new REST API endpoints introduced (reuses OpenRegister's existing credential broker surface) — no new Newman/Postman collection needed
- UI change (Settings section) is a thin composition of an already-tested shared component (`CnCredentials`); a manual Playwright smoke pass through the section is recommended but not a new automated suite
- All tests pass (`composer test`, `npm test`, `npm run check:specs`)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-005/ADR-007)
- `openspec validate --strict` passes
