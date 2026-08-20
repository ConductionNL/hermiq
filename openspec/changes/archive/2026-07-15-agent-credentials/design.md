# Design: agent-credentials

## Architecture Overview
Two independent additions on top of infrastructure that already exists and is
already shipped:

```
                     ┌─────────────────────────────┐
                     │ OpenRegister (existing, HEAD)│
                     │ CredentialController          │
                     │   scope=personal|organisation │
                     │ CredentialBrokerService        │
                     │   owner/allowedApps/rules/host │
                     │ credential-broker register     │
                     │   (brokeredcredential objects)  │
                     └───────┬───────────┬───────────┘
                             │           │
        reads/writes via     │           │ request() — UNCHANGED
        CnCredentials (UI)   │           │
                             │           │
     ┌───────────────────────▼──┐   ┌────▼─────────────────────────┐
     │ Hermiq Settings page      │   │ Hermiq ProviderFactory        │
     │ (NEW) AgentCredentialsSettings.vue │  createChatDriver()      │
     │  <CnCredentials scope=personal/>   │  NEW: resolves an        │
     │  <CnCredentials scope=organisation/> │  override credentialId │
     └───────────────────────────┘   │  via CredentialScopeResolver │
                                      └───────────────┬───────────────┘
                                                       │
                                          ┌────────────▼────────────┐
                                          │ NEW: CredentialScopeResolver │
                                          │  reads credential-broker     │
                                          │  register, picks personal    │
                                          │  → organisation → null       │
                                          └───────────────────────────┘
```

The UI half (Settings-page section) and the run-time half (`ProviderFactory`
resolution) share only the manifest's `credentials[]` declaration and the
`credential-broker`/`brokeredcredential` register/schema constants — they are
otherwise independent and can ship/rollback separately if needed.

## API Design
No new hermiq API endpoints. Both halves consume OpenRegister's existing,
already-shipped surface:
- `GET /apps/openregister/api/credentials?scope=personal|organisation` — read
  (used by `CnCredentials` internally).
- `GET /apps/openregister/api/credentials/providers` — provider catalogue
  (used by `CnCredentials` internally).
- `POST` / `PUT` / `DELETE /apps/openregister/api/credentials(/{id})` — used by
  `CnCredentials` internally; server-side scope + owner/admin guards are
  OpenRegister's `CredentialController`, unchanged by this proposal.

`CredentialScopeResolver` is an in-process PHP service, not a new HTTP surface —
it calls `ObjectService` directly, the same in-process seam
`TenantModelPolicyService` and `ScheduleWebhookSecretService` already use.

## Database Changes
None. No new OpenRegister schema, no new schema property, no migration. The
`credential-broker`/`brokeredcredential` schema this change reads from already
exists and already ships with `scope`/`organisation`/`allowedApps` (see
`openregister/lib/Settings/credential_broker_register.json`, version 1.1.0).

## Nextcloud Integration
- Controllers: none new.
- Services:
  - `OCA\Hermiq\Service\Credential\CredentialScopeResolver` (new) — reads
    `credential-broker`/`brokeredcredential` objects via
    `OCA\OpenRegister\Service\ObjectService` (constructor-injected, same as
    `TenantModelPolicyService`).
  - `OCA\Hermiq\Service\Llm\ProviderFactory` (modified) — gains an optional
    `?CredentialScopeResolver $credentialResolver = null` constructor param.
- Mappers/Entities: none new; reads `OCA\OpenRegister\Db\ObjectEntity` the same
  way `TenantModelPolicyService::getForOrganisation()` does.
- Events/Hooks: none.

## Security Considerations
- **Defense in depth, not a new trust boundary.** `CredentialScopeResolver`
  only ever *selects a candidate credentialId*. It grants nothing by itself:
  every call still goes through `CredentialBrokerService::request()`'s four
  guards (owner, `allowedApps` contains `hermiq`, provider `allowRules`,
  host-lock) exactly as today. If the resolver ever picked a credential the
  acting user does not own, or an organisation the agent isn't in, the broker
  itself would refuse the call — the resolver cannot bypass those checks, it
  can only fail to find a valid candidate (in which case resolution falls back
  to instance, or ultimately `ProviderUnavailableException` if that's also
  unset, unchanged from today).
- **The resolver's own filter is scoped narrowly on purpose**: personal
  candidates require `owner === $actingUserId` (never any other user's
  credential); organisation candidates require `organisation === ` the agent's
  own resolved organisation (never a different tenant's). Both additionally
  require `provider` match and `allowedApps` containing `hermiq` — a credential
  an owner has not explicitly allowed hermiq to use is never selected, mirroring
  the broker's own `allowedApps` guard one layer up (belt-and-braces, not a
  substitute for it).
