# Design: parallel delegation

## Context

LLPhant runs the tool calls of one model response one after another inside the PHP request, so
several `hermiq.delegateAgent` calls can never overlap. Concurrency has to come from the parts
running somewhere else while the parent waits.

## Decisions

- **D1. One tool call carries the parallel set.** `hermiq.delegateAgents` takes
  `parts: [{agentId, task}]`. Each part passes the same gates as `delegate()`, in order, before any
  part starts; every part that passes counts toward `delegation.maxFanOut`; a refused part returns
  its refusal in its slot and the others still run.
- **D2. Parts run as queued jobs.** Each passing part is written as a child run and queued as a new
  `lib/BackgroundJob/DelegatedRunJob.php` (`QueuedJob`) carrying the parent's acting user, the
  organisation, the anchor and the parent run id. The job calls
  `ScheduleService::runAgentAsOwner(forceOwner: true)` exactly as the inline path does, so identity,
  isolation and the audit tree are unchanged. At most `delegation.maxParallel` parts (default 3) are
  queued at once; the rest wait for a free slot.
- **D3. The parent waits with a deadline and never deadlocks.** The parent polls its children's
  run state. A part not claimed by a worker within `delegation.parallelGraceSeconds` (default 10)
  is claimed by the parent and run inline, so an instance with one background worker, or a chat
  turn served in a web request, behaves as today: one part after another. A part still running at
  `delegation.parallelTimeoutSeconds` (default 600) returns `delegation_timeout` in its slot.
- **D4. Results come back in call order.** The tool result is a list in the order of `parts`, each
  slot either the part's final text and effective reach or its error envelope.

## Risks

- A claim race between a worker and the parent: the claim is a compare-and-set on the child run's
  state, so a part runs once; the loser skips it.
