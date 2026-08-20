# Design: skill-install-idempotency

## Architecture Overview

Two small collaborators behind the existing install path, so the decision logic is
testable without an HTTP request or a live GitHub fetch:

```
SkillBundleInstaller::installParsed()
  └── per skill ──> SkillIdentityResolver::resolve()      → existing object | null
                    SkillUpsertPolicy::merge()            → payload + outcome flags
                    SkillMarketplaceService::installFromSource()  (create or update)
```

`SkillBundleInstaller` (extracted in `skill-bundle-publish`) stays the one place a
bundle is installed, so the HTTP route and OpenBuild's cross-app caller inherit this
behaviour identically rather than one of them being fixed.

## Decisions

### Identity is a canonical URL, resolved in three steps

`sourceUrl` match → one-time normalised-name fallback among skills with NO
`sourceUrl` → stamp. The fallback is restricted to unidentified skills on purpose: a
name collision against a skill that already carries a *different* `sourceUrl` is two
genuinely different skills, and merging them would lose one.

Mirror hosts are normalised before compare or store. Our repos are mirrored, so
without this the same skill from two hosts is two objects — the duplication defect
returning through a side door.

### Update content, preserve curation

Bundles carry content. Maturity, evidence, agent installations and acceptance history
are decisions this instance made. An app update has no standing to reset them, so the
merge is explicit about which keys it writes rather than replacing the payload.

### Re-quarantine on ANY content change

Not "on a worse scan verdict". An approval is a statement about specific content; once
that content changes the statement no longer applies, whatever the scanner says. The
failure mode of re-quarantining is an unnecessary review; the failure mode of the
alternative is unreviewed content executing under an old approval. Those are not
comparable, so the cheap-to-recover one wins.

### Local learnings are preserved, not merely flagged

A warning issued after an overwrite is worthless, and a confirmation prompt puts the
destructive default one click away. Keeping the local `learnings.md` and reporting it
makes the loss unreachable. The rest of the update still lands, so a preserved
learnings file never blocks a security fix in the skill body.

**The comparison must NOT use `publishedAt`.** `SkillConsolidationService::isBehind()`
already compares `lastAcceptedVersionAt > publishedAt`, which is right for deciding
whether to republish. `publishedAt` is stamped only by `stampGithubPublish()`, so on
a consuming instance it is empty and the comparison is silently always false. Reusing
it would produce a guard that looks correct and never fires — the exact shape of a
gate that never ran. Hence `sourceUpdatedAt`.

The condition requires BOTH the clock comparison AND a real difference in
`learnings.md`, so a skill nobody has taught anything is never affected.

## API Design

Additive fields on the existing bundle-install response; no endpoint is added.

```json
{
  "installed": 0, "updated": 94, "unchanged": 0, "skipped": 0, "failed": 0,
  "truncated": false,
  "skills": [
    { "name": "example-skill", "outcome": "updated",
      "state": "quarantined", "learningsKept": true,
      "sourceUrl": "https://github.com/OWNER/REPO/skills/example-skill" }
  ]
}
```

OpenBuild's `ChannelApplyReport::adoptCounts()` carries these through unmodified and
absorbs any shortfall as a named skip, so its balance identity still holds.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Identity resolution and merge on install | **imperative** | ADR-031 exempts external integration. This is orchestration at a discrete moment against a remote bundle, not derived state over OpenRegister objects. No field is calculated and no lifecycle is declared. |

No `x-openregister-*` block applies.

## Seed Data (ADR-001)

**No new schema, so no new seed set.** The existing seeded skills
(`SeedMaturityExampleSkills`, `SeedSkillDraftExample`) are deliberately left without
`sourceUrl` — they ARE the "installed before identity existed" fixture the name
fallback must handle, and are used as such in the tests.

Test fixtures use the nil UUID `00000000-0000-0000-0000-000000000000` and
`PLACEHOLDER` credential names, never realistic-looking values.

## Risks / Trade-offs

- **The name fallback can mis-match** a locally authored skill that happens to share
  a bundle skill's name. Bounded to skills with no `sourceUrl`, applied once, and
  reported in the outcome so it is visible rather than assumed.
- **Re-quarantine churn**: the 7 currently-`active` skills need re-approval if their
  content changed. Accepted deliberately.
- **A preserved `learnings.md` diverges from upstream** until someone reconciles it.
  Reported on every run so it cannot rot unnoticed.
- **Unit tests will pass while the feature is broken** — they have at every stage of
  this programme. Acceptance evidence is a live re-install asserting the count stays
  101, plus a Playwright run through the UI.
