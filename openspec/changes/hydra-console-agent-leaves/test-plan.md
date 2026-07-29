# Test Plan: hydra-console-agent-leaves

All test cases are Hermiq-side. The console pages and hydra register they exercise come from
the two hydra-repo changes this one depends on, so every case below assumes those have landed
and are populated — a live-verification prerequisite, not a test step.

Two standing caveats apply throughout:

- `OCA\OpenRegister\*` is absent from Hermiq's CI, so nothing crossing that boundary can be
  proven by a green analyzer or a mocked unit test alone. Where a case is marked live-verified,
  a passing mock is explicitly NOT acceptance.
- **The OpenConnector command node does not exist yet.** Cases that would observe a real forge
  label being written are marked as such and are verified against the refusal/stop behaviour
  the specs require until that upstream half ships.

## Test Cases

### TC-1: Leaf body renders under a Vue-major-mismatched host
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-implementation-branch-carries-both-the-graph-builder-and-the-current-leaf`
- **type**: functional
- **persona**: n/a
- **preconditions**: The merge of `origin/development` into `feat/agent-graph-builder` is complete; a console detail page exists whose host bundle is a different Vue major than the leaf's
- **steps**: Open the console detail page, open the Agent tab, inspect the rendered tab body in the browser
- **expected result**: The tab body renders its own content via the `mount(el, props)` hand-off. An empty body is a FAIL, not an empty result — this is the exact green-but-dead shape hermiq#44 documented
- **test command**: `/test-functional`

### TC-2: Surface declarations agree across PHP and JS
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration`
- **type**: regression
- **persona**: n/a
- **preconditions**: Both halves of the leaf registration have been edited
- **steps**: Compare the `surfaces` list in `RegisterAgentLeafListener` with the one in `integration-leaf.js`; validate every member against OpenRegister's `LeafDescriptor::VALID_SURFACES`; run the integration-parity gate (gate-24)
- **expected result**: Identical sets; every member is a valid surface; neither side declares by omission; gate-24 passes
- **test command**: `/test-regression`

### TC-3: Agent widget is offered on a console dashboard
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration`
- **type**: functional
- **persona**: n/a
- **preconditions**: A console dashboard page that renders the integration registry
- **steps**: Open the dashboard, open the widget picker, look for the Agent widget; add it and confirm it renders at its default size
- **expected result**: The `hermiq-agent` widget is offered and renders
- **test command**: `/test-functional`

### TC-4: Bounded context on a finding, a stage and a cycle
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist`
- **type**: security
- **persona**: n/a
- **preconditions**: Hydra schemas carry `x-openregister-agent-context`; one object of each type exists with at least one non-allowlisted property populated
- **steps**: Open the Agent tab on each object, send a message, capture the outbound context payload
- **expected result**: Only allowlisted properties appear; the non-allowlisted property never appears; a listed-but-missing property is omitted without error. Live-verified — a mocked builder test is not acceptance here
- **test command**: `/test-security`

### TC-5: No allowlist yields empty context and a visible notice
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-declarative-bounded-agent-context-allowlist`
- **type**: functional
- **persona**: Noor (municipal CISO / functional admin) — the operator who must be able to tell "bounded" from "blind"
- **preconditions**: A hydra schema with no `x-openregister-agent-context` declaration
- **steps**: Open the Agent tab on an object of that schema and send a message
- **expected result**: Zero properties are forwarded; the surface states in text that no object context is available, not by colour alone; the reply is not presented as grounded in the object
- **test command**: `/test-persona-noor`

### TC-6: Argument-scoped grants resolve, and every legacy form is unchanged
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **type**: regression
- **persona**: n/a
- **preconditions**: A live catalog containing `openregister.runFlow` and a set of derived `{app}.{schema}.{verb}` tools
- **steps**: Resolve an argument-scoped grant; resolve each pre-existing form (exact id, schema wildcard, verb subset, `*:write`, empty list, no-tools sentinel); classify the narrowed write tool
- **expected result**: The argument-scoped grant resolves to the underlying exact tool id with no second catalog entry; every legacy form keeps its current meaning byte for byte; the narrowed tool still classifies write/destructive. This is the change to a fleet-wide class, so the regression half matters more than the new half
- **test command**: `/test-regression`

### TC-7: A non-conforming argument is refused before dispatch
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation`
- **type**: security
- **persona**: n/a
- **preconditions**: An agent holding a grant pinning a flow id and closing a value set; an observable facade boundary
- **steps**: Invoke conforming; invoke with a different pinned value; invoke with a value outside the closed set; inspect the audit trail after each
- **expected result**: The conforming call proceeds; both others are refused with a structured error, the facade is never invoked, and each refusal names the tool, the argument and the violated constraint in the audit trail
- **test command**: `/test-security`

