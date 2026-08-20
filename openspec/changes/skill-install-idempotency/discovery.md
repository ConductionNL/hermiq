# Discovery: skill-install-idempotency

## Question

What durable identity can a bundle-installed skill be matched on, and what signal
truthfully answers "have local learnings accumulated since we last synced?"

## Approach Taken

- Read `SkillMarketplaceService::installFromSource()` — confirmed `saveObject()` with
  no uuid and no existence check.
- Read the `Skill` schema in `lib/Settings/hermiq_register.json`.
- Queried the live table `oc_openregister_table_2428_4349` for duplicate names, state
  distribution and provenance.
- Read `SkillConsolidationService` for existing learnings/version machinery.
- Checked whether `SkillSource` already models per-skill provenance.

## Findings

- **No identity exists.** `name` is the only required property. `githubOwner`/
  `githubRepo` exist but are written ONLY by `stampGithubPublish()` — the publish
  direction — so an installed skill has them empty.
- **`SkillSource` is not it.** It has `name`/`type`/`url` but models a browsable
  source registry and is never referenced by the install path.
- **The bug has already bitten.** 101 rows, 99 distinct names. *Meeting Summariser*
  has an `active` row and a `quarantined` shadow 21 days later; *Nightly Backup
  Helper* has two `active` rows five minutes apart. 94 quarantined + 7 active.
- **`isBehind()` cannot be reused.** `SkillConsolidationService::isBehind()` compares
  `lastAcceptedVersionAt > publishedAt`. `publishedAt` is only ever stamped when this
  instance publishes TO a remote, so on a consuming instance it is empty and the
  method returns false unconditionally. A learnings guard built on it would look
  correct in review and never fire once in production.
- **Mirrors matter.** The repos are mirrored to a second host, so a raw URL as
  identity would let the same skill install twice from two hosts.

## Conclusion

Identity = canonical `sourceUrl` with mirror hosts normalised and no git ref, plus a
one-time name fallback for the 101 skills that predate the field. The learnings
signal needs its own clock, `sourceUpdatedAt`, stamped on every sync FROM source —
both provided by `skill-source-identity-schema`.
