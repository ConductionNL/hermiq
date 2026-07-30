# Discovery: hydra-console-agent-leaves

## Question

The product owner's constraint reframed this change mid-flight: *API calls go through
OpenConnector nodes; porting hydra should create NO code at all — just flows. If code
seems needed, analyse what abstraction our flows are missing and spec THAT instead.*

That makes the central question an **abstraction-availability** question, not a
feasibility one:

1. Can a Hermiq agent command an OpenConnector-backed endpoint or flow **today**, through
   the derived MCP catalog, without Hermiq shipping a bespoke tool? If yes, what is the
   grant? If no, what exactly is missing?
2. Can the triage loop itself be a **flow** — trigger, agent step, command step — rather
   than only an interactive leaf agent?
3. Which of the pre-pivot draft's Hermiq code survives as a legitimate pre-existing-defect
   fix?

## Approach Taken

Read the code that answers each question rather than reasoning from the docs.

- `lib/Service/Engine/ToolGrantResolver.php` — the full grant grammar, the
  `resolvesToNothing()` contract, and the write/destructive classification precedence.
- `lib/Service/Engine/FacadeToolInvoker.php` — how a tool call actually reaches the
  facade, and how many governance short-circuits already sit in front of that dispatch.
- OpenRegister `lib/Mcp/BuiltIn/` and `lib/Mcp/IMcpToolProvider.php` — what the derived
  catalog actually contains, and how a sibling app contributes to it.
- OpenRegister `lib/Service/Flow/` — `IFlowNode`, `IFlowResolver`,
  `RegisterFlowNodesEvent`, `RegisterFlowResolversEvent`, `FlowRunService::queue()`, and
  the built-in node set.
- `lib/Flow/HermiqAgentNode.php`, `HermiqFlowResolver.php` and their listeners — what
  Hermiq already contributes to that engine.
- `lib/Settings/hermiq_register.json` — the `agentflow` schema, i.e. what a seeded flow
  may actually declare.
- `apps-extra/openconnector/lib/` — swept for `IMcpToolProvider`, `IFlowNode` and the flow
  registration events.
- `lib/Listener/RegisterAgentLeafListener.php` vs `src/integration-leaf.js`, against
  OpenRegister's `LeafDescriptor::VALID_SURFACES`.
- The hydra chain-head and console changes in `apps-extra/hydra/openspec/changes/`.

## Findings

### 1. The "invoke a flow as an agent tool" abstraction EXISTS

OpenRegister ships `FlowMcpToolProvider` (`lib/Mcp/BuiltIn/FlowMcpToolProvider.php`),
registered among the built-in providers, exposing two tools:

- `openregister.runFlow` — queues a flow run by `flowId` against an optional subject
  (`uuid`, `register`, `schema`), `trigger: 'mcp'`, returning the run uuid.
- `openregister.flowRunStatus` — reads a run's status, per-step log and result items.

Its own docblock states the intent exactly: *"this goes the other way — it makes a flow a
thing an agent can find and run… a flow becomes a callable action rather than something
only a person or a Nextcloud event can trigger."* These enumerate through the same
`ToolRegistryFacade::listTools()` catalog Hermiq's `ToolLoop` already consumes, so a
Hermiq agent can already see and call them.

**So the answer to question 1 is: the mechanism exists, and no new tool is needed.**

### 2. …but it is not grantable, not parameterisable, and not attributed

Three concrete gaps, each verified in code, each fatal to handing this to an agent:

**(a) One tool id, every flow.** `openregister.runFlow` selects its target from a `flowId`
*argument*. `ToolGrantResolver::expandGrant()` supports exact ids and `{app}.{schema}.*`
wildcards only — a grant is all-or-nothing per tool id. So granting the triage agent the
label-write flow means granting it *every flow on the instance*. There is no way to
express "this agent may run exactly this one flow". (The wildcard forms don't help: they
key on `{app}.{schema}.{verb}`, which `openregister.runFlow` is not.)

**(b) No parameter channel.** The tool's only input beyond `flowId` is the subject triple.
A command's parameters — which label — cannot travel with the invocation, so a closed
command vocabulary cannot be enforced anywhere near the grant.

**(c) No owner.** `FlowMcpToolProvider::runFlow()` calls `FlowRunService::queue()` without
the optional `$user`, so `triggeredBy` is null. `HermiqAgentNode::execute()` then resolves
`$owner = $config['owner'] ?? $context['triggeredBy'] ?? ''` — an empty owner. An
agent-dispatched flow run is therefore **unattributed**, and for a flow whose terminal
step commands a build pipeline, that is an unattributed pipeline command.

One further observation: `openregister.runFlow` declares **no** ADR-063 hints. Being a
2-segment id it classifies write/destructive only via `isWriteOrDestructive()`'s
fail-closed branch. The right outcome by accident, not by declaration.

### 3. OpenConnector contributes NOTHING to either registry today

A sweep of `apps-extra/openconnector/lib/` for `IMcpToolProvider`, `IFlowNode`,
`RegisterFlowNodesEvent` and `RegisterFlowResolversEvent` returns **no hits**.
OpenConnector registers no MCP tool provider and contributes no flow node or resolver.
OpenRegister's built-in node set is `Filter`, `Loop`, `Merge`, `Router`, `SetFields`,
`Stop`, `SubFlow`, `Switch`, `Wait` — none of which makes an HTTP call.

So *"API calls go through OpenConnector nodes"* is, right now, aspirational: there is no
OpenConnector node in the flow engine for a flow to call. This is a cross-repo
prerequisite, not something Hermiq can or should work around.

### 4. The triage loop CAN be a flow, and it is data

