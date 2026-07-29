# Design: skill-learnings

## Architecture Overview

Learnings capture is a strictly post-run, append-only observer. It never sits on the
run's critical path, never edits skill `body`/`frontmatter`, and writes only two
well-known entries in the skill's agentskills.io `files` map.

```
Run executes (ScheduleService/Engine)
  │  ContextAssembler injects installed-skill content → records skillsUsed: [skill uuids]
  ▼
Run audit entry persisted (runId, trace, tokens, skillsUsed)
  │  enqueue SkillLearningsCaptureJob {runId, scheduleUuid, agentId, skillIds}   ← QueuedJob,
  ▼                                                                                after the run
SkillLearningsCaptureService::captureForRun()          (best-effort; every error caught+logged)
  │  1. idempotency: skill's learning-candidates.md already contains runId → skip
  │  2. budget: BudgetService::isBlocked(org, agentId) → skip (run exhausted budget = no capture)
  │  3. ONE cheap LLM pass (ProviderFactory) over the persisted run trace + existing candidates
  │       → new atomic observations AND/OR confirmations of existing candidates
  │  4. RedactionService::redact() per observation; empty-after-redaction → dropped
  │  5. mechanical append/update of structured candidate lines in files['learning-candidates.md']
  │  6. stamp levelEvidence.l6 {candidateCount, lastCaptureAt}
  ▼
SkillLearningsPromotionTask (TimedJob, daily; sibling of SkillCuratorTask)
  │  SkillLearningsPromotionService — NO LLM, purely mechanical line parsing:
  │  • candidate with 3+ DISTINCT run ids  → move to files['learnings.md'] under its section
  │  • candidate with an eval-fail marker  → promote regardless of confirmation count
  │  • candidate untouched for 30 days     → drop
  │  stamp levelEvidence.l6 {candidateCount, learningsCount, lastPromotedAt}
  ▼
SkillDetail page → read-only Learnings tab (rendered learnings.md + candidate count + last activity)
```

`learnings.md` carries five fixed sections: **Patterns That Work**, **Mistakes to
Avoid**, **Domain Knowledge**, **Open Questions**, **Consolidated Principles**. This
change writes the first four (promotion targets the section chosen at capture time);
**Consolidated Principles** is written only by the future `skill-self-improvement`
consolidation — it exists here so the file shape is stable from day one.

### Candidate line grammar (what makes promotion mechanical)

The LLM never writes the file. The capture service parses the LLM's structured output
and serializes candidate lines itself, in a strict grammar:

```
- [2026-07-27] {domain} Tender deadlines in TED notices are CET, not local time. <!-- runs: 00000000-0000-0000-0000-000000000000 -->
- [2026-07-27] {mistakes} Do not summarise lots separately when the notice has one CPV. <!-- runs: 00000000-0000-0000-0000-000000000000,00000000-0000-0000-0000-000000000001 | eval-fail: 00000000-0000-0000-0000-000000000002#case-3 -->
```

`[date]` = last-touched date (refreshed on confirmation — the 30-day clock);
`{section}` ∈ `patterns|mistakes|domain|questions` (chosen by the capture pass);
`runs:` = distinct confirming run ids; `eval-fail:` = optional failed-eval reference.
The promotion pass needs nothing but this grammar — count run ids, check markers,
compare dates.

## Goals / Non-Goals

**Goals:** the L6 capture substrate — utilization-gated post-run capture, idempotent per
run ID, budget-counted, failure-isolated, redaction-inherited; mechanical two-stage
promotion; `levelEvidence.l6` activity written by this subsystem only; read-only UI.

**Non-Goals:** consolidation into the skill body, draft versions, approval-gate routing,
re-quarantine, eval regression gates (all `skill-self-improvement`); the publish-time
export split (learnings.md ships, learning-candidates.md stripped — also
`skill-self-improvement`; here BOTH files simply exist in the `files` map and travel
with the normal agentskills.io export by construction); writing `levelEvidence.l5`
(`skill-evals`); any manual learnings editor.

## Decisions

### Decision 1: ADR-031 declarative-vs-imperative split

**Capture extraction is imperative — a legitimate ADR-031 exception.** Extracting dated
atomic observations from a run trace is NLP over unstructured trace content (step
timelines, tool outcomes, model text) — exactly the "domain rule selector over
unstructured content" class that `x-openregister-calculations` cannot express; it
additionally requires an LLM call through `ProviderFactory`, which no declarative
dialect performs.

