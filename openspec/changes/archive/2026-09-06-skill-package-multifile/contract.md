# Contract: skill-package-multifile

## Consumers

- `openbuild`: `app-repo-format-v2` embeds skills in an app repo and needs a settled multi-file skill package shape to serialise into `skills/<name>/` and parse back out.
- `buildiq-hydra` (published artefact, not a code project): depends on this contract for its 94 skills — 78 of them multi-file — to install with their `references/`, `assets/` and `evals/` intact.
- `hermiq` (internal): `SkillVersionController::republish()` and `SkillMarketplaceController::publishGithub()` already emit this shape via `publishFileSelection()`; this change makes the inverse direction consume it.

## Endpoints

### `POST /apps/hermiq/api/skills/marketplace/install`

**Auth**: Nextcloud session (`IUserSession`); `@NoAdminRequired`, `@NoCSRFRequired`. Unchanged by this contract.

**Request:**
```json
{
  "package": "---\nname: Create PR\ndescription: Open a PR with local checks\n---\nFollow the checklist in references/local-checks.md",
  "source": "hub",
  "files": [
    { "name": "references/local-checks.md", "content": "1. composer check:strict" },
    { "name": "learnings.md", "content": "- 2026-07-31: CI differs from the container" }
  ]
}
```

`files` is **optional**. Omitting it reproduces today's behaviour exactly. `package` and `source` are unchanged: `package` required non-empty, `source` one of `local|org|hub` (anything else coerced to `hub`).

**Response (200):**
```json
{
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Create PR",
  "description": "Open a PR with local checks",
  "state": "quarantined",
  "source": "hub",
  "files": [
    { "name": "references/local-checks.md", "content": "1. composer check:strict" },
    { "name": "learnings.md", "content": "- 2026-07-31: CI differs from the container" }
  ]
}
```

`state` is always `quarantined` on this route regardless of `source` — unchanged, and explicitly not relaxed by this contract.

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | `package` missing or empty after trim; `files` present but not an array |
| 401  | No authenticated user |
| 500  | Install failed (scan or persistence error) |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | `A non-empty package is required` | `package` empty after trim (existing) |
| 400 | `files must be an array` | `files` supplied as a non-array (new) |
| 401 | `Unauthenticated` | No session user (existing) |
| 500 | `Install failed` | Throwable during scan or `saveObject()` (existing) |

An auxiliary entry with an unsafe or empty `name` is **not** an error condition: the entry is dropped, the drop is logged, and the request still returns 200. This is deliberate — a partially-unsafe package should not block installation of a valid skill body, and failing the whole request would let one bad path deny a legitimate install.

## Versioning

No API version bump. The change is purely additive on the request side and additive-in-population on the response side (`files` already exists in the Skill shape, previously always `[]`).

A client written against the current contract continues to work with no modification: it sends no `files`, and receives `files: []` exactly as before.

## Breaking Change Policy

This change introduces no breaking change. Should the directory form later need to become the only accepted form, that would be a breaking change requiring: a deprecation notice on the string form, a minor version of the hermiq app supporting both, and coordination with `openbuild`'s `app-repo-format-v2` — which is the only cross-project consumer that would need to move in step.

## SLA

Install remains a single synchronous request. Parsing is in-memory with no per-file network or filesystem access, so a 63-file skill (the `create-pr` worst case) MUST parse within the same request budget as a single-file skill. Content scanning cost scales with total scanned bytes, which now includes auxiliary content — the dominant term remains OR's `ContentScanService`, unchanged in its own SLA.
