# Contract: skill-bundle-publish

## Consumers

- `buildiq-hydra` (published artefact): the reason this contract exists — 94 skills shipped and re-synced as one repository.
- `openbuild` (`app-repo-format-v2`): embeds an app's skills using the same `skills/<name>/` layout, so the two formats nest rather than compete.
- `hermiq` (internal): the skills catalogue surface, which must not mistake a bundle repo for a single-skill repo.

## Endpoints

### `POST /apps/hermiq/api/skills/bundle/publish`

**Auth**: Nextcloud session; broker-owner-gated exactly as single-skill publish. The GitHub token is never held or logged.

**Request:**
```json
{
  "owner": "ConductionNL",
  "repo": "buildiq-hydra",
  "visibility": "private",
  "skillIds": ["00000000-0000-0000-0000-000000000000"],
  "credentialId": "<credential-uuid>"
}
```

**Response (200):**
```json
{
  "repoUrl": "https://github.com/ConductionNL/buildiq-hydra",
  "commitSha": "0000000000000000000000000000000000000000",
  "created": false,
  "skills": [{ "name": "create-pr", "files": 63, "outcome": "published" }]
}
```

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | invalid owner/repo, `skillIds` missing/empty/not an array, `visibility` not `public`/`private` |
| 401  | unauthenticated |
| 502  | credential broker unavailable |

### `POST /apps/hermiq/api/skills/bundle/install`

**Request:**
```json
{ "owner": "ConductionNL", "repo": "buildiq-hydra", "ref": null, "credentialId": "<credential-uuid>" }
```

**Response (200):**
```json
{
  "installed": 2, "skipped": 0, "failed": 0, "truncated": false,
  "skills": [{ "name": "create-pr", "outcome": "installed", "state": "quarantined", "severity": "clean" }]
}
```

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | invalid owner/repo/ref |
| 401  | unauthenticated |
| 404  | no `hermiq-skills.json` at the repo root — not a bundle |
| 502  | credential broker unavailable |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | `invalid_repo` | owner/repo fails the owner-repo pattern |
| 400 | `invalid_ref` | ref fails the ref pattern |
| 400 | `skillIds must be a non-empty array` | publish called with nothing to publish |
| 401 | `Unauthenticated` | no session user |
| 404 | `not_a_bundle` | manifest absent at the repo root |
| 502 | `broker_unavailable` | credential broker cannot be resolved |

**A partial install is NOT an error.** Installing 93 of 94 skills returns 200 with `failed: 1` and a per-skill outcome list. Collapsing that into a 500 would destroy the only information the caller needs — which skill failed. Callers MUST read `failed`/`skipped` rather than inferring success from the status code.

## Versioning

New endpoints; nothing existing changes. `hermiq-skills.json` carries a `formatVersion`, and an unrecognised major version is a 404 `not_a_bundle` rather than a best-effort parse — a bundle that half-parses is worse than one that refuses.

## Breaking Change Policy

Single-skill publish/install (`hermiq-skill` topic, `hermiq-skill.md` at root) is explicitly unaffected and remains supported. Were the bundle layout to change incompatibly, `formatVersion` gates it and the only cross-project consumer needing coordination is openbuild's `app-repo-format-v2`.

## SLA

A bundle install is N sequential installs, each with a content scan; wall time scales with N and is bounded by the 64-skill / 16 MiB caps. It is a synchronous request today — if a bundle materially larger than hydra's 94 skills becomes a real case, the honest fix is a background job, not a raised timeout.
