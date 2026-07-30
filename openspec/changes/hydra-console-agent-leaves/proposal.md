---
kind: code
depends_on: [hydra-register-data-plane, hydra-console-openbuild-app]
---

# Proposal: hydra-console-agent-leaves

## Summary

Hydra — Conduction's bash + Docker + `claude`-CLI app-building orchestrator — mirrors its
pipeline state into Nextcloud as an OpenRegister register (`hydra`) rendered by a virtual
OpenBuild app, `hydra-console`. Those pages are today read-only glass: an operator can
*see* that a cycle stalled but cannot ask anything about it. This change puts Hermiq on
those pages. It corrects two wrong declarations on the existing agent leaf, seeds a
read-only **Hydra Triage** agent and a triage **agentflow** as data, and gives that agent
exactly one command capability — an approval-gated, argument-scoped grant to invoke the
ONE flow that writes a forge label.

It ships **no forge code**. An earlier draft of this change proposed a
`hermiq.setForgeIssueLabel` MCP tool backed by a `ForgeLabelService`. That is dropped: the
label write is an OpenConnector-backed endpoint or flow node owned by the console change,
and what remains in Hermiq is the generic abstraction that makes commanding it *safe* —
argument-scoped, attributed tool grants.

## Motivation

The product-owner constraint for this whole chain is: API calls go through OpenConnector
nodes, and porting hydra should create no code at all — just flows. Where code seems
necessary, the right response is to name the flow abstraction that is missing and specify
THAT.

Applying that rule here changed the shape of the change. Hydra's operator loop is: read
the forge issue, read the pipeline logs, decide, hand-edit a label to move the state
machine. The console collapses the reading half. The deciding half is what an agent reads
well and a human reads slowly. The acting half — writing the label — looked like it needed
a Hermiq tool, and the grounding sweep showed it does not: OpenRegister already exposes
`openregister.runFlow` as an agent tool, so "an agent commands a pipeline" is already a
flow-shaped problem. What it *lacks* is any way to grant one flow rather than all of them,
any way to carry a command's parameters, and any owner on the resulting run. Those three
gaps are the real work.

## Affected Projects

- [ ] Project: `hermiq` — corrects the leaf's surface declaration and its invisible
  empty-context state (both pre-existing defects), adds argument-scoped grant resolution
  and enforcement plus flow-run attribution, and seeds two objects: the Hydra Triage agent
  and the triage agentflow. Ships no forge tool and no forge service.
- [ ] Project: `hydra` — consumes only. Its register schemas must carry
  `x-openregister-agent-context` allowlists; its console must place the leaf and own the
  OpenConnector-backed command endpoint. No hydra code is edited here.
- [ ] Project: `openconnector` — must eventually contribute the endpoint or flow node the
  command flow's terminal step calls. Not edited here; recorded as a prerequisite.

## Scope

### In Scope

