## Context

The harness budget is 250 ms. Measured 2026-08-16, the harness costs ~4.1 s on a turn
that calls no tools. The components, and which change addresses each:

| Component | Measured | Addressed by |
|---|---|---|
| `claude` process spawn | ~340 ms/turn (~950 ms cold) | **this change** |
| MCP `initialize` | ~1000–1200 ms | **this change** (a live client need not re-handshake) |
| MCP `tools/list` | ~960–990 ms | **this change**, plus `mcp-output-schema-payload` (openregister#2529) already cut the payload 76% |
| Tool schemas in context | ~101,000 → ~600–1,900 tok | `tool-scope-security-default` (already reduced) |
| Nextcloud request | ~46 ms | nothing — it is within budget |
| Model inference | ~2.2 s | nothing — it is the model |

The honest arithmetic: even if this change removes spawn and re-handshake entirely,
**the floor is the model's own ~2.2 s**. The 250 ms budget applies to the harness,
not to the answer, and this change is the largest remaining harness item.

## Goals / Non-Goals

**Goals:**
- Remove per-turn process spawn.
- Retain an initialised MCP client across turns of the same session.
- Measure the result, including if it disappoints.

**Non-Goals:**
- Model latency.
- Nextcloud request time — 46 ms, already within budget.
- A shared/global process. See D1.

## Decisions

### D1 — Key the pool by (agent, user), never by agent alone

A live process holds resolved credentials, and the personal-scope contract says a
subscription credential serves its owner only. Keying by agent alone would route one
user's turn through another user's credential.

This is the decision that most limits the hit rate — a single-user instance pools
well, a many-user one less so — and it is not negotiable for a cheaper cache.

### D2 — Invalidate on grant change, not on idle timeout

A pooled process holds the tool set it started with. If invalidation waits for an
idle reap, a revoked tool stays callable for as long as the process stays busy —
precisely the case where it matters most.

This is the interaction with `tool-scope-security-default`. Scoping tools is
pointless if a warm process ignores the scope, so that change lands first.

### D3 — Cold path always available

Pooling trades "every turn gets a clean process" for latency. It must not also trade
reliability: any pool miss, unhealthy process or dispatch failure falls back to a
spawn, and the failure is recorded.

Recording matters as much as the fallback. A pool that never hits and silently falls
back looks identical to one that is working, and would be reported as a success.

### D4 — Measure before and after, on the same instance

The predicted saving is ~340 ms spawn plus up to ~2 s handshake. Neither is measured.

The precedent is direct: `mcp-output-schema-payload` cut the tool payload by 76% and
moved handshake latency by only 20–25%, because the cost was schema enumeration
rather than serialisation. The same surprise is available here — if the ~1 s
`initialize` is dominated by work that a live client still repeats, pooling will
disappoint. That must be reported, not smoothed.

## Seed Data (ADR-001)

**None.** No OpenRegister schemas are introduced or modified.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Process pool lifecycle | **Imperative** | Process supervision in the runner; not a lifecycle, aggregation, derived field, notification, relation or widget. |
| Pool bounds and idle timeout | **Declarative** | Configuration, not code. |

## Risks / Trade-offs

**State bleed is silent.** An answer subtly shaped by a previous prompt looks like a
normal answer. The test uses a distinctive token precisely because a plausible-looking
response proves nothing.

**Failure blast radius grows.** A wedged process today costs one turn; pooled, it
costs every turn routed to it until reaped. Health checks and the cold-path fallback
are what bound it.

**Hit rate may be low in practice.** Keyed by (agent, user) with an idle timeout, a
sparse multi-user instance may rarely hit. That would make this change nearly
worthless — which is why the hit rate is a required metric rather than an
implementation detail.
