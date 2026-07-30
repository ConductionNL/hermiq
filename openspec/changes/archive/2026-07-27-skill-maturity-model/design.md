# Design: skill-maturity-model

## Architecture Overview

Maturity is a metadata plane laid over the existing `agentskill` object — it never
touches the agentskills.io content plane (`frontmatter`/`body`/`files`) and never
touches the curation lifecycle (`state`). One imperative service computes it; one
endpoint triggers computation; everything else reads.

```
POST /api/skills/{id}/qualify  (owner-guarded, 404 on any mismatch)
        │
        ▼
SkillMaturityService::qualify(skill)
        │  reads frontmatter + body + files            → L1–L3 (mechanical)
        │  reads levelEvidence.l4 (human attestation)  → L4 (never auto-detected)
        │  reads levelEvidence.l5–l7 (other subsystems)→ L5–L7 (this change only reads)
        ▼
maturityLevel = highest CONTIGUOUS level passed (L(n) requires L(n−1))
        │  persists maturityLevel + refreshed l1–l3 evidence via ObjectService
        ▼
Scorecard response: per-level {passed, reasons[]} — structure, triggering,
eval evidence, learnings activity, orchestration use
```

The catalog list renders `maturityLevel` as dots (0–7); a new manifest `detail` page
(`/skills/:id`, mirroring `AgentDetail` / `EvalDatasetDetail`) hosts the scorecard
widget; the existing `SkillRowActions` widget gains a **Qualify** action that calls the
endpoint and shows the returned scorecard.

### Level rules (L1–L3 mechanical, mirroring `update-skill-overview.sh` detection)

| Level | Passes when | Scorecard reason bucket |
|---|---|---|
| L1 Anatomy | frontmatter parses and yields non-empty `name` + `description`; `body` non-empty | structure |
| L2 Triggering | `description` reads as a trigger: starts with verb-ish trigger phrasing AND contains when-to-use phrasing (`use when` / `when the user` / `trigger`); `body` < 500 lines; progressive disclosure — a body ≥ 200 lines MUST be accompanied by at least one `references/` entry in `files` rather than being one monolith | triggering |
| L3 Patterns | `files` map contains at least one `references/*` or `examples/*` entry | structure |
| L4 Personalization | `levelEvidence.l4.attestedBy` + `attestedAt` present — set ONLY by the action-gated attest endpoint, never auto-detected | human attestation |
| L5 Measurement | `levelEvidence.l5` carries `evalDatasetId` + `passRate` + `baselineDelta` + `lastValidated` (written by the future `skill-evals` change) | eval evidence |
| L6 Self-Improvement | `levelEvidence.l6` carries learnings activity (`learningsCount` > 0 + `lastConsolidatedAt`; written by future `skill-learnings` / `skill-self-improvement`) | learnings activity |
| L7 AI Workforce | `levelEvidence.l7` carries executed-chain evidence (`lastExecutedChainRunId` + `lastExecutedAt`; written by future `skill-orchestration`) — declaration alone is "structurally L7", not mature L7 | orchestration use |

Qualification criteria are deliberately Arize-shaped: a compact, procedural body scores
better than a comprehensive-docs monolith (the <500-line cap + progressive-disclosure
check), and the description must support minimal routing (the trigger-phrasing check).

## Goals / Non-Goals

**Goals:** land the maturity metadata + the L1–L4 qualification surface; define (not
write) the L5–L7 evidence contract; keep maturity strictly orthogonal to `state`; keep
the agentskills.io export byte-identical.

**Non-Goals:** writing L5–L7 evidence (future changes); background recomputation;
auto-promotion; skill self-modification; any `SkillSerializer` change.

## Decisions

### Decision 1: Declarative-vs-imperative (ADR-031)

