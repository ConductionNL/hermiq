# Tasks: skill-learnings

## 1. Register JSON — levelEvidence.l6 activity fields

- [x] 1.1 In `lib/Settings/hermiq_register.json`, extend the `Skill` schema's `levelEvidence.l6` sub-object with OPTIONAL `candidateCount` (integer, minimum 0) and `lastCaptureAt` (string, `format: date-time`); descriptions state they are written only by the learnings subsystem (declared explicitly because OR silently drops undeclared keys). Nothing added to `required`; no `if`/`then`/`allOf`; bump register `info.version` 0.16.0 → 0.17.0 and app version in `appinfo/info.xml`; repair step applies the bump as a FORCED import (openregister#2075). Edit with the Edit tool; re-validate the JSON. *(Implementation note: the parallel `skill-evals` change landed first and took 0.17.0, so this change's actual bump is 0.17.0 → 0.18.0 — same forced-import ride; app 0.1.98 → 0.1.99.)*

## 2. Utilization seam (spec: Requirement "The engine records which skills were exercised in a run")

- [x] 2.1 `lib/Service/Engine/ContextAssembler.php`: minimal injection — load the content of the agent's installed `active` skills (`Agent.skillInstalls`) into the assembled context and return the injected skill uuids; `lib/Service/ScheduleService.php`: persist that list as `skillsUsed` on the run's audit entry and, AFTER the run record is written, enqueue `SkillLearningsCaptureJob` via `IJobList::add()` with `{runId, scheduleUuid, agentId, skillIds}` — enqueue wrapped in try/catch (a failure is logged, never fails the run). Runs injecting no skills record none and enqueue nothing.

## 3. Capture (spec: Requirements "post-run capture", "failure-isolated", "budget-gated", "idempotent", "redaction")

- [x] 3.1 Create `lib/Service/SkillLearningsCaptureService.php` (SPDX, `@spec` tags): per exercised skill — (a) idempotency FIRST: skip if `learning-candidates.md` already contains the run id (no LLM call); (b) budget gate: skip + log when `BudgetService::isBlocked(org, agentId)`; (c) ONE cheap LLM pass via `ProviderFactory` over the persisted run trace + current candidates, returning structured JSON `{observations: [{section, text}], confirmations: [{candidateIndex}]}`; (d) `RedactionService::redact()` per observation, drop empties, ALL empty → no write at all; (e) length-cap observations (service constant); (f) serialize candidate lines in the fixed grammar (`- [date] {section} text <!-- runs: ids | eval-fail: ref -->`), append confirmations' run ids + refresh dates; (g) annotate `eval-fail:` when the captured run is a failing eval-case run; (h) stamp `levelEvidence.l6.candidateCount` + `lastCaptureAt`; every skill in its own try/catch (one failure never blocks the next); never touch `body`/`frontmatter`/other files.
- [x] 3.2 Create `lib/Cron/SkillLearningsCaptureJob.php` (`QueuedJob`, registered in `appinfo/info.xml`): calls the capture service inside a catch-all (log + swallow — a capture error NEVER fails or delays anything); record the capture pass's token usage through the same audit-entry channel `BudgetService::currentUsageTokens()` aggregates (`action='run'` entry tagged `runType: 'skill-capture'`, carrying the originating `runId` and schedule/agent scope).

## 4. Promotion (spec: Requirement "Promotion is a mechanical two-stage background pass")

- [x] 4.1 Create `lib/Service/SkillLearningsPromotionService.php`: parse the candidate grammar WITHOUT any LLM; promote candidates with 3+ DISTINCT run ids or an `eval-fail:` marker into `learnings.md` under their tagged section (create the file with the five fixed sections — Patterns That Work, Mistakes to Avoid, Domain Knowledge, Open Questions, Consolidated Principles — when absent; NEVER write Consolidated Principles); remove promoted lines from `learning-candidates.md`; drop candidates untouched > 30 days; unparseable lines age out via the 30-day rule; thresholds are class constants; stamp `levelEvidence.l6.candidateCount`/`learningsCount`/`lastPromotedAt` from the parsed files; never write `lastConsolidatedAt`.
- [x] 4.2 Create `lib/Cron/SkillLearningsPromotionTask.php` (`TimedJob`, daily, `setAllowParallelRuns(false)` — the `SkillCuratorTask` pattern), registered in `appinfo/info.xml`; per-skill try/catch.

## 5. Write-path guard extension (spec delta: skill-maturity "never client-writable")

- [x] 5.1 Extend the skill write-path guard (`SkillController`/`SkillService` create, edit, import) to also carry stored `levelEvidence.l6` forward and ignore client-supplied values — only the capture/promotion services write it; `targetLevel` stays freely editable.

## 6. Frontend (SkillDetail Learnings surface)

