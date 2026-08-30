# Test Plan: skill-self-improvement

## Test Cases

### TC-1: Threshold trigger creates a draft, active skill untouched
- **spec_ref**: `openspec/changes/skill-self-improvement/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill`
- **type**: api
- **preconditions**: skill with ≥20 learnings entries, no open draft, budget/kill-switch clear
- **steps**: run the consolidation job (occ background-job trigger); fetch the skill and its drafts
- **expected result**: one `SkillDraft` in `proposed` with provenance (`learningRefs`, `baseVersionId`); skill `frontmatter`/`body`/`files` byte-identical to before
- **test command**: /test-api

### TC-2: Open draft suppresses all triggers; manual propose returns the open draft
- **spec_ref**: `...#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill`
- **type**: api
- **preconditions**: skill with a draft in `awaiting-approval`
- **steps**: re-run the job; call `POST /api/skills/{id}/propose-improvement`
- **expected result**: no second draft; manual call returns the existing draft (200)
- **test command**: /test-api

### TC-3: Kill-switch and budget hard-cap block consolidation and the paired eval
- **spec_ref**: `...#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps`
- **type**: security
- **preconditions**: org kill-switch engaged (then: budget hard-cap reached)
- **steps**: run the job over an eligible skill; inspect provider/eval call counts and audit entries
- **expected result**: zero `ProviderFactory` calls, zero eval cases; blocked attempt audited; draft (if pre-existing in `proposed`) does not advance
- **test command**: /test-security

### TC-4: Dangerous scan verdict discards with no override; scan outage fails closed
- **spec_ref**: `...#requirement-every-draft-is-content-scanned-with-learnings-treated-as-instruction-content`
- **type**: security
- **preconditions**: draft whose proposed `learnings.md` contains injected instruction content rated dangerous; second run with scan service unavailable
- **steps**: run pre-qualification; enumerate routes for any force/override affordance
- **expected result**: draft → `discarded` with verdict in audit note; no override endpoint exists; scan-outage draft stays `proposed`
- **test command**: /test-security

### TC-5: Strictly-worse paired eval auto-discards; learnings retained
- **spec_ref**: `...#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded`
- **type**: api
- **preconditions**: skill linked to an EvalDataset; draft scoring below the active version
- **steps**: run pre-qualification; read draft, audit note, and `files['learnings.md']`
- **expected result**: `discarded` with both pass rates in the audit note; learnings unchanged; no Approval created
- **test command**: /test-api

### TC-6: No linked evals → honest `noEvalEvidence` flag, never L5
- **spec_ref**: `...#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded`
- **type**: functional
- **preconditions**: seeded `tender-summary` pending draft (fresh install)
- **steps**: open SkillDetail; accept the draft; qualify the skill
- **expected result**: review card states "no eval evidence" verbatim; after accept, `levelEvidence.l5` still absent and scorecard caps below L5
- **test command**: /test-functional

### TC-7: Accept creates a new version through the Approval machine
- **spec_ref**: `...#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization`
- **type**: functional
- **preconditions**: pending draft + linked pending Approval; caller granted `skill.review-draft`
- **steps**: Accept from SkillDetail; inspect Approval, skill content, version history, `lastAcceptedVersionAt`
- **expected result**: Approval `approved`; skill content equals proposed content as a NEW version; prior versions unchanged; timestamp stamped
- **test command**: /test-functional

### TC-8: Edit-then-accept ships the human-edited content after re-qualification
- **spec_ref**: `...#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization`
- **type**: functional
- **preconditions**: pending draft; authorized reviewer
- **steps**: edit the draft body via the review surface (SkillDetail-only); attempt to approve the linked Approval from the generic inbox before re-qualification completes; let pre-qualification re-run; then Accept
- **expected result**: the edit invalidates the stored `scanVerdict`/`evalEvidence` and re-runs pre-qualification; the Approval is NOT approvable from any surface until it passes (no edited-but-unscanned body can apply); accepted version carries the edited content; draft records `editedBeforeAccept: true` + editor
- **test command**: /test-functional

### TC-9: Reject marks bad learnings that never drive the next proposal
- **spec_ref**: `...#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization`
- **type**: api
- **preconditions**: pending draft with ≥2 driving learnings entries
- **steps**: reject marking one entry bad; re-trigger consolidation past threshold; read the new draft's provenance
- **expected result**: draft `rejected`, Approval `denied`; new draft's `learningRefs` exclude the marked entry
- **test command**: /test-api

### TC-10: Unauthorized reviewer gets 403 unchanged; invisible draft 404; inbox approval applies the draft
- **spec_ref**: `...#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization`
- **type**: security
- **preconditions**: caller without `skill.review-draft`; second caller outside tenant; third path: pending draft whose Approval sits in the generic approval inbox (and a fourth pending draft for inbox denial)
- **steps**: POST accept as each unauthorized caller; approve the third draft's Approval from the generic inbox without opening SkillDetail; deny the fourth from the inbox
- **expected result**: 403 with draft+skill unchanged; 404 (never 403) for the invisible draft; the inbox-approved draft IS applied — skill content equals the proposed content as a NEW version, `lastAcceptedVersionAt` stamped, draft `accepted`; the inbox-denied draft reconciles to `rejected` with the skill unchanged
- **test command**: /test-security

### TC-11: Version history, diff scope, rollback-as-new-version
- **spec_ref**: `...#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning`
- **type**: api
- **preconditions**: skill with ≥3 versions differing in body, state, and publishedAt
- **steps**: GET versions; GET diff (two versions); POST rollback to the oldest
- **expected result**: newest-first history with AuditTrail UUIDs; diff contains `body` only (never `state`); rollback restores `frontmatter`/`body`/`files` as a NEW version while `state`/`maturityLevel`/provenance keep current values; history unmutated
- **test command**: /test-api

