---
kind: code
---

# Proposal: skill-package-multifile

## Summary

Hermiq's skill publish path already carries auxiliary files — `GitHubTemplatePushService::publish()` accepts `auxFiles` and commits each one as its own blob at its own path, and `isSafeRepoPath()` explicitly permits nested paths like `references/persona.md`. The install path cannot receive them. `SkillMarketplaceService::installFromSource()` takes a single `package` **string**, `SkillSerializer::fromPackage()` returns only `{frontmatter, body, name, description}`, and both `installFromSource()` and `SkillService::importSkill()` hardcode `'files' => []`. The round trip is therefore asymmetric: a multi-file skill publishes complete and re-installs as a bare `SKILL.md`, reporting success. This change makes the install side symmetric with the publish side by introducing a directory-form package that carries `files[]`, while keeping the existing single-`.md` form parsing unchanged.

## Motivation

The `Skill` schema has modelled auxiliary files since the skills-catalog change: `files[]` is an array of `{name, content}`, and `SkillDraft` carries the matching `proposedFiles`. The publish half of the feature was built out (file selection, `learning-candidates.md` stripping, per-blob commits at nested paths). The install half was never wired — it writes an empty array unconditionally.

This is a silent-fidelity-loss bug, not a missing feature. Nothing errors. The install returns HTTP 200 and a well-shaped Skill object; the skill simply no longer works, because the reference material, scripts and assets its body points at are gone. A skill whose `SKILL.md` says "follow the checklist in `references/local-checks.md`" installs cleanly and then instructs the agent to read a file that does not exist.

The scale is not marginal. In the hydra skill set that motivates this work, **78 of 94 skills are multi-file**; `create-pr` alone carries 63 files across `references/`, `evals/` and `learnings.md`. Under today's install path every one of those 78 skills arrives gutted.

The immediate driver is the `buildiq-hydra` repository, which is intended to be a full, installable replacement for the hydra app including its skills. That is only meaningful if a skill survives the export → import round trip intact.

## Affected Projects

- [ ] Project: `hermiq` — `SkillSerializer` gains a directory-form package; `SkillService::importSkill()` and `SkillMarketplaceService::installFromSource()` persist the parsed `files[]` instead of `[]`; the marketplace install route accepts a multi-file payload.

## Scope

### In Scope

- A **directory-form package**: a package that carries `SKILL.md` plus zero or more auxiliary files at their own (possibly nested) relative paths.
- `SkillSerializer::toPackageFiles()` / `fromPackageFiles()` — a files-aware counterpart to the existing string form, emitting and parsing the directory form.
- `SkillService::importSkill()` persisting parsed `files[]`.
- `SkillMarketplaceService::installFromSource()` persisting parsed `files[]`, with the existing quarantine behaviour unchanged.
- Content scanning extended to cover auxiliary file content, not just `body` + `frontmatter` — an aux file is executable instruction material and must not bypass the gate that the body goes through.
- Path safety on the install side, mirroring `isSafeRepoPath()`: reject absolute paths, `..` traversal, backslashes and empty segments.
- Back-compatible parsing: an existing single-`.md` package still installs exactly as it does today, with `files[]` empty.

### Out of Scope

- **Bundling many skills into one repository.** Publishing N skills to a single repo (the `buildiq-hydra` layout) is a separate change; this one only makes a single skill round-trip losslessly.
- **Changing the publish side.** `publish()`, `republish()`, `auxFiles`, and `publishFileSelection()` are already correct and are not touched.
- **Changing the `Skill` schema.** `files[]` already exists with the right shape; no register migration is needed.
- **Binary/asset encoding.** Auxiliary files are treated as UTF-8 text, matching how `files[].content` is already modelled and how the publish path base64-encodes for transport only. Binary assets are deliberately deferred.
- **The `learning-candidates.md` selection rule.** Publish-time stripping stays exactly as it is.

## Approach

Add a files-aware package form alongside the existing string form rather than replacing it.

The current `toPackage()`/`fromPackage()` pair maps a skill to a single markdown string, and its byte-for-byte frontmatter guarantee is load-bearing (it is what keeps agentskills.io interop honest). That pair keeps its exact signature and behaviour. Two new methods handle the directory form as a `path => contents` map — the same shape `GitHubTemplatePushService` already thinks in, and the same shape OpenBuild's `AppRepoSerializer` uses, so the downstream bundle change has a natural seam to build on.

`fromPackageFiles()` delegates the `SKILL.md` entry to the existing `fromPackage()`, so the frontmatter round-trip guarantee is inherited rather than reimplemented. Everything that is not `SKILL.md` becomes a `files[]` entry, after path validation.

The install callers then persist `$parsed['files']` instead of the hardcoded `[]`. The marketplace route accepts either a bare string (today's behaviour, unchanged) or a files map.

## New Dependencies

None.

## Impact

- `lib/Service/SkillSerializer.php` — two new public methods; existing two unchanged.
- `lib/Service/SkillService.php` — `importSkill()` persists parsed files.
- `lib/Service/SkillMarketplaceService.php` — `installFromSource()` persists parsed files; `scanContent()` covers aux content.
- `lib/Controller/SkillMarketplaceController.php` — install route accepts a files map in addition to a package string.
- No schema change, no migration, no route removal. Existing callers of `toPackage()`/`fromPackage()` (`AgentTemplateService`, `HermiqSkillShareableConfigType`, `SkillService::exportSkill()`) are unaffected.

## Cross-Project Dependencies

Consumed by, but not blocking on:

- **openbuild** `app-repo-format-v2` — needs a settled multi-file skill package shape before it can embed skills in an app repo.
- **buildiq-hydra** publication — depends on this change for its skills to be installable.

## Risks

### Risk 1: Auxiliary file content bypasses the content-safety gate

**Severity:** High — **Mitigation:** `installFromSource()` scans `body` + `frontmatter` today. Auxiliary files are instruction material an agent will read and act on, so an unscanned aux file is a direct injection route into an agent's context. Extend `scanContent()` to include aux file content in the scanned material, and keep the existing fail-closed quarantine: an install still always lands `quarantined` regardless of source, so a dangerous verdict blocks one-click approval exactly as it does for the body today.

### Risk 2: Path traversal via a crafted package

**Severity:** High — **Mitigation:** A package is externally supplied. Validate every aux path on the install side with the same rules `isSafeRepoPath()` applies on publish (no leading `/`, no `..` or `.` segments, no empty segments, no backslashes, length bound). Reject the entry rather than sanitising it, so a malicious path fails loudly instead of silently landing somewhere unexpected.

### Risk 3: Breaking the existing single-file install path

**Severity:** Medium — **Mitigation:** The existing `fromPackage()` is not modified and the existing route contract still accepts a bare string. A regression test asserts a single-`.md` package still installs with `files: []`, and the existing 5 SkillSerializer tests must stay green unchanged.

### Risk 4: Package size on large skills

**Severity:** Low — **Mitigation:** `create-pr` at 63 files is the realistic worst case and is well within request limits as text. No pagination is introduced; if a genuinely large skill appears later, that is a separate concern from correctness.

## Rollback Strategy

Revert the commit. The change is additive: no schema migration, no data rewrite, no route removal. Skills installed while it was live keep their `files[]` — the field already exists in the schema, so rolled-back code simply stops populating it and ignores what is there. No stored object becomes invalid.

## Open Questions

None.
