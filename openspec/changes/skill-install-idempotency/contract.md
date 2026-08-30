# Contract: skill-install-idempotency

## Consumers
- `openbuild`: `AppChannelApplier` installs a published app's `skills/` channel by
  delegating to hermiq, and carries hermiq's counts into its own channel report
  unmodified.

## Endpoints

### `POST /api/skills/bundle/install`
**Auth**: Nextcloud session (the acting user owns the installed skills)

**Request:**
```json
{ "owner": "ConductionNL", "repo": "buildiq-hydra", "ref": null }
```

**Response (200):**
```json
{
  "installed": 0,
  "updated": 94,
  "unchanged": 0,
  "skipped": 0,
  "failed": 0,
  "truncated": false,
  "skills": [
    {
      "name": "example-skill",
      "outcome": "updated",
      "state": "quarantined",
      "learningsKept": true,
      "matchedBy": "source-url",
      "sourceUrl": "https://github.com/OWNER/REPO/skills/example-skill"
    }
  ]
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `invalid_repo` / `invalid_ref` — coordinates fail the owner/repo/ref pattern |
| 401  | Unauthenticated |
| 404  | `not_a_bundle` — the repository carries no `hermiq-skills.json` |
| 502  | `fetch_failed` — the bundle could not be fetched |

## Compatibility

**Additive only.** `installed`, `skipped`, `failed`, `truncated` and `skills` keep
their existing names and meanings; `updated` and `unchanged` are new counters, and
`outcome`, `learningsKept`, `matchedBy` and `sourceUrl` are new per-skill fields.

`truncated` remains a **bool** — it reports that the fetch hit its bound, not how
many blobs went unread. A consumer must not treat it as a count.

An existing consumer reading only `installed` will see it drop to 0 on a re-install
where it previously (wrongly) counted duplicates as installs. OpenBuild's
`adoptCounts()` absorbs the difference as a named skip rather than breaking its
balance identity, so no consumer change is required.
