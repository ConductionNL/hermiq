---
kind: code
---

# Proposal: hermiq-flow-rename

## Summary

Hermiq stops calling flows "graphs". Routes, components, the store, the
registry, manifest pages and every user-facing label become "flow", matching
what the thing actually is everywhere else in the fleet.

## Why

There is one flow engine and it is OpenRegister's. Hermiq's "graph" was never a
different entity — same table (`oc_openregister_flows`), same endpoints, same
engine, same `app='hermiq'` rows. It was a display name, and the only thing it
ever added was a second word for one concept.

That cost is not hypothetical. In one session the word alone produced:

- a page routed at `/graphs` reading a duplicate `hermiq/agentflow` OBJECT
  mirror while the engine ran the native flow rows — two stores, because two
  names made two things feel reasonable;
- a `POST /apps/hermiq/api/graph/run` endpoint that was never registered in
  `appinfo/routes.php`, so the Run button posted into a 404 indefinitely;
- an editor that read `nodes[].type` and `edges[].source` — keys a flow does not
  have — and drew a blank canvas without erroring.

Each of those is a place where "graph" let hermiq drift from the thing it was
supposedly just renaming. If the word costs this much for developers who wrote
it, it is not doing users any favours either.

## What Changes

- **Routes**: `/graphs` → `/flows`, `/graphs/:id` → `/flows/:id`.
- **Components**: `GraphBuilder` → `FlowBuilder`, `GraphSidebar` → `FlowSidebar`,
  `GraphIndex` → `FlowIndex`, `RunGraphDialog` → `RunFlowDialog`.
- **Store**: `useGraphEditorStore` → `useFlowEditorStore`, `graph` → `flow`,
  `graphs` → `flows`, `canvasEdges`/`startNodeIds`/`endNodeIds` keep their names.
- **Manifest**: page ids `GraphIndex`/`GraphDetail` → `FlowIndex`/`FlowDetail`,
  titles "Graphs"/"Graph" → "Flows"/"Flow"; registry keys follow.
- **Strings**: every `t('hermiq', …)` mentioning a graph is retranslated.

## Redirects

The old routes redirect to the new ones rather than 404ing. Hermiq flow URLs are
pasted into issues and run logs across the Hydra tooling, and a dead link is a
worse outcome than a permanent redirect that costs one route entry.

## Impact

- **Affected specs**: `flow-authoring`
- **Affected code**: 11 files under `src/` naming "graph", plus
  `src/manifest.json` and `src/registry.js`
- **Not affected**: `lib/Flow/HermiqAgentNode.php` and `HermiqWorkloadNode.php`
  are step-type implementations and already say "flow"; `SeedHydraTriageFlow.php`
  is dealt with separately (it seeds the `agentflow` object mirror)
- **i18n**: every renamed string needs re-extraction; `npm run test:l10n` gates it

## Capabilities

### Modified Capabilities
- `flow-authoring` — the vocabulary of hermiq's flow surface