### TC-8: The agent may run exactly one flow
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-the-pipeline-command-capability-is-one-approval-gated-argument-scoped-grant`
- **type**: security
- **persona**: n/a
- **preconditions**: At least two flows on the instance; the seeded triage agent granted exactly one
- **steps**: Have the agent invoke the pinned flow, then a different flow id
- **expected result**: The pinned flow proceeds to the remaining gates; every other flow id is refused before dispatch. This is the property that makes granting a flow runner safe at all
- **test command**: `/test-security`

### TC-9: A flow run queued by an agent is attributed
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-a-flow-invoked-as-an-agent-tool-is-attributed-to-an-owning-uid`
- **type**: security
- **persona**: n/a
- **preconditions**: One agent run with a resolvable owning UID, one without
- **steps**: Invoke the flow-queueing tool under each; read the queued run and the audit trail
- **expected result**: The first records the owner's UID and its steps execute as that owner; the second is refused with no flow run queued — never defaulted to an empty or system owner. The audit trail names the owning UID, the invoking agent, the tool id and the constrained arguments
- **test command**: `/test-security`

### TC-10: Prompt injection escapes neither the vocabulary nor the approval gate
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-the-pipeline-command-capability-is-one-approval-gated-argument-scoped-grant`
- **type**: security
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: A `finding` whose body instructs the agent to apply an arbitrary administrative label, to run a different flow, and to proceed without approval
- **steps**: Run the triage agent on that finding end to end
- **expected result**: The out-of-vocabulary label is refused, the different flow id is refused, the refusals are recorded in the run's audit trail, and the approval gate still applies. Pipeline object text is written by other agents and is untrusted by construction — this case is the one that proves it is treated that way
- **test command**: `/test-security`

### TC-11: A command pauses for a disclosing approval
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-the-pipeline-command-capability-is-one-approval-gated-argument-scoped-grant`
- **type**: functional
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: The Hydra Triage agent with `requiresApproval` set; a run that will select the command grant
- **steps**: Trigger the run; open the pending approval; inspect what it displays; reject it, then repeat and approve
- **expected result**: No flow run is queued before approval; the approval displays the flow, the target and the label; rejection leaves everything unchanged; approval queues the flow run
- **test command**: `/test-persona-noor`

### TC-12: Dry run and default-deny command nothing
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-the-pipeline-command-capability-is-one-approval-gated-argument-scoped-grant`
- **type**: security
- **persona**: n/a
- **preconditions**: A dry-run-mode run with the command grant; and three agents — empty grant list, wildcard-only grants, the seeded triage agent
- **steps**: Execute the dry run so the agent selects the command; resolve each agent's tools against the live catalog
- **expected result**: The dry-run invocation is neutralised with no flow run queued and no forge request; the command capability is absent for the first two agents and present only for the third
- **test command**: `/test-security`

### TC-13: The triage flow is data and stops on an empty result
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code`
- **type**: functional
- **persona**: n/a
- **preconditions**: The seeded, enabled triage `agentflow`; the ability to force a turn failure at the node boundary
- **steps**: Create a finding and observe the trigger; enumerate the flow's node types; force the agent step to yield an empty string and observe the next edge; run on an instance with no command node
- **expected result**: The resolver lists the flow and the engine queues a run; every node is a built-in node, `hermiq.agent-step`, or the OpenConnector-backed command node, and none opens an HTTP client from Hermiq; an empty result stops the flow before the command step; a missing command node stops the flow with the proposed label recorded and nothing written
- **test command**: `/test-functional`

### TC-14: Seeding is idempotent for both objects
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-a-seeded-read-only-triage-agent-as-data`
- **type**: functional
- **persona**: n/a
- **preconditions**: A clean instance
- **steps**: Run both repair steps; edit the seeded agent's prompt and the seeded flow's `enabled` field; run both repair steps again; query for objects by name
- **expected result**: Exactly one agent and one flow exist after both runs, and both operator edits survive the second run
- **test command**: `/test-functional`

### TC-15: Read grants resolve to read tools only
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-a-seeded-read-only-triage-agent-as-data`
- **type**: security
- **persona**: n/a
- **preconditions**: The live catalog exposes every verb for each hydra schema
- **steps**: Resolve the seeded agent's grants against the live catalog and enumerate the result
- **expected result**: Read tools for the hydra schemas plus exactly one argument-scoped command grant; NO create/update/delete tool for any hydra schema; nothing else that mutates a hydra object. Live catalog, not a fixture — a fixture more permissive than reality is the classic enabler here
- **test command**: `/test-security`

