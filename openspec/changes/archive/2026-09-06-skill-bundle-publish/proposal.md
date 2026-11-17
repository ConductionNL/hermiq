---
kind: code
depends_on:
  - skill-package-multifile
---

# Proposal: skill-bundle-publish

## Summary

Hermiq publishes exactly one skill per GitHub repository. `GitHubTemplatePushService::publish()` creates a new repo, commits `hermiq-skill.md` at its root, and tags it `topic:hermiq-skill`. Publishing hydra's skill set under that model means **94 separate repositories**, one per skill, and there is no way to install them as a set. This change adds a *bundle*: many skills in one repository under `skills/<name>/`, published create-or-update, and installed as a set through the existing quarantine gate.

## Motivation

The immediate driver is `buildiq-hydra`, which is meant to be a single installable replacement for the hydra app **including its 94 skills**. One-repo-per-skill defeats that outright: it is not one artefact, it cannot be versioned as one artefact, and installing it means 94 separate install actions against 94 repos that must all be discovered first.

The one-repo-per-skill model also has no update story for a set. `publish()` refuses an existing repo (`assertRepoAbsent()`), and the one carve-out, `republish()`, is deliberately scoped to a single skill's own provenance repo — the caller derives owner/repo from that skill's `githubOwner`/`githubRepo` and never accepts client coordinates. Neither shape can express "re-sync these 94 skills to this one repo".

Finally, the layout should match how the skills already live on disk. Hydra stores them as `.claude/skills/<name>/SKILL.md` plus `references/`, `assets/`, `evals/`. A bundle that mirrors that (`skills/<name>/…`) round-trips a real skill directory without rewriting paths, which matters because `skill-package-multifile` made auxiliary paths meaningful.

## Affected Projects

- [ ] Project: `hermiq` — a bundle serialiser, a create-or-update bundle publish, bundle discovery, and a bundle install that fans out through the existing per-skill quarantine gate.

## Scope

### In Scope

- **Bundle layout**: `hermiq-skills.json` at the repo root (a self-describing manifest listing the bundled skills) plus `skills/<name>/SKILL.md` and `skills/<name>/<aux path>` per skill.
- **`SkillBundleSerializer`** — build the bundle tree from N skills, and parse a bundle tree back into N skill payloads. Delegates each skill to `SkillSerializer::toPackageFiles()` / `fromPackageFiles()` so the byte-for-byte frontmatter guarantee and the auxiliary path validation are inherited, not reimplemented.
- **Create-or-update publish** to a named repo. Unlike `publish()`, a bundle repo is expected to be re-synced, so an existing repo is updated (tree diff + ref update) rather than refused.
- **Bundle discovery** under a distinct topic (`hermiq-skill-bundle`) so a bundle repo is never mistaken for a single-skill repo by the existing catalogue.
- **Bundle install** — fetch the bundle, install each skill through the **unchanged** `installFromSource()` path so every skill still lands `quarantined` and still gets content-scanned individually.
- A per-skill result list on install (installed / skipped / failed), so a partial failure is reported rather than hidden.

### Out of Scope

- **Changing single-skill publish/install.** `publish()`, `republish()`, `githubInstall()` and the `hermiq-skill` topic keep their exact current behaviour.
- **Relaxing quarantine.** A bundle install is N ordinary installs; it grants no new trust. Bulk-approving a bundle is deliberately not offered.
- **Deleting skills on re-sync.** A bundle publish adds and updates; it never removes a skill that has disappeared from the source set. Destructive sync needs its own decision and its own spec.
- **Non-skill artefacts.** Flows, agents and personas belong to OpenBuild's `app-repo-format-v2`, not here.
- **Binary assets** — still deferred, as in `skill-package-multifile`.

## Approach

Build the bundle as a `path => contents` map, the same shape `SkillSerializer::toPackageFiles()` already returns and the same shape `GitHubTemplatePushService` already builds its tree from. The bundle serialiser is therefore mostly composition: for each skill, prefix its directory-form map with `skills/<name>/`.

Publishing reuses the existing Git-Data commit chain (ref → base commit → blobs → tree → commit → ref update) that both `publish()` and `republish()` already use; the only behavioural difference is that a missing repo is created and an existing one is updated, instead of an existing one being refused.

Installing walks the manifest and calls `installFromSource()` per skill. That deliberately keeps the security posture identical to a single install: each skill is scanned, each lands quarantined, and a dangerous verdict still blocks one-click approval.

## New Dependencies

None.

## Impact

- New: `lib/Service/SkillBundleSerializer.php`.
- Modified: `lib/Service/GitHubTemplatePushService.php` (bundle publish), `lib/Service/GitHubTemplateCatalogService.php` (bundle fetch + topic), `lib/Controller/SkillController.php` (bundle routes), `appinfo/routes.php`.
- Unchanged: `SkillSerializer`, `SkillService`, `SkillMarketplaceService::installFromSource()`.

## Cross-Project Dependencies

- **Depends on** `skill-package-multifile` — the bundle is built out of directory-form packages; without it every bundled skill would be a bare `SKILL.md`.
- **Consumed by** `buildiq-hydra` publication, and by openbuild's `app-repo-format-v2` when it embeds an app's skills.

## Risks

### Risk 1: A bundle install becomes a bulk-trust hole

**Severity:** High — **Mitigation:** Route every skill through the unchanged `installFromSource()`: individually content-scanned, individually quarantined. No bulk-approve affordance is added. A bundle is a delivery mechanism, never a trust assertion — installing 94 skills yields 94 quarantined skills a reviewer must still clear.

### Risk 2: Path traversal via a crafted bundle

**Severity:** High — **Mitigation:** Two layers. Skill *names* from the manifest are validated as slugs before being used as directory components; auxiliary paths inside each skill are validated by the inherited `SkillSerializer::isSafeAuxPath()`. A bundle entry that escapes its own `skills/<name>/` prefix is rejected, not relocated.

### Risk 3: Unbounded fan-out on install

**Severity:** Medium — **Mitigation:** A bundle is N installs and N content scans in one request. Bound the skill count and the total fetched bytes, report truncation explicitly, and return a per-skill result list so a caller sees exactly what landed rather than inferring success from a 200.

### Risk 4: Re-sync silently diverges from the source set

**Severity:** Low — **Mitigation:** Publish is additive by design (see Out of Scope) and the manifest records what was published, so a reader can diff intent against the tree. Destructive sync is deferred rather than half-implemented.

## Rollback Strategy

Revert the commit. Purely additive: new service, new routes, new topic. No schema change, no migration, and no existing route's behaviour is modified — single-skill publish and install are untouched, so a rollback cannot strand an already-published single skill. Bundle repos published while it was live remain valid git repositories; they simply stop being installable until it is re-applied.

## Open Questions

None.
