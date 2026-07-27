---
kind: code
---

# Proposal: skill-maturity-model

## Summary

Port the fleet's L1–L7 skill maturity framework (ADR-068) onto hermiq's `agentskill`
objects as a **skill-qualification tool**: the `Skill` schema gains computed
`maturityLevel`, curator-intent `targetLevel`, and per-level `levelEvidence` metadata; a
new `SkillMaturityService` computes L1–L3 mechanically from a skill's
frontmatter/body/files; a "Qualify skill" action recomputes maturity and returns a
per-level scorecard; and the catalog UI renders maturity dots on the skills list plus a
scorecard on a new skill detail page. L4 stays human-attested (action-gated), and L5–L7
are read from evidence fields that future changes (`skill-evals`, `skill-learnings`,
`skill-orchestration`) will write — this change only defines and reads those fields.

## Motivation

ADR-068 makes L1–L7 the single fleet-wide maturity vocabulary for both development skills
and hermiq product skills. Today the `agentskill` schema has **no maturity concept** —
only the curation lifecycle (`active`/`stale`/`archived`/`quarantined`). A curator
browsing the catalog cannot tell a well-structured, well-triggering, evidence-backed
skill from a one-line stub; nothing qualifies a skill before it is installed onto an
agent. Without the maturity fields landing first, none of the ADR-068 follow-up changes
(skill-scoped evals, learnings capture, gated self-improvement, orchestration evidence)
have anywhere to record their evidence. This change is the foundation of that chain, and
it is immediately useful on its own as a qualification/scorecard tool.

Qualification criteria are informed by Arize's skill-authoring findings: a compact,
procedural body scores better than a comprehensive-docs style, and the description must
support minimal routing (clear trigger phrasing, when-to-use) — so L2 checks reward
exactly that shape.

## Capabilities

### New Capabilities

- `skill-maturity`: the L1–L7 maturity metadata on `agentskill` objects
  (`maturityLevel` computed 0–7, `targetLevel` curator intent, `levelEvidence`
  per-level evidence), the `SkillMaturityService` L1–L3 mechanical computation, the
  action-gated L4 human attestation, the owner-guarded Qualify endpoint + scorecard,
  and the catalog/detail maturity UI. Maturity is orthogonal to lifecycle `state`.

### Modified Capabilities

