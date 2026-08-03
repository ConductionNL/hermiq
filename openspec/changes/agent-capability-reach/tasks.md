# Tasks: agent-capability-reach

Spec 2 of a four-change ADR-032 chain — see proposal.md frontmatter and design.md
"Why a chain, not one change".

**Blocked on `agent-object-owner-authorization`** (`kind: config`), which declares the owner-scoped
`authorization` block on the Agent schema. Task 6's second write path cannot be closed without it,
because a hermiq-side pre-write guard is exactly what ADR-023 Rule 1 forbids and hermiq has nowhere
to put one (`Application.php:121-131` registers only post-write listeners). Do not start Task 6 until
that change is merged and the live register has been read back to confirm the block applied — a
version bump is not evidence (`importFromApp(force: false)` advances the version WITHOUT applying).

`Agent.tools` is already `string[]` (verified: `lib/Settings/hermiq_register.json`,
`components.schemas.Agent` v0.4.0). **This change introduces no OpenRegister schema edit** — every new
syntax rides inside those strings. If implementation appears to need one, stop and escalate: it
changes the ADR-032 kind of this change.

## Implementation Tasks

### Task 1: `ToolReachResolver` — the vocabulary, the ordering and the fail-closed default

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external`
- **files**: `lib/Service/Engine/ToolReachResolver.php`, `tests/Unit/Service/Engine/ToolReachResolverTest.php`
- **acceptance_criteria**:
  - GIVEN the closed vocabulary WHEN it is declared THEN it is exactly `self`, `user`, `instance`, `external` with a total order `self` < `user` < `instance` < `external`, expressed as class constants with a comparison helper — not as scattered string literals
  - GIVEN a descriptor declaring a valid `reach` WHEN resolved THEN the declared value wins over any inference
  - GIVEN a descriptor declaring NO `reach` and a 3-segment `{app}.{schema}.{verb}` id WHEN the verb is `search`/`get` THEN `user`; WHEN `create`/`update`/`delete` THEN `instance`
  - GIVEN any other shape — no descriptor, a 2-segment curated id, a verb outside the closed set, or a `reach` value outside the vocabulary — WHEN resolved THEN `external`. Never `self`, never derived from `scope`
  - Mirror `ToolGrantResolver`'s existing shape: a pure class, static where it takes no state, with the ADR-063 verb vocabulary referenced from the same closed set the grant resolver already uses rather than re-typed
  - Positive control: write one test per resolution branch (declared / inferred-read / inferred-write / fail-closed) and assert they produce DIFFERENT verdicts. A single fail-closed test cannot tell "all branches wired" from "everything falls through to external"
- [x] Implement
- [x] Test

### Task 2: Declare a reach on all 14 native descriptors

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-every-tool-descriptor-declares-a-reach-on-a-closed-ordered-vocabulary`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `tests/Unit/Mcp/HermiqToolProviderTest.php`
- **acceptance_criteria**:
  - GIVEN `TOOL_DESCRIPTORS` WHEN this task lands THEN every one of the 14 entries carries a `reach` key, and the values match design.md Decision 3's table exactly
  - Specifically: `listFiles`/`readFile`/`searchContacts`/`listCalendarEvents`/`listDeckBoards`/`recommendCourses` = `user`; `searchTools`/`rememberMemory`/`recallMemory`/`forgetMemory` = `self`; `delegateAgent` = `instance`; `sendMail`/`webSearch`/`webFetch` = `external`
  - GIVEN `scope`, `readOnlyHint` and `destructiveHint` WHEN this task lands THEN none is removed, renamed or re-valued — `reach` is additive
  - GIVEN a test that enumerates the descriptor table WHEN it runs THEN it asserts EVERY entry has a `reach`, so a 15th tool added later cannot ship without one
  - `searchContacts` is `user` deliberately even though the system addressbook surfaces other users' cards — reading changes nothing and tells nobody. Record that reasoning in the descriptor comment; it is the rule that keeps the whole OpenRegister read catalogue out of the gate
