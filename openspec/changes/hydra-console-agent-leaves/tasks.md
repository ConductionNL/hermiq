# Tasks: hydra-console-agent-leaves

Eight tasks. Three are pre-existing-defect fixes, two are the missing abstraction, two are
seed data, one is live verification. **No task creates a forge client, a forge service, or a
Hermiq tool descriptor** — that path is dropped by the architectural pivot.

## Implementation Tasks

### Task 1: Complete the branch base merge
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-implementation-branch-carries-both-the-graph-builder-and-the-current-leaf`
- **files**: (merge only — resolve conflicts across `lib/`, `src/`, `appinfo/`; no new file)
- **acceptance_criteria**:
  - GIVEN the in-flight merge of `origin/development` into `feat/agent-graph-builder` WHEN it is completed THEN the branch carries both the agent graph builder and the `mount(el, props)` leaf hand-off (hermiq#44/#47, v0.1.94) and the flow-engine consumer (hermiq#35)
  - GIVEN the merge completes without textual conflict WHEN no leaf render has been observed live THEN the task is NOT done — a clean merge alone is not acceptance
  - GIVEN a console detail page on a Vue-major-mismatched host WHEN the Agent tab is opened THEN the tab body renders its own content, and an empty body is treated as merge failure
- [ ] Implement
- [ ] Test

### Task 2: Align the leaf surface vocabulary across both halves
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration`
- **files**: `lib/Listener/RegisterAgentLeafListener.php`, `src/integration-leaf.js`
- **acceptance_criteria**:
  - GIVEN the PHP descriptor declares `['detail-page','single-entity']` and the JS half declares no `surfaces` key at all WHEN both are edited THEN they name the same set explicitly, every member drawn from OpenRegister's `LeafDescriptor::VALID_SURFACES`
  - GIVEN the leaf ships `widget: CnAgentRunsWidget` with `defaultSize: { w: 4, h: 4 }` WHEN the surface set is chosen THEN it includes the dashboard surfaces the console places that widget on
  - GIVEN the JS half previously declared surfaces by omission WHEN it is edited THEN it declares them explicitly so the cross-layer parity gate has something to compare
  - GIVEN an OpenBuild dashboard page WHEN its widget picker is opened THEN the `hermiq-agent` widget is offered and renders at its default size
- [ ] Implement
- [ ] Test

### Task 3: Make the fail-closed empty context visible
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist`
- **files**: `src/components/CnAgentChatTab/CnAgentChatTab.vue`, `lib/Service/Agent/AgentContextBuilder.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a `finding`, `stage` or `cycle` object WHEN chat context is built THEN only properties named by the schema's `x-openregister-agent-context` are forwarded, verified against a live object rather than a fixture
  - GIVEN a schema with no allowlist WHEN context is built THEN the context is empty and no property is forwarded
  - GIVEN a listed property missing on the instance WHEN context is built THEN it is omitted and the build succeeds
  - GIVEN a resolved context of zero properties WHEN the user opens the chat THEN the surface states in text that no object context is available and does not present the reply as grounded
  - GIVEN the new notice string WHEN the UI is viewed in Dutch and English THEN both are translated
- [ ] Implement
- [ ] Test

### Task 4: Argument-scoped grants in the resolver
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`
- **acceptance_criteria**:
  - GIVEN an argument-scoped grant string WHEN it is expanded against the catalog THEN it resolves to the underlying exact tool id with its declared input schema, and no second catalog entry is invented
  - GIVEN the same grant WHEN its constraints are read THEN a pinned value and a closed value set are both representable in one `Agent.tools` string, preserving the ADR-035 `string[]` shape
  - GIVEN an argument-scoped grant over a write/destructive tool WHEN it is classified THEN it still classifies write/destructive for default-deny, dry-run and approval — narrowing never downgrades
  - GIVEN every pre-existing grant form (exact id, `{app}.{schema}.*`, `{app}.{schema}.{verb}`, `*:write`, the no-tools sentinel) WHEN resolution runs THEN each keeps its current meaning with no behaviour change
  - GIVEN an unconstrained exact-id grant for a multi-target tool WHEN it resolves THEN it remains legal and is understood as granting every target
- [ ] Implement
- [ ] Test

### Task 5: Constraint enforcement and owner attribution at the dispatch chokepoint
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`
- **acceptance_criteria**:
  - GIVEN a conforming invocation WHEN it is checked THEN it proceeds to the remaining governance checks and then to the facade
  - GIVEN a pinned argument that differs, or a value outside a closed set WHEN the tool is invoked THEN the call is refused with a structured error before the facade, and the tool, argument and violated constraint appear in the audit trail
  - GIVEN object text instructing the agent to use a target or value the grant does not permit WHEN it invokes accordingly THEN the call is refused and no prompt, tool description or model rationale relaxes the constraint
  - GIVEN an agent invoking a flow-queueing tool WHEN the run has a resolvable owning UID THEN the queued flow run records it and the flow's steps execute as that owner
  - GIVEN no resolvable owning UID WHEN the same tool is invoked THEN the invocation is refused and no flow run is queued — never defaulted to an empty or system owner
  - GIVEN the existing short-circuits (searchTools, guardrail deny/confirm, approval gate, dry-run) WHEN the new check is added THEN no second invocation path is introduced and their ordering still holds