- None as a spec delta. The `agentskill` schema extension is captured as ADDED
  requirements inside the new `skill-maturity` capability (the fields are inert
  metadata consumed only by this change's own service); the `skills-catalog`
  round-trip requirement is not modified — it is re-asserted as an invariant here.

## Affected Projects

- [ ] Project: `hermiq` — `Skill` schema extension in `lib/Settings/hermiq_register.json`;
  new `lib/Service/SkillMaturityService.php`; new `SkillMaturityController` + routes
  (qualify, attest-L4); seed repair-step extension; `src/manifest.json` (maturity column,
  SkillDetail page); new scorecard widget + Qualify row action; unit + e2e tests.

## Scope

### In Scope

- `agentskill` schema: `maturityLevel` (integer 0–7, computed, never hand-set),
  `targetLevel` (integer 1–7, curator intent), `levelEvidence` (object: per-level
  evidence sub-objects `l1`…`l7` with booleans + timestamps, e.g.
  `l5: {evalDatasetId, passRate, baselineDelta, lastValidated}`).
- `SkillMaturityService`: mechanical L1–L3 computation from frontmatter/body/files;
  L4 read from human attestation evidence only; L5–L7 read from `levelEvidence`
  fields written by other subsystems; `maturityLevel` = highest contiguous level
  passed (L(n) requires L(n−1)).
- "Qualify skill" OCS endpoint (POST, owner-guarded per the agent-evals IDOR
  pattern): recomputes maturity, persists it, returns a per-level scorecard
  (pass/fail + reasons: structure, triggering, eval evidence, learnings activity,
  orchestration use).
- Action-gated L4 attestation endpoint (`ActionAuthService::requireAction()`, ADR-023).
- Frontend: maturity badge/dots on the SkillsCatalog list, a new SkillDetail page
  (`/skills/:id`) with the maturity scorecard, and a Qualify row action showing the
  scorecard after qualifying.
- Seed data: realistic example skills at different maturity levels
  (municipality/consultancy context) so the scorecard renders meaningfully on a
  fresh install.

### Out of Scope

- Writing L5–L7 evidence: skill-scoped evals (`skill-evals`), learnings capture and
  consolidation (`skill-learnings`, `skill-self-improvement`), and orchestration
  execution evidence (`skill-orchestration`) are separate ADR-068 follow-up changes.
  This change only defines the fields and reads them.
- Any change to the `SkillSerializer` byte-for-byte agentskills.io round-trip — the
  new fields are hermiq metadata and are never serialized into the exported package.
- Any coupling between maturity and the curation lifecycle: neither `state` nor
  `maturityLevel` derives from the other.
- Auto-promotion, background recomputation jobs, or self-modification of skills.

## Approach

Extend the declarative `Skill` schema in `lib/Settings/hermiq_register.json` (register
version bump + forced re-import so existing schemas actually gain the fields). Implement
`SkillMaturityService` as an imperative rule-based content analyser (ADR-031
justification in design.md) that reads a skill's `frontmatter`, `body`, and `files` map
and emits a scorecard; the service is the ONLY writer of `maturityLevel` — the skill
update path carries stored maturity fields forward and ignores client-supplied values.
The qualify endpoint follows the agent-evals owner-guard (404 on any non-owner, never
403). L4 attestation is a separate action-gated endpoint stamping
`levelEvidence.l4 = {attestedBy, attestedAt}`. UI is manifest-driven: a maturity column
on the existing `SkillsCatalog` index page, a new `SkillDetail` page (type `detail`, like
`AgentDetail`/`EvalDatasetDetail`) hosting a `skill-maturity-scorecard` widget, and a
Qualify action added to the existing `SkillRowActions` widget.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — `Skill` schema gains three optional properties;
  register `info.version` bump; no existing object becomes invalid (nothing added to
  `required`).
- `lib/Service/SkillMaturityService.php` (new), `lib/Controller/SkillMaturityController.php`
  (new), `appinfo/routes.php` (two routes), seed repair step (example skills).
- `src/manifest.json` (SkillsCatalog column + SkillDetail page), `src/registry.js` /
  `src/customComponents.js` (scorecard widget registration), `src/widgets/SkillRowActions.vue`
  (Qualify action), new `src/widgets/SkillMaturityScorecard.vue` + badge component.
- NOT impacted: `SkillSerializer`, `SkillService` import/export, the marketplace
  quarantine/approve/publish paths, the curation lifecycle.

## Cross-Project Dependencies

- ADR-068 (hydra `openspec/architecture/`) is the architectural decision this
  implements; the canonical level definitions stay in
  `.github/docs/claude/writing-skills.md` — hermiq references them, it does not fork.
- OpenRegister: schema re-import via `ConfigurationService::importFromApp()`; no OR
  code change needed.
- Future hermiq changes `skill-evals`, `skill-learnings`, `skill-self-improvement`,
  `skill-orchestration` will write `levelEvidence.l5`–`l7`.

## Risks

### Risk 1: Schema re-import silently not applied to existing installs
**Severity:** Medium — **Mitigation:** known OR gotcha (`importFromApp(force:false)`
advances the stored version without applying changes). The repair step must force the
import for this bump; verified by an upgrade-path check in the test plan.

### Risk 2: maturityLevel gamed via generic object write path
**Severity:** Medium — **Mitigation:** the service is the only writer; the skill update
path strips/ignores client-supplied `maturityLevel` and computed `levelEvidence`
sub-objects, and every qualify recomputes from content — a hand-set value never survives
the next qualification. L4 is only settable through the action-gated attest endpoint.

### Risk 3: Serializer round-trip regression
**Severity:** Low — **Mitigation:** `SkillSerializer::toPackage()` emits only
`frontmatter` + `body` (+ files via export) by construction; a regression test asserts
the exported package of a qualified skill is byte-identical to the same skill before
qualification.

## Rollback Strategy

Revert the code (service, controller, routes, widgets, manifest entries). The three
schema properties are optional and inert — they can stay in place on rollback without
invalidating any object; a subsequent register version bump may remove them. No data
migration to undo (seeded example skills are ordinary `agentskill` objects and can be
archived through the normal lifecycle).

## Open Questions

None blocking — deferred decisions are recorded in design.md.
