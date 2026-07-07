---
kind: code
---

# Proposal: raise-nc-minversion-taskprocessing

## Why

`appinfo/info.xml` declares `<nextcloud min-version="28" max-version="34"/>`, but
`lib/AppInfo/Application.php::register()` calls
`IRegistrationContext::registerTaskProcessingProvider()` **unconditionally** four
times (lines 126–129: `Text2TextProvider`, `Text2TextSummaryProvider`,
`Text2TextHeadlineProvider`, `ContextAgentProvider`).

`IRegistrationContext::registerTaskProcessingProvider()` is `@since 30.0.0`. On a
Nextcloud 28 or 29 instance the method does not exist, so the call raises a fatal
`Error: Call to undefined method` **during app bootstrap** — hermiq cannot enable at
all on the two lowest NC versions it advertises support for. The advertised
`min-version="28"` is therefore false: the real floor is NC 30.

This is a pure product-readiness (honesty) defect: the manifest advertises a
compatibility range the code cannot honour. Two ways to make the claim true — raise
the floor, or guard the calls behind a version check. Hermiq's whole reason to exist
is to back Nextcloud's TaskProcessing AI API (the providers are core, not optional),
and the rest of the fleet already baselines at NC 30+, so **raising the floor to 30**
is the honest, low-risk fix; version-guarding would ship a hermiq that silently
provides no AI on 28/29, which defeats the app.

## What Changes

- `appinfo/info.xml`: change `<nextcloud min-version="28" max-version="34"/>` to
  `min-version="30"`. `max-version="34"` is unchanged.
- No `lib/` change: the provider registrations are correct for NC ≥ 30; only the
  declared floor was wrong.
- Add a regression guard (a lightweight test/assertion, per the app's test
  conventions) that fails if any `@since`-gated NC API newer than the declared
  `info.xml` `min-version` is called unconditionally in `Application::register()` —
  so the floor and the code cannot drift apart again.

## Impact

- Affected: `appinfo/info.xml` (min-version 28 → 30), the app's test suite (new
  guard). No behavioural change on NC 30–34 (the only versions where hermiq ever
  actually booted).
- Users on NC 28/29: hermiq will now correctly refuse to install (accurate
  incompatibility) instead of installing and fataling at boot.