- [x] Implement
- [x] Test

### Task 3: Union gating — default-deny and the approval gate consult reach

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN the gating rule WHEN it is written THEN it is the UNION `isWriteOrDestructive(...) || reach >= instance`, never a replacement. A low reach must not un-gate anything
  - GIVEN `FacadeToolInvoker::requiresApprovalGate()` WHEN it classifies THEN it passes the catalogue DESCRIPTOR to `isWriteOrDestructive()`. It calls it with the id alone today (`FacadeToolInvoker.php:962`), so the descriptor-hint branch is dead on that path — a derived `.get` declaring `destructiveHint:true` is currently NOT gated while default-deny classifies it write. Closing that is a security fix; review it as one
  - GIVEN a refusal the gate produces WHEN reach fired it THEN the envelope names the resolved reach, so a run trace reads as a reach denial rather than an undifferentiated `approval_required`
  - GIVEN the pre-change unit fixtures WHEN they run after this task THEN every pre-existing verdict is unchanged except `hermiq.webSearch` and `hermiq.webFetch`. Baseline-compare by FAILING TEST NAME, not by count
  - Add the delegation reach rule here: effective reach = max(delegation tool's reach, highest reach among the target's resolved grants). It must not weaken the existing `requiresApproval`-target and kill-switch refusals
- [x] Implement
- [x] Test

### Task 4: `#noapproval` fragment — split it off FIRST

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-a-grant-may-carry-a-noapproval-waiver-fragment-parsed-before-any-other-grant-parsing`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a grant entry WHEN it is parsed THEN the `#noapproval` fragment is split off BEFORE `splitGrant()` splits on `?`. Getting this order wrong fails SILENTLY in two ways and both must have a test: `…?to=in:a,b#noapproval` parses a closed set whose last member is `b#noapproval` (a constraint nothing satisfies, grant silently dead), and `hermiq.webFetch#noapproval` passes through `expandGrant()` as an exact id matching no catalogue entry (capability silently lost)
  - GIVEN the fragment WHEN grants are expanded THEN it plays no part: `{toolId}` and `{toolId}#noapproval` resolve to the identical catalogue id, and no resolved id contains the text `noapproval`
  - GIVEN a stored grant list with no fragment WHEN parsed THEN the resolved set AND the parsed argument constraints are byte-identical to the pre-change output. Run the existing grant-form fixtures unchanged as the proof
  - Expose the waiver as its own accessor (e.g. `waivedToolIds()`) alongside `baseToolIds()` / `argumentConstraints()`, so the grammar keeps exactly one home. Do not let a second place interpret a grant string
  - Add a class constant for the fragment opener and the `noapproval` token; `#` is currently documented as deliberately unused (`ToolGrantResolver.php:148-155`) — update that docblock rather than leaving it contradicting the code
- [x] Implement
- [x] Test

### Task 5: Honour the waiver at the gate — and only there

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/Engine/ToolLoop.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN the waiver WHEN it is consulted THEN it is consulted in `requiresApprovalGate()` ONLY, after grant expansion has placed the tool in the resolved set and after `constraintViolationFor()` has accepted the arguments
  - GIVEN a waiver naming a tool the agent was never granted WHEN that tool is invoked THEN the outcome is byte-identical to the no-waiver case. The waiver adds nothing to the resolved set
  - GIVEN a waiver on an argument-scoped grant WHEN the arguments fall outside the constraint THEN the existing `grant_constraint_violated` refusal fires BEFORE the gate is consulted
  - GIVEN a waived, granted, conforming invocation of a tool the gate would otherwise fire on WHEN it runs THEN it dispatches with NO pending `Approval` created
  - Write the three negative scenarios as tests that would FAIL if the waiver were consulted one step earlier. "It can only narrow" is true in the first implementation and quietly false in the third — the ordering must be asserted, not commented
