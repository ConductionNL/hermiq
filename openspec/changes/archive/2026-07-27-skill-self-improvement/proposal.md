---
kind: code
depends_on: [skill-maturity-model, skill-evals, skill-learnings]
---

# Proposal: skill-self-improvement

## Summary

Close the ADR-068 §5 loop: a `SkillConsolidationTask` (TimedJob, sibling of
`SkillCuratorTask`) proposes a DRAFT new skill version by consolidating the skill's
promoted `files['learnings.md']` entries through an LLM pass — never editing the active
skill in place. Every draft runs a pre-qualification pipeline (marketplace content scan
treating learnings as instruction content + paired draft-vs-active eval on the linked
EvalDataset), then routes through the human-approval-gate `Approval` state machine with a
Talk/Notification ping and an action-gated Accept / Edit-then-accept / Reject review
surface on SkillDetail. Skills gain agent-versioning-style version history, diff,
rollback, and run-pinning; an accepted version that postdates `publishedAt` on a
GitHub-published skill raises a "published copy is behind" signal with a one-click,
never-automatic Republish that strips `files['learning-candidates.md']` from the export.

## Motivation

ADR-068 makes hermiq's "self-improving skills" claim real only if self-modification is a
gated draft version — the threat model is explicit: a self-modified skill is a
prompt-injection amplifier, so any design that writes skill content without a trust
boundary is unacceptable. After `skill-maturity-model` (evidence contract + scorecard),
`skill-evals` (paired with/without-skill measurement writing `levelEvidence.l5`) and
`skill-learnings` (candidate capture + mechanical promotion writing `levelEvidence.l6`),
the loop still has no closing arc: learnings accumulate in `files['learnings.md']` but
nothing consolidates them into a better skill body, there is no draft/approval path, no
skill version history to roll back to, and a GitHub-published skill silently drifts
behind its accepted local improvements. This change is the non-negotiable gated path of
ADR-068 §5: draft → approval → re-scan → eval regression gate → versioned rollback,
never in-place, never auto-applied.

## Capabilities

### New Capabilities

- `skill-self-improvement`: the `SkillDraft` object + consolidation triggers
  (learnings threshold, eval regression event, manual propose), the LLM consolidation
  pass through `ProviderFactory` (budget- and kill-switch-gated), the pre-qualification
  pipeline (content scan + paired A/B eval with auto-discard on regression), the
  Approval-gated Accept / Edit-then-accept / Reject surface, skill version
  history/diff/rollback/run-pinning mirroring `agent-versioning`, the post-acceptance
  regression watch with a rollback suggestion, and the GitHub "published copy is
  behind" republish signal.

### Modified Capabilities

- `skills-marketplace`: the GitHub publish requirement gains (a) an explicit
  REPUBLISH path — updating the skill's OWN provenance-stamped repo through the same
  publish action authorization, as a carve-out from "refuse to overwrite an existing
  repository", never automatic; and (b) an export-policy delta — the committed
  agentskills.io package ships `files['learnings.md']` but MUST strip
  `files['learning-candidates.md']` (unvetted observations never leave the instance).

## Affected Projects

- [ ] Project: `hermiq` — new `SkillDraft` schema + `Skill.lastAcceptedVersionAt` in
  `lib/Settings/hermiq_register.json` (version bump + forced re-import + seed draft);
  new `SkillConsolidationTask` (TimedJob), `SkillConsolidationService`,
  `SkillVersionService`, `SkillDraftController` + routes; `SkillMarketplaceService` /
  GitHub publish path republish + strip delta; SkillDetail review surface (diff,
  provenance, scan verdict, eval delta, Accept/Edit/Reject), version history widget,
  behind-badge on catalog + detail; unit + e2e tests.

## Scope

### In Scope

- `SkillDraft` OpenRegister schema: proposed `frontmatter`/`body`/`files`, base version
  pin, trigger + provenance (driving learnings entries, run IDs), pipeline `status`,
  scan verdict, eval delta (or an explicit "no eval evidence" flag), linked `approvalId`,
  decision metadata, rejected-learnings marks.
