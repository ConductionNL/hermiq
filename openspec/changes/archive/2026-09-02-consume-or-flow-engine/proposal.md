# Proposal: consume-or-flow-engine

> Archived 2026-09-02 (finish-agentflow-retirement). The contribution shipped and
> lives on: `HermiqAgentNode` still provides the agent step, registered through
> `HermiqFlowNodeListener`. The `agentflow` object store this change consumed is
> retired. Flows live in OpenRegister's native flow store (REQ-FA-002), the
> resolver is gone, and the schemas left the register descriptor in v0.30.0.

## Summary

Make hermiq a CONSUMER of OpenRegister's flow engine instead of the owner of a
sixth one. hermiq contributes its agent step as a flow node and a resolver so
OpenRegister's engine and worker can load and run hermiq's agentflows.

## Why

hermiq shipped `GraphExecutor` — its own graph walker — because there was no
shared engine to contribute a step to. There is now (ADR-022, ADR-065): the
OpenRegister flow engine handles branching, joins, waits, run persistence,
triggers and the trace. hermiq keeps the one thing only it can do — run an
agent turn — and lets the shared engine do the rest.

## What Changes

- **`HermiqAgentNode`** — the agent step as an OpenRegister `IFlowNode`,
  contributed through `RegisterFlowNodesEvent`. The turn is unchanged: the same
  proven `ScheduleService::runAgentAsOwner()` the old executor called. It runs
  once per item, so a fanned-out collection is each handled, and honours the
  same `agentId` / `prompt` / `output` / `expectJson` config the builder authors.
- **`HermiqFlowResolver`** — an `IFlowResolver`: turns an agentflow id into a
  flow document, loads a run's subject object, and lists which enabled
  agentflows are wired to a fired event. This is what lets OpenRegister's worker
  and triggers run hermiq's flows.
- Both registered through OpenRegister's events, guarded on the flow-engine
  classes existing so an instance whose OpenRegister predates them still boots.

## Out of scope (this change)

- **Deleting `GraphExecutor` and repointing the graph builder's run path at the
  OpenRegister engine.** That is the payoff, but ripping out a working, shipped
  executor and rewiring `GraphController` is the risky step and deserves its own
  change, once this contribution has proven itself. Until then the two coexist:
  the builder still runs graphs through `GraphExecutor`, and the shared engine
  can run the same agentflows through the contributed node.
- **Migrating old agentflow node types.** Graphs authored against the old
  executor use `condition` / `router` / `object-write` node types; the shared
  engine uses `openregister.switch` etc. plus `hermiq.agent-step`. A codemod for
  stored agentflows belongs with the GraphExecutor deletion.

## Note on CI

hermiq's CI does not install OpenRegister, so classes referencing
`OCA\OpenRegister\Service\Flow\*` cannot be statically analysed or unit-tested
there — the same standing limitation as `HermiqToolProvider`. This change is
verified live on an instance where both apps are installed.