- [x] Implement
- [x] Test

### Task 6: Owner-only on every path that persists a grant list, plus the waiver audit event

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-only-the-agent-owner-may-persist-a-grant-list-carrying-a-waiver`
- **files**: `lib/Controller/ToolOversightController.php`, `src/modals/AgentFormModal.vue`, `src/api/toolOversight.js`, `tests/Unit/Controller/ToolOversightControllerTest.php`
- **acceptance_criteria**:
  - The IDOR is REPRODUCED, not suspected — a non-owner PUT to `/apps/openregister/api/objects/hermiq/agent/{uuid}` returned HTTP 200 and replaced an admin-owned agent's `tools` with `["hermiq.sendMail","hermiq.readFile"]`, with `enforce_default_closed` confirmed unset. Do not re-litigate whether the hole exists; do run the three-way check (pre-dep / post-dep / post-fix) on a DISPOSABLE instance, because without the pre-dep row a later "blocked" result cannot be distinguished from a path the test never exercised
  - GIVEN `agent-object-owner-authorization` merged WHEN the generic object write path is exercised by a non-owner THEN it is refused BY OPENREGISTER. Do not add an app-side pre-write guard — ADR-023 Rule 1, and hermiq registers only post-write listeners which cannot deny
  - GIVEN `ToolOversightController::updateToolGrants()` WHEN a non-owner calls it THEN the existing 403 guard (`:263-270`) still fires; do not regress it, and keep it as the defence-in-depth second layer
  - GIVEN `AgentFormModal.vue:643/683` WHEN an agent is saved THEN `tools` no longer rides the generic object payload; the grant list is written through the guarded tool-grants endpoint so that endpoint is genuinely the single write path its docblock claims
  - GIVEN a grant list persisted WHEN a `#noapproval` entry is added or removed THEN a DISTINCT audit event is written via the same OpenRegister audit path as other governance events (ADR-004), carrying acting user, agent, the exact grant entry and add-vs-remove, greppable by one stable action token
  - GIVEN an ordinary grant change with no waiver on either side WHEN it is persisted THEN NO waiver audit event is written
  - The UI string "Only the agent owner can change tool grants" (`ToolGrantEditor.vue:80-82`) is help text and gates nothing. Leave the editor rewrite to `grant-waiver-ui`, but do not let this task close while the sentence is still the only enforcement on the generic path
- [x] Implement
- [x] Test

### Task 7: `docs/tool-grants.md` — the grant model, written down for the first time

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-grant-model-is-documented-for-operators`
- **files**: `docs/tool-grants.md`
- **acceptance_criteria**:
  - GIVEN the page WHEN it ships THEN it covers every grant form — exact id, `{app}.{schema}.*`, `{app}.{schema}.*:write`, `?arg=value`, `?arg=in:a,b,c`, `#noapproval`, and the `__none__` sentinel — each with a worked example
  - GIVEN the reach section WHEN it is written THEN it leads with a worked example for EACH of `self`, `user`, `instance`, `external` before it states the grammar, and explains the effect-and-disclosure rule that makes an OpenRegister read `user` rather than `instance`
  - GIVEN the gate section WHEN it is written THEN it states the union rule plainly and lists what fires the gate
  - GIVEN the waiver section WHEN it is written THEN it says plainly that human oversight is switched off for that grant, that the waiver narrows nothing else, that it is owner-only, and that adding or removing it is audited
  - GIVEN the migration note WHEN it is written THEN it names `hermiq.webSearch` and `hermiq.webFetch` explicitly as the two capabilities an existing agent can lose, and gives the one-line grant edit that restores them
  - Docusaurus frontmatter matching `docs/agent-object-leaf.md` (title, sidebar_position, description, keywords); the sidebar is autogenerated so no `sidebars.js` edit is needed
  - Placeholder hygiene — gitleaks scans this: use `user@example.com`, `YOUR_API_KEY_HERE`, `00000000-0000-0000-0000-000000000000`. No realistic-looking secrets or UUIDs
