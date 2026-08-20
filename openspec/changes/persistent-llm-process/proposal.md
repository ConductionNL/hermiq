---
kind: code
---

## Why

Every chat turn spawns a fresh `claude` CLI process, and **that costs ~5 s, not the
~340 ms this proposal originally claimed.**

### ⚠️ The original figure was measured with the wrong instrument

The first version of this document said "~950 ms cold, ~340 ms warm" on the strength
of six `claude --version` invocations. **`--version` short-circuits before the CLI
does any of the work a turn needs** — it never reads config, never resolves a
credential, never constructs an MCP client. Re-measured 2026-08-16 in
`hermiq-llm-runner`:

```
node -e "0"          79 ms      the runtime floor
claude --version    292 ms      what this proposal used to quote
claude --help       730 ms      more of the bundle, still no session
real session init  ~4000 ms     OBSERVED end-to-end (see below)
claude binary  311,175,440 bytes (a 297 MB module tree to parse)
```

The honest number comes from the Apache access log, which timestamps every MCP
packet the CLI sends back into Nextcloud. For a single-tool turn:

```
t+0.0s   POST /api/chat/send starts
t+4.0s   first MCP POST            <- 4s before the CLI makes ANY contact
t+5.0s   handshake done            <- 4 requests (one of them a wasted GET -> 401)
t+10.0s  readDocument tool call    <- 5s of model inference to decide
t+14.6s  turn ends                 <- 4.6s of model inference on the result
```

**~5 s per turn is harness**: ~4 s of CLI session init plus ~1 s of MCP handshake.
The tool execution itself is **sub-second** — it was never the expensive part.

### What this changes about the case for pooling

| Component | Was claimed | Measured |
|---|---|---|
| `claude` process start | ~340 ms/turn | **~4 s/turn** |
| MCP `initialize` + `tools/list` | ~1000–1200 ms | ~1 s/turn (4 HTTP requests) |
| Hermiq engine orchestration | — | **~0–10 ms** (not a target) |
| Nextcloud request | ~46 ms | ~110 ms session-auth (not a target) |
| Model inference | ~2.2 s | ~5 s **per tool round trip** (not recoverable) |

The recoverable slice is therefore **~5 s of every turn, not ~340 ms** — an order of
magnitude larger than the case this change was originally argued on, and the largest
single cost in the stack that engineering can remove. A persistent process holding an
initialised MCP client removes both halves at once.

⚠️ Two measurement rules this episode earns, because both were violated here:
**`--version` is not a session**, and **the number that matters is the one observed
end-to-end, not the one a convenient sub-command reports.**

### Where it sits in the whole cost

| Component | Measured | Fixed by this change? |
|---|---|---|
| `claude` session init | **~4 s/turn** | **yes — this is the prize** |
| MCP handshake (4 HTTP requests) | ~1 s/turn | **yes** — a live session need not re-handshake |
| — of which a `GET /api/mcp/run` → **401** | 1 wasted round trip, every turn | incidental; fix separately |
| Tool execution itself | **< 1 s** | no, and it never needed to be |
| Nextcloud request | ~110 ms (session auth) | no |
| Hermiq engine orchestration | ~0–10 ms | no — already free |
| Model inference | ~5 s **per tool round trip** | no — inherent to agentic tool use |

The handshake is per-`/run` today **because the process is per-`/run`**. A persistent
process holding an initialised MCP client removes the session init *and* the
re-handshake — together ~5 s of a ~14 s single-tool turn, i.e. **more than a third of
the wall clock, before the model has done anything different.**

What it does NOT remove is the extra inference pass each tool result requires: a
turn that calls one tool runs the model twice, a turn that calls two runs it three
times, at ~5 s each. That is the shape of agentic tool use, not overhead, and no
amount of pooling touches it.

## What Changes

- **A pooled, long-lived CLI process per (agent, user) session**, owned by
  `hermiq-llm-runner`, replacing spawn-per-turn.
- **Turns are dispatched to a live process** over its existing stdio protocol rather
  than by starting a new one.
- **Idle processes are reaped** on a timeout, and the pool is bounded.
- **A cold path is retained**: if no process is available, or a pooled one is
  unhealthy, the turn spawns as it does today. The change must not be able to make a
  turn fail that would previously have succeeded.

## The risks this has to answer, because they are the reason it was not done already

**Credential and identity leakage between turns.** A live process holds resolved
credentials. Hermiq's personal-scope contract says a subscription credential serves
its owner only, so a process MUST be keyed by user and MUST NOT be reused across
users. A pool keyed only by agent would be a cross-user credential leak.

**State bleed.** A fresh process guarantees a clean context. A reused one must be
proven to carry no conversation state between turns, or one user's prompt can
influence another's answer.

**Failure containment.** A crashed or wedged process currently affects one turn.
Pooled, it affects every turn routed to it until it is reaped.

**Governance must not weaken.** The MCP config, the allowlist and the
`--disallowedTools` set are currently constructed per run. A pooled process holds
whichever set it started with, so **a change to an agent's grants must invalidate its
pooled processes** — otherwise a revoked tool stays live in memory.

That last one is the sharp edge, and it interacts directly with
`tool-scope-security-default`: a tool-scoping change that a running process does not
observe is a governance hole, not a caching optimisation.

## Capabilities

### New Capabilities
- `llm-runtime-latency`: how a turn reaches a model process, what may be reused
  between turns, and what MUST NOT be.

## Impact

- **Code**: `exapp/llm-runner` — process pool, lifecycle, health, invalidation.
- **Expected saving**: **~5 s/turn** — ~4 s of `claude` session init plus ~1 s of MCP
  re-handshake. Both are now measured end-to-end (request start → first MCP packet in
  the Apache access log), not inferred from a sub-command. The change MUST be
  re-measured the same way before it is called done: the acceptance test is the
  request-start-to-first-MCP-packet gap, because that is the interval this change
  exists to collapse.
- **Not in scope**: the model's ~5 s per tool round trip, Nextcloud's ~110 ms, and
  Hermiq's ~0–10 ms of orchestration. None of the three is the problem.

## Sequencing

This change should land **after** `tool-scope-security-default`. Pooling a process
that holds a stale, over-broad tool set is strictly worse than spawning a correct one
each time.
