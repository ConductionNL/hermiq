# Test Plan: agent-capability-reach

Written because this change fails the `test-plan` skipWhen (8 tasks, not ≤4). It does NOT restate the
spec scenarios: every scenario in the three delta specs already carries an `@e2e` or a reason-bearing
`@e2e exclude` naming how it is asserted, and gate-19 enforces that mapping mechanically. What this
plan adds is the dimension those annotations do not carry — the **security**, **regression** and
**persona** cases, and the explicit statement of what is deliberately not tested here because it
belongs to a sibling change in the chain.

Two properties in this change cannot be established by an assertion that merely passes:

1. **The gating non-regression.** "Nothing became more permissive" is an absence claim, and an
   absence claim is what a wrong lookup manufactures for free. It must be tested as a *differential*
   against the pre-change verdicts, per id, not as a set of green assertions.
2. **The owner-only enforcement.** There are TWO write paths and only one of them was ever guarded.
   A test that exercises the guarded one proves nothing about the change, because it passed before
   the change too.

## Test Cases

### TC-1 — Reach resolution, all four branches (Task 1)

- **Type:** Unit (PHPUnit, `tests/Unit/Service/Engine/ToolReachResolverTest.php`)
- **Covers:** "An undeclared or unrecognised reach resolves to external"; "Every tool descriptor
  declares a reach on a closed, ordered vocabulary"
- **Cases:** declared-wins · inferred-read (`search`/`get` → `user`) · inferred-write
  (`create`/`update`/`delete` → `instance`) · fail-closed (no descriptor / 2-segment id / verb
  outside the closed set / value outside the vocabulary → `external`)
- **Positive control, mandatory:** the four branches MUST produce different verdicts in the same test
  run. A suite in which every case returns `external` is indistinguishable from one where the
  declared and inferred branches are never reached, and that is the likely first bug.

### TC-2 — Descriptor completeness (Task 2)

- **Type:** Unit (`tests/Unit/Mcp/HermiqToolProviderTest.php`)
- **Covers:** "Every tool descriptor declares a reach on a closed, ordered vocabulary"
- **Case:** enumerate `TOOL_DESCRIPTORS` and assert each of the 14 entries carries a `reach` drawn
  from the closed vocabulary, matching design.md Decision 3's table value for value.
- **Why enumerate rather than assert 14 named tools:** the assertion must fail when a 15th tool is
  added without a reach. A test that names the 14 passes forever while the catalogue grows holes.

### TC-3 — Gating differential (Task 3) — REGRESSION, the load-bearing test

- **Type:** Regression (PHPUnit)
- **Covers:** "Default-deny and the approval gate key off reach in union with the existing rule";
  `agent-tool-governance` "A low reach does not relax the write/destructive rule"
- **Method:** capture the pre-change gating verdict for every id in a representative catalogue,
  then assert the post-change verdict is identical **except** for `hermiq.webSearch` and
  `hermiq.webFetch`, which flip from ungated to gated.
- **Compare by failing test NAME, not by count.** An equal pass/fail count across a refactor can hide
  a set of newly-broken tests offset by a set of newly-fixed ones.
- **Deliberate expected failures:** exactly two ids. Any third is a defect, not a rebaseline.

### TC-4 — Gate/default-deny agreement (Task 3)

- **Type:** Unit
- **Covers:** "A declared destructive hint is honoured on the gating path"
- **Case:** for a 3-segment id whose verb reads `get` but whose descriptor declares
  `destructiveHint: true`, assert `requiresApprovalGate()` and `applyDefaultDeny()` reach the SAME
  verdict. They disagree today (`FacadeToolInvoker.php:962` passes no descriptor); the test is the
  proof the bypass closed.

### TC-5 — Fragment split order (Task 4)

- **Type:** Unit
- **Covers:** "A grant may carry a noapproval waiver fragment parsed before any other grant parsing"
- **Cases, each written to fail if the fragment is split AFTER the `?` split:**
  - `{toolId}?arg=in:a,b#noapproval` → parsed set is exactly `{a, b}`; no value contains
    `noapproval`
  - `{toolId}#noapproval` → resolves to `{toolId}`; no resolved id contains `noapproval`
  - the full pre-existing grant-form fixture set, run unchanged → byte-identical resolved sets and
    argument constraints
- **Why this matters more than it looks:** both mis-orderings fail SILENTLY — one produces a
  constraint nothing can satisfy, the other an id matching no catalogue entry. Neither raises.

### TC-6 — Waiver narrowing, three negatives (Task 5)

- **Type:** Unit + Security
- **Covers:** "The waiver suppresses the approval gate and nothing else"
- **Cases:** waiver on an ungranted tool → outcome identical to no-waiver · waiver alongside a
  violated constraint → `grant_constraint_violated` fires first · waiver on an RBAC-denied
  invocation → still denied
- Each MUST be written so it fails if the waiver is consulted one step earlier in the invoker.