- [x] Implement
- [x] Test

### Task 8: Playwright e2e under `tests/e2e/spec-coverage/`

- **spec_ref**: `openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-only-the-agent-owner-may-persist-a-grant-list-carrying-a-waiver`
- **files**: `tests/e2e/spec-coverage/tool-grant-reach.spec.ts`, `tests/e2e/spec-coverage/_fixtures.ts`
- **acceptance_criteria**:
  - Reuse the SHARED helpers already hoisted in `_fixtures.ts`: `TEST_PREFIX`, `seedAgent`, `cleanupFamily`, `dismissTour`, `collectHermiqConsoleErrors`, `harvestToken`, `jsonHeaders`. Do not re-copy them; a drifting local copy of `dismissTour` is exactly the failure that hoisting fixed
  - GIVEN a seeded agent WHEN its tool catalogue is read through the API THEN every entry carries a `reach` from the closed vocabulary and none is absent or null
  - GIVEN the agent's owner WHEN a grant list containing a `#noapproval` entry is PUT and read back THEN the fragment is preserved verbatim
  - GIVEN a SECOND authenticated user WHEN they attempt the same grant write THEN it is refused — assert BOTH paths: hermiq's tool-grants endpoint and the generic OpenRegister object path. A pass on only the first proves nothing, because that path was already guarded before this change
  - Add a second-user helper to `_fixtures.ts` (create a throwaway user via the provisioning API, prefixed with `TEST_FAMILY`, cleaned up in `afterAll`). NC passwords have a 10-character minimum and fail silently below it
  - `dismissTour` is required before any click: the `cn-wizard-dialog` does NOT close on Escape and does not hide what is underneath, so a stale overlay passes every visibility assertion and fails only on a click
  - `@e2e` annotations in the three spec files must name these tests; every scenario not covered here already carries a reason-bearing `@e2e exclude` (gate-19 is diff-scoped, so added and modified scenarios both count)
- [x] Implement
- [ ] Test — **written, lint-clean, NOT yet executed green against a live instance.**
  The catalogue and waiver assertions were run live in an earlier revision of this
  spec (the catalogue test correctly FAILED before openregister#2302 was deployed,
  which is what proved it can fail; the waiver round-trip passed). The NEW
  non-owner-on-both-paths test has never executed: the shared dev instance was at
  300–630% CPU with an 8s login, and an e2e run against a saturated instance
  fabricates defects rather than finding them. Do not tick this from a green run
  that did not include `a non-owner is refused on BOTH grant write paths`.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — run them in the
  `nextcloud:34` container, host PHP is too old
- New/changed API endpoints covered by Newman/Postman tests — this change adds no new endpoint, it
  hardens two existing write paths
- Playwright browser tests: Task 8. UI rendering of reach and the waiver toggle belongs to
  `grant-waiver-ui`, not here
- All tests pass (`composer test`, `composer check:strict`, `npx playwright test`). Note
  `composer check:strict` can be green-but-dead in this fleet — confirm `psalm`/`phpstan`/`phpmd`
  actually ran rather than trusting exit 0
- Feature documentation updated in `docs/`: Task 7, plus the migration note naming `webSearch` and
  `webFetch` as the two capabilities an existing agent can lose
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing string on
  the audit or refusal path (ADR-005 / ADR-007)
- Hydra gates apply: `@spec` traceability on changed methods, `@e2e` on added/modified scenarios,
  SPDX headers on new PHP files (`ToolReachResolver.php`), no stubs, no forbidden debug helpers
- Do not use sed/awk/scripts to modify code — use the Edit tool
- Fix pre-existing quality issues in the files you touch rather than leaving them
- No OpenRegister schema edit belongs in this change. If one seems necessary, stop and escalate — it
  changes the ADR-032 kind and this change would become `mixed`
- `openspec validate agent-capability-reach --type change --strict` passes
