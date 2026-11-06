## 1. Parallel delegation

- [ ] 1.1 Add `DelegationService::delegateMany(string $callerAgentId, array $parts): array` that runs
      the existing gates per part in order, counts passing parts toward fan-out, and returns one slot
      per part in call order (REQ-DLGPAR-001, design D1, D4). Verify: unit tests for all allowed, one
      refused, and fan-out reached part way.
- [ ] 1.2 Add `lib/BackgroundJob/DelegatedRunJob.php` (`QueuedJob`) that claims its child run with a
      compare-and-set on the run state, then calls `ScheduleService::runAgentAsOwner(forceOwner: true)`
      with the parent's acting user, organisation and anchor, and writes the delegation audit entry
      (design D2). Verify: unit tests for a claimed run, an already claimed run, and a failing run.
- [ ] 1.3 Let the parent wait on its children with the grace period, the inline fallback and the
      timeout of REQ-DLGPAR-002, reading `delegation.maxParallel`, `delegation.parallelGraceSeconds`
      and `delegation.parallelTimeoutSeconds` from app config with the defaults of design D2 and D3.
      Verify: unit tests with a fake clock for no worker, a worker that claims, and a timeout.
- [ ] 1.4 Register the tool `hermiq.delegateAgents` next to `hermiq.delegateAgent` in
      `lib/Mcp/HermiqToolProvider.php` and `lib/Service/Engine/FacadeToolInvoker.php`, with a
      description that tells the model to use it for independent parts. Verify: the tool list test
      includes it with its parameter schema.

## 2. Chat steps

- [ ] 2.1 Show each part as its own step under "Toolstappen" on the chat page, with the target agent
      and its state, as the single delegation step drawn on HmChat. Verify: Vitest test of the
      step list with two parts.

## 3. Verification

- [ ] 3.1 `@spec` tags on every new method (gate 16); every scenario carries a reason-bearing
      `@e2e exclude` (gate 19).