Hermiq already contributes `hermiq.agent-step` (`HermiqAgentNode`) and `HermiqFlowResolver`,
which resolves `agentflow` objects in the `hermiq` register into flow documents and matches
them to fired triggers via `flowsForTrigger()` on `trigger` / `triggerRegister` /
`triggerSchema`. The `agentflow` schema (`lib/Settings/hermiq_register.json`) carries
`name`, `trigger`, `triggerSchema`, `enabled`, `nodes`, `edges`, `limits`.

A triage flow — trigger on a new finding → `hermiq.agent-step` → branch → command step — is
therefore expressible entirely as **one seeded object**, walked by OpenRegister's engine,
with no Hermiq code. That is the pivot's preferred shape, and it works.

Two constraints it must respect: `HermiqAgentNode::execute()` swallows a failed turn to an
empty string (a `catch (Throwable) { $answer = ''; }`), so the flow must branch on empty
explicitly or a failed turn silently becomes a command; and the flow's owner must come from
the flow object, since a trigger-fired run has no acting user.

### 5. The leaf halves have drifted, and the PHP half is the narrower one

`LeafDescriptor::VALID_SURFACES` is `['user-dashboard', 'app-dashboard', 'detail-page',
'single-entity']`. `RegisterAgentLeafListener` declares only `['detail-page',
'single-entity']`, while `src/integration-leaf.js` declares **no** `surfaces` key at all
while shipping `widget: CnAgentRunsWidget` with `defaultSize: { w: 4, h: 4 }` — the JS half
advertises a dashboard-placeable widget the PHP half says is not dashboard-placeable. The
console is dashboard-first, so this is a real blocker. The cross-layer parity gate did not
catch it because the JS half is *silent* rather than contradictory.

### 6. The render-mode fix is upstream but not on this branch

The descriptor declares `renderMode: RENDER_MODE_MOUNT` — the DOM hand-off that lets a Vue-3
leaf render under a Vue-2.7 host. The consuming half, `mount(el, props)`, landed on
`development` (hermiq#44/#47, v0.1.94) and is not yet on `feat/agent-graph-builder`, which
carries an in-flight merge of `origin/development`. On the un-merged branch the leaf
registers and its tab appears, but the body does not render under a mismatched host.

### 7. Nothing cross-app here is statically verifiable in this repo

`OCA\OpenRegister\*` is absent from Hermiq's CI environment, so `LeafDescriptor`,
`ObjectService`, the flow engine, the broker and the tool facade are all unanalysable by
PHPStan/Psalm here. Every claim above was read from the sibling checkouts and must be
re-confirmed live rather than by a green analyzer.

## Recommendation

**Build the missing abstraction; build nothing hydra-specific in code.**

- **Drop the bespoke forge path entirely.** No `hermiq.setForgeIssueLabel`, no
  `ForgeLabelService`, no `lib/Service/Forge/`. It would have required a third named
  exception to `nc-native-tools`' "remote systems route through OpenConnector" rule — a
  rule that already says what the pivot says. Make that rule's read-only scope explicit
  instead.
- **Specify argument-scoped tool grants** as the missing abstraction: an exact-id grant
  narrowed by declared argument constraints (a pinned value, or a closed value set),
  resolving to the same catalog tool id, enforced *before* dispatch. This is generic — any
  multi-target tool in any app gets it — and it is what turns `openregister.runFlow` from
  "run everything" into one grantable capability. Put the closed label vocabulary on the
  grant as data, so hydra can change its state machine without a Hermiq release.
- **Specify owner attribution** on an agent-queued flow run, refusing rather than
  defaulting when the owner cannot be resolved.
- **Enforce both at `FacadeToolInvoker`**, which is already the single dispatch chokepoint
  carrying the guardrail, approval-gate, dry-run and search-tool short-circuits. One more
  check there; no second invocation path, and no OpenRegister change required.
- **Seed the hydra part as two objects** — the Hydra Triage agent and the triage agentflow
  — via the established `Seed*.php` repair-step pattern.
- **Keep three code items as pre-existing-defect fixes**: the branch-base merge (accepted
  on observed render, not a clean merge), the leaf surface parity on both halves, and the
  visible empty-context state.

## Risks Uncovered

- **The upstream half is unbuilt.** With no OpenConnector node or tool, the command flow's
  terminal step has nothing to call. The flow must be specified to stop having recorded its
  proposed label rather than degrade.
- **An unconstrained flow grant is a full-instance capability.** Until argument-scoped
  grants exist, `openregister.runFlow` should not be granted to any agent at all. Worth
  flagging beyond this change.
- **Grants that resolve to nothing are silent unless someone asks.** If the chain head's
  schema slugs differ from the ones the seeded grants name, the triage agent loads with zero
  tools. `resolvesToNothing()` exists but only helps if the seed path or a startup check
  calls it.
- **The context allowlist is fail-closed, so an under-specified schema yields an empty
  context** and an agent that answers confidently about nothing — a correctness risk created
  by a security property working as designed.
- **`HermiqAgentNode::execute()` swallows a failed turn to an empty string**, so any flow
  consuming a triage node cannot distinguish "nothing to say" from "the run failed".

## Next Steps

Proceed to specs as **three MODIFIED deltas against existing capabilities**, not new ones:
`agent-object-leaf` (leaf surfaces, empty-context disclosure, plus the seeds and the
attribution rule), `agent-tool-governance` (the argument-scoped grant grammar, its
enforcement, flow-run attribution, and the one command grant), and `nc-native-tools` (the
remote-write rule made explicit). No second discovery is needed; the remaining unknowns are
ownership questions for the hydra and openconnector repos, not feasibility questions here.