- Completing the in-flight merge of `origin/development` into `feat/agent-graph-builder`,
  so this work sits on a branch carrying BOTH the agent graph builder AND the current leaf
  — specifically the `mount(el, props)` cross-Vue-major escape hatch (hermiq#44 / #47,
  v0.1.94) and the OR-flow-engine consumer (hermiq#35). Without the mount fix the leaf body
  does not render at all under a Vue-major-mismatched host.
- Fixing the leaf surface-vocabulary mismatch: the PHP `LeafDescriptor` declares
  `['detail-page', 'single-entity']` while `src/integration-leaf.js` declares **no**
  `surfaces` key at all while shipping `widget: CnAgentRunsWidget` with
  `defaultSize: { w: 4, h: 4 }`. The JS half advertises a dashboard-placeable widget the
  PHP half says is not dashboard-placeable, and the console is dashboard-first.
- Making the fail-closed empty-context state VISIBLE on the leaf, so a bounded answer and
  an ungrounded one are distinguishable.
- The missing flow abstraction, as generic capability: **argument-scoped tool grants**
  (pin a multi-target tool to one target and a closed value set, enforced at Hermiq's
  existing dispatch chokepoint) and **owner attribution** on an agent-queued flow run.
- Seed data only, for the hydra-specific part: one read-only Hydra Triage agent, and one
  triage `agentflow` (trigger on a new finding → `hermiq.agent-step` → branch on empty →
  command step).

### Out of Scope

- **Any bespoke forge/label/issue tool or service in Hermiq.** Dropped by the pivot.
- Defining the `hydra` register, its schemas, or their allowlists —
  `hydra-register-data-plane`.
- The console manifest, its pages, dashboards, action buttons, and the command endpoint
  that performs the forge write — `hydra-console-openbuild-app` (`hydra-console-commands`).
- The OpenConnector endpoint or flow node itself, and its credential handling.
- The label vocabulary's MEMBERS. They are hydra's, resolved from its state-machine
  definition and declared as data on the grant; this change specifies only that the list
  is closed and enforced.
- The personal-scope `cli` execution-mode runner — `hydra-exec-personal-cli-runner`.
- Any agent that *writes* hydra objects. Hydra owns its own state; the console commands it
  only through the label channel.
- A discriminated `type: "agent"` manifest action in nextcloud-vue — the console uses the
  interim `api-call` recipe.
- Autonomous label writes. Every command goes through the approval gate here.

## Approach

Four pieces, in order, only two of which are code.

1. **Base the branch.** Finish the in-flight merge, then accept it on an *observed* leaf
   render — not on a clean merge.
2. **Fix the two declaration defects.** Widen and make explicit the leaf surfaces on both
   halves; surface the empty-context state in text.
3. **Build the missing abstraction.** Extend grant resolution with an argument-scoped form,
   enforce its constraints at `FacadeToolInvoker` (the one dispatch chokepoint that already
   holds the guardrail, approval and dry-run short-circuits), and attribute an
   agent-queued flow run to the acting owner.
4. **Seed the hydra part as data.** One agent object, one agentflow object, both idempotent
   repair steps in the established `Seed*.php` pattern. Neither is code; both are editable
   by an operator without a release.

## New Dependencies

None. No new package, no new credential store, no new HTTP client. Hermiq makes no
outbound forge call at all under this design.

## Impact

- `lib/Service/Engine/ToolGrantResolver.php` — argument-scoped grant parsing and resolution.
- `lib/Service/Engine/FacadeToolInvoker.php` — one more pre-dispatch short-circuit,
  alongside the four it already has, plus owner injection on a flow-queueing invocation.
- `lib/Listener/RegisterAgentLeafListener.php` + `src/integration-leaf.js` — `surfaces`
  corrected on both halves.
- `src/components/CnAgentChatTab/` — the empty-context notice.
- `lib/Repair/` — two new idempotent seed steps, registered in `appinfo/info.xml`.
- **Not touched:** no new controller, no new service package, no `lib/Service/Forge/`, no
  new descriptor in `HermiqToolProvider`.
- Every agent in the fleet keeps its catalog unchanged: no tool is added, so nothing is
  acquired implicitly. The command reaches exactly one agent, by one narrowed grant.

## Cross-Project Dependencies

This change declares `depends_on: [hydra-register-data-plane, hydra-console-openbuild-app]`
and both live in a **different repository** (`apps-extra/hydra`). OpenSpec cannot resolve or
gate a cross-repo dependency, so the ordering is a human contract:

- From `hydra-register-data-plane` this change consumes the `hydra` register slug, the
  schema slugs its read grants name, and the `x-openregister-agent-context` allowlists that
  make the leaf's context non-empty. Grants naming a schema that does not exist resolve to
  nothing — `ToolGrantResolver::resolvesToNothing()` is what surfaces that loudly.
- From `hydra-console-openbuild-app` this change consumes the console's pages (its
  `Detail pages reserve a slot for the hermiq agent leaf` requirement), its `api-call`
  actions, and — critically — the command endpoint from `hydra-console-commands`
  (`The command endpoint performs the forge write server-side`).
- `hydra-exec-personal-cli-runner` depends on this one and not the reverse; its
  `pipeline-run-attribution` capability is where owner semantics are defined chain-wide, and
  this change's attribution requirement is written to agree with it.

Also relevant: OpenRegister classes are absent from Hermiq's CI environment — nothing under
`OCA\OpenRegister` is statically analysable here — so every cross-app assertion in this
change is verified live, never by the analyzer.

## Risks

