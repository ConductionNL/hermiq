# Contract: cli-runner-credential-declaration

This change introduces **no new endpoint**. The contract it establishes is a **cross-repo data contract**:
Hermiq's `src/manifest.json` names a provider identifier that OpenRegister's catalogue must define, and
the two live in different repositories. The identifier string, the entry's shape, and the required landing
order are the interface. This document records it because getting it wrong is a runtime failure, not a
compile error.

## Consumers

- **`hermiq`** (consumer): declares `anthropic-cli` in `src/manifest.json` `credentials[]`. From link 2
  (`cli-runner-text-turn-dispatch`) onward it will resolve the credential through
  `CredentialBrokerService::resolveInjectable()` for app-side env injection. It does **not** consume the
  broker's proxy path for this provider — that path denies it by design.
- **`openregister`** (producer): defines the `anthropic-cli` entry in the runtime-immutable catalogue
  `lib/Settings/credential-providers.json`, and validates every credential create against it.
- **Any other app** (unaffected): the entry is inert for apps that do not declare it. The credential
  surface filters to app-declared providers, and `resolveInjectable()` enforces the credential's own
  `allowedApps` list, so a provider existing in the catalogue grants nothing to anyone.

## The interface: the provider catalogue entry

The producer MUST add the following entry to `providers` in
`openregister/lib/Settings/credential-providers.json`. The keys are the contract.

```json
{
  "identifier": "anthropic-cli",
  "title": "Anthropic (Claude Max) - CLI subscription",
  "$comment": "inject_only: no baseUrl, no allowRules. See design.md.",
  "inject_only": true,
  "authScheme": {
    "header": "Authorization",
    "template": "Bearer {secret}"
  }
}
```

| Field | Required | Contract |
|---|---|---|
| `identifier` | yes | MUST be exactly `anthropic-cli` — the string Hermiq's manifest names. The map key MUST match it. |
| `title` | yes | Human-readable; surfaced by `GET /api/credentials/providers`. |
| `inject_only` | yes | MUST be `true`. This is what routes the provider to `resolveInjectable()` and away from the proxy. |
| `baseUrl` | **MUST be absent** | Its presence would route the entry onto the proxy path and make `resolveInjectable()` return `null` — the CLI path would get no secret. |
| `allowRules` | **MUST be absent** | Same. There is no request for the broker to constrain. |
| `authScheme` | yes | **Descriptive only** on an inject-only entry — the consuming app decides how to inject. Not a control. `{secret}` is a placeholder; no token appears in the file. |

The consumer MUST add the matching entry to `hermiq/src/manifest.json` `credentials[]`, in the shape
already established at `src/manifest.json:7-35`:

```json
{
  "provider": "anthropic-cli",
  "reason": "<human-readable reason naming the CLI execution mode>",
  "scopes": []
}
```

`provider` MUST match the catalogue `identifier` byte-for-byte. There is no fuzzy matching:
`ProviderCatalogue::get()` is an exact array-key lookup
(`lib/Service/Credential/ProviderCatalogue.php:78-87`).

## Endpoints

**No endpoint is added or modified.** Three existing OpenRegister routes change their *data* — never their
shape, auth, or status codes — because the catalogue they read gained a row. Listed for completeness:

### `GET /api/credentials/providers`
**Auth**: Nextcloud session (`#[NoAdminRequired]`, `lib/Controller/CredentialController.php:207-208`;
route: `appinfo/routes.php:39`).

Returns one additional entry. Exposes only `identifier` and `title` — never a secret (there is none in the
catalogue) and never the allow-rules.

**Response (200):**
```json
{ "results": [ { "identifier": "anthropic-cli", "title": "Anthropic (Claude Max) - CLI subscription" } ] }
```

### `POST /api/credentials`
**Auth**: Nextcloud session (route: `appinfo/routes.php:40`).

`create()` validates the provider against the catalogue and rejects an unknown one:
`if ($name === '' || $this->catalogue->get($provider) === null)` →
`400 {"message": "Invalid credential request"}` (`CredentialController.php:264-266`). **This is why the
catalogue entry MUST land first**: without it, a Hermiq manifest row would offer the user a provider whose
save returns 400.

**Request:**
```json
{ "name": "My Claude Max subscription", "provider": "anthropic-cli", "secret": "YOUR_TOKEN_HERE", "allowedApps": ["hermiq"] }
```

**Response (201):** the serialised credential object — provider, name, owner, allowedApps, createdAt.
**Never the secret**; it goes to the vault via `credentialStore->put()` and is not returned.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `provider` is not in the catalogue (i.e. this change has not landed), or `name` is empty |
| 401  | No authenticated user |
| 500  | The credential object could not be saved |