`SkillMaturityService` is **imperative** — justified, not a reflex: L1–L3 computation is
rule-based content analysis of `frontmatter`/`body`/`files` (YAML frontmatter parsing,
trigger-phrase heuristics on the description, line counting, files-map path inspection,
contiguity folding across seven levels). That is a domain rule selector over unstructured
markdown content, not expressible as `x-openregister-calculations` (which operate on
structured object fields, not parsed document content). Everything that CAN stay
declarative does: `maturityLevel`, `targetLevel`, and all `levelEvidence` timestamps/ids
are plain schema fields on the register JSON with no imperative accessor — future
subsystems write `l5`–`l7` evidence as ordinary object writes, and this service only
folds them into a level.

### Decision 2: Mixed-spec rationale (`kind: code` with a register-JSON edit)

The change patches `lib/Settings/hermiq_register.json` (normally the head of a
config→code chain per ADR-032). Here the register patch is a **thin coupled config
edit**: the three new schema properties are inert metadata consumed exclusively by this
change's own service and UI — no other change or subsystem consumes them yet, so
splitting a config-only chain head adds a review round with no reviewer value. This wave
is applied locally (not via the Hydra supervisor), so chain scheduling is not in play
either. Hence one `kind: code` change, with the schema requirements specified inside the
`skill-maturity` capability.

### Decision 3: `maturityLevel` is service-written only

No schema mechanism can make a field truly read-only through the generic object path, so
enforcement is layered: (a) the qualify service is the only code path that computes and
persists the value; (b) the skill update path (`SkillController`/`SkillService` merge)
carries the STORED `maturityLevel` + computed `levelEvidence` sub-objects (`l1`–`l4`)
forward and ignores client-supplied values; (c) every qualification recomputes from
content, so a smuggled value never survives the next qualify. Alternative considered:
rejecting writes with 400 — rejected because the generic OR object path cannot
distinguish a carried-forward echo from a forged write, and silent-preserve matches the
existing "editing preserves fields the form does not surface" contract.

### Decision 4: Contiguous-level fold

`maturityLevel` is the highest n with ALL of L1..Ln passed (the dev-framework rule: you
cannot be L5 with broken triggering). Alternative — a bitmask of independently-passed
levels — rejected: the scorecard already exposes per-level pass/fail, and a single
ordinal renders as dots and sorts naturally in the catalog.

### Decision 5: Endpoint shape follows house `/api` routes, owner-guarded per agent-evals

`POST /api/skills/{id}/qualify` and `POST /api/skills/{id}/attest-l4` register in
`appinfo/routes.php` beside the existing skill routes (the house pattern; hermiq exposes
no true OCS routes today). The qualify guard copies the agent-evals IDOR rule: the
caller must own the skill; any mismatch or missing object returns **404, never 403** (no
existence confirmation). Attest-L4 additionally requires
`ActionAuthService::requireAction('skill.attest-maturity')` (ADR-023) — attestation is a
curator act, not an owner right; an unauthorized caller gets 403 with the skill
unchanged (mirroring `skill.approve-quarantined`).

### Decision 6: Maturity ⊥ lifecycle

Qualification is allowed in every lifecycle state (including `quarantined` — a curator
may want the scorecard as review input), and no lifecycle transition reads or writes
maturity fields. Neither value ever derives from the other.

### Decision 7: Detail surface is a new manifest `detail` page

`/skills` today is `type: index` with no detail page. The scorecard needs a durable home
(not only a post-qualify modal), so the manifest gains `SkillDetail`
(`/skills/:id`, `type: detail`, register `hermiq`, schema `agentskill`) with a
`skill-maturity-scorecard` widget — the same shape as `AgentDetail` and
`EvalDatasetDetail`. The list badge is a `maturityLevel` column rendered as dots.

## API Design

### `POST /api/skills/{id}/qualify`
**Auth**: Nextcloud session, `#[NoAdminRequired]`, owner-guarded (404 on mismatch).

**Request:** empty body.