### Risk 1: The OpenConnector half does not exist yet
**Severity:** High — **Mitigation:** OpenConnector today registers no MCP tool provider and
contributes no flow node or resolver, so the command flow's terminal step has nothing to
call. This is stated plainly rather than papered over: the seeded flow is specified to
terminate having recorded its proposed label when the command node is absent, so the change
lands useful (triage, chat, bounded context, attribution) and the command arms itself only
when its upstream half ships. The alternative — writing the call in Hermiq — is precisely
what the pivot forbids.

### Risk 2: A granted flow runner is a grant to run every flow
**Severity:** High — **Mitigation:** This is the defect that made the abstraction necessary.
`openregister.runFlow` takes its target from a `flowId` argument, so an exact-id grant today
reaches every flow on the instance. Argument-scoped grants pin it to one, enforced before
dispatch at the same chokepoint that already refuses on guardrails and approval. Without
this, the honest options were "grant the agent everything" or "write a bespoke tool" — both
worse.

### Risk 3: An agent-queued flow run is unattributed
**Severity:** High — **Mitigation:** `FlowMcpToolProvider::runFlow()` queues with no acting
user, so `triggeredBy` is null and `HermiqAgentNode` falls back to an empty owner. For a flow
whose terminal step commands a build pipeline that is an unattributed pipeline command. The
attribution requirement refuses the invocation rather than defaulting the owner.

### Risk 4: Prompt injection reaches the command
**Severity:** High — **Mitigation:** Pipeline object text is written by other agents and is
untrusted by construction. Three independent layers: the grant pins the flow (an injected
"run flow X" is refused), the grant's closed value set pins the label (an injected "set label
admin" is refused before dispatch), and the approval gate puts a human on every command with
the flow, target and label disclosed. The executing endpoint validates the vocabulary again,
independently.

### Risk 5: The leaf renders but its chat is useless because context is empty
**Severity:** Medium — **Mitigation:** The allowlist is fail-closed by design: no allowlist
means empty context, never the whole object. That is correct security and a bad demo. Fixed
by ordering (change 1 ships the allowlists) plus the requirement that an empty resolved
context is surfaced in text rather than silently producing a confidently wrong answer.

### Risk 6: The branch base merge conflicts or silently drops the mount fix
**Severity:** Medium — **Mitigation:** The merge is the first task and its acceptance is
behavioral, not textual: the leaf must be observed rendering its body on a live console
detail page. Merge-textual success is not accepted as proof.

### Risk 7: A flow consumer cannot tell "no answer" from "failed"
**Severity:** Medium — **Mitigation:** `HermiqAgentNode::execute()` swallows turn failures to
an empty string. The seeded triage flow is specified to branch explicitly on empty and never
reach its command step on one — so a failed turn cannot become a pipeline command.

## Rollback Strategy

Each piece reverts independently and none holds data hostage.

- The argument-scoped grant form: reverting narrows nothing that was previously granted; an
  argument-scoped grant string simply stops resolving, which `resolvesToNothing()` reports
  loudly rather than silently.
- The seeded agent and flow: ordinary OpenRegister objects. Delete them, or set the agent
  `active: false` / the flow `enabled: false` to disable without losing configuration.
  Removing the repair steps stops recreation on upgrade.
- The surface fix and the empty-context notice: reverting narrows where the leaf appears and
  removes a text state; neither can break a page.
- The branch base: the foundation, not independently revertible — rolling it back means
  rolling back the change as a whole.
- No forge state is ever affected by a rollback, because Hermiq never writes to a forge.

## Open Questions

- **The OpenConnector command surface.** Whether the label write arrives as an OpenConnector
  MCP tool, an OpenConnector-contributed flow node, or a plain endpoint the flow calls is not
  yet decided and is not Hermiq's to decide. The requirements here are written against "an
  OpenConnector-backed endpoint or flow node" so they hold under any of the three.
- **The parameter channel.** `openregister.runFlow` carries only a subject object today, so a
  command's label cannot travel with the invocation. Whether that is closed by widening the
  flow tool's input schema (OpenRegister's call) or by the command flow deriving the label
  from the object it runs on is open; the grant's closed value set is specified either way.
- **Per-organisation seeding.** The seeds follow the existing single-object, matched-by-name
  precedent. Multi-tenant hydra deployments would want one triage agent per organisation;
  deferred until such a deployment exists.
