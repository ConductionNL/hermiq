## Context

The harness budget is 250 ms. Measured 2026-08-16 END-TO-END — request start to the
first MCP packet in the Apache access log, on a quiet machine — the harness costs
**~5 s per turn**. The components, and which change addresses each:

| Component | Measured | Addressed by |
|---|---|---|
| `claude` session init | **~4 s/turn** | **this change** |
| MCP handshake (`initialize` + `tools/list`, 4 HTTP requests) | ~1 s/turn | **this change** (a live client need not re-handshake), plus `mcp-output-schema-payload` (openregister#2529) already cut the payload 76% |
| — a `GET /api/mcp/run` → 401 inside that handshake | 1 wasted round trip per turn | incidental; worth its own one-line fix |
| Tool schemas in context | ~101,000 → ~600–1,900 tok | `tool-scope-security-default` (already reduced) |
| Tool execution itself | **< 1 s** | nothing — it was never the cost |
| Nextcloud request | ~110 ms (session auth) | nothing — within budget |
| Hermiq engine orchestration | **~0–10 ms** | nothing — already free |
| Model inference | **~5 s per tool round trip** | nothing — it is the model |

⚠️ **An earlier revision of this table was wrong, and wrong flatteringly.** It put
process spawn at ~340 ms because it timed `claude --version`, which short-circuits
before config, credential resolution or MCP client construction. The 311 MB CLI
bundle costs ~4 s to bring to a usable session. Always measure the interval the
change must collapse, not a sub-command that skips the work.

The honest arithmetic: this change removes ~5 s of a ~14 s single-tool turn. What
remains is the model's own inference, **once per tool round trip** — one call means
two passes, two calls mean three. That is agentic tool use, not overhead, and the
250 ms budget was never going to apply to it.

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

### D0 — The mechanism is `--input-format stream-json`, and it is PROVEN, not assumed

The CLI is invoked `-p` (print) today, which is one-shot: it initialises, answers, and
exits, so the init is paid per turn. `--input-format stream-json` with
`--output-format stream-json` is documented as "realtime streaming input" and keeps
the process alive accepting further user messages on stdin.

Verified 2026-08-16 inside `hermiq-llm-runner` (probe spawning the real binary):

```
t+  308 ms   message written to stdin
t+ 1676 ms   {"type":"system","subtype":"init","session_id":"71ceba16-…","tools":[…]}
             ^ the process is READY, and stays alive for subsequent messages
```

**~1.7 s of init with no MCP server configured**; the ~4 s observed end-to-end is
that plus the governed MCP handshake against Nextcloud. Both are paid ONCE by a
process that survives the turn — which is the entire saving this change exists for.

Messages are newline-delimited JSON:
`{"type":"user","message":{"role":"user","content":[{"type":"text","text":"…"}]}}`

### D0.1 — ⚠️ The per-run bearer token is the real blocker, and it needs a decision

`ProviderFactory::mintGovernedRunToken()` mints a token PER RUN, and
`buildGovernedMcpConfig()` writes it into a 0600 scratch file the CLI reads **at
startup**. A process that outlives its turn therefore holds a token that has been
consumed, so its next tool call fails — and it fails as "the model has no tools",
which is the same silent symptom this codebase has already been bitten by twice.

Pooling CANNOT be implemented without resolving this. The options, none free:

1. **Token lifetime follows the pooled process**, not the run. Simplest, and it
   widens the window a leaked token is useful in — the thing per-run minting exists
   to narrow.
2. **Re-handshake per turn with a fresh token.** Keeps token semantics exactly as
   they are and still saves the ~4 s of session init, but gives back the ~1 s of MCP
   handshake — i.e. ~4 s of the ~5 s, at no governance cost.
3. **Refresh the config file and make the CLI re-read it.** Depends on CLI behaviour
   we have not verified; do not assume it re-reads.

**Option 2 is the recommended starting point**: it banks 80% of the saving while
leaving the security contract untouched, and it can ship before the harder question
is settled. Option 1 is a governance change and belongs in its own proposal with the
security owner in the room.

### D0.2 — ⚠️ An auth failure does not fail fast; it backs off for minutes

Observed in the same probe, with a deliberately invalid key:

```
401 → api_retry attempt 1..6, delay 594 → 1089 → 2405 → 4922 → 9826 → 19611 ms
      "max_retries": 10
```

A pooled process that loses its credential does not die — it retries with
exponential backoff toward ten attempts, holding a pool slot and a caller for
minutes while emitting only `api_retry` events. Any pool MUST treat a repeated
`api_retry` with `error_status: 401` as terminal for that process and reap it,
rather than waiting for the CLI to give up. This is separate from the health check
in D3 and is not covered by it: the process is alive and responsive, it is simply
never going to succeed.

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
