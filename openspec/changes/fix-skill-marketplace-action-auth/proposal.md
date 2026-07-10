---
kind: code
---

# Proposal: fix-skill-marketplace-action-auth

# Why

`lib/Controller/SkillMarketplaceController.php` gates `approve()` (`lib/Controller/SkillMarketplaceController.php:118-156`)
and `publish()` (`lib/Controller/SkillMarketplaceController.php:170-185`) with nothing more
than "is anyone logged in" (`$this->userSession->getUser() === null`). There is no
`ActionAuthService::requireAction()` call and no per-object owner/reviewer check — every
authenticated tenant user can invoke both endpoints.

`approve()` forwards straight to `SkillMarketplaceService::approveQuarantined()`
(`lib/Service/SkillMarketplaceService.php:161-192`), which re-scans the skill body via
`ContentScanService` and — when the scan verdict is `SEVERITY_DANGEROUS` — blocks the
transition *unless the caller passes `force=true`*
(`lib/Service/SkillMarketplaceService.php:180-190`). Because the controller performs no
action-level check, **any** authenticated member of a tenant can pass `force=true` over the
`/api/skills/{id}/approve` route and activate a skill the scanner explicitly flagged for
remote-code / destructive-shell / exfiltration / prompt-injection patterns
(`lib/Service/SkillMarketplaceService.php` docblock, `lib/Controller/SkillMarketplaceController.php:133-148`
implements the `409` "conflict" UX for exactly this case, but nothing stops a non-reviewer
from resubmitting with `force=true`). `publish()` similarly lets any tenant member push any
skill's content to an external hub via OpenConnector with no reviewer/curator gate.

This is precisely the class of endpoint ADR-023 (`hydra/openspec/architecture/adr-023-action-authorization.md`)
was written for — "a regular member ... CANNOT invoke `generateMinutesDraft()`" — and the
same app already implements the correct pattern one file over:
`lib/Controller/AiFeatureController.php:111-141,155-214` gates `acknowledge()` / `enable()` /
`disable()` through `ActionAuthService::requireAction()` with actions seeded `["admin"]`
by default. `SkillMarketplaceController` has no equivalent, making the marketplace's
security-scan override the one privileged action in the app with zero action-RBAC.
`installFromSource()` is lower risk (it only ever produces `quarantined` output, never
`active`) but is included for consistency and future-proofing since it is the entry point
for the whole flow.

# What Changes

- Inject `ActionAuthService` into `SkillMarketplaceController` (mirrors
  `AiFeatureController`'s constructor pattern).
- Gate `approve()` behind a new action `skill.approve-quarantined`; when the caller passes
  `force=true` AND the scan verdict is `dangerous`, additionally require a distinct,
  more restrictive action `skill.override-scan-verdict` (both default `["admin"]`) — a
  non-privileged tenant member may approve a *clean* scan but not override a *dangerous* one.
  **BREAKING**: previously any authenticated user could do both; after this change both
  require action-RBAC membership (default admin-only until an admin broadens the matrix).
- Gate `publish()` behind a new action `skill.publish-hub`.
- Seed `skill.approve-quarantined`, `skill.override-scan-verdict`, `skill.publish-hub` into
  the app's action matrix seed (wherever `aifeature.*` actions are currently seeded) with
  `["admin"]` defaults.
- Return `401`/`403` consistent with `AiFeatureController`'s `OCSForbiddenException` →
  `Http::STATUS_FORBIDDEN` mapping.
- Update `src/views/SkillsCatalog.vue` (if it inspects response status) and any Newman/unit
  fixtures asserting today's "any authenticated user" behaviour.

# Impact

- Affected code: `lib/Controller/SkillMarketplaceController.php`, the action-matrix seed
  file(s) used by `ActionAuthService`, `tests/Unit/Controller/SkillMarketplaceControllerTest.php`
  (or equivalent), `src/views/SkillsCatalog.vue` (403 handling only, no new UI required —
  admins already see the Approve/Publish actions).
- Affected specs: `skills-marketplace` (adds an action-authorization requirement).