- `SkillConsolidationTask` triggers: (a) learnings threshold (configurable, default ~20
  entries), (b) event: a linked eval run regressed vs previous, (c) manual
  "Propose improvement" endpoint (owner/curator-guarded).
- Consolidation as ONE LLM pass through `ProviderFactory::generateText()` — subject to
  tenant model policy, budget hard-caps and the org kill-switch exactly as schedule
  ticks and eval runs are.
- Pre-qualification before any human sees the draft: `ContentScanService` scan over the
  full draft including `learnings.md` as instruction content; paired draft-vs-active
  eval reusing `skill-evals`' paired mode; draft scoring WORSE than active is
  auto-discarded with an audit note (learnings retained); no linked evals → draft
  survives flagged "no eval evidence" and can never grant L5.
- Human acceptance through a `human-approval-gate` `Approval` object with
  Talk/Notification ping; Accept / Edit-then-accept / Reject behind ADR-023 action
  authorization; Reject lets the curator mark specific driving learnings entries as bad
  so they do not drive the next proposal.
- Skill versioning mirroring `agent-versioning`: history from OR AuditTrail, diff across
  the versioned field set (`frontmatter`, `body`, `files`), rollback that creates a new
  version without mutating history, run audit entries pinning the exact skill versions
  that executed.
- Post-acceptance regression watch: the next eval run's existing regression gate
  surfaces a "roll back to previous version?" suggestion on SkillDetail + notification.
- GitHub republish signal: "published copy is behind" badge (catalog + SkillDetail) when
  an accepted version postdates `publishedAt` on a skill with `githubOwner`/`githubRepo`;
  notification to the publisher; one-click Republish through the SAME publish action
  authorization; NEVER auto-republish; republish strips `learning-candidates.md`.
- Every state transition audited through the run-audit-log seam (OR AuditTrail).
- Seed data: one pending draft (on the `tender-summary` seed skill) so the review
  surface renders on a fresh install.

### Out of Scope

- Capture and promotion of learnings (`learning-candidates.md` → `learnings.md`,
  3+ confirmations / eval-failure promotion, 30-day expiry, redaction) — owned by the
  parallel `skill-learnings` change; this change only CONSUMES `files['learnings.md']`.
- The paired with/without-skill eval mechanics and `levelEvidence.l5` writes — owned by
  the parallel `skill-evals` change; this change invokes its paired mode.
- Auto-apply of any draft (explicitly deferred by ADR-068 to a later ADR, only after
  scan + regression gates have a track record) and any auto-republish to GitHub.
- Orchestration/L7 evidence (`skill-orchestration` is a separate change).
- Changing `SkillSerializer`'s byte-for-byte round-trip contract — the
  learning-candidates strip is applied to the FILES SELECTION at publish time, not to
  serialization fidelity.

## Approach

Model the draft as its own OpenRegister object (`SkillDraft`) so the active skill is
never touched until acceptance. A `SkillConsolidationService` owns the pipeline state
machine imperatively (ADR-031 justification in design.md — LLM work plus gates on
external evidence), writing an AuditTrail entry per transition; the human decision is
NOT re-modeled — it delegates to the existing `Approval` object state machine, and the
Accept/Reject endpoints are action-gated per ADR-023. Acceptance writes the draft's
content onto the `Skill` through the normal versioned write path (creating the new
AuditTrail version) and stamps `Skill.lastAcceptedVersionAt`, which the frontend compares
against `publishedAt` for the behind-badge. `SkillVersionService` mirrors
`agent-versioning`: versions ARE the Skill object's AuditTrail entries; diff is limited
to `frontmatter`/`body`/`files`; rollback re-writes a prior version's values as a new
version. Republish reuses the fail-closed `GitHubTemplatePushService` path in update
mode against the skill's own provenance repo only.

## New Dependencies

None. (LLM via existing `ProviderFactory`; scan via existing OpenRegister
`ContentScanService`; GitHub via existing broker-mediated push service; evals via the
parallel `skill-evals` paired mode.)

## Impact

- `lib/Settings/hermiq_register.json` — new `SkillDraft` schema; `Skill` gains optional
  `lastAcceptedVersionAt`; register `info.version` bump + forced re-import.
