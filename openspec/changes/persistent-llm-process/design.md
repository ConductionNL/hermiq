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

### D0.0 — ⚠️ Reaching `init` is NOT answering. Measured 2026-08-17.

The evidence above stops at `init`, and a pool cannot be built on a process that
has merely *started*. Re-probed against the real binary with a real credential
(`exapp/llm-runner/src/poolprobe.js`, two turns down one process):

```
POOL PROBE OK init=1604ms turn1=2948ms turn2=4346ms
             secondTurnAnswered=true  contextCarried=true
             turn1="ACK"  turn2="ZARQUON"
```

| | |
|---|---|
| init | 1604 ms (confirms the ~1.7 s above) |
| turn 1, incl. init | 2948 ms |
| **turn 2, same process** | **1398 ms** |

**A second turn is answered, and it costs 53% less** because the init is already
paid. That is the saving, now measured rather than argued.

⚠️ **The first attempt at this probe deadlocked at its 180 s ceiling with
`init=-1`** — it waited for the `init` event *before* writing turn 1. The CLI does
not necessarily announce itself before it has input. **"Spawn, then wait to be
greeted" is a hang, not a protocol**: write the first frame immediately.

### D0.05 — 🔴 CONVERSATION STATE CARRIES ACROSS TURNS, and that decides the key

`contextCarried=true` above is not incidental. Turn 2 asked *"what word did I ask
you to remember?"* with **no history re-sent**, and the process answered
`ZARQUON`. The session remembers.

This cuts both ways and constrains the whole design:

- It is **why pooling is cheap** — a pooled turn need not re-send history at all.
- It is **the leak**. Hermiq flattens and sends the FULL history every turn
  (`buildPrompt()`), so a pooled process would receive history it already holds,
  and a process shared between two callers would carry one caller's words into
  the other's turn.

Therefore **the pool key must be the CONVERSATION, not (agent, user)** as D1
currently states. A persistent session *is* a conversation; keying any wider
shares memory across turns that were never meant to see each other. Whichever key
is chosen, hermiq must stop re-sending history on a pool hit or the model sees it
twice.

This supersedes D1 and is the reason task 2's "prove no conversation state crosses
turns" cannot be satisfied as written: **it does cross, by design of the
transport.** The requirement becomes *contain* it, not *prevent* it.

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

#### Verified 2026-08-17 — the token is worse than "short-lived", it is CONSUMED

`RunTokenService` was read rather than assumed:

- **Lifetime**: TTL = `RUNNER_TIMEOUT_MS` (default 120 s) + 30 s slack.
- **Consumption**: `consume()` is called in a `finally` **when the run closes** —
  success, error or timeout alike — "so later use is rejected".

So a pooled governed process does not merely hold an *aging* token, it holds a
**dead** one the moment its first turn ends. Its next tool call fails, and it fails
as *"the model has no tools"* — the silent shape this codebase has already been
bitten by twice.

⚠️ **This also undercuts option 2 as written.** "Re-handshake per turn with a fresh
token" needs the CLI to pick up a *new* token mid-session, which is exactly the
unverified re-read that option 3 depends on. Options 2 and 3 are not independent:
without a proven config re-read, option 2 has no mechanism.

**Consequence: governed turns cannot be pooled today.** Ungoverned (text-only)
turns carry no token and no MCP config and are pooled without touching any of
this — that is the slice that can ship. Governed pooling is blocked on a
governance decision (option 1) or a verified re-read (option 3), and should not be
attempted by inference from either.

#### Option 3 is DEAD. Measured 2026-08-17: the CLI does not re-read its config.

Probed rather than assumed (`exapp/llm-runner/src/mcpreadprobe.js`): two local MCP
stub servers exposing differently-named tools, config pointed at A, one turn
taken, config rewritten to point at B under the live process, second turn taken
asking for B's tool.

```
MCP REREAD PROBE OK  swappedAt=7652ms
  serverA_methods=[initialize|notifications/initialized|tools/list|tools/call|…]
  serverB_methods=[]
  REREAD=false
```

Server A took a full handshake **and a real `tools/call`** — the positive control,
so the stub is a working MCP server and the discriminator is sound. After the
swap, server B received **nothing at all**. The config is read once at startup.

Which server was contacted was observed AT THE SERVER, deliberately: a model asked
"what tools do you have" narrates a plausible answer, and that answer is not
evidence.

**Therefore option 2 has no mechanism** — its per-turn re-handshake required
exactly this re-read — **and option 1 is the only route to governed pooling.**
Decision taken 2026-08-17 by the product owner: **adopt option 1**, the run
token's lifetime follows the pooled process. The cost is accepted explicitly: this
widens the window a leaked token is useful in, which is what per-run minting
existed to narrow. It is bounded by the pool's idle/hard-cap lifetime, so that cap
IS the token's security parameter and must be set deliberately, not inherited.

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
