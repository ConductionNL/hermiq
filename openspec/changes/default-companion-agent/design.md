# Design: default-companion-agent

## Architecture Overview

One resolver, three tiers, an access check at every tier.

```
ChatStreamController::sendMessage()  (line 224)
  └─ resolveCompanionAgentForUser(userId)          ← NEW: replaces the direct call below
       ├─ TIER 1  per-user preference   IConfig::getUserValue('hermiq', uid, 'companion_agent_uuid')
       │            └─ canUserAccessAgent()? ── no ─┐ (fall through, never fail)
       ├─ TIER 2  app-config default    IConfig::getAppValue('hermiq', 'companion_agent_uuid')   ← hermiq#116
       │            └─ canUserAccessAgent()? ── no ─┤
       └─ TIER 3  pickFallbackAgentForUser(userId)  ← EXISTING (line 558), unchanged
                    └─ first accessible of findAll(config: ['limit' => 20]); '' if none
```

`pickFallbackAgentForUser()` is **not modified**. It keeps its signature, its `Throwable` catch,
its warning log, and its `''` return. It simply stops being tier 1 and becomes tier 3.

### Verified state at HEAD (do not assume otherwise)

- `pickFallbackAgentForUser()` exists at `lib/Controller/ChatStreamController.php:558`; called
  once, at line 224. Its body is first-accessible only: `findAll(config: ['limit' => 20])`, loop,
  `canUserAccessAgent()`, return the first UUID, `''` if none.
- **`companion_agent_uuid` does not exist anywhere in `lib/` or `src/` in this worktree.**
  hermiq#116 is open, not merged. Tier 2 is therefore specified here but **must not be
  implemented here** — read the key defensively and fall through when it is absent.
- `ProviderFactory` (`lib/Service/Llm/ProviderFactory.php`, lines ~1880-1882) resolves
  `$model = ($anthropicConfig['chatModel'] ?? 'claude-opus-4-8')` and then **overrides it with the
  agent's own `model`** when set. This is why picking the wrong agent produced `claude --model
  qwen2.5` → exit 1, empty stderr, infinite spin.

## The precedence contract

**A stored UUID is a preference, never an authorization.** This is the single most important rule
in the change and the reason the access check is repeated per tier rather than done once at the
end:

1. **Tier 1 is user-writable.** The user sets it via an endpoint. Trusting it to name an agent
   they may access is a textbook IDOR (OWASP A01:2021).
2. **Tier 2 is admin-writable but not per-user.** An admin's instance-wide choice may name an
   agent a given user cannot access. Same check, same fall-through.
3. **Fall through, never fail.** A failed access check at tier 1 or 2 is not an error state — it
   means "this tier has no answer". The user gets tier 3 and a working companion. Returning an
   error would break chat for a user whose default merely went stale.

This also disposes of the deleted-agent case for free: a deleted agent cannot pass
`canUserAccessAgent()`, so a stale preference degrades to the next tier automatically. Cleanup of
the stale key is optional; **correctness must not depend on it.**

## API Design

### `PUT /api/user/default-agent`
Sets the calling user's default agent. `#[NoAdminRequired]`, CSRF-protected (the default —
do not add `#[NoCSRFRequired]`; it is a state-changing endpoint reached from the app's own UI).

**Request:**
```json
{ "agentId": "00000000-0000-0000-0000-000000000000" }
```
**Response:**
```json
{ "agentId": "00000000-0000-0000-0000-000000000000" }
```

The endpoint MUST validate that the calling user can access `agentId` (`canUserAccessAgent()`)
and MUST return `403` otherwise — **writes validate, reads fall through.** The two postures are
deliberately different: rejecting at write time gives the user immediate feedback, while
falling through at read time keeps chat working when a once-valid preference goes stale.

### `DELETE /api/user/default-agent`
Clears it. `#[NoAdminRequired]`. Returns `204`. Resolution then starts at tier 2.

Both routes MUST declare their auth posture explicitly in `appinfo/routes.php` + attributes; a
missing attribute makes the endpoint unreachable (NC middleware rejects before the controller).

## Database Changes

**None.** The preference is a single scalar per user, stored via Nextcloud's own user-config
(`IConfig::setUserValue('hermiq', $uid, 'companion_agent_uuid', $uuid)`) — the canonical NC
pattern for a per-user preference, already what hermiq#116 uses for its app-scoped sibling
(`getAppValue`/`setAppValue`). No table, no column, no migration class.

**Explicitly rejected: an OpenRegister object or schema property.** A `UserPreference` schema (or
a `defaultAgent` property on `UserProfile`) would buy nothing — no RBAC nuance beyond "the user's
own value", no audit requirement, no sharing — and would cost a register re-import, a magic
table, and a seed-data obligation for one scalar. It would also drag this `kind: code` change
into config territory, which ADR-032 forbids.

## Nextcloud Integration

- Controllers: `lib/Controller/ChatStreamController.php` (call site line 224; new resolver);
  a controller for `PUT`/`DELETE /api/user/default-agent`