- [ ] Implement
- [ ] Test

### Task 6: Seed the Hydra Triage agent
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-a-seeded-read-only-triage-agent-as-data`
- **files**: `lib/Repair/SeedHydraTriageAgent.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN it runs twice, with an operator edit in between THEN exactly one agent named "Hydra Triage" exists and the edit survives (idempotent by name, `ObjectService`, system context, per the `Seed*.php` precedent)
  - GIVEN the seeded `tools` list WHEN it is resolved against the live catalog THEN it yields read tools for the hydra schemas plus exactly one argument-scoped command grant, and no create/update/delete tool for any hydra schema
  - GIVEN the command grant WHEN it is written THEN the label vocabulary is resolved from hydra's own state-machine definition at seed time and never hardcoded in Hermiq
  - GIVEN the seeded agent WHEN its policy is read THEN `requiresApproval` is true and `delegationAllowlist` is empty
  - GIVEN an agent that named grants, used no no-tools sentinel, and resolved to zero tools WHEN resolution completes THEN it is reported as a misconfiguration and not run as chat-only; an agent using the sentinel is NOT reported
- [ ] Implement
- [ ] Test

### Task 7: Seed the triage agentflow
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code`
- **files**: `lib/Repair/SeedHydraTriageFlow.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the seeded, enabled `agentflow` WHEN a finding object is created THEN `HermiqFlowResolver::flowsForTrigger()` lists it and the engine queues a run that walks the `hermiq.agent-step` node
  - GIVEN a triage run whose turn fails and whose agent step therefore yields an empty string WHEN the flow evaluates its next edge THEN the empty result is treated as "no result" and the flow does NOT proceed to the command step
  - GIVEN the seeded flow definition WHEN its node types are enumerated THEN every node is a built-in engine node, the `hermiq.agent-step` node, or the OpenConnector-backed command node — and none opens an HTTP client from Hermiq code
  - GIVEN an instance where the OpenConnector-backed command node is absent WHEN the flow runs THEN it terminates with the proposed label recorded and no forge write attempted
  - GIVEN the flow object WHEN its trigger fires with no acting user THEN the run is attributed to the NC UID of the person who authored and activated it, and an ownerless flow does not dispatch
- [ ] Implement
- [ ] Test

### Task 8: Live end-to-end verification on the console
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector`
- **files**: `tests/e2e/`, `docs/agent-object-leaf.md`
- **acceptance_criteria**:
  - GIVEN the repository WHEN it is swept THEN Hermiq's tool catalog contains no forge, label or issue tool, no `lib/Service/Forge/` exists, and no Hermiq provider or service opens an HTTP client to a forge host
  - GIVEN a populated hydra register and console WHEN the loop is exercised — open a finding, chat with bounded context, run the triage agent, approve a command — THEN the run appears in the widget and the command is refused, queued or written according to whether the OpenConnector half is present
  - GIVEN a finding whose text injects an out-of-vocabulary label and a request to skip approval WHEN the agent runs THEN the invocation is refused, the refusal is in the audit trail, and the approval gate still applied
  - GIVEN a dry run and a wildcard-only agent WHEN each is exercised THEN no flow run is queued and the command capability is absent
  - GIVEN every scenario added by this change WHEN gate-19 runs THEN each is referenced by a Playwright e2e test or carries a reason-bearing `@e2e exclude` (the exclusions named in test-plan.md)
  - GIVEN nothing under `OCA\OpenRegister` is statically analysable in this repo WHEN cross-app behaviour is signed off THEN it is on live observation, not on a green analyzer
- [ ] Implement
- [ ] Test

## Quality checklist

- New business logic covered by PHPUnit unit tests (`tests/Unit/`) — especially the
  argument-scoped grant parser and the pre-dispatch constraint check, which hold the security
  decision; run PHP tests in the `nextcloud:34` container, host PHP is too old
- Grant-grammar regression coverage for every pre-existing form, so the additive change is
  provably additive
- `run-on-object` covered by Newman/Postman tests (202 shape, 400 on missing field, 404
  fail-closed) — unchanged in this change, re-run as regression
- UI changes covered by Playwright browser tests; the leaf render check must run against a live
  console page, since a registered-but-empty leaf is the failure mode being guarded
- Mocks must not be more permissive than reality — grant resolution and catalog assertions run
  against the live catalog
- All tests pass (`composer test`, `newman run`); `composer check:strict` clean, including any
  pre-existing issues touched
- `docs/agent-object-leaf.md` updated with the console surfaces and the argument-scoped grant
  form (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added for the empty-context notice, run
  statuses, approval text and every user-facing refusal message (ADR-005/ADR-007); tool ids,
  error codes and LLM-facing descriptions stay untranslated English identifiers
- Secrets in fixtures, docs and specs use obvious placeholders only (`YOUR_PAT_HERE`, nil UUID)
  — gitleaks runs on these files; this change introduces no credential of its own
- `openspec validate hydra-console-agent-leaves` passes