### TC-12: Runs pin the executing skill version; pin failure never fatal
- **spec_ref**: `...#requirement-runs-pin-the-exact-skill-versions-that-executed`
- **type**: api
- **preconditions**: agent with skill S installed at version V
- **steps**: complete a scheduled run; accept a draft creating W; read the run's audit entry; repeat with version lookup forced to fail
- **expected result**: audit entry pins V (not W); failed lookup yields an unpinned entry with the run unaffected
- **test command**: /test-api

### TC-13: Post-acceptance regression surfaces an advisory rollback suggestion
- **spec_ref**: `...#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion`
- **type**: functional
- **preconditions**: accepted draft (V→W); next eval run fails the regression gate
- **steps**: complete the eval run; open SkillDetail; check notifications
- **expected result**: rollback suggestion targeting V + notification to the accepting reviewer; no rollback happens without an explicit request
- **test command**: /test-functional

### TC-14: Behind-badge, publisher notification, authorized one-click Republish
- **spec_ref**: `...#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal`
- **type**: functional
- **preconditions**: GitHub-published skill (`publishedAt` stamped, broker configured) with a pending draft
- **steps**: accept the draft; observe catalog + SkillDetail; trigger Republish as an authorized caller
- **expected result**: behind-badge on both surfaces + one notification; no GitHub call until Republish; republish pushes to the provenance repo only, restamps `publishedAt`, badge clears
- **test command**: /test-functional

### TC-15: Republish carve-out and export strip (marketplace delta)
- **spec_ref**: `openspec/changes/skill-self-improvement/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **type**: security
- **preconditions**: skill with `learnings.md` + `learning-candidates.md` in files; a foreign existing repo as an alternative target
- **steps**: republish to own provenance repo; attempt publish to the foreign existing repo; inspect the committed package
- **expected result**: own-repo update succeeds; foreign existing repo refused; package contains `learnings.md` but NOT `learning-candidates.md` nor provenance fields; emitted files byte-round-trip
- **test command**: /test-security

### TC-16: Every transition is audit-reconstructable
- **spec_ref**: `...#requirement-every-draft-state-transition-is-audited`
- **type**: security
- **preconditions**: one draft driven through propose → awaiting-approval → accept, another through auto-discard
- **steps**: read the organisation-scoped AuditTrail entries
- **expected result**: entries for every transition with acting principal (job vs user), gate evidence (verdicts, pass rates, version id), timestamps
- **test command**: /test-security

### TC-17: Seeded draft renders and is decidable on a fresh install
- **spec_ref**: `...#requirement-a-seeded-pending-draft-demonstrates-the-review-surface`
- **type**: functional
- **preconditions**: fresh install after repair steps
- **steps**: open `tender-summary` SkillDetail; re-run the repair step; decide the draft
- **expected result**: review card with diff/provenance/no-eval-evidence flag; re-run creates no duplicates (also after deciding); decision exercises the real path
- **test command**: /test-functional

### TC-18: Review surface accessibility
- **spec_ref**: `...#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions`
- **type**: accessibility
- **preconditions**: seeded pending draft; behind-badge active
- **steps**: audit SkillDetail review card, diff, badges with keyboard + screen reader semantics
- **expected result**: added/removed and pass/fail not color-only; actions and badges carry accessible names; WCAG 2.2 AA; EN + NL strings present
- **test command**: /test-accessibility

### TC-19: Noor (CISO) reviews the self-improvement trust chain
- **spec_ref**: `...#requirement-every-draft-state-transition-is-audited`
- **type**: persona
- **persona**: Noor (Municipal CISO / functional admin)
- **preconditions**: full loop executed once (TC-7) and one auto-discard (TC-5)
- **steps**: as Noor, reconstruct from the UI + audit trail who/what changed the skill, on which evidence, and verify no path bypasses the human gate
- **expected result**: complete lineage visible; Noor can confirm no auto-apply and no auto-republish exists
- **test command**: /test-persona-noor

### TC-20: An Approval without its decision-evidence payload never reaches an inbox
- **spec_ref**: `...#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization`
- **type**: security
- **preconditions**: draft passing pre-qualification and about to reach `awaiting-approval`; Approval creation forced to omit, in turn, the SkillDetail deep link, the scan verdict, the eval delta/`noEvalEvidence` flag, and the driving-learnings summary
- **steps**: drive the draft toward `awaiting-approval` with each incomplete payload variant; then with the complete payload; inspect the generic approval inbox and the draft state
- **expected result**: each incomplete Approval is rejected as invalid and never reaches any approval surface, with the draft remaining awaiting a valid Approval; the complete Approval reaches the inbox carrying all four fields (an inbox approver can make an informed decision without opening SkillDetail)
- **test command**: /test-security

## Coverage Summary

- All 12 `skill-self-improvement` requirements covered (TC-1…TC-14, TC-16…TC-20); the
  marketplace MODIFIED publish requirement covered by TC-14/TC-15.
- Regression guard: skills-catalog serializer round-trip and skill-maturity write
  protection are re-asserted inside TC-7/TC-11/TC-15 rather than separate TCs.
- Deliberately untested here: paired-eval internals and learnings capture/promotion
  (owned by `skill-evals` / `skill-learnings` test plans); LLM consolidation OUTPUT
  quality (subjective — absorbed by the human gate; only pipeline behaviour is tested).
- Live GitHub republish uses a placeholder-owner test repo; broker-unavailable path
  asserted as 503 fail-closed.
- After implementation: promote TC-7 (gated accept), TC-14/15 (republish + strip), and
  TC-19 (trust-chain reconstruction) to reusable scenarios via `/test-scenario-create`.