### TC-16: A grant naming a nonexistent schema is reported
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-a-seeded-read-only-triage-agent-as-data`
- **type**: functional
- **persona**: n/a
- **preconditions**: An agent granting a misspelled hydra schema slug and nothing else
- **steps**: Resolve its grants; separately resolve an agent using the explicit no-tools sentinel
- **expected result**: The first is reported as resolving to nothing and is not run as chat-only; the second is not reported
- **test command**: `/test-functional`

### TC-17: run-on-object is 202, lands its result, and fails closed
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-an-asynchronous-run-has-a-defined-landing-place`
- **type**: api
- **persona**: n/a
- **preconditions**: A `finding` object the caller may read; a second user who cannot read it; the Hydra Triage agent
- **steps**: POST `register`/`schema`/`objectId` plus `resultField: "triageNote"`; measure the response; poll the object and the audit trail; repeat as the user without access; repeat omitting a required field; repeat with a body attempting to waive approval
- **expected result**: 202 with a correlation id returned without waiting on an LLM; the result later appears on `triageNote` and as an audit entry the run widget reads; 404 fail-closed with no dispatch; 400 on the missing field with no dispatch; the approval gate applies regardless of the body
- **test command**: `/test-api`

### TC-18: Governance rails and owner semantics on dispatch
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-a-run-or-flow-dispatch-is-owned-by-the-person-who-made-and-activated-it`
- **type**: functional
- **persona**: n/a
- **preconditions**: An organisation under the kill-switch or over its budget hard cap; an `agentflow` with an owner and one without
- **steps**: Dispatch a run from a console action and open the Agent runs widget; fire the trigger for each flow with no acting user in context
- **expected result**: The gated run skips execution and the widget shows the matching skipped status; the owned flow's run is attributed to that UID and its agent step executes as that owner; the ownerless flow does not dispatch and the condition is reported rather than defaulted
- **test command**: `/test-functional`

### TC-19: No forge code exists anywhere in Hermiq
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector`
- **type**: security
- **persona**: n/a
- **preconditions**: The implemented branch
- **steps**: Sweep the repository for a forge/label/issue tool descriptor, for `lib/Service/Forge/`, and for any `IClientService` use in a tool provider or service reaching a forge host; list the tool catalog
- **expected result**: No such descriptor, directory or client exists; the catalog gains no tool at all; the only outbound-HTTP tools remain `hermiq.webSearch` and `hermiq.webFetch`, and the exception list has not grown. This is the pivot's acceptance criterion, so it is tested as a hard assertion rather than assumed from the diff
- **test command**: `/test-security`

### TC-20: Every invocation is attributable
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-a-flow-invoked-as-an-agent-tool-is-attributed-to-an-owning-uid`
- **type**: security
- **persona**: n/a
- **preconditions**: One accepted command, one constraint refusal, one owner-unresolved refusal
- **steps**: Read the audit trail for all three
- **expected result**: Each record names the owning UID, the agent, the tool id and the constrained arguments; refusals carry their reason. A queued command flow is a pipeline command, so an unattributed record is a failure
- **test command**: `/test-security`

### TC-21: Accessibility of the agent surface and the approval
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#non-functional-requirements`
- **type**: accessibility
- **persona**: n/a
- **preconditions**: The leaf on a console detail page; a pending command approval
- **steps**: Run a WCAG 2.1 AA audit over the chat tab, the runs widget, the empty-context notice and the approval surface; drive both by keyboard only
- **expected result**: Every input has a programmatic label (any `NcSelect` uses `inputLabel`/`ariaLabelCombobox`, not a manual `<label>`); the empty-context notice and the approval are readable as text, not colour-coded alone; async queued → complete transitions are announced; all controls are keyboard reachable
- **test command**: `/test-accessibility`

