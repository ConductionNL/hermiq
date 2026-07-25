# Tasks: consume-or-flow-engine

- [x] `HermiqAgentNode` (IFlowNode) — agent turn per item, unchanged
      runAgentAsOwner, agentId/prompt/output/expectJson.
- [x] `HermiqFlowResolver` (IFlowResolver) — resolveFlow / resolveSubject /
      flowsForTrigger over agentflow objects.
- [x] Listeners + registration, guarded on the flow-engine classes existing.
- [x] Live-verified on 8080: `hermiq.agent-step` is in OpenRegister's palette;
      the resolver loads a real stored agentflow; an unknown id resolves null.
- [ ] Delete GraphExecutor and repoint the builder's run path at the shared
      engine (follow-up — risky, own change).
- [ ] Codemod stored agentflows onto the shared node types (with the deletion).
