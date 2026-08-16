# Tasks

## 0. Sequencing

- [ ] Land `tool-scope-security-default` first

Acceptance criteria:
- Pooling a process that holds a stale, over-broad tool set is strictly worse than spawning a correct one each time.

## 1. Pool the process

- [ ] Add a process pool in `exapp/llm-runner` keyed by (agent, user)
- [ ] Dispatch a turn to a live process over its existing protocol
- [ ] Bound the pool and reap idle processes

Acceptance criteria:
- A second user is never routed to another user's process. A live process holds resolved credentials, and the personal-scope contract makes that a leak rather than a cache hit.
- An unattributable turn takes the cold path.

## 2. Keep the guarantees a fresh process gave for free

- [ ] Prove no conversation state crosses turns, using a distinctive token
- [ ] Invalidate an agent's pooled processes when its grants change, immediately — not at idle reap
- [ ] Fall back to spawning on any pool miss, unhealthy process, or dispatch failure

Acceptance criteria:
- A revoked tool is not callable on the next turn. Scoping tools is worthless if a warm process ignores the scope.
- Pool failure is never user-visible, and IS recorded — a pool that silently never hits is indistinguishable from one that works.

## 3. Measure

- [ ] Record spawn and handshake cost before and after, same instance, same prompt
- [ ] Expose the pool hit rate
- [ ] Report the result even if it disappoints

Acceptance criteria:
- The reported saving is a measured difference between a cold turn and a pooled turn, not an estimate of what spawn costs.
- A zero hit rate is visible as zero and cannot be reported as a latency improvement.
- Precedent: `mcp-output-schema-payload` cut a payload 76% and moved latency 20-25%, because the cost was not where it looked. Assume the same is possible here.
