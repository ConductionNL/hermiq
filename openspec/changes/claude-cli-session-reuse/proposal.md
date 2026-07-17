---
kind: code
depends_on: []
---

# Proposal: claude-cli-session-reuse

## Summary

> *"We can't have a 9 second upkeep on every message we send. So a running conversation should be
> a running claude session."*

Today it cannot be. `exapp/llm-runner/src/runner.js` creates a **throwaway scratch directory per
turn** (`fs.mkdtempSync`, line 118), points the child's `HOME` **and** `cwd` at it (lines 124,
139), and `rm -rf`s it on **every** exit path (`cleanup(scratch)` at lines 144, 175, 182 →
`fs.rmSync(dir, {recursive: true, force: true})`, line 228). The Claude CLI stores sessions under
`$HOME/.claude/projects/<escaped-CWD>/<session-id>.jsonl` — so **every session is created and
deleted on every single message.** Compounding it, `buildPrompt()` (line 50) flattens the *entire*
message history into one string every turn, so input grows linearly with conversation length and
nothing can be prompt-cached.

This change maps a **conversation UUID → a stable session** with a persistent per-conversation
home, and sends **only the new user message** instead of the whole history.

## Motivation

Measured: `llm` ≈ **9s for a two-character answer** (spawn + round-trip, no meaningful
generation), ≈17s for a normal reply. Process spawn is ~1–2s of that and **will remain** — this
change does not eliminate the spawn.

