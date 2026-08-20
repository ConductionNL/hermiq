# Design: skill-source-identity-schema

## Architecture Overview

Two optional properties on the `Skill` schema in `lib/Settings/hermiq_register.json`.
Nothing reads them yet — `skill-install-idempotency` is the first consumer. This
change exists separately because a durable identity field is a prerequisite for the
install rewrite, and the two are different kinds (ADR-032).

## Data Model

| property | type | purpose |
|---|---|---|
| `sourceUrl` | string | Canonical origin: `https://github.com/<owner>/<repo>/skills/<bundleName>` |
| `sourceUpdatedAt` | string (date-time) | When the skill was last refreshed FROM that origin |

### Why the URL, and why without a ref

A URL is source-agnostic and human-inspectable, and it survives a skill being
renamed in frontmatter (the bundle directory is the stable part). It is deliberately
recorded **without a git ref**: pinning a branch would make `main` and a feature
branch two different skills, which reintroduces the duplication this exists to stop.

**Known limitation — mirrors.** Our repos are mirrored to Codeberg, so the same skill
fetched from `codeberg.org` and `github.com` yields two different URLs and therefore
two objects. `skill-install-idempotency` normalises known mirror hosts to one
canonical host before storing; the field itself stores the normalised form. Recorded
here because a reader of this schema would otherwise reasonably assume raw URLs.

### Why `sourceUpdatedAt` is not `publishedAt`

`publishedAt` is stamped by `stampGithubPublish()` when this instance publishes a
skill **to** GitHub. On a consuming instance that only ever installs, it is empty
forever. The existing `SkillConsolidationService::isBehind()` compares
`lastAcceptedVersionAt > publishedAt` for the republish signal, which is correct for
the publish direction and **silently always false** for the install direction.

Reusing it would produce a guard that never fires — the same shape as a green gate
that never ran. `sourceUpdatedAt` gives the install direction its own honest clock.

## Declarative-vs-imperative decision (ADR-031)

No lifecycle, aggregation, calculation, notification, relation or widget behaviour is
introduced. These are plain stored properties, so no `x-openregister-*` block
applies. The behaviour that reads them is imperative and lives in the dependent
change.

## Seed Data (ADR-001)

**Not applicable.** No new schema is introduced and no new object type is created —
two optional properties are added to an existing schema whose objects are already
seeded (`SeedMaturityExampleSkills`, `SeedSkillDraftExample`). Existing seeds stay
valid because both properties are optional; a seeded skill with no `sourceUrl` is
exactly the "installed before identity existed" case the dependent change must
handle, so leaving the seeds untouched is useful rather than an omission.

## Risks / Trade-offs

- **Optional, so absence is ambiguous.** A missing `sourceUrl` means either "never
  installed from a bundle" or "installed before this change". The dependent change
  resolves this with a one-time name fallback and then stamps, rather than guessing.
- **No backfill here.** Deliberate: backfilling requires matching logic that belongs
  with the installer, not with a schema declaration.
