# Proposal: agent-credentials

## Summary
Hermiq already routes every LLM-provider call through OpenRegister's credential
broker (`BrokerHttpClient`, `ProviderFactory`) instead of holding API keys itself,
and `nc-vue` already ships a `CnCredentials` component and an OpenRegister
`CredentialController` that both understand **personal** (owner-scoped) and
**organisation** (membership-scoped) credentials. What is missing is the wiring:
hermiq declares no `credentials[]` in its manifest, mounts no credential-management
surface of its own, and — at run time — only ever consults the single
instance-wide `credentialId` an admin configured in `hermiq.llm`. This change
declares hermiq's credential needs, surfaces personal + organisation credential
management inside hermiq's own Settings page using the existing `CnCredentials`
component, and extends `ProviderFactory`'s credential resolution so a run prefers
the acting user's personal credential, then their organisation's credential,
before falling back to the instance default — the same org → instance fallback
shape `tenant-model-policy` already established for model policy.

## Motivation
Today, "which OpenAI/Fireworks key does this agent run use" has exactly one
answer instance-wide: whatever an admin pasted into `hermiq.llm` (soon to live at
NC admin settings per the sibling `ai-features-to-admin` change). A user who
wants to run agents against their own OpenAI account, or an organisation that
wants its own budget/quota separate from the instance default, has no way to do
that — the broker and the shared UI component that would make it possible
already exist (`CredentialController`'s `scope=personal|organisation`,
`CnCredentials`'s `scope` prop) but nothing in hermiq uses them beyond the single
admin-configured provider credential. OpenBuild already proves the personal half
of this pattern for its own GitHub-push credential (a picker over
`GET /apps/openregister/api/credentials`, with a hint pointing users at
OpenRegister's personal-settings wallet); no app in the fleet has yet mounted the
organisation half. This is the natural next step now that the broker itself
supports both scopes (`credential-broker-organisation-scope`, already shipped in
OpenRegister 0.2.17-unstable.14).

## Affected Projects
- [ ] Project: `hermiq` — declares `credentials[]` in its manifest, adds an
  "Agent credentials" section to its in-app Settings page mounting `CnCredentials`
  (personal + organisation), and extends `ProviderFactory`'s credential
  resolution with a personal → organisation → instance fallback chain.

## Scope

### In Scope
- Declaring hermiq's external-provider credential needs (`openai`, `fireworks`,
  `github`) in `src/manifest.json`'s `credentials[]` array, consumed by
  `CnCredentials`'s "What {app} uses" section.
- A new "Agent credentials" section on hermiq's in-app Settings page
  (`/settings`, `type:"settings"`) mounting `CnCredentials` twice — once
  `scope="personal"`, once `scope="organisation"` — both `appId="hermiq"`, so a
  user can grant hermiq access to their own credentials and an organisation
  admin can provision organisation-wide ones, without leaving the app.
- A new `CredentialScopeResolver` service that, given a provider identifier, the
  acting user, and an organisation, picks the best-scoped broker credential id:
  the acting user's own personal credential for that provider (if allowed for
  `hermiq`), else the organisation's credential for that provider, else `null`
  (meaning: fall back to the instance-wide configured credential — unchanged
  behaviour).
- Wiring that resolver into `ProviderFactory::createChatDriver()` for the
  `openai` and `fireworks` branches only (the two branches that carry a broker
  `credentialId` today), as an additive override to the existing
  `hermiq.llm.<provider>Config.credentialId` — opt-in via the same
  null-organisation guard `enforceModelPolicy()` already uses, so every existing
  caller that already passes a real `organisation` gets the new precedence for
  free and no caller's behaviour changes without it.
- Bumping hermiq's declared OpenRegister minimum version (documentation only —
  NC's `info.xml` has no field for this, see the existing comment in
  `appinfo/info.xml`) to the version that ships the organisation-scope
  credential feature this change depends on.

### Out of Scope
- Changing `BrokerHttpClient`, `CredentialBrokerService`, or any of the broker's
  four security guards (owner / allowedApps / allow-rules / host-lock) — this
  change only decides WHICH credential id to hand the broker; the broker keeps
  re-validating every call unchanged, exactly as it does today.
- The `hermiq.webResearch` search-backend credential (`WebSearchClient`). Its
  provider is an admin-configured, potentially self-hosted arbitrary endpoint
  (SearXNG or a generic JSON API) that has no catalogue entry in OpenRegister's
  `credential-providers.json` (only host-lockable providers are catalogued —
  see that file's own `$fleetComment` on self-hosted targets needing a broker
  design change). Extending scope resolution to a credential type the broker
  cannot yet host-lock would mean either widening the catalogue (a broker-side
  change, out of scope here) or storing a raw secret (which this fleet has
  explicitly moved away from). Filed as a follow-up; see Open Questions.
- A per-agent `credentialRef` override field. The resolver's automatic
  personal → organisation → instance precedence covers the common case
  (bring-your-own-key without any extra agent configuration); an explicit
  "pin this exact credential" picker on the Agent form is deferred — see
  Open Questions.