**Response (200):**
```json
{
  "skillId": "00000000-0000-0000-0000-000000000000",
  "maturityLevel": 2,
  "targetLevel": 4,
  "scorecard": [
    { "level": 1, "passed": true,  "reasons": [] },
    { "level": 2, "passed": true,  "reasons": [] },
    { "level": 3, "passed": false, "reasons": ["no references/ or examples/ entry in files"] },
    { "level": 4, "passed": false, "reasons": ["not human-attested"] },
    { "level": 5, "passed": false, "reasons": ["no eval evidence (levelEvidence.l5 empty)"] },
    { "level": 6, "passed": false, "reasons": ["no learnings activity"] },
    { "level": 7, "passed": false, "reasons": ["no executed chain run"] }
  ]
}
```
**Errors:** 404 (skill missing OR caller not owner — indistinguishable), 401.

### `POST /api/skills/{id}/attest-l4`
**Auth**: Nextcloud session, `#[NoAdminRequired]`,
`ActionAuthService::requireAction('skill.attest-maturity')`.

**Request:**
```json
{ "note": "Tuned for our municipality's WOO workflow" }
```
**Response (200):** the refreshed scorecard (as above) — attesting recomputes.
**Errors:** 403 (action not held; skill unchanged), 404 (missing/invisible), 401.

## Database Changes

None — hermiq owns no tables. The `Skill` schema in `lib/Settings/hermiq_register.json`
gains three OPTIONAL properties (nothing added to `required`):

- `maturityLevel`: integer, minimum 0, maximum 7, default 0 — description states it is
  computed by `SkillMaturityService` and never hand-set.
- `targetLevel`: integer, minimum 1, maximum 7 — curator intent, freely editable.
- `levelEvidence`: object with sub-objects `l1`–`l3` (`passed`, `checkedAt`),
  `l4` (`attestedBy`, `attestedAt`, `note`),
  `l5` (`evalDatasetId`, `passRate`, `baselineDelta`, `lastValidated`),
  `l6` (`learningsCount`, `lastConsolidatedAt`, `lastPromotedAt`),
  `l7` (`declaredChain`, `lastExecutedChainRunId`, `lastExecutedAt`).
  No conditional (`if`/`then`/`allOf`) blocks — the OR importer rejects them.

