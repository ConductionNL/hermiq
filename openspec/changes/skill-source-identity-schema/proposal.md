---
kind: config
---

## Why

A skill installed from a published bundle carries **no durable identity**.
`SkillMarketplaceService::installFromSource()` calls `saveObject()` with no uuid and
no existence check, so every install creates a new object. Re-installing an app
duplicates every one of its skills.

This is not hypothetical — it has already happened on the shared instance:

| skill | rows |
|---|---|
| Meeting Summariser | `active` (approved, 07-05) **and** a `quarantined` shadow (07-26) |
| Nightly Backup Helper | two `active` copies, five minutes apart on 07-05 |

The first is the dangerous shape: an approved skill with a quarantined twin
competing with it. Re-installing `buildiq-hydra` today would add **94 more**.

The `Skill` schema has `githubOwner`/`githubRepo`, but **the install path never
populates them** — they are stamped only by `stampGithubPublish()`, the publish
direction. `SkillSource` has a `url`, but models a browsable source registry and is
not referenced by the install path at all. So there is nothing to match on.

Fixing the install logic is meaningless without somewhere to record identity, so
that field comes first, as its own `config` change (ADR-032). The code change
depends on it.

## What Changes

Two properties on the `Skill` schema in `lib/Settings/hermiq_register.json`:

- **`sourceUrl`** — the canonical location a skill was installed from, in the form
  `https://github.com/<owner>/<repo>/skills/<bundleName>`. **No git ref**: a branch
  is not identity, and pinning one would make every branch a different skill.
- **`sourceUpdatedAt`** — when the skill was last updated **from** that source.

`sourceUpdatedAt` is deliberately distinct from the existing `publishedAt`.
`publishedAt` records when this instance published a skill **to** GitHub, so on a
consuming instance that never publishes it is empty. Any "has anything changed
since we last synced?" question answered against `publishedAt` would silently
always answer no — which is precisely how a guard that never fires gets written.

## Capabilities

### Modified Capabilities
- `skills-marketplace`: an installed skill records where it came from and when it
  was last refreshed from there.

## Impact

- **Modified**: `lib/Settings/hermiq_register.json` (`Skill` schema only)
- **Data**: two new optional properties; no existing property changes shape, so no
  migration and no rewrite of the 101 existing skill objects
- **Consumers**: none yet — `skill-install-idempotency` is the first reader
