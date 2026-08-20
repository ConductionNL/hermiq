# Tasks

## 0. Sequencing and the blocking decision

- [ ] Land `tool-scope-security-default` first
- [ ] Decide the token strategy (design D0.1) before writing any pool code

Acceptance criteria:
- Pooling a process that holds a stale, over-broad tool set is strictly worse than spawning a correct one each time.
- The per-run bearer token is written into the MCP config the CLI reads AT STARTUP, so a process that outlives its turn holds a consumed token. Recommended start is D0.1 option 2 — re-handshake per turn with a fresh token, banking ~4 s of the ~5 s at zero governance cost. Option 1 (token lifetime follows the process) widens the leak window per-run minting exists to narrow and needs the security owner, not this change.
- A wrong choice here fails as "the model has no tools" — silent, and already the shape of two bugs in this codebase.

## 1. Pool the process

- [ ] Switch the anthropic provider from `-p` one-shot to `--input-format stream-json` + `--output-format stream-json`
- [ ] Add a process pool in `exapp/llm-runner` keyed by (agent, user)
- [ ] Dispatch a turn as a newline-delimited `{"type":"user","message":{…}}` frame to a live process
- [ ] Bound the pool and reap idle processes

Acceptance criteria:
- The mechanism is verified, not assumed: a probe against the real binary reached `{"type":"system","subtype":"init"}` at **1676 ms** and the process stayed alive for further stdin frames (design D0).
- A second user is never routed to another user's process. A live process holds resolved credentials, and the personal-scope contract makes that a leak rather than a cache hit.
- An unattributable turn takes the cold path.

## 2. Keep the guarantees a fresh process gave for free

- [ ] Prove no conversation state crosses turns, using a distinctive token
- [ ] Invalidate an agent's pooled processes when its grants change, immediately — not at idle reap
- [ ] Reap a process on repeated `api_retry` with `error_status: 401`
- [ ] Fall back to spawning on any pool miss, unhealthy process, or dispatch failure

Acceptance criteria:
- A revoked tool is not callable on the next turn. Scoping tools is worthless if a warm process ignores the scope.
- ⚠️ An auth failure does NOT kill the CLI: measured, a 401 produced `api_retry` with delays 594 → 1089 → 2405 → 4922 → 9826 → 19611 ms toward `max_retries: 10`. A pooled process would hold its slot and its caller for minutes while looking alive and responsive, so a liveness probe cannot detect it (design D0.2).
- Pool failure is never user-visible, and IS recorded — a pool that silently never hits is indistinguishable from one that works.

## 3. Measure

- [ ] Measure request-start → first MCP packet, before and after, same instance, same prompt
- [ ] Expose the pool hit rate
- [ ] Report the result even if it disappoints

Acceptance criteria:
- The acceptance measurement is the **request-start-to-first-MCP-packet gap in the Apache access log** — currently ~4 s — because that is the interval this change collapses. It is observable without instrumenting the CLI.
- ⚠️ Do NOT measure `claude --version`. The original version of this proposal claimed a ~340 ms saving on exactly that basis; `--version` short-circuits before config, credential resolution and MCP client construction. The real figure is ~4 s, an order of magnitude larger. Measure the interval the change must collapse, never a sub-command that skips the work.
- A zero hit rate is visible as zero and cannot be reported as a latency improvement.
- Precedent: `mcp-output-schema-payload` cut a payload 76% and moved latency 20-25%, because the cost was not where it looked. Assume the same is possible here.

## 4. Incidental, cheap, separate

- [ ] Stop the CLI's `GET /apps/hermiq/api/mcp/run` → 401 round trip

Acceptance criteria:
- Every governed turn currently includes a `GET` to a POST-only endpoint whose 401 is discarded. It is small next to the 4 s, but it is pure waste on every turn and does not depend on any decision in task 0.
