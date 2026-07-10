# Tasks: fix-skill-marketplace-action-auth

## 1. Action matrix

- [ ] 1.1 Add `skill.approve-quarantined`, `skill.override-scan-verdict`, `skill.publish-hub`
      to the app's action-matrix seed data (the same file/mechanism seeding
      `aifeature.acknowledge` / `aifeature.enable` / `aifeature.disable`), default `["admin"]`.

## 2. Controller

- [ ] 2.1 Inject `ActionAuthService` + `LoggerInterface` (already present) into
      `SkillMarketplaceController::__construct()`.
- [ ] 2.2 In `approve(string $id)`: after the `Unauthenticated` check, call
      `$this->actionAuth->requireAction($user, 'skill.approve-quarantined')`, catching
      `OCSForbiddenException` → `403` (mirror `AiFeatureController::acknowledge()`).
- [ ] 2.3 When the request's `force` param is `true`, additionally require
      `skill.override-scan-verdict` BEFORE calling
      `SkillMarketplaceService::approveQuarantined(..., force: true)` — a caller who can
      approve clean skills but not override dangerous ones must get `403` on the
      force path specifically, not silently downgraded to non-forced behaviour.
- [ ] 2.4 In `publish(string $id)`: after the `Unauthenticated` check, call
      `$this->actionAuth->requireAction($user, 'skill.publish-hub')`, catching
      `OCSForbiddenException` → `403`.
- [ ] 2.5 Update the class docblock (currently claims "tenancy is the guard") to describe
      the action-RBAC layering, matching `AiFeatureController`'s docblock style.

## 3. Tests

- [ ] 3.1 Unit-test: non-admin caller with no matrix entry gets `403` on `approve()` and
      `publish()`; admin caller (or a caller whose group is granted the action) succeeds.
- [ ] 3.2 Unit-test: non-admin granted `skill.approve-quarantined` but NOT
      `skill.override-scan-verdict` gets `403` when calling `approve(force: true)` on a
      skill with a `dangerous` scan verdict, but succeeds on a clean-verdict skill.
- [ ] 3.3 Update any existing test/fixture that asserted "any authenticated user may
      approve/publish" to the new gated behaviour.

## 4. Verify

- [ ] 4.1 Verify live on NC + OR: a non-admin tenant member gets `403` calling
      `POST /api/skills/{id}/approve` and `POST /api/skills/{id}/publish`; an admin
      succeeds; after broadening the matrix via the admin settings UI, a non-admin in the
      granted group succeeds too.
- [ ] 4.2 `composer phpcs` (lib scope) + PHPStan; PHPUnit the CI way.

## Acceptance criteria

- `SkillMarketplaceController::approve()` and `::publish()` require action-RBAC via
  `ActionAuthService`, defaulting to admin-only, same as `AiFeatureController`.
- Overriding a `dangerous` content-scan verdict (`force=true`) requires a strictly more
  privileged action than a plain approve.
- No regression to the quarantine invariant (externally-sourced skills still start
  `quarantined`, never auto-`active`).

## Quality reminders

- SPDX in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- i18n keys in English if any new UI copy is added for 403 handling.