### `POST /api/credentials/{id}/request` — the proxy path, which MUST deny this provider
**Auth**: Nextcloud session (route: `appinfo/routes.php:44`).

For an `anthropic-cli` credential this route MUST fail closed. `CredentialBrokerService::request()` denies
an inject-only provider outright before any rule or host check
(`lib/Service/Credential/CredentialBrokerService.php:190-192`), with the reason
`inject-only provider cannot be proxied; use resolveInjectable`. This is the contract's most important
guarantee: **the entry can never become an open proxy**, regardless of what a caller asks for.

## In-process interface (not an HTTP endpoint)

`CredentialBrokerService::resolveInjectable(string $credentialId, string $appId, ?string $actingUserId): ?string`
(`CredentialBrokerService.php:250-276`) — the path link 2 will use. Unchanged by this change.

| Returns | When |
|---|---|
| the raw secret | Guard 1 (owner/IDOR, `:255-258`) and Guard 2 (`allowedApps`, `:260-261`) both pass **and** the provider is inject-only |
| `null` | The provider is **not** inject-only (`:266-268`) — the caller must route to `request()` instead |
| throws `CredentialAccessDeniedException` | Guard 1 or 2 fails, or an inject-only credential has no stored secret (`:270-273`) |

Trusted same-instance callers only. The `null` return is a **routing signal**, not an error — a consumer
that treats it as "no credential" would silently mis-handle every proxy provider.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | Invalid credential request | `provider` not in the catalogue — the state before this change lands, and the failure mode if the two PRs land out of order |
| 401 | Unauthorized | No authenticated Nextcloud user |
| 403 | Access denied | Broker guard failure: not the owner, app not in `allowedApps`, or an attempt to **proxy** this inject-only provider |
| 500 | Server error | Credential object could not be saved |

No new error code is introduced. Every failure mode above is pre-existing behaviour reached by a new value.

## Versioning

- **Catalogue file `version`: `1.4.0` → `1.5.0`.** Minor bump: **purely additive**. One new provider; no
  existing entry's `baseUrl`, `allowRules`, `authScheme` or `inject_only` flag changes. This follows the
  precedent set when `1.4.0` introduced the five `generic-*` inject-only providers (`$injectOnlyComment`,
  line 4).
- **Hermiq `src/manifest.json` `version`: `0.3.0`** — a `credentials[]` addition is additive and does not
  change the manifest's own contract with the renderer.
- **Backward compatibility: total.** Every existing credential, provider and caller behaves identically.
  There is no consumer of `anthropic-cli` until link 2, so the row is inert on merge.

## Breaking Change Policy

- **Landing order is mandatory and not negotiable**: `openregister` PR 1, then `hermiq` PR 2. Reverse order
  produces a user-visible 400 on save (`CredentialController.php:264-266`). Order-only coupling — no
  feature flag or migration is needed, because the wrong order fails loudly and immediately rather than
  silently.
- **Removing or renaming `anthropic-cli` later would be breaking** for any stored credential referencing
  it: `resolveProvider()` would fail closed and the credential would become unresolvable (it would deny,
  never fail open). Such a change MUST bump the catalogue minor version, ship a coordinated Hermiq manifest
  update, and tell affected users to re-create the credential — the secret is in the vault and is not
  migrated by a catalogue edit.
- **Adding `baseUrl` or `allowRules` to this entry later would be silently breaking**: it would flip the
  provider onto the proxy path and make `resolveInjectable()` return `null`, so link 2's CLI dispatch would
  stop receiving a secret. The spec delta makes both fields' absence a normative requirement with its own
  scenario, so a reviewer has a rule to check rather than a convention to remember.
- **The catalogue is runtime-immutable** (`credential-providers.json:2`): any change to this contract ships
  in a reviewed release. There is no create/update/delete endpoint and no admin setting that can widen it.

## SLA

No availability or latency commitment is introduced or changed. The catalogue is a local file read
server-side by `ProviderCatalogue` (`CATALOGUE_PATH = /lib/Settings/credential-providers.json`,
`ProviderCatalogue.php:46`); the manifest is read on the existing settings path. No network call, no query,
no new failure mode.

The catalogue is read **unvalidated per entry** — `load()` validates only the top-level `providers` map,
so consumers guard entry shape themselves (`ProviderCatalogue.php:110-120`). A malformed entry would
therefore surface at the consuming call site, not at load. This is why "both files remain valid JSON" is an
explicit acceptance criterion rather than an assumption.
