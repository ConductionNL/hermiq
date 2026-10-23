---
kind: code
depends_on: [session-data-migration]
---

# Proposal: session-api-rename

# Summary

Move hermiq's conversation API surface to `/api/sessions/*`, rename `ConversationController` and the services behind it, and point them at the `session` schema. This is the spec that breaks existing API consumers, so it is also the spec that has to state what happens to them.

## Motivation

With the data migrated, `/api/conversations/*` is the last backend surface still using the retired word. Leaving it renamed-in-the-UI-only is the worst of both worlds: the vocabulary claim ("we standardised on session") would be false at the layer most likely to be read by an integrator.

## Affected Projects

- [ ] Project: `hermiq` — routes, controller, services

## Scope

### In Scope

- `/api/sessions` (GET, POST), `/api/sessions/{uuid}` (GET, PATCH, DELETE), `/api/sessions/{uuid}/messages` (GET), `/api/sessions/{uuid}/restore` (POST), `/api/sessions/{uuid}/permanent` (DELETE).
- The feedback route currently at `/api/conversations/{conversationUuid}/messages/{messageId}/feedback` → `/api/sessions/{sessionUuid}/...`, including the parameter name.
- Rename `ConversationController` → `SessionController` and the services/DTOs behind it; point reads and writes at the `session` schema.
- Keep the old `/api/conversations/*` routes as **deprecated aliases** delegating to the new controller — see Approach.
- Every route keeps its current auth attribute. A rename must not quietly change an endpoint's auth posture; a missing attribute makes an endpoint unreachable, and a wrong one makes it too reachable.

### Out of Scope

- Removing the deprecated aliases. That is a later change, once telemetry shows nobody calls them.
- Frontend — the next spec.
- The `conversation` schema itself.

## Approach

Add the `/api/sessions/*` routes pointing at the renamed controller, and keep `/api/conversations/*` as thin aliases onto the same methods.

**Deprecation posture: alias, don't break.** The alternative — delete the old routes with the rename — turns every existing integration into a 404 on deploy, with no transition. The aliases cost a handful of lines in `routes.php`, and they let the old path be retired on evidence rather than on optimism. Each alias logs at info level when hit, so the retirement decision is made from data.

## New Dependencies

None.

## Impact

- `appinfo/routes.php` — new routes, old ones retained as aliases.
- `lib/Controller/ConversationController.php` → `SessionController.php`, plus the services behind it.
- `lib/Settings/*` if any registered notification or capability references the controller by name.
- Consumers: none break on deploy. The frontend keeps working through the aliases until the next spec moves it.

## Cross-Project Dependencies

Depends on `session-data-migration` (same repo). Reading the `session` schema before the objects exist returns an empty list — which looks exactly like "the user has no sessions", the failure mode this ordering exists to prevent.

## Risks

### Risk 1: An auth attribute is lost in the rename
**Severity:** High — **Mitigation:** Every route in `routes.php` must map to a controller method declaring its posture (`#[PublicPage]` / `#[NoAdminRequired]` / `#[NoCSRFRequired]` / `#[AuthorizedAdminSetting]`). A missing attribute makes the endpoint unreachable — NC middleware rejects before the controller runs — and a wrongly widened one is an authorization hole. Diff the attribute of every moved method against its original, one by one, and treat the list as a review artifact rather than a claim.

### Risk 2: A route points at a method that no longer exists
**Severity:** Medium — **Mitigation:** A `routes.php` entry naming a missing method is a ReflectionException 500 at runtime, not a startup error, so it will not be caught by "the app still loads". Hit every new route once and assert the status code.

### Risk 3: The aliases silently diverge
**Severity:** Low — **Mitigation:** Aliases point at the same controller methods, not copies. No second implementation exists to drift.

## Rollback Strategy

Revert. The old routes were never removed, so a revert restores the previous controller with the old paths still serving — no consumer is stranded mid-way.

## Open Questions

None.
