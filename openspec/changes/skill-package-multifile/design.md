# Design: skill-package-multifile

## Architecture Overview

Hermiq's skill transport has two halves that currently disagree about what a skill is.

```
PUBLISH (correct today)                    INSTALL (lossy today)
─────────────────────                      ─────────────────────
Skill object                               package string
  frontmatter, body, files[]                 │
   │                                         ▼
   ├─ SkillService::exportSkill()           SkillSerializer::fromPackage()
   │    └─ toPackage()  → SKILL.md            → { frontmatter, body,
   │                                              name, description }
   ├─ SkillService::publishFileSelection()          ⚠ no files channel
   │    └─ [{name, content}, …]                     │
   │       (learning-candidates.md stripped)        ▼
   ▼                                         saveObject(files: [])   ⚠ hardcoded
GitHubTemplatePushService::publish(
  package, auxFiles: [...])
   └─ one blob per file, own path
      isSafeRepoPath() allows nesting
```

The publish half already thinks in terms of a *set of paths*. The install half thinks in terms of a *single string*. This change gives the install half the same vocabulary.

The seam chosen is a `path => contents` map — deliberately the same shape `GitHubTemplatePushService` builds its tree from, and the same shape OpenBuild's `AppRepoSerializer::serialize()` returns. That is what lets the follow-on bundle change (many skills in one repo) and OpenBuild's `app-repo-format-v2` compose against this without another translation layer.

**Directory-form package:**

```
SKILL.md                      ← frontmatter + body, parsed by the EXISTING fromPackage()
references/persona.md         ← files[] entry, name = "references/persona.md"
references/voice.md           ← files[] entry
assets/blog-template.mdx      ← files[] entry
learnings.md                  ← files[] entry (vetted experience travels; ADR-068 §3)
```

`fromPackageFiles()` splits the map: the `SKILL.md` entry is delegated verbatim to the existing `fromPackage()`, inheriting its byte-for-byte frontmatter guarantee rather than reimplementing it. Every other entry becomes a `files[]` element after path validation.

Back-compatibility is structural, not conditional: `toPackage()`/`fromPackage()` keep their exact signatures and bodies. A caller holding a plain string keeps working because that code path is untouched.

## API Design

### `POST /apps/hermiq/api/skills/marketplace/install`

The existing route gains an optional `files` parameter. `package` remains required and unchanged.

**Request (existing form — still valid, `files[]` lands empty):**
```json
{
  "package": "---\nname: My skill\n---\nBody here",
  "source": "hub"
}
```

**Request (directory form):**
```json
{
  "package": "---\nname: Create PR\n---\nFollow references/local-checks.md",
  "source": "hub",
  "files": [
    { "name": "references/local-checks.md", "content": "1. Run composer check:strict" },
    { "name": "learnings.md", "content": "- 2026-07-31: CI differs from container" }
  ]
}
```

**Response (200):** unchanged Skill shape, with `files` populated and `state: "quarantined"`.

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | `package` empty, or `files` present but not an array |
| 401  | Unauthenticated |
| 500  | Install failed |

An entry with an unsafe or empty `name` is **rejected and dropped**, not sanitised, and the drop is logged. A package consisting entirely of unsafe paths still installs — as a single-file skill — because the body itself is valid; correctness of the body does not depend on the aux files being accepted.

## Database Changes

None. `Skill.files[]` already exists in `lib/Settings/hermiq_register.json` as an array of `{name, content}`, and `SkillDraft.proposedFiles` mirrors it. No register version bump, no migration.

## Nextcloud Integration

- **Controllers:** `SkillMarketplaceController::installFromSource()` — reads the new optional `files` param. Auth posture unchanged (`@NoAdminRequired`, `@NoCSRFRequired`), still session-authenticated via `IUserSession`.
- **Services:** `SkillSerializer` (new `toPackageFiles()` / `fromPackageFiles()`), `SkillMarketplaceService::installFromSource()`, `SkillService::importSkill()`.
- **Mappers/Entities:** none — persistence goes through `ObjectService::saveObject()` against the existing `skill` schema.
- **Events/Hooks:** none.