- Building the agent-template GitHub-store publish/discover flow itself (the
  sibling `agent-template-github-store` change owns that). This change only
  declares `github` in `credentials[]` and makes `CredentialScopeResolver`
  provider-agnostic so that change can reuse it.
- Restructuring hermiq's nav/Settings page beyond adding the one new section
  (the broader nav realignment is the sibling `inapp-settings-section` /
  `ai-features-to-admin` / `dashboard-org-widgets` changes).

## Approach
1. Add `credentials[]` to `src/manifest.json` (schema already supports this key;
   no app in the fleet uses it yet).
2. Add an "Agent credentials" section to the Settings page's `config.sections[]`
   whose body is a new `AgentCredentialsSettings.vue` component (registered in
   `src/customComponents.js`) that mounts `CnCredentials` twice (personal, then
   organisation), reading `credentials[]` from the manifest it's injected with.
3. Add `lib/Service/Credential/CredentialScopeResolver.php`: reads the
   `credential-broker`/`brokeredcredential` OpenRegister objects (system-wide,
   `_rbac:false` — mirrors `TenantModelPolicyService`/`ScheduleWebhookSecretService`'s
   existing precedent for this kind of small, admin/user-curated collection),
   filters in PHP for provider match + `allowedApps` containing `hermiq`, and
   returns the best-scoped match's uuid.
4. Thread that resolver through `ProviderFactory` as a nullable-defaulted
   constructor dependency (same backward-compatible shape as
   `TenantModelPolicyService`), consulted inside `createChatDriver()` right
   before the `openai`/`fireworks` branches build their driver.

## New Dependencies
None — reuses `nc-vue`'s existing `CnCredentials` component and OpenRegister's
existing `CredentialController`/`CredentialBrokerService` REST surface, both
already shipped.

## Impact
- `src/manifest.json` — new `credentials[]` array; one new Settings section.
- `src/customComponents.js` — one new registry entry.
- `src/components/settings/AgentCredentialsSettings.vue` — new file.
- `lib/Service/Credential/CredentialScopeResolver.php` — new file.
- `lib/Service/Llm/ProviderFactory.php` — new optional constructor param;
  `createChatDriver()`, `createOpenAiDriver()`, `createFireworksDriver()` gain a
  credential-override parameter.
- `l10n/en.json`, `l10n/nl.json` — new strings for the Settings section.
- `appinfo/info.xml` — `<version>` bump + OpenRegister min-version note update.

## Cross-Project Dependencies
- Depends on OpenRegister's `credential-broker-organisation-scope` capability
  (already shipped, OpenRegister 0.2.17-unstable.14) for `scope=organisation`
  credential CRUD + the org-admin write guard.
- The sibling `inapp-settings-section` change owns the broader Settings-page
  layout; this change adds one section to whatever shape that change lands,
  and does not depend on its ordering.
- The sibling `ai-features-to-admin` change moves the LLM provider/model
  picker to NC admin settings; unaffected by this change — the instance-wide
  `hermiq.llm.<provider>Config.credentialId` stays the terminal fallback
  regardless of which settings page edits it.
- The sibling `agent-template-github-store` change should consume
  `CredentialScopeResolver` (provider `github`) for its own credential picker
  rather than hand-rolling scope resolution again.

## Risks

### Risk 1: A user with multiple personal credentials for the same provider gets a non-deterministic pick
**Severity:** Low — **Mitigation:** `CredentialScopeResolver` deterministically
picks the first match in `ObjectService::findAll()`'s return order (creation
order in practice). Documented as a known simplification; an explicit
per-agent override (Out of Scope, above) would resolve the ambiguity properly
and is filed as a follow-up rather than solved with an ad-hoc tie-break rule
here.

### Risk 2: Resolver adds a broker-register read on every chat-driver construction
**Severity:** Low — **Mitigation:** The `credential-broker` register is a small,
admin/user-curated collection (not user-scale data), and `createChatDriver()`
already does one settings read (`getLlmConfig()`) plus one policy read
(`enforceModelPolicy()`) per call; this is a third read of the same shape, not a
new class of cost. No caching is introduced in this change; if profiling later
shows it matters, that's a follow-up, not a blocker.

## Rollback Strategy
Every piece is additive: the manifest section, the new Vue component, and the
new resolver can all be removed without touching existing data. `ProviderFactory`'s
new constructor parameter is nullable-defaulted, so reverting the DI wiring (or
simply not injecting a resolver) restores today's exact behaviour — the
instance-wide `credentialId` is always used, identical to pre-change. No
migration, no schema version bump, nothing to reverse in OpenRegister.

## Open Questions
- Should an explicit per-agent `credentialRef` override (pin one specific
  credential, disambiguating Risk 1) be built as a fast-follow, or is the
  automatic precedence sufficient for the common case? Deferred — see
  DEFERRED_DECISIONS.
- Should the web-research search-backend credential (`WebSearchClient`) get its
  own scope resolution once OpenRegister's catalogue grows a self-hosted /
  generic inject-only provider entry? Deferred to that broker-side change.