**Promotion counters: considered `x-openregister-calculations`, decided imperative.**
`candidateCount`/`learningsCount` LOOK like computed fields, but they derive from
parsing markdown lines inside one entry of the `files` array (`files[].content` where
`name = 'learning-candidates.md'`) — parsed document content, not structured object
fields, so a declarative calculation over the object graph cannot produce them. Keeping
them in the promotion/capture services also preserves the single-writer contract from
skill-maturity-model (one code path writes `l6`, client writes are discarded).
Everything that stays declarative stays declarative: the `l6` fields themselves are
plain optional schema properties with no imperative accessor.

**Thresholds are service constants.** The 3-distinct-run confirmation bar and the
30-day candidate expiry are constants in `SkillLearningsPromotionService` (the spec
fixes the RULE — two-stage promotion with a confirmation bar and an expiry; the service
owns the numbers, tunable after fleet experience without a spec change, mirroring the
skill-maturity-model line-count precedent).

### Decision 2: Utilization is recorded at the injection seam, and this change owns the minimal seam

"No credit/blame without utilization" needs a factual record of which skills' content
entered the run context. Today the run loop does not yet inject installed skill content
(the skills-catalog spec calls run-loop consumption of `installedOn` an OR seam — grep
confirms `Engine`/`ContextAssembler` never touch skills). Decision: this change adds the
**minimal** seam — `ContextAssembler` loads the content of the agent's installed
`active` skills (`Agent.skillInstalls`) into the assembled context and returns the list
of injected skill uuids, which `ScheduleService` persists as `skillsUsed` on the run's
audit entry. Capture consumes ONLY `skillsUsed`; a run path that injects no skills
records none and gets no capture. Alternative — defer injection and let capture key off
`installedOn` alone — rejected: that is exactly the credit-without-utilization ADR-068
forbids, and it would capture "observations" about skills the model never saw.

### Decision 3: Capture runs as a QueuedJob, never inline

Failure isolation and zero run latency are hard constraints, so capture is enqueued
(`IJobList::add()`) after the run's audit entry is written and executes in a later
background-job pass — the same pattern as `AgentRunRequestedJob`. The enqueue call
itself is wrapped in try/catch (an enqueue failure is logged and swallowed). Inside the
job every skill is processed in its own try/catch so one bad skill cannot starve the
others. Alternative — synchronous post-run call — rejected: a slow/unavailable provider
would delay run completion and a fatal would risk the poison-bg-job failure mode; a
queued job that fatals affects only itself.

### Decision 4: Budget gating and cost accounting reuse the run channel unchanged

Before the LLM pass, `BudgetService::isBlocked(organisation, agentId)` is checked — a
run that exhausted the budget gets no capture pass (hard constraint), with the skip
logged. The capture call's token usage is recorded through the same audit-entry channel
`BudgetService` already aggregates for runs (an `action='run'` entry tagged
`runType: 'skill-capture'`, carrying the originating `runId`), so capture cost counts
against the SAME per-org/per-agent period windows with **zero BudgetService changes**.
Alternative — a separate capture-budget ledger — rejected: ADR-068 wants one budget
authority, and a second ledger would be invisible to the existing budget warnings/caps.

### Decision 5: The LLM proposes; the service disposes (grammar + idempotency + redaction)

The capture pass receives the run trace, the skill's name/description, and the CURRENT
candidate list, and returns structured JSON: new observations (`{section, text}`) and
confirmations (`{candidateIndex}`) — putting the fuzzy "is this the same observation?"
judgement at capture time, where an LLM is already paid for. The service then acts
mechanically: it validates sections, applies `RedactionService::redact()` to every
observation text (an observation that redacts to empty is dropped; if ALL are dropped,
nothing is written at all — the soft redaction-empty behavior), caps observation length
(service constant — atomic statements, never raw conversation transcript), appends new
candidate lines, and appends the run id + refreshes the date on confirmed lines.
Idempotency is checked BEFORE the LLM call: if `learning-candidates.md` already contains
the run id (in any `runs:` marker), the skill is skipped — re-delivered jobs and
double-enqueues cost nothing and duplicate nothing.

### Decision 6: Writes inherit agent-memory redaction + governance — no new channel

Learnings writes are ordinary `ObjectService::saveObject()` updates of the `Skill`
object (the `files` array), so OpenRegister's hash-chained AuditTrail records every
capture/promotion write automatically; redaction uses the same `RedactionService` the
memory path uses (agent-memory "redacted before persist" requirement, applied verbatim).
No new endpoint, no new tool, no new write mechanism. The capture services run in
backend service context — deliberately NOT exposed as an MCP tool, so no agent can
invoke capture on itself (prompt-injection cannot ask for its own laundering pass).

### Decision 7: `levelEvidence.l6` — extend the existing contract, do not fork it