## Security Considerations

Three concerns, all on the install side, because that is where externally-authored content enters.

**1. Aux files are unscanned instruction material.** `installFromSource()` today scans `body` + `frontmatter` through `scanContent()` → OR's `ContentScanService`. An auxiliary file is not passive data: a skill body that says "follow the checklist in `references/local-checks.md`" makes that file's content into agent instructions. Shipping aux files without scanning them would open a bypass where the dangerous payload simply moves out of `body` and into `references/`. **Mitigation:** aux file content is concatenated into the scanned material. The existing fail-closed posture is preserved unchanged — `installFromSource()` still ALWAYS lands `quarantined` regardless of source, and a `dangerous` verdict still blocks one-click approval via `approveQuarantined()`.

**2. Path traversal.** Package paths are attacker-controlled. Install-side validation mirrors `GitHubTemplatePushService::isSafeRepoPath()`: reject leading `/`, any `\`, any `.` or `..` or empty segment, and paths over 200 characters. Reject-and-log rather than sanitise, so a crafted path fails visibly instead of silently relocating. Note these paths are stored as `files[].name` strings in OpenRegister, never resolved against the filesystem — validation is defence in depth against a future consumer that *does* materialise them (which is exactly what the buildiq-hydra install path will do).

**3. Content size.** No new unbounded input beyond what the route already accepts; `files` is bounded by the same request limits as `package`.

No change to auth, CSRF posture, or tenant scoping. `saveObject()` remains tenant-scoped as before.

## File Structure

```
lib/
  Controller/
    SkillMarketplaceController.php   (modified — optional files param)
  Service/
    SkillSerializer.php              (modified — + toPackageFiles/fromPackageFiles)
    SkillService.php                 (modified — importSkill persists files)
    SkillMarketplaceService.php      (modified — installFromSource persists + scans files)
tests/
  Unit/Service/
    SkillSerializerTest.php          (modified — + directory-form round trip)
    SkillMarketplaceServiceTest.php  (modified — + aux scan, + path rejection)
    SkillServiceTest.php             (modified — + importSkill files)
  Unit/Controller/
    SkillMarketplaceControllerTest.php (modified — + files param)
```

## Declarative-vs-imperative decision

Per ADR-031 the default path is declarative. This change is assessed and lands **imperative**, deliberately:

| Behaviour | Path | Rationale |
|---|---|---|
| Package (de)serialisation | Imperative (`SkillSerializer`) | Format translation between an external wire format (agentskills.io) and OR objects. Not lifecycle, aggregation, derived field, notification, relation or widget — none of the six declarative categories apply. ADR-031's "external integration" exception is the governing one. |
| Aux content scanning | Imperative (`scanContent()`) | Extends an existing imperative call into OR's `ContentScanService`. Declaring it would mean inventing a schema-level scan hook that does not exist. |
| Path validation | Imperative | Input validation on a controller boundary. |

No `x-openregister-*` keys are added or modified. No new `*Service.php` class is introduced either — all four edits are to existing services.

## Seed Data

This change introduces **no new schema**, so ADR-016 seed obligations are satisfied by the existing `skill` seed set. It does, however, make an existing field (`files[]`) meaningfully populated for the first time, and an empty `files[]` everywhere would leave the round trip untested on install.

Three fixture skills exercise the shape, general enough to read sensibly for a municipality, consultancy or travel agency:

| Skill | `files[]` | Exercises |
|---|---|---|
| `Onboard a new employee` | `references/checklist.md`, `references/it-access.md` | Nested paths, the common 2-file case |
| `Publish a tender summary` | `assets/summary-template.md`, `learnings.md` | Mixed asset + vetted-learnings; asserts `learnings.md` survives install |
| `Archive a closed case` | *(none)* | The single-file back-compat path — installs with `files: []` |

A fourth, negative fixture (`../escape.md`, `/etc/passwd`) is used only in tests, never seeded, and asserts reject-and-log.

## Rollout / Rollback

Additive and reversible. No migration, no route removal, no schema version change. Rolled-back code stops populating `files[]` and ignores existing values; no stored object becomes invalid.
