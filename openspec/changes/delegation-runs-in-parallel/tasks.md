## 1. Parallel delegation

- [x] 1.1 Add `DelegationService::delegateMany(string $callerAgentId, array $parts): array` that runs
      the existing gates per part in order, counts passing parts toward fan-out, and returns one slot
      per part in call order (REQ-DLGPAR-001, design D1, D4). Verify: unit tests for all allowed, one
      refused, and fan-out reached part way.
      Built: every part is gated (pending parts counted toward fan-out) before any runs, with one
      slot per part in call order (`DelegationPart`, `DelegationSlots`). Until 1.2 and 1.3 land, the
      passing parts run inline one after another, which is REQ-DLGPAR-002's no-worker behaviour.
- [x] 1.2 Add `lib/BackgroundJob/DelegatedRunJob.php` (`QueuedJob`) that claims its child run with a
      compare-and-set on the run state, then calls `ScheduleService::runAgentAsOwner(forceOwner: true)`
      with the parent's acting user, organisation and anchor, and writes the delegation audit entry
      (design D2). Verify: unit tests for a claimed run, an already claimed run, and a failing run.
      Built: each passing part is a `DelegatedRun` OpenRegister object (register 0.55.0, schema
      0.1.0); `DelegatedRunStore::claim()` moves it from queued to running under an exclusive
      Nextcloud lock on the record, so only one claim wins. `DelegatedRunJob` calls
      `DelegationService::runStoredPart()`, which takes up the parent run's frame (depth and
      ancestry, `DelegationContext::resume()`), runs the part as the parent's acting user, audits it
      under the parent run and records done or failed. Tests: `DelegatedRunStoreTest`,
      `DelegatedRunJobTest`, `DelegationServiceTest` (worker run at the parent's depth, run once,
      failing run).
- [x] 1.3 Let the parent wait on its children with the grace period, the inline fallback and the
      timeout of REQ-DLGPAR-002, reading `delegation.maxParallel`, `delegation.parallelGraceSeconds`
      and `delegation.parallelTimeoutSeconds` from app config with the defaults of design D2 and D3.
      Verify: unit tests with a fake clock for no worker, a worker that claims, and a timeout.
      Built: `ParallelDelegation` hands out at most `delegation.maxParallel` parts at once, polls
      them on `PollClock`, claims and runs inline a part still queued after the grace period, and
      times out a part still open at the timeout (`delegation_timeout`, audited as `timeout`).
      `delegateMany()` uses it for two or more passing parts; one part, `maxParallel` 1 or no acting
      user still run inline. Tests: `ParallelDelegationTest` (fake clock: overlap, no worker, race,
      timeout, failure, max parallel) and `DelegationServiceTest`.
- [x] 1.4 Register the tool `hermiq.delegateAgents` next to `hermiq.delegateAgent` in
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
