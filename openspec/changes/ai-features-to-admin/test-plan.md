# Test Plan: ai-features-to-admin

## Test Cases

### TC-1: Admin settings shows the AI-feature register
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-ai-feature-register-renders-inside-nextcloud-admin-settings-not-the-in-app-nav`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — the DPO-adjacent governance
  admin who reviews/enables AI features
- **preconditions**: Logged in as an instance admin; at least one seeded `AiFeature`
  object exists
- **steps**: Navigate to Administration settings → Hermiq
- **expected result**: An "AI features" section is visible below "AI provider" and "Web
  research", listing the seeded feature(s) with name, risk category, lifecycle state,
  and DPO-acknowledgement column; Acknowledge/Enable/Disable buttons are present and
  behave identically to the former `/ai-features` nav page
- **test command**: `/test-functional`

### TC-2: The in-app nav no longer exposes AI features
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-ai-feature-register-renders-inside-nextcloud-admin-settings-not-the-in-app-nav`
- **type**: regression
- **persona**: Sem (Young Digital Native / general app user)
- **preconditions**: Logged in as any user with access to the hermiq app
- **steps**: Open the hermiq app's main nav and inspect the menu list; attempt to
  navigate directly to the app's former in-app `/ai-features` path
- **expected result**: No "AI features" menu item appears in the main nav; the former
  in-app route does not resolve to the AI-feature register (no matching SPA route)
- **test command**: `/test-functional`

### TC-3: Enable is still blocked until DPO acknowledgement, from the new location
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-governance-api-and-its-authorization-are-unchanged-by-the-ui-relocation`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: A `disabled`, non-acknowledged `AiFeature` exists; admin is on
  `/settings/admin/hermiq`
- **steps**: Click "Enable" on the un-acknowledged feature; observe the button is
  disabled; click "Acknowledge (DPO)"; click "Enable" again
- **expected result**: "Enable" is disabled/refused before acknowledgement (server-side
  409 if forced) and succeeds after acknowledgement — identical behaviour to the
  pre-move nav page, proving the relocation did not change the governance API
- **test command**: `/test-functional`

### TC-4: Non-admin API authorization is unchanged
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-governance-api-and-its-authorization-are-unchanged-by-the-ui-relocation`
- **type**: security
- **persona**: Priya (ZZP Developer / Integrator) — calling the API directly, not
  through any UI
- **preconditions**: A non-admin user with no `aifeature.*` action-matrix broadening
- **steps**: Call `POST /apps/hermiq/api/ai-features/{slug}/acknowledge` directly as
  that user
- **expected result**: 403 `OCSForbiddenException`, matching the behaviour before this
  change (the UI relocation does not touch `ActionAuthService` gating)
- **test command**: `/test-api`

### TC-5: opencatalogi_available / is_admin resolve correctly from the new bootstrap
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-admin-settings-bootstrap-supplies-the-same-capability-flags-the-component-already-reads`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: Two runs — (a) OpenCatalogi installed, (b) OpenCatalogi absent
- **steps**: Open `/settings/admin/hermiq` in each configuration; inspect whether the
  "Publish to Algoritmeregister" button appears on a ready-to-publish, high-risk,
  enabled, acknowledged feature
- **expected result**: (a) the publish button is visible; (b) the publish/withdraw
  buttons are entirely absent (graceful degradation), matching the former nav page's
  behaviour
- **test command**: `/test-functional`

## Coverage Summary
- REQ "The AI-feature register renders inside Nextcloud admin settings, not the in-app
  nav" — covered by TC-1, TC-2
- REQ "The admin-settings bootstrap supplies the same capability flags the component
  already reads" — covered by TC-5
- REQ "The governance API and its authorization are unchanged by the UI relocation" —
  covered by TC-3, TC-4

## Out of Scope
- No Playwright browser-automation suite is added for this surface — consistent with
  the existing "AI provider"/"Web research" admin-settings sections, which are also
  live-verified only (see `@e2e exclude` notes in the spec). TC-1/2/3/5 are executed via
  live browser verification during implementation, not a committed automated test.
- Algoritmeregister publish/withdraw *functional correctness* (readiness-gate matrix,
  fleet-publication delegation) is already covered by the `algoritmeregister-publication`
  change's own test plan and is not re-tested here — TC-5 only checks the button's
  *visibility* resolves correctly from the new bootstrap, not the publish flow itself.
