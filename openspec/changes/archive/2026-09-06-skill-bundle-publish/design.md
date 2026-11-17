# Design: skill-bundle-publish

## Architecture Overview

A bundle is a repository holding many skills, laid out the way skills already live on disk.

```
buildiq-hydra/
  hermiq-skills.json              ← manifest: what this bundle contains
  skills/
    create-pr/
      SKILL.md                    ← SkillSerializer's directory form, prefixed
      learnings.md
      references/local-checks.md
      evals/evals.json
    blog-write/
      SKILL.md
      references/voice.md
      assets/blog-template.mdx
```

The layout mirrors hydra's own `.claude/skills/<name>/` exactly, so a real skill directory round-trips without path rewriting — which matters now that `skill-package-multifile` made auxiliary paths meaningful rather than decorative.

**Composition, not reimplementation.** Every layer delegates down:

```
SkillBundleSerializer::toBundle(skills[])
  └── for each skill: SkillSerializer::toPackageFiles(skill)   ← frontmatter fidelity
        └── prefix each entry with skills/<name>/

SkillBundleSerializer::fromBundle(files{})
  └── group entries by skills/<name>/ prefix
        └── SkillSerializer::fromPackageFiles(stripped map)    ← aux path validation
```

Neither the frontmatter round-trip nor the auxiliary path safety rules are restated here; they are inherited. That is deliberate — a second copy of `isSafeAuxPath()` would be a second place to get it wrong.

## API Design

### `POST /apps/hermiq/api/skills/bundle/publish`

**Auth**: Nextcloud session; owner-gated through the broker exactly as single-skill publish is.

**Request:**
```json
{
  "owner": "ConductionNL",
  "repo": "buildiq-hydra",
  "visibility": "private",
  "skillIds": ["<uuid-1>", "<uuid-2>"],
  "credentialId": "<credential-uuid>"
}
```

**Response (200):**
```json
{
  "repoUrl": "https://github.com/ConductionNL/buildiq-hydra",
  "commitSha": "0000000000000000000000000000000000000000",
  "created": false,
  "skills": [
    { "name": "create-pr", "files": 63, "outcome": "published" },
    { "name": "blog-write", "files": 7, "outcome": "published" }
  ]
}
```

`created` distinguishes a fresh repo from an update — the caller should not have to infer it.

### `POST /apps/hermiq/api/skills/bundle/install`

**Request:**
```json
{ "owner": "ConductionNL", "repo": "buildiq-hydra", "ref": null, "credentialId": "<credential-uuid>" }
```

**Response (200):**
```json
{
  "installed": 2,
  "skipped": 0,
  "failed": 0,
  "truncated": false,
  "skills": [
    { "name": "create-pr", "outcome": "installed", "state": "quarantined", "severity": "clean" },
    { "name": "blog-write", "outcome": "installed", "state": "quarantined", "severity": "clean" }
  ]
}
```

Every entry reports its own outcome. A partial failure returns 200 with `failed > 0` rather than a blanket 500 — installing 93 of 94 skills is a materially different result from installing none, and collapsing both into "error" would hide it.

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | invalid owner/repo/ref, `skillIds` empty or not an array, `visibility` not `public`/`private` |
| 401  | unauthenticated |
| 404  | no `hermiq-skills.json` at the repo root — not a bundle |
| 502  | broker unavailable |

## Database Changes

None. Bundle membership is expressed by the repository, not by a new schema. Each installed skill is an ordinary `agentskill` object with the existing `githubOwner`/`githubRepo` provenance fields.

## Nextcloud Integration

- **Controllers:** `SkillController` — two new methods, same auth posture as the existing `githubInstall()`/`githubSearch()` pair.
- **Services:** new `SkillBundleSerializer`; `GitHubTemplatePushService` (bundle commit), `GitHubTemplateCatalogService` (bundle fetch + `hermiq-skill-bundle` topic).
- **Mappers/Entities:** none — persistence stays on `installFromSource()`.
- **Events/Hooks:** none.

## Security Considerations

**1. A bundle must not become a bulk-trust hole.** This is the design's central constraint. Installing a bundle is N ordinary installs: each skill is content-scanned individually (including its auxiliary files, per `skill-package-multifile`) and each lands `quarantined`. No bulk-approve is offered, and `installFromSource()` is called unmodified rather than a faster internal path — the moment a bundle gets its own persistence path, the quarantine invariant becomes something that must be re-proved instead of inherited.

**2. Path traversal, twice over.** A bundle controls both the directory name and the paths inside it. Skill names from the manifest are validated as kebab-case slugs before being used as a path component, so `../../` cannot arrive as a "name". Auxiliary paths are validated by the inherited `isSafeAuxPath()`. An entry whose full path escapes its declared `skills/<name>/` prefix is dropped and logged, never relocated.

**3. Fan-out bounds.** One request triggering 94 fetches plus 94 content scans is a resource-amplification surface. Bound skills per bundle (64) and total fetched bytes (16 MiB); report `truncated: true` rather than silently stopping.

**4. Create-or-update loosens `assertRepoAbsent()` — deliberately, and narrowly.** The existing refusal exists so a single-skill publish cannot overwrite an unrelated repository. A bundle repo is by definition re-synced, so refusal is wrong for it. The safeguard is that bundle publish only ever writes under `skills/` and `hermiq-skills.json`; it uses `base_tree` so unrelated paths in the repo are preserved, and it never force-pushes.

## File Structure

```
lib/
  Service/
    SkillBundleSerializer.php        (new)
    GitHubTemplatePushService.php    (modified — publishBundle)
    GitHubTemplateCatalogService.php (modified — fetchBundle + topic)
  Controller/
    SkillController.php              (modified — 2 routes)
appinfo/routes.php                   (modified)
tests/
  Unit/Service/SkillBundleSerializerTest.php   (new)
  Unit/Controller/SkillControllerTest.php      (modified)
  e2e/skill-bundle.spec.ts                     (new)
```

## Declarative-vs-imperative decision

Assessed under ADR-031; lands **imperative**, for the same reason `skill-package-multifile` did.

| Behaviour | Path | Rationale |
|---|---|---|
| Bundle (de)serialisation | Imperative | Wire-format translation against an external system (GitHub). None of the six declarative categories — lifecycle, aggregation, derived field, notification, relation, widget — applies. ADR-031's external-integration exception governs. |
| Bundle publish / fetch | Imperative | HTTP against the GitHub Git-Data API through the credential broker. |
| Per-skill install fan-out | Imperative | Delegates to the existing imperative `installFromSource()`; making it declarative would mean duplicating the quarantine gate. |

No `x-openregister-*` keys are added or changed.

## Seed Data

No new schema, so ADR-016 is satisfied by the existing `agentskill` seed set. The bundle round trip is exercised against the already-seeded skills — `tender-summary` (4 auxiliary files, including `learnings.md` and `learning-candidates.md`), `meeting-notes-cleanup` and `woo-request-triage` (single-file) — which between them cover the multi-file and single-file cases without inventing fixtures. The `learning-candidates.md` strip is inherited from `publishFileSelection()` and is asserted rather than re-implemented.

## Rollout / Rollback

Additive: new service, new routes, new topic, no schema change, no existing behaviour modified. Rollback leaves published bundle repos intact as ordinary git repositories.