- New: `lib/BackgroundJob/SkillConsolidationTask.php`,
  `lib/Service/SkillConsolidationService.php`, `lib/Service/SkillVersionService.php`,
  `lib/Controller/SkillDraftController.php`, `lib/Controller/SkillVersionController.php`,
  routes, seed repair step.
- Modified: GitHub publish path (`SkillMarketplaceService` / GitHub push service
  call-site) for republish + `learning-candidates.md` strip; run audit write path to pin
  executed skill versions.
- Frontend: SkillDetail gains the draft review surface (side-by-side diff, provenance,
  scan verdict, eval delta, Accept/Edit/Reject), a version history + rollback widget,
  and the behind-badge; catalog list gains the behind-badge; notification handlers.
- NOT impacted: `SkillSerializer` round-trip semantics, curation lifecycle
  (`SkillCuratorTask` logic), quarantine/approve paths for installs, maturity L1–L4
  computation.

## Cross-Project Dependencies

- Depends on the sibling hermiq changes `skill-maturity-model` (SkillDetail page,
  `levelEvidence` contract, computed-field write protection), `skill-evals` (paired
  draft-vs-active eval mode, `levelEvidence.l5`), and `skill-learnings`
  (`files['learnings.md']` content + `levelEvidence.l6`).
- ADR-068 §5 (hydra `openspec/architecture/`) is the architectural decision; ADR-023
  (action authorization), ADR-031 (declarative-first), ADR-060 (regression evidence from
  real executed runs).
- OpenRegister: `ContentScanService`, `AuditTrail`, `ObjectService`,
  `ConfigurationService::importFromApp(force:true)`; no OR code change needed.

## Risks

### Risk 1: Prompt-injection laundering through consolidation
**Severity:** High — **Mitigation:** the entire change IS the mitigation (ADR-068 threat
model): draft-only writes, mandatory content scan that treats `learnings.md` as
instruction content, eval regression auto-discard, human approval with side-by-side
diff, versioned rollback, and no export of unvetted `learning-candidates.md`. A
"Prompt-injection amplification" threat-model subsection in design.md restates why each
gate exists; removal of any gate is a spec violation, not a tuning decision.

### Risk 2: Runaway LLM/eval spend from consolidation triggers
**Severity:** Medium — **Mitigation:** consolidation and its paired eval respect the org
kill-switch and budget hard-caps exactly as schedule ticks (agent-evals precedent);
threshold trigger fires at most one open draft per skill (no new draft while one is
pending); eval spend rolls into the existing budget aggregation.

### Risk 3: Draft/Approval state divergence
**Severity:** Medium — **Mitigation:** the human decision lives ONLY on the `Approval`
object and the apply/reject logic hangs on its state transition, so a decision from
ANY surface (SkillDetail or the generic approval inbox) runs the identical path; the
Approval payload must carry the decision evidence (deep link, scan verdict, eval
delta, learnings summary) or is invalid; every transition is audited, and the TimedJob
reconciliation idempotently applies/rejects any decided Approval whose transition
event was missed.

### Risk 4: Republish carve-out weakens the "never overwrite" publish rule
**Severity:** Medium — **Mitigation:** republish is allowed ONLY to the exact
`githubOwner`/`githubRepo` already stamped on the SAME skill's provenance, behind the
same publish action authorization; publishing to any other existing repo still refuses.

### Risk 5: Version pinning cost/fragility on the run path
**Severity:** Low — **Mitigation:** mirrors agent-versioning's "pin is never fatal"
rule — a failed version lookup degrades to an unpinned audit entry, never a failed run.

## Rollback Strategy

Revert the code (job, services, controllers, routes, widgets, notification handlers).
The `SkillDraft` schema and `Skill.lastAcceptedVersionAt` are optional and inert without
the code — they can stay in place; existing drafts become dormant objects (no
hard-delete, consistent with the marketplace lifecycle). Version history is plain OR
AuditTrail data and needs no undo. A subsequent register version bump may remove the
schema once drained.

## Open Questions

None blocking — deferred decisions are recorded in design.md.