### TC-7 — Owner-only, both write paths (Task 6) — SECURITY, the live finding

- **Type:** Security + API (`/test-security`, Playwright API-level)
- **Covers:** "Only the agent owner may persist a grant list carrying a waiver"
- **Status: already reproduced.** A non-owner PUT to
  `/apps/openregister/api/objects/hermiq/agent/{uuid}` returned HTTP 200 and replaced an
  admin-owned agent's `tools` with the attacker's list, including `hermiq.sendMail`;
  `enforce_default_closed` confirmed unset. This TC verifies the FIX, not the existence of the bug.
- **Method — three-way, on a disposable instance:**
  1. **Pre-dependency:** a second authenticated user PUTs `tools` to
     `/apps/openregister/api/objects/hermiq/agent/{uuid}` → expected to SUCCEED, matching the
     recorded reproduction. Re-run it as the control row: without it, a later "blocked" result
     cannot be distinguished from a path the test never exercised.
  2. **Post-`agent-object-owner-authorization`:** the same write → refused by OpenRegister.
  3. **Post-this-change:** the same write → still refused; and hermiq's own tool-grants endpoint
     still refuses the non-owner (the pre-existing 403 must not regress).
- **Do not run against the shared dev instance.** `docker ps -qf name=` is a substring match; confirm
  routing with a marker round-trip before writing anything.

### TC-8 — Waiver audit event (Task 6)

- **Type:** Unit + API
- **Covers:** "Waiving approval is recorded as a distinct audited event"
- **Cases:** add a waiver → one event with a stable action token, acting user, agent, exact grant
  entry, add-vs-remove · remove a waiver → the symmetric event · change an ordinary grant with no
  waiver on either side → NO waiver event (the absence case is the one that catches an over-broad
  trigger).

### TC-9 — End-to-end grant surface (Task 8)

- **Type:** Functional (Playwright, `tests/e2e/spec-coverage/tool-grant-reach.spec.ts`)
- **Covers:** the four `@e2e`-annotated scenarios across the three delta specs
- **Cases:** every tool-catalogue entry carries a reach from the closed vocabulary · a waiver
  round-trips through PUT/GET with the fragment intact · a second authenticated user is refused on
  BOTH write paths · the owner is not obstructed
- Reuse `_fixtures.ts` helpers; call `dismissTour` before any click.

### TC-10 — Persona: Noor Yilmaz, Municipal CISO / Functional Admin

- **Type:** Persona (`/test-persona-noor`)
- **Covers:** the change's compliance intent (ADR-004, EU AI Act Art. 14) rather than any single
  requirement
- **Questions the persona run must be able to answer from the shipped artefacts alone:**
  - Which tools on this instance can send data outside the building, and can I see that without
    reading source?
  - Has anyone switched off human oversight for any agent, when, and for exactly which capability?
  - If I read `docs/tool-grants.md` and nothing else, do I understand what `#noapproval` costs me?
- A "yes, but you have to know where to look" is a failure of this test case.

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Every tool descriptor declares a reach | TC-1, TC-2, TC-9 |
| An undeclared or unrecognised reach resolves to external | TC-1 |
| Default-deny and the gate key off reach in union | TC-3, TC-4 |
| A delegation cannot launder reach | TC-3 (unit case on the max-reach computation) |
| A grant may carry a noapproval fragment parsed first | TC-5, TC-9 |
| The waiver suppresses the gate and nothing else | TC-6 |
| Only the owner may persist a grant list carrying a waiver | TC-7, TC-9 |
| Waiving is a distinct audited event | TC-8 |
| The grant model is documented | TC-10 (review against the requirement; no browser assertion) |
| `agent-tool-governance` MODIFIED grammar + default-deny | TC-3, TC-5, TC-9 |
| `human-approval-gate` MODIFIED gate trigger + waiver | TC-4, TC-6 |

### Deliberately not tested here

- **UI rendering of reach, the grouping/filtering of 98 entries, and the waiver toggle** — no such UI
  ships in this change. It belongs to `grant-waiver-ui`, along with its WCAG 2.2 AA and
  `/test-accessibility` obligations.
- **The Mail-app ladder** — belongs to `mail-app-capability-ladder`, including the
  degrade-when-Mail-absent case.
- **The Agent schema authorization block itself** — belongs to `agent-object-owner-authorization`,
  including the forced-re-import verification (read the live register back; a version bump is not
  evidence). TC-7 step 2 consumes that change's result, it does not test it.
- **Performance** — reach resolution adds an in-memory lookup over a descriptor already fetched; no
  measurable budget is claimed and none is tested.

### Promotion after implementation

TC-3 (gating differential) and TC-7 (owner-only, both paths) carry ongoing regression value beyond
this change and SHOULD be promoted to reusable test scenarios with `/test-scenario-create` once the
chain closes. Both are the kind of check that silently stops being true the next time the grant
grammar is extended.
