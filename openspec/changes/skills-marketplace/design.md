# Design: skills-marketplace

## Context

Extends `skills-catalog` (V1) to cross-org / external-hub sharing with a security gate and a
lifecycle Curator (ADR-003 skills-as-OR-objects, ADR-001 Option C+). Reuses the catalog's
`SkillSerializer` and `Skill` object.

## Decisions

**Quarantine is enforced; the scan is a review gate (seam).** An externally-sourced skill
(another org or a hub) is created in `state='quarantined'` and is NEVER auto-active — the
spec's hard invariant. OpenRegister has no content-scanning service (`SecurityService` is
login/auth rate-limiting), so the "security scan" is realised as an explicit **review gate**:
`approveQuarantined()` is the only transition `quarantined`→`active`. When an OR/third-party
content scanner lands, it plugs in ahead of the approval. The invariant (no external skill
runs un-reviewed) holds today regardless.

**Curator = age-based, never deletes.** `curate()` transitions `active`→`stale` past a
staleness threshold and `stale`→`archived` past an archival threshold, stamping
`staleSince`/`archivedAt`, and NEVER deletes the object or its files (the spec forbids
hard-delete — archived skills keep historical agent configs reconstructable). Age is used
because Hermiq cannot observe skill USAGE during a run (that is the OR run loop — a seam);
usage-based staleness plugs in via a `lastUsedAt` stamp when OR exposes it. The Curator is a
daily `TimedJob` delegating to the service (ADR-002 thin-wrapper), added to the SINGLE
existing `<background-jobs>` block (a second block breaks the NC upgrade — a known gotcha).

**Publish routes through OpenConnector — no direct HTTP.** `publishToHub()` serialises the
skill via `SkillSerializer` and submits through OpenConnector's `CallService` (resolved
lazily through the container). No provider opens a direct HTTP client (the nc-native-tools
rule). A live external hub needs a configured OpenConnector connector + a reachable
ClawHub/skills.sh, so with none configured the method returns a structured
`hub_unavailable` error — the seam is explicit, not faked.

**Consume = the catalog import.** Importing an agentskills.io package from a hub is the
existing `skills-catalog` import path; marketplace adds only that such an import from an
external source lands in quarantine.

## Risks / Trade-offs

- **No real content scanner.** [OR has none] → Quarantine + explicit review gate enforce the
  invariant; the scanner is a documented plug-in point.
- **Age vs. usage staleness.** [Hermiq can't see run-time skill usage] → Age-based curation
  now; usage-based when OR stamps last-used.
- **No reachable hub.** [external hubs + connectors not configured here] → publish returns a
  structured seam error; the serialise + OpenConnector-routing path is what's built + tested.

## Open Questions

- **Seam — content security scan.** Depends on an OR/third-party skill scanner. The review
  gate stands in until then.
- **Seam — live hub + usage staleness.** Depend on OpenConnector connectors + OR run-loop
  usage stamping. Documented.