Register `info.version` bumps 0.15.1 → 0.16.0 and the repair step must apply it as a
FORCED import (known OR gotcha: `importFromApp(force:false)` advances the stored version
without applying changes to existing schemas — openregister#2075).

## Nextcloud Integration

- Controllers: `SkillMaturityController` (qualify, attestL4) — new; routes in
  `appinfo/routes.php` beside the existing `skill#`/`skillMarketplace#` block.
- Services: `SkillMaturityService` (new; reads/writes via OpenRegister `ObjectService`
  like `SkillService`); `ActionAuthService` (existing, ADR-023) for attest.
- Mappers/Entities: none (thin client — OR objects only).
- Events/Hooks: none. Repair step: extend the existing seed path with
  `SeedMaturityExampleSkills` (idempotent by name, system context
  `_rbac: false, _multitenancy: false`, mirroring `SeedSkillCreator`).

## Security Considerations

- **IDOR:** qualify is owner-guarded with 404-never-403 (agent-evals pattern); attest
  returns 404 for invisible skills before the action check leaks anything.
- **Authorization:** L4 attestation gates through
  `ActionAuthService::requireAction('skill.attest-maturity')` (ADR-023); the action must
  be added to the admin action matrix surface.
- **Computed-field integrity:** `maturityLevel`/`levelEvidence.l1–l4` are not writable
  through the skill update path (Decision 3); L4 evidence is only writable via the
  attest endpoint.
- **No new content channel:** the service only READS skill content; it never writes
  `body`/`frontmatter`/`files`, so it adds no prompt-injection surface (ADR-068 threat
  model).
- **CSRF:** standard NC session + CSRF token on both POST routes (no `#[NoCSRFRequired]`).

## NL Design System

Maturity dots and the scorecard use CSS variables only (no hardcoded colors);
pass/fail must not be color-only (dot fill + accessible label/aria text, WCAG 2.2 AA);
scorecard reasons are translatable strings (EN + NL per ADR-007), rendered with standard
NC components via the nc-vue Cn* library.

## File Structure

```
lib/
  Controller/SkillMaturityController.php        (new)
  Service/SkillMaturityService.php              (new)
  Settings/hermiq_register.json                 (Skill schema + version bump)
  Migration/… (repair step registration only if a new class is added to info.xml)
appinfo/
  routes.php                                    (2 routes)
  info.xml                                      (version bump; repair step)
src/
  manifest.json                                 (SkillsCatalog column; SkillDetail page)
  registry.js / customComponents.js             (widget registration)
  widgets/SkillMaturityScorecard.vue            (new)
  widgets/SkillMaturityDots.vue                 (new — list badge)
  widgets/SkillRowActions.vue                   (Qualify action)
  api/skills.js                                 (qualify + attest calls)
tests/
  unit/Service/SkillMaturityServiceTest.php     (new)
  e2e (Playwright): qualify flow + scorecard render
```

## Seed Data

Seeded by an idempotent repair step (matched by name; never overwrites admin edits;
system context), giving a fresh install a spread of maturity levels so the catalog dots
and scorecard render meaningfully. General-organization content (municipality +
consultancy), placeholders only (nil UUIDs / `YOUR_API_KEY_HERE` style):

| Skill | Level shown | Content shape |
|---|---|---|
| `meeting-notes-cleanup` | L1 | Valid frontmatter (name + description) + a 5-line body; description is a bare noun phrase ("Meeting notes helper") so L2's trigger check fails — demonstrates a structurally-valid but poorly-triggering skill. `targetLevel: 2`. |
| `woo-request-triage` | L2 | Municipality context: description "Triage an incoming WOO request — use when a new WOO/Woo-verzoek arrives and needs routing, deadline and exemption pre-check." Compact procedural body (~60 lines), no references files. `targetLevel: 3`. |
| `tender-summary` | L4 | Consultancy context: description with clear trigger phrasing ("Summarise a tender publication — use when the user pastes or links a TenderNed/TED notice"), body < 200 lines, `files` containing `references/exemption-grounds.md` and `examples/tender-summary-example.md`; `levelEvidence.l4` pre-attested (`attestedBy: "admin"`, seeded timestamp, note "Tuned for NL public-procurement summaries"). `targetLevel: 5` — its scorecard shows L5 failing with "no eval evidence", pointing at the future skill-evals change. |

All three seed with `state: active`, `source: local`, empty `createdBy` (system-seeded),
and `maturityLevel` set to the level the qualify service would compute (verified by a
unit test so seeds never drift from the rules).

## Risks / Trade-offs

- [Trigger-phrase heuristics are English-biased] → keep the phrase list configurable in
  one place in the service; Dutch trigger phrasings ("gebruik wanneer", "bij") included
  from the start; a false L2 fail is visible in the scorecard reasons and costs nothing.
- [L5–L7 fields are dead metadata until follow-up changes land] → deliberately accepted:
  ADR-068 requires the evidence contract to exist before evals/learnings/orchestration
  can write it; the scorecard is honest about absence ("no eval evidence").
- [Forced schema re-import on upgrade] → repair step forces the import (openregister#2075);
  upgrade path covered in the test plan.
- [Seeded L4 attestation is synthetic] → acceptable for demo/seed content; the note text
  says it is an example; docs state real attestation flows through the action-gated
  endpoint.

## Migration Plan

1. Land schema patch + version bumps (register 0.16.0, app `info.xml`).
2. Repair step: forced register import + seed skills (idempotent).
3. Code + UI ship in the same release; qualify is a no-op risk (computes level 0+ for any
   existing skill only when invoked).
4. Rollback: revert code; the optional schema fields stay inert (see proposal Rollback
   Strategy).

## Open Questions

None blocking. Deferred: the exact body-line thresholds (500 hard cap, 200
progressive-disclosure trigger) are constants in `SkillMaturityService` and may be tuned
after the first fleet qualification pass without a spec change (the spec fixes the
RULE, the service owns the numbers — recorded in the spec's Notes).