- Services: `IConfig` (or `IUserConfig`) for the user value; `ObjectService` for the agent lookup
  and `canUserAccessAgent()` — no new service class is warranted for a three-branch resolver
- Mappers/Entities: none — persistence is NC config + OpenRegister (ADR-022)
- Events/Hooks: none
- Annotations: `#[NoAdminRequired]` on both endpoints; CSRF left ON

## Security Considerations

- **The preference is not an authorization.** `canUserAccessAgent()` on every read, at every tier,
  fall through on failure. See "The precedence contract".
- **IDOR at the write endpoint.** `agentId` is a user-supplied object id. The endpoint MUST
  access-check before storing and MUST return `403` on failure — otherwise any authenticated user
  can store any agent's UUID and the read-time check becomes the only thing standing between them
  and another tenant's agent. Defence in depth: both checks, always.
- **The read-time check is the load-bearing one.** If the write check were the only one, an agent
  whose sharing was later revoked would remain the user's companion.
- **CSRF.** Both endpoints are state-changing and reached from the app's own UI. Do not add
  `#[NoCSRFRequired]`.
- **No secrets.** The preference is an agent UUID — not a credential, not a token. It is not
  written to a schema (see ADR-064 custody: never store secrets in a schema; this stores no
  secret at all).
- **Tenant scoping** is inherited entirely from `canUserAccessAgent()` — no bespoke auth code is
  introduced by this change.

## NL Design System

- The settings picker uses the standard `NcAppSettingsSection` + an `NcSelect`. **Every `NcSelect`
  MUST carry an `inputLabel`** — a manual `<label>` breaks the component's internal accessibility
  wiring (WCAG 2.1 AA, SC 1.3.1 / 4.1.2). This is a mechanical gate in this repo.
- The "Make my default" action renders through the existing `CnActionButtons` header surface — no
  bespoke button styling.
- **The hexagon carries a brand rule, not a style preference.** From
  `CnAiFloatingButton.vue`: *"pointy-top point-up hexagon (Conduction brand rule — never rotated,
  never flat-top)"*. Its geometry is load-bearing: `clip-path: polygon(50% 0%, 100% 25%, 100%
  75%, 50% 100%, 0% 75%, 0% 25%)` yields six equal sides **only** at a √3:2 width:height ratio
  (52×60px in the button). Fill is Conduction Cobalt. Any avatar reuse MUST preserve the ratio and
  the point-up orientation, and MUST use CSS variables/brand tokens rather than re-hardcoding the
  hex value.

## File Structure

```
lib/
  Controller/
    ChatStreamController.php     # MODIFIED: line 224 call site + resolveCompanionAgentForUser()
    <UserPreference>Controller   # NEW (or an existing controller extended): PUT/DELETE /api/user/default-agent
appinfo/
  routes.php                     # MODIFIED: two routes
src/
  App.vue                        # MODIFIED: new NcAppSettingsSection above #talk-delivery
  manifest.json                  # MODIFIED: +1 api-call entry in AgentDetail config.headerActions
l10n/
  en.json, nl.json               # MODIFIED: new strings