- [x] 6.1 Create `src/widgets/SkillLearnings.vue` (read-only: render `files['learnings.md']` as markdown + activity strip from `levelEvidence.l6` — candidate count, `lastCaptureAt`, `lastPromotedAt`, `learningsCount`; empty state when no learnings files; NO edit affordance); add it to the `SkillDetail` page in `src/manifest.json` beside the maturity scorecard; register in `src/registry.js`/`src/customComponents.js`; CSS variables only, Cn* components.
- [x] 6.2 Add EN + NL translation strings for the tab/card label, activity labels, and empty state (ADR-007).

## 7. Seed data (spec: Requirement "One seeded skill demonstrates the learnings shape")

- [x] 7.1 Extend the seed repair step (`SeedMaturityExampleSkills`, idempotent, system context) so `tender-summary` gains — ONLY when absent, never overwriting admin edits — a demo `learnings.md` (five sections, 2–3 consultancy-context entries per populated section, Consolidated Principles EMPTY), a demo `learning-candidates.md` (two grammar-exact candidate lines, nil-UUID run ids), and matching `levelEvidence.l6` (`candidateCount: 2`, `learningsCount`, `lastCaptureAt`, `lastPromotedAt`, deliberately NO `lastConsolidatedAt`); placeholders nil-UUID / `YOUR_API_KEY_HERE` style only.

## 8. Tests + verification

- [x] 8.1 PHPUnit `tests/unit/Service/SkillLearningsCaptureServiceTest.php` (nextcloud:34 container): idempotent re-process (byte-identical file, zero LLM calls), budget-blocked skip, redaction masks a credential + redaction-empty writes nothing, confirmation extends run-id list without duplicating, grammar serialization pinned, per-skill failure isolation, `body`/`frontmatter` byte-unchanged, l6 stamp correctness.
- [x] 8.2 PHPUnit `tests/unit/Service/SkillLearningsPromotionServiceTest.php`: 3-distinct-run promotion into the right section, eval-fail immediate promotion, 30-day expiry, promoted lines removed from candidates, Consolidated Principles never written, no LLM/provider interaction, l6 counts derived from parsed content; plus the write-path guard test (forged `l6` does not survive) and a serializer round-trip test with both learnings files present.
- [x] 8.3 Playwright e2e (`@e2e` refs on the spec scenarios): SkillDetail Learnings tab renders the seeded `tender-summary` learnings + activity counts with no edit affordance; a skill without learnings shows the empty state; maturity scorecard still reports L6 not passed.
- [ ] 8.4 Live-verify on NC 34 + OR: forced re-import applies the two `l6` fields to the EXISTING schema; a run with an exercised skill records `skillsUsed` and a later bg-job pass appends candidates; a budget-capped org gets no capture; capture tokens appear in the budget window; run latency and outcome unaffected when the provider is down.

## Acceptance criteria

- `levelEvidence.l6` gains optional `candidateCount` + `lastCaptureAt`; forced re-import on upgrade; nothing required; no conditionals.
- `skillsUsed` recorded at the injection seam; capture driven exclusively by it (no credit/blame without utilization).
- Capture: queued post-run, ONE `ProviderFactory` call per exercised skill, budget-gated AND budget-counted, idempotent per run id, redacted-before-persist (empty → no write), service-serialized grammar, appends only to the two learnings files.
- A capture failure of any kind never fails, delays, or alters the run; logged; per-skill isolation.
- Promotion: daily TimedJob, purely mechanical — 3+ distinct runs or eval-fail marker promote into the five-section `learnings.md`; 30-day expiry; Consolidated Principles reserved for skill-self-improvement.
- `levelEvidence.l6` written by this subsystem only (write-path guard extended); L6 pass rule unchanged — no consolidation, no L6.
- Both learnings files live in the `files` map and round-trip byte-for-byte through the export; no publish-split here.
- Read-only Learnings tab on SkillDetail (EN + NL, empty state); no editing surface.
- Idempotent demo learnings on `tender-summary` only.

## Quality reminders

- `kind: code`, `depends_on: [skill-maturity-model]` — the l6 register edit is a thin coupled config edit on the predecessor's contract (design.md Decision 7).
- Edit `hermiq_register.json` with the Edit tool — no sed/awk/scripts; re-validate JSON after editing.
- PHP gate runs in the nextcloud:34 container (host PHP too old); `composer check:strict` must pass.
- No new HTTP endpoint, no MCP tool, no new write channel — `ObjectService` writes only (audit-chained); capture must not be agent-invocable.
- Do NOT implement consolidation, draft versions, approval routing, or the export policy split (skill-self-improvement); do NOT write `levelEvidence.l5` (skill-evals) or `lastConsolidatedAt`.
- Placeholders: nil UUIDs (`00000000-0000-0000-0000-000000000000`) / `YOUR_API_KEY_HERE` style only (gitleaks).