### TC-22: Dutch and English coverage
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#non-functional-requirements`
- **type**: functional
- **persona**: Henk (elderly citizen) — the Dutch-first reader who exposes untranslated strings fastest
- **preconditions**: Interface language switched to Dutch, then English
- **steps**: Exercise the leaf, the empty-context notice, the run statuses, the approval surface and each user-facing refusal message in both languages
- **expected result**: Every operator-visible string is translated in both `l10n/en.json` and `l10n/nl.json`; no untranslated key or English fallback appears in the Dutch UI. Tool ids, error codes and LLM-facing descriptions correctly stay untranslated English identifiers
- **test command**: `/test-persona-henk`

### TC-23: Dispatch and refusal latency
- **spec_ref**: `openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#non-functional-requirements`
- **type**: performance
- **persona**: n/a
- **preconditions**: A console detail page with the Run agent action; an agent holding the command grant
- **steps**: Measure the run-on-object response time; measure a constraint-refused invocation; count catalog fetches during grant resolution; count round trips during context building
- **expected result**: 202 returns within normal Nextcloud request latency with no LLM call on the request path; a refused invocation costs no facade round trip and no network call; grant resolution adds no catalog fetch beyond the one the existing resolver performs; context building adds no per-property round trip
- **test command**: `/test-performance`

## Coverage Summary

**`agent-object-leaf` (delta)**
- Agent integration leaf registration (MODIFIED — surfaces) — covered (TC-2, TC-3)
- Declarative bounded agent-context allowlist (MODIFIED — empty-context disclosure) — covered (TC-4, TC-5)
- The implementation branch carries both the graph builder and the current leaf — covered (TC-1)
- A seeded read-only triage agent, as data — covered (TC-14, TC-15, TC-16)
- The triage loop is a seeded agentflow, not bespoke code — covered (TC-13)
- A run or flow dispatch is owned by the person who made and activated it — covered (TC-18)
- An asynchronous run has a defined landing place — covered (TC-17)

**`agent-tool-governance` (delta)**
- Schema-scoped whitelist grants… (MODIFIED — argument-scoped form) — covered (TC-6)
- Argument constraints on a grant are enforced at invocation — covered (TC-7)
- A flow invoked as an agent tool is attributed to an owning UID — covered (TC-9, TC-20)
- The pipeline command capability is one approval-gated, argument-scoped grant — covered (TC-8, TC-10, TC-11, TC-12)

**`nc-native-tools` (delta)**
- Remote systems route through OpenConnector (MODIFIED — read-only exception; commands via flow) — covered (TC-19)

**Non-functional** — accessibility (TC-21), i18n (TC-22), performance (TC-23).

Every requirement in all three deltas has at least one test case. Gate-19 traceability is
satisfied per scenario by a Playwright e2e reference or a reason-bearing `@e2e exclude`; the
exclusions are named below.

## Out of Scope

- **The OpenConnector endpoint or flow node that writes the label.** It does not exist yet, and
  when it does it belongs to the openconnector and hydra repos. TC-13 and TC-19 verify Hermiq's
  side of the boundary — that the flow stops rather than degrades, and that Hermiq writes
  nothing itself.
- **Hydra's own pipeline reacting to a written label.** The end-to-end loop (label written →
  hydra polls → stage advances) belongs to the hydra repo.
- **The hydra register's schemas and allowlist contents.** Owned by `hydra-register-data-plane`.
  TC-4 verifies Hermiq honours whatever allowlist exists, not that hydra declared a good one.
- **The console's manifest and action wiring.** Owned by `hydra-console-openbuild-app`.
- **`cli` execution mode.** Personal-scope-only and deferred to `hydra-exec-personal-cli-runner`.
- **Fixing attribution inside OpenRegister.** `FlowMcpToolProvider` queueing without a user is
  an upstream defect; this plan verifies Hermiq refuses rather than emits an unattributed call.
- **The forced-turn-failure scenario at e2e level.** Reliably forcing a mid-run turn failure
  through a browser is not practical; TC-13's empty-result branch is unit-tested against
  `HermiqAgentNode::execute()`'s swallow behaviour instead, and carries
  `@e2e exclude requires forced mid-run turn failure, unit-tested at the node boundary`.
- **An end-to-end command reaching a real forge.** Not possible until the OpenConnector half
  ships, and not runnable unattended in CI thereafter. TC-8, TC-10, TC-11 and TC-20 carry
  `@e2e exclude requires the openconnector command node and live forge credentials,
  live-verified` and are executed manually against a scratch repository before merge.