```

## Seed Data

**Not applicable — this change introduces no new schemas and no new OpenRegister entities.**

ADR-001/ADR-016 require realistic seed data for every schema a change introduces or modifies. This
change introduces none:

- The per-user default agent is a **single scalar stored in Nextcloud's user config**
  (`IConfig::setUserValue('hermiq', $uid, 'companion_agent_uuid', …)`), not an OpenRegister
  object. It has no schema to seed.
- The `Agent` schema is **read, never modified** — no property is added to it, so its existing
  seed data is unaffected.
- A default agent preference is inherently **per-user runtime state**, not installable fixture
  data: seeding it would mean asserting a preference on behalf of a user who has not expressed
  one, which is precisely what tiers 2 and 3 exist to handle. On a fresh install, no user has a
  default and the app is fully testable — resolution falls through to app-config (if hermiq#116
  has landed) or first-accessible, exactly as today.

**Net seed-data delta: none.**

## Declarative-vs-imperative decision

**Applicable — this change touches widgets/headerActions, so ADR-031 must be answered. The answer
is declarative for the action, imperative for the resolver.**

### "Make my default" → declarative `api-call` headerAction

`AgentDetail` is a `type: detail` manifest page whose `config.headerActions` already holds three
declarative entries (`edit-agent`, `version-history`, `view-factsheet`, all `type: open-modal`).
`CnDetailPage` supports an **`api-call`** action type — *"POST/PUT + toast + refresh"* — with
`@objectId` / `@object.<field>` token resolution and an optional `visibleWhen` predicate.

"Make my default" is exactly that shape: PUT the current object's id to an endpoint, toast,
refresh. So it is declared, not coded:

```json
{
  "id": "make-my-default",
  "label": "Make my default",
  "type": "api-call",
  "method": "PUT",
  "url": "/apps/hermiq/api/user/default-agent",
  "body": { "agentId": "@objectId" },
  "icon": "StarOutline"
}
```

**No new Vue component, no new page, no bespoke button.** Two builder warnings:

1. **It MUST go in `config.headerActions`.** The manifest renderer's body-widget branch bypasses
   `CnDetailPage` and silently drops `config.headerActions` and `lifecycleActions` entirely.
   `AgentDetail` is `type: detail` with headerActions rendering today, so a fourth entry is safe —
   but verify it in a live browser. **Grepping the bundle is theatre**; schema-legal ≠ rendered.
2. **Confirm the exact `api-call` field names against the installed `CnDetailPage`** before
   writing the manifest. The shape above is illustrative; the component is the authority. A
   manifest key the renderer does not read fails *silently* — the button renders and does nothing.

### The resolver → imperative, correctly

Three tiers with an access check and a fall-through at each is control flow with a security
invariant. There is no declarative dialect for "try these sources in order, access-check each,
fall through on failure", and inventing one would hide the single most important rule in the
change. It stays a plain PHP method.

### Not touched

No lifecycle/status transitions (this is a free-form record action, **not** a state-machine
transition — it belongs in `headerActions`, never `lifecycleActions`). No aggregations, no derived
fields, no notifications, no relations. No new widget and no `widgetKey` — the hexagon avatar is a
presentational fallback inside existing surfaces.

## Risks / Trade-offs

### [hermiq#116 has not landed] → the app-config tier is optional by construction

Verified: `companion_agent_uuid` appears nowhere in `lib/` or `src/` at HEAD. The resolver reads
the key defensively — absent or empty means "this tier has no answer" — so both merge orders work
and neither PR blocks the other. **Do not implement the key here.** If #116 lands first, tier 2
starts answering with no change to this code. If this lands first, the chain is per-user →
first-accessible until #116 arrives.

### [The hexagon is CSS local to a fixed-position button] → it must be shared, not copy-pasted

`.cn-ai-floating-button` is `position: fixed !important; z-index: 9000 !important;` at a fixed
52×60px, with the hex shape in a child `__hex` span. **None of that is reusable as an avatar** —
an avatar is inline, sized by its container, and appears many times per page.

Options considered:

| Option | Verdict |
|---|---|
| Copy the clip-path CSS into hermiq | **Rejected.** Duplicates a brand rule with a load-bearing √3:2 ratio into a leaf app; guarantees drift. Vue logic and shared visual contracts live in `nextcloud-vue`. |
| Reuse `CnAiFloatingButton` directly | **Rejected.** `position: fixed !important` cannot be overridden into an inline avatar; it is a floating button by construction. |
| Extract a shared hexagon avatar in `nextcloud-vue` (e.g. `CnAgentAvatar`) taking a size + optional MDI icon name, defaulting to the companion's `Creation` icon | **Chosen.** One brand-correct implementation, consumed by hermiq and any future app. Requires an nc-vue change + version bump. |

Trade-off: this makes the change **cross-repo**, adding an nc-vue PR (branch from `beta`) and a
publish before hermiq can consume it. Accepted — the alternative is brand drift in a leaf app. The
**contract** (props, sizing, orientation) must be shared, not just the component.

### [The picker's placement] → above "Talk delivery", below "About Hermiq"

`src/App.vue`'s `#user-settings` slot renders, in order: `#about` ("About Hermiq") →
`#talk-delivery` ("Talk delivery") → `#setup` ("Setup", `v-if="isAdmin"`) → `#credentials`
("Credentials"). The new section goes **between `#about` and `#talk-delivery`**: "About" is prose,
so the default-agent picker becomes the first *actionable* personal setting — which matches its
importance (it decides which agent every chat talks to). Note `#setup` is admin-only; the new
section is **not** — every user sets their own default.

### [Model/provider mismatch is not fixed by this change] → deliberate, and named

The `claude --model qwen2.5` failure had **two** causes: the picker chose an unsuitable agent
(fixed here), and nothing validates that an agent's `model` is compatible with its provider
(**not** fixed here). `ProviderFactory` lines 1880-1882 will still let any agent `model` override
`anthropicConfig.chatModel`, and the runner will still exit 1 with empty stderr and spin the UI
forever. A user who sets a `qwen2.5` agent as their default reproduces the original bug on
purpose.

Out of scope because it is a different capability (provider/model compatibility validation +
surfacing runner exit codes to the UI) with a different blast radius. **It must be filed as a
follow-up issue, not forgotten** — see DEFERRED_QUESTIONS in the report for this change. A
cheap partial mitigation available here: the settings picker MAY warn when the selected agent's
`model` does not match the configured provider's family. Not specified as a requirement — the
authoritative compatibility check does not exist yet, and a guess in the UI would be worse than
nothing.

## Migration Plan

No data migration. Deploy order is unconstrained relative to hermiq#116 (see above). Rollback:
revert; orphaned user-config rows are harmless and forward-compatible. If the nc-vue hexagon
component is chosen, nc-vue publishes first, then hermiq bumps and consumes — standard order.

## Open Questions

Recorded in full as DEFERRED_QUESTIONS in this change's report. The two that most affect the
build: (1) whether the shared hexagon avatar lands in `nextcloud-vue` (assumed yes) or is deferred
so hermiq ships the resolver alone; (2) the exact `api-call` field names in the installed
`CnDetailPage` version.
