---
kind: code
depends_on:
  - skill-source-identity-schema
---

## Why

Installing a skill bundle is **not idempotent**.
`SkillMarketplaceService::installFromSource()` calls `saveObject()` with no uuid and
no existence check, so every install creates a new object. Re-installing an app
duplicates every skill it ships.

Already true on the shared instance, before anyone re-installs anything:

| skill | rows |
|---|---|
| Meeting Summariser | `active` (approved, 07-05) **and** a `quarantined` shadow (07-26) |
| Nightly Backup Helper | two `active` copies, five minutes apart on 07-05 |

The first row is the shape that matters: an approved skill now has a quarantined
twin competing with it. Re-installing `buildiq-hydra` would add **94 more**, taking
the table from 101 to 195.

This is the same defect class that `apply-v2-channels` just fixed for connectors —
an install path with no identity — except nothing on the hermiq side prevents it.

## What Changes

**Identity.** Skills are matched on `sourceUrl` (from
`skill-source-identity-schema`). Existing skills carry none, so resolution is:
exact `sourceUrl` match → **one-time** fallback to normalised name among skills that
have no `sourceUrl` → stamp `sourceUrl` so every later install is an exact match.
Known mirror hosts are normalised to one canonical host first, so the same skill
installed from Codeberg and from GitHub is one object rather than two.

**Update, do not duplicate.** A matched skill is updated in place:

| updated from the bundle | preserved, never touched |
|---|---|
| `body`, `frontmatter`, `files`, `description` | `maturityLevel`, `targetLevel`, `levelEvidence`, `installedOn`, `createdBy`, `publishedAt`, `archivedAt`, `lastAcceptedVersionAt` |

**Re-quarantine on any content change.** If the incoming content differs at all, the
skill returns to `quarantined` with a reason naming the change, and must be
re-approved. Preserving an approval across a content change would let unreviewed
content run under an old decision — the quarantine gate exists precisely to stop
that, so it re-arms whenever the content it approved is no longer the content
present.

**Local learnings are never overwritten.** ADR-068 §3 gives a skill a `learnings.md`
that this instance can add to. When local learnings have been accepted since the last
sync, an incoming `learnings.md` **does not replace them** — the rest of the update
still applies, and the outcome says the local learnings were kept. The destructive
case is made unreachable rather than merely announced; a warning delivered after the
overwrite would be worthless.

The condition is deliberately narrow — `lastAcceptedVersionAt > sourceUpdatedAt`
**and** the incoming `learnings.md` actually differs — so it cannot fire on a skill
nobody has taught anything.

Note it CANNOT be built on the existing `SkillConsolidationService::isBehind()`,
which compares against `publishedAt`. That is the publish direction; on a consuming
instance `publishedAt` is empty and the check silently always returns false.

**Cleanup.** The two real duplicate rows are removed, oldest-approved kept.

## Capabilities

### Modified Capabilities
- `skills-marketplace`: installing a skill that is already present updates it rather
  than duplicating it, and never silently discards local learnings.

## Impact

- **New**: `lib/Service/SkillIdentityResolver.php`, `lib/Service/SkillUpsertPolicy.php`
- **Modified**: `lib/Service/SkillMarketplaceService.php` (`installFromSource`),
  `lib/Service/SkillBundleInstaller.php` (per-skill outcomes),
  `lib/Controller/SkillController.php` (surface outcomes)
- **API**: bundle-install outcomes gain `updated`, `learningsKept` and the matched
  identity (additive). Consumed by OpenBuild's `AppChannelApplier`, which carries
  hermiq's counts through unmodified.
- **Data**: existing skills gain `sourceUrl` on first re-install; two duplicate rows
  deleted after inspection