skill-maturity-model defined `l6: {learningsCount, lastConsolidatedAt, lastPromotedAt}`.
This change adds two OPTIONAL fields — `candidateCount`, `lastCaptureAt` — and keeps the
predecessor's names (`lastPromotedAt`, not "lastPromotionAt") to avoid contract drift.
A register JSON edit IS genuinely needed here despite the prefer-none rule: OpenRegister
silently drops undeclared object keys on write (known magic-mapper gotcha), so an
undeclared `candidateCount` would never persist. The L6 PASS rule is untouched
(`learningsCount > 0` + `lastConsolidatedAt`): capture + promotion alone deliberately do
NOT flip a skill to L6 — consolidation (`skill-self-improvement`) completes the level,
and the scorecard stays honest ("no consolidation yet") in the meantime. The
skill-maturity-model write-path guard is extended to carry stored `l6` forward and
ignore client-supplied values, because as of this change `l6` has a real single writer.

### Decision 8: Eval-failure promotion is a marker contract, not a coupling

When the run being captured is an eval-case run with a failing verdict (the eval engine
reuses the run path, so the audit entry identifies it), the capture service annotates
that run's candidates with `eval-fail: <evalRunUuid>#<caseId>`. The promotion pass
promotes any candidate carrying the marker regardless of confirmation count ("explains a
failed eval case"). No dependency on `skill-evals` internals: absent eval runs, the
marker simply never occurs.

### Decision 9: UI is a read-only widget on the existing SkillDetail page

`SkillLearnings.vue` renders `files['learnings.md']` as markdown plus an activity strip
(`candidateCount`, `lastCaptureAt`, `lastPromotedAt`, `learningsCount` from
`levelEvidence.l6`); candidates themselves are NOT rendered (unpromoted, noisy,
operator-irrelevant). No editing surface in this change — a manual editor would create a
second write channel bypassing redaction. Registered as a widget on the SkillDetail
manifest page from skill-maturity-model, beside the maturity scorecard.

## API Design

None — no new or modified HTTP endpoints. Capture and promotion are background
subsystems; the UI reads the `Skill` object through the existing OR object path.

## Database Changes

None (thin client — no tables). Register JSON (`lib/Settings/hermiq_register.json`):
the `Skill` schema's `levelEvidence.l6` sub-object gains OPTIONAL `candidateCount`
(integer, minimum 0) and `lastCaptureAt` (string, `format: date-time`); descriptions
state they are written only by the learnings subsystem. Nothing added to `required`; no
`if`/`then`/`allOf`. Register `info.version` bumps 0.16.0 → 0.17.0 and the repair step
applies it as a FORCED import (openregister#2075).

## Nextcloud Integration

- Controllers: none new/modified.
- Services: `SkillLearningsCaptureService` (new), `SkillLearningsPromotionService`
  (new); consumes existing `ProviderFactory`, `BudgetService`, `RedactionService`,
  OpenRegister `ObjectService`, `LoggerInterface`.
- Background jobs: `SkillLearningsCaptureJob` (`OCP\BackgroundJob\QueuedJob`, enqueued
  per run via `IJobList`), `SkillLearningsPromotionTask` (`OCP\BackgroundJob\TimedJob`,
  daily, `setAllowParallelRuns(false)` — the `SkillCuratorTask` pattern); both
  registered in `appinfo/info.xml`.
- Engine: `ContextAssembler` gains the minimal installed-skill injection + returns
  injected skill uuids; `ScheduleService` persists `skillsUsed` on the run audit entry
  and enqueues the capture job (try/catch-wrapped).
- Mappers/Entities: none. Events/Hooks: none.

## Security Considerations

- **Prompt-injection laundering (ADR-068 threat model):** capture APPENDS to two files
  only; it never writes `body`/`frontmatter`, and nothing in this change injects
  learnings content back into any prompt or context — the loop back into skill content
  is exactly the human-gated `skill-self-improvement` path. Candidate text is
  length-capped, redacted, and stored as inert file content.
- **Redaction:** every observation passes `RedactionService::redact()` before persist —
  no secrets, no personal data; extraction-not-quotation is enforced by prompt AND by
  the length cap, so raw conversation content does not enter the files. Redaction-empty
  → no write.
- **Governance inheritance:** all writes go through the unchanged `ObjectService`
  path (hash-chained AuditTrail); capture is not an MCP tool, so agent allowlists and
  tool governance are untouched and un-bypassable.
- **Computed-field integrity:** the skill write paths ignore client-supplied
  `levelEvidence.l6`, carrying stored values forward (extension of the
  skill-maturity-model guard).
- **Tenant scoping:** capture/promotion resolve the skill's own organisation; the LLM
  pass sees only that tenant's run trace and that skill's candidates; budget checks are
  per-org/per-agent.
- **CSRF/endpoints:** n/a — no new HTTP surface.

## NL Design System

Learnings tab uses standard Cn*/Nc components, CSS variables only (no hardcoded
colors); rendered markdown inherits app typography; activity metadata is text (not
color-only); all new strings EN + NL (ADR-007).

## File Structure

```
lib/
  Service/SkillLearningsCaptureService.php        (new)
  Service/SkillLearningsPromotionService.php      (new)
  Service/Engine/ContextAssembler.php             (minimal skill injection + skillsUsed)
  Service/ScheduleService.php                     (persist skillsUsed; enqueue capture job)
  Cron/SkillLearningsCaptureJob.php               (new — QueuedJob)
  Cron/SkillLearningsPromotionTask.php            (new — TimedJob, daily)
  Repair/SeedMaturityExampleSkills.php            (extend: demo learnings on tender-summary)
  Settings/hermiq_register.json                   (l6 + candidateCount/lastCaptureAt; version bump)
appinfo/
  info.xml                                        (version bump; job registration)
src/
  manifest.json                                   (SkillDetail page: Learnings widget)
  registry.js / customComponents.js               (widget registration)
  widgets/SkillLearnings.vue                      (new — read-only)
tests/
  unit/Service/SkillLearningsCaptureServiceTest.php
  unit/Service/SkillLearningsPromotionServiceTest.php
  e2e (Playwright): Learnings tab renders on SkillDetail
```

## Seed Data

The learnings files are demo-seeded on ONE existing seed skill — `tender-summary` (the
L4 seed from skill-maturity-model) — via the same idempotent repair step (matched by
name; files added only when absent; never overwriting admin edits; system context):

- `files['learnings.md']`: the five sections with 2–3 realistic consultancy-context
  entries under Patterns That Work / Mistakes to Avoid / Domain Knowledge (e.g. "TED
  deadlines are CET"), Open Questions with one entry, Consolidated Principles EMPTY
  (consolidation has not run — honest state).
- `files['learning-candidates.md']`: two candidate lines in the exact grammar (one with
  a single nil-UUID run id, one with two), so the promotion job and the UI have real
  input on a fresh install.
- `levelEvidence.l6`: `{candidateCount: 2, learningsCount: 6, lastCaptureAt,
  lastPromotedAt}` — deliberately NO `lastConsolidatedAt`, so the maturity scorecard
  truthfully shows L6 not yet passed.

Placeholders: nil UUIDs (`00000000-0000-0000-0000-000000000000`) / `YOUR_API_KEY_HERE`
style only. Other schemas: none introduced or modified beyond `l6` (covered above).

## Risks / Trade-offs

- [LLM extraction quality varies — noisy candidates] → the two-stage design is the
  mitigation: noise dies in `learning-candidates.md` (no 3-run confirmation → 30-day
  expiry); `learnings.md` only ever receives confirmed or eval-explaining entries.
- [Grammar drift breaks mechanical promotion] → the service serializes every line
  itself (LLM output is JSON, parsed and validated); unparseable legacy lines are
  treated as untouched candidates and age out via the 30-day rule; unit tests pin the
  grammar.
- [QueuedJob latency — candidates appear only after the next bg-job pass] → accepted;
  learnings are a slow loop by design. `lastCaptureAt` in the UI makes staleness
  visible.
- [Concurrent captures for the same skill (parallel runs) race on the files array] →
  last-write-wins on the Skill object is the OR default; a lost candidate line is
  self-healing (the same observation recurs; idempotency is per run id, and the missing
  run id makes the skill eligible for re-capture if the job re-fires). Accepted for the
  substrate; consolidation-era locking is a `skill-self-improvement` concern.
- [Injection seam adds prompt size for agents with many skills] → minimal seam injects
  only `active` installed skills; per-skill content already passed the catalog's size
  norms; measured in the test plan, and capped counts are a tunable service constant.

## Migration Plan

1. Register JSON `l6` extension + version bumps (register 0.17.0, `appinfo/info.xml`);
   repair step forces the import.
2. Code ships in one release: seam + services + jobs + widget. Capture only fires for
   runs executed AFTER upgrade (older runs have no `skillsUsed` — never re-processed).
3. Rollback: revert code and unregister jobs — capture/promotion stop immediately; the
   two `l6` fields and any written learnings files are inert data (see proposal).

## Open Questions

None blocking. Deferred (provisional decisions recorded above): (a) the utilization
seam — this change owns the MINIMAL injection + `skillsUsed` recording (Decision 2); if
a broader run-loop skills feature lands first, this change shrinks to recording only;
(b) `l6` field naming — predecessor names kept (`lastPromotedAt`); (c) confirmation/
expiry thresholds and the observation length cap are service constants (Decision 1).