The CLI in the runner is **2.1.211** and supports sessions: `--session-id <uuid>` ("Use a specific
session ID for the conversation (must be a valid UUID)"), `-c/--continue`, `--resume`,
`--fork-session`. The capability is there; the runner throws away the state it needs.

**A session is addressed by (HOME, CWD, session-id) — not by session-id alone.** Storage layout,
observed directly on the host: `$HOME/.claude/projects/<escaped-CWD>/<session-id>.jsonl`, where
the project directory is the CWD with `/` escaped to `-`. This is why `--continue` is documented
as *"Continue the most recent conversation **in the current directory**"*. The runner discards
**both** halves of that address. **A reader who stabilises only `HOME` will get a silent cold
start on every turn — exactly the failure this change exists to remove.**

This **supersedes the rejected "pre-warm on chat open" idea**. Pre-warming was rejected on the
mechanics: `claude -p` is one-shot (spawn → answer → exit), so there is no resident process to
warm, and context cannot be precomputed because it depends on the unsent message. Session reuse is
the real mechanism — it does not keep a process alive, it keeps the *conversation state* alive so
the next one-shot spawn resumes instead of re-reading everything.

## Affected Projects

- [ ] Project: `hermiq` — `exapp/llm-runner/src/runner.js`: a stable per-conversation session home
  used as both `HOME` and `cwd`, replacing the per-turn mkdtemp + cleanup; `--session-id` on the
  first turn and `--resume` afterwards; `buildPrompt()` sends only the new message on a resumed
  turn. `exapp/llm-runner/src/server.js` + `exapp/llm-runner/src/providers.js`: thread the
  conversation identity through. `lib/Service/Llm/ProviderFactory.php`: send it.

## Scope

### In Scope

- A **stable, persistent session home per conversation**, used as **both `HOME` and `cwd`**.
- Map the hermiq conversation UUID to the CLI `--session-id`; `--resume` on subsequent turns.
- Send **only the new user message** on a resumed turn; the full history only on a cold start.
- Thread a conversation identity (and its tenant/user) from `ProviderFactory` through
  `/run` into `runner.js` — the payload carries none today.
- A TTL/eviction policy for session homes, and per-conversation + per-tenant isolation.
- Cold-start fallback whenever the session home is missing, evicted, or unusable.

### Out of Scope

- **Eliminating the process spawn.** `claude -p` remains one-shot; ~1–2s of spawn stays.
- **Promising a specific speedup.** The saving is **unmeasured**. It must be measured during apply
  — see design.md and the acceptance criteria.
- **Pre-warming.** Rejected on the mechanics (above). Superseded by this change.
- **The `context` phase (26–62s).** That is `session-context-performance`. This change attacks the
  `llm` phase only. The two are complementary and independent.
- **Making the runner stateful/authoritative.** Sessions are a **cache**. Hermiq's
  `conversation`/`message` objects remain the source of truth.

## Approach

1. **Address the session properly.** A per-conversation directory — e.g.
   `/var/lib/llm-runner/sessions/<tenantOrUser>/<conversationUuid>/` — used as **both** `HOME` and
   `cwd`, so the transcript lands deterministically at
   `<home>/.claude/projects/<escaped-cwd>/<conversationUuid>.jsonl`.
2. **First turn:** `--session-id <conversationUuid>` + the full prompt. **Subsequent turns:**
   `--resume` + only the new user message.
3. **Cold-start fallback:** a missing/evicted/corrupt home means "no cache" → send the full
   history and re-establish the session. Never a truncated conversation.
4. **Evict:** TTL sweep. The cache is disposable by design.

## New Dependencies

None. A writable persistent volume for the session root is a deployment requirement, not a package.

## Impact

- `exapp/llm-runner/src/runner.js` — the per-turn scratch lifecycle (lines 118, 124, 139, 144,
  175, 182, 226-228) and `buildPrompt()` (line 50). This is the change's centre.
- `exapp/llm-runner/src/server.js` — `/run` destructures `{provider, model, messages,
  credentialEnv}` (line 110) and calls `run({provider, model, messages, credentialEnv})` (line
  127). **No conversation identity exists in the payload today**; it must be added and validated.
- `exapp/llm-runner/src/providers.js` — `args: (model) => ['-p', '--output-format', 'json', …]`
  (line 128) takes only `model`; it must take the session parameters.
- `lib/Service/Llm/ProviderFactory.php` — builds the runner payload (~line 1048; the `messages`
  flattener at lines 1163-1174) and must send the conversation identity.
- Runner deployment — a persistent volume and a disk budget where there was none.

## Cross-Project Dependencies

None outside this repo. The runner is hermiq's own sidecar ExApp; the `/run` interface is internal
to hermiq (PHP producer, Node consumer, one deployable pair) and is consumed by no other app.

## Risks

### Risk 1: Persisting transcripts where nothing was retained before
**Severity:** High — **Mitigation:** the scratch dir was throwaway **by design**: no host paths,
nothing retained. This change retains conversation transcripts inside the container. Isolation is
therefore load-bearing: the home MUST be per-conversation **and** per-tenant/user, **never
shared**; a TTL/eviction policy MUST bound retention; and the transcript is a **cache** of data
hermiq already holds authoritatively, never a new system of record. See design.md.

### Risk 2: Silent cold start — only `HOME` is stabilised, not `cwd`
**Severity:** High — **Mitigation:** a session is addressed by **(HOME, CWD, session-id)**.
Stabilising `HOME` alone leaves `cwd: scratch` (line 139) changing per turn, so the CLI looks in a
different `projects/<escaped-CWD>/` every time, finds nothing, and cold-starts — while *appearing*
to work. The change would ship with zero benefit and no error. Both halves must be stable, and a
test must assert that turn 2 **resumed** rather than merely succeeded.

### Risk 3: The per-run bearer token leaks into a persistent directory
**Severity:** High — **Mitigation:** a governed-MCP config carries a **per-run** token. If such a
file is written into a now-persistent home and left there, a short-lived secret gains
indefinite lifetime on disk. It MUST still be written per turn at `0600` and removed after the
turn, exactly as its per-run lifetime requires — persistence of the *session* must not become
persistence of the *token*. **Note:** no MCP config exists in `exapp/llm-runner/` at HEAD in this
worktree (`grep -rn "mcp" exapp/llm-runner/src/` returns nothing) — this is a **forward
constraint** on `cli-runner-governed-mcp-and-egress`, whichever lands second.

### Risk 4: Concurrent turns on one conversation corrupt the session
**Severity:** Medium — **Mitigation:** two turns resuming one session concurrently is a real race
(a user double-sends; a retry overlaps). The design must serialise per conversation or detect and
cold-start. Unspecified behaviour here means a corrupt transcript.

### Risk 5: The runner container is replaceable; sessions vanish
**Severity:** Low — **Mitigation:** by design. Sessions are a cache; a replaced container is a
cache miss, and a cache miss is a cold start, which is exactly today's behaviour. This is only a
risk if the design ever treats the session as authoritative — which it must not.

### Risk 6: Unbounded disk growth
**Severity:** Medium — **Mitigation:** transcripts accumulate per conversation where nothing
accumulated before. TTL/eviction plus a disk budget; eviction must be safe at any time because a
cold start is always a valid fallback.

## Rollback Strategy

Revert the commit: the runner returns to per-turn mkdtemp + full-history prompts — today's
behaviour, with the 9s floor. Because sessions are a **cache** and hermiq's `conversation`/
`message` objects remain authoritative, **no conversation data is lost by reverting**; the next
turn simply cold-starts. Any session directories left on the volume are orphaned and can be deleted
outright — nothing reads them after a revert. No schema, no migration, no persisted state in
hermiq itself.

## Capabilities

### New Capabilities

- `claude-cli-session-reuse`: the conversation→session mapping, the (HOME, CWD, session-id)
  addressing contract, the session-home isolation and eviction policy, and the cold-start
  fallback guarantee.

### Modified Capabilities

- `agent-engine-port`: a resumed turn sends only the new user message rather than the whole
  flattened history.
