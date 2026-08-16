---
kind: code
---

## Why

Every chat turn spawns a fresh `claude` CLI process. Measured 2026-08-16 inside
`hermiq-llm-runner`, six consecutive `claude --version` invocations:

```
949 ms   366   350   320   353   340
```

**~950 ms cold, ~340 ms warm — and the warm figure recurs on every turn**, because
each `/run` spawns a new process. Against a 250 ms harness budget that is over on its
own, before the model, the MCP handshake or Nextcloud are counted.

A warm-up at session start is worth ~600 ms **once**. It cannot touch the ~340 ms,
which is process spawn: Node runtime start, module graph load, config and credential
resolution, MCP client construction. Only keeping a process alive removes it.

### Where it sits in the whole cost

| Component | Measured | Fixed by this change? |
|---|---|---|
| `claude` process spawn | ~340 ms/turn | **yes** |
| MCP `initialize` | ~1000–1200 ms | partly — a live session need not re-handshake |
| MCP `tools/list` | ~960–990 ms | partly — same |
| Nextcloud request | ~46 ms | no, and it does not need to be |
| Model inference | ~2.2 s | no |

The handshake numbers are the interesting part: they are per-`/run` today **because
the process is per-`/run`**. A persistent process holding an initialised MCP client
removes the spawn *and* the re-handshake, which together are ~2.4 s of the ~4.1 s
harness overhead.

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
- **Expected saving**: ~340 ms/turn spawn, plus up to ~2 s of re-handshake where a
  live MCP client can be retained. Neither is measured yet; both are the point of the
  change and MUST be measured before the change is called done.
- **Not in scope**: the model's own ~2.2 s, and Nextcloud's ~46 ms.

## Sequencing

This change should land **after** `tool-scope-security-default`. Pooling a process
that holds a stale, over-broad tool set is strictly worse than spawning a correct one
each time.