- **No secret ever transits hermiq or this resolver.** `CredentialScopeResolver`
  returns a UUID (a reference), never a secret — identical posture to every
  other broker consumer in this app (`BrokerHttpClient`, `WebSearchClient`).
- **Fail-closed unchanged.** When the resolver finds nothing (no personal, no
  organisation credential), behaviour is byte-for-byte identical to before this
  change: the instance-wide `hermiq.llm.<provider>Config.credentialId` is used,
  and `ProviderUnavailableException`/`BrokerHttpClient`'s own fail-closed
  `RuntimeException`s fire exactly as they do today if that is also empty or
  the broker is unavailable.
- **Opt-in threading, not a silent behavior change for untouched callers.**
  Resolution only runs when `$organisation !== null` is passed to
  `createChatDriver()` — the exact same guard `enforceModelPolicy()` already
  uses. Every existing call site (`ConversationManagementHandler`,
  `CourseRecommendationEngine`, `ResponseGenerationHandler`) already passes a
  real (non-null) organisation, so they get the new precedence automatically;
  any caller/test that doesn't pass one keeps behaving exactly as before.

## NL Design System
`AgentCredentialsSettings.vue` renders two `CnCredentials` instances with plain
`<h4>`/`<p>` section headers using Nextcloud CSS variables only (no new custom
styling) — `CnCredentials` itself already handles its own NcNoteCard/NcButton/
NcSelect chrome and is WCAG-AA per its existing usage in OpenRegister's own
personal-settings page.

## File Structure
```
lib/
  Service/
    Credential/
      CredentialScopeResolver.php        (new)
    Llm/
      ProviderFactory.php                 (modified)
src/
  manifest.json                           (modified — credentials[], Settings section)
  customComponents.js                     (modified — new registry entry)
  components/
    settings/
      AgentCredentialsSettings.vue        (new)
tests/
  Unit/
    Service/
      Credential/
        CredentialScopeResolverTest.php   (new)
      Llm/
        ProviderFactoryTest.php           (modified — new cases)
l10n/
  en.json, nl.json                        (modified)
appinfo/
  info.xml                                (modified — version bump)
```

## Seed Data
Not applicable — this change introduces no new OpenRegister schema or objects.
It reads OpenRegister's own `credential-broker`/`brokeredcredential` objects,
whose seed data already exists (see `credential_broker_register.json`'s
`objects[]`) and needs no addition.

## Trade-offs
- **Automatic precedence vs. an explicit per-agent `credentialRef` picker.**
  Chosen: automatic (personal → organisation → instance), zero extra
  configuration for the common "I have my own OpenAI key" case. Rejected for
  now: an explicit picker on the Agent form, which would let a user disambiguate
  between multiple personal credentials for the same provider but adds a new
  Agent schema field and a new form control — deferred as a fast-follow once
  real usage shows the ambiguity (Risk 1 in proposal.md) actually matters.
- **Mounting `CnCredentials` twice in hermiq's own Settings vs. relying solely
  on OpenRegister's generic personal-settings wallet.** The generic wallet
  (`openregister/src/components/userSettings/PersonalRoot.vue`) mounts
  `CnCredentials` with no `appId`, so its per-app "allow this app" toggle is
  inert there (`appAllowed()`/`toggleThisApp()` both short-circuit on an empty
  `appId`) — a user cannot actually grant hermiq access to a credential created
  from that generic page today. Mounting a second, `appId="hermiq"`-scoped
  instance inside hermiq's own Settings is the only way that toggle currently
  works for hermiq specifically; this is the same reasoning OpenBuild's
  `ExportDialog` hint ("Add one under Personal settings → Additional settings,
  then reopen this dialog") already depends on implicitly. Fixing the generic
  wallet's toggle to work app-agnostically is an OpenRegister-side concern, out
  of scope here.
- **Filtering in PHP after a full `findAll()` vs. a server-side filtered query.**
  Chosen: mirrors the exact precedent already in this codebase
  (`CredentialController::index()`, `TenantModelPolicyService::getForOrganisation()`)
  for what is, by the schema's own description, a small admin/user-curated
  collection — not a new performance pattern, and consistent rather than
  novel. If this collection ever grows large enough to matter, that is a
  broker-side (OpenRegister) concern affecting all its existing callers
  equally, not something to solve differently just in hermiq.
