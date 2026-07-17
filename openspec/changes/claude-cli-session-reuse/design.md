# Design: claude-cli-session-reuse

## Architecture Overview

### The mechanism, precisely

**A Claude CLI session is addressed by `(HOME, CWD, session-id)` — not by `session-id` alone.**

Storage layout, observed directly on the host:

```
$HOME/.claude/projects/<escaped-CWD>/<session-id>.jsonl
```

The project directory is the **CWD with `/` escaped to `-`**. A real example on this host:
`/home/rubenlinde/.claude/projects/-home-rubenlinde-nextcloud-docker-dev-workspace-server-apps-extra/`
containing `<uuid>.jsonl` files. This is also why `--continue` is documented as *"Continue the most
recent conversation **in the current directory**"* — the CWD is part of the address, not incidental.

### What the runner throws away

`exapp/llm-runner/src/runner.js` discards **both halves** of that address, every turn:

| Line | Code | Effect |
|---|---|---|
| 118 | `const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'llm-runner-'))` | a new directory per turn |
| 124 | `HOME: scratch` | half the address, thrown away |
| 125 | `TMPDIR: scratch` | |
| **139** | **`cwd: scratch`** | **the other half, thrown away** |
| 144, 175, 182 | `cleanup(scratch)` on **every** exit path | |
| 226-228 | `fs.rmSync(dir, { recursive: true, force: true })` | `rm -rf` |

So the session is created at `<scratch>/.claude/projects/-tmp-llm-runner-XXXX/<id>.jsonl` and
`rm -rf`'d moments later. **Every message is a cold start.**

> **The trap.** Stabilising `HOME` alone is not enough. With `cwd` still a fresh mkdtemp, the CLI
> looks under a *different* `projects/<escaped-CWD>/` every turn, finds nothing, and cold-starts —
> **while appearing to work**. No error, no warning, zero benefit. A test must assert that turn 2
> **resumed**, not merely that it succeeded.

### Compounding: the prompt grows every turn

`buildPrompt()` (line 50) flattens the **entire** message history into one string
(`ROLE: content\n\n…`) every turn. Input grows linearly with conversation length, and because the
prefix is rebuilt inside a fresh session each time, **nothing can be prompt-cached**. Turn 20
re-sends turns 1-19.

### Target

```
BEFORE (every turn)                      AFTER
mkdtemp → HOME+cwd=scratch               home = /var/lib/llm-runner/sessions/<tenant>/<convUuid>/
claude -p  <FULL HISTORY>                HOME=home, cwd=home   (BOTH — the full address)
rm -rf scratch                           turn 1: claude -p --session-id <convUuid>  <full history>
                                         turn N: claude -p --resume <convUuid>      <new message only>
                                         (home persists; TTL-evicted; cache only)
```

### Verified at HEAD (do not assume otherwise)

- CLI in the runner is **2.1.211**; supports `--session-id <uuid>` ("must be a valid UUID"),
  `-c/--continue`, `--resume`, `--fork-session`.
- **`/run` carries no conversation identity.** `server.js:110` destructures `{provider, model,
  messages, credentialEnv}`; `server.js:127` calls `run({provider, model, messages,
  credentialEnv})`. `runner.js:112` has the same signature. The identity must be **added** and
  validated — it does not exist to be read.
- `providers.js:128` — `args: (model) => { const base = ['-p', '--output-format', 'json']; return
  model ? base.concat(['--model', model]) : base; }`. Takes **only `model`**; must take the
  session parameters.
- `lib/Service/Llm/ProviderFactory.php` builds the payload (~line 1048) and flattens `messages`
  (lines 1163-1174) — the PHP side that must send the identity.

## API Design

### `POST /run` (runner, internal)

**Request (added fields):**
```json
{
  "provider": "anthropic",
  "model": "claude-opus-4-8",
  "messages": [{ "role": "user", "content": "…" }],
  "credentialEnv": { "CLAUDE_CODE_OAUTH_TOKEN": "YOUR_TOKEN_HERE" },
  "sessionKey": {
    "conversationId": "00000000-0000-0000-0000-000000000000",
    "tenant": "00000000-0000-0000-0000-000000000000"
  }
}
```

`sessionKey` is **optional**. Omitted ⇒ today's behaviour exactly (per-turn scratch, full history,
cleanup) — so the runner stays backward-compatible and any non-conversation caller (title
generation, evals) keeps working untouched.

**Validation is mandatory, not cosmetic.** `conversationId` becomes a **path segment** and a
`--session-id` value. It MUST be validated as a UUID and rejected otherwise. An unvalidated value
here is a path-traversal primitive (`../../`) *and* an argv-injection primitive. Same for `tenant`.

**Response:** unchanged (`{text, toolCalls, usage}`). A `resumed: true|false` field SHOULD be added
— without it, the silent-cold-start failure (Risk 2) is unobservable from the caller, and the
acceptance criteria below cannot be asserted end-to-end.

## Database Changes

**None.** No tables, no columns, no OpenRegister schema, no migration class. The conversation UUID
that keys a session **already exists** — it is the `Conversation` object's UUID. Nothing new is
persisted in hermiq. The only new persistent state is on the runner's filesystem, and it is a
**cache** (below).

## Nextcloud Integration

- Controllers: none
- Services: `lib/Service/Llm/ProviderFactory.php` — sends `sessionKey`
- Mappers/Entities: none
- Events/Hooks: none. (A TTL sweep MAY be an NC background job, but the runner owning its own cache
  eviction is simpler and keeps the cache's lifecycle inside the component that owns it.)

## Security Considerations

This is the section that matters. **The scratch dir was throwaway by design** — no host paths,
nothing retained. Persisting it is a real posture change and must be paid for explicitly.

### 1. The isolation boundary

The session home MUST be keyed by **conversation AND tenant/user**. **Never a shared home.**

```
/var/lib/llm-runner/sessions/<tenantOrUser>/<conversationUuid>/
```

used as **both `HOME` and `cwd`**, so the transcript lands deterministically at
`<home>/.claude/projects/<escaped-cwd>/<conversationUuid>.jsonl`.

- A shared home would put one tenant's transcripts in another's `--continue` reach — `--continue`
  resumes *"the most recent conversation in the current directory"*, so a shared CWD is a
  cross-tenant read primitive, not a theoretical one.
- The tenant segment is not decoration: conversation UUIDs are unguessable, but defence in depth
  means the boundary is structural (a directory the other tenant's runs never point at), not
  merely probabilistic.
- Directory permissions MUST NOT be world-readable.
- Both path segments MUST be validated as UUIDs before use — see API Design.

### 2. Retention and TTL

The container now retains conversation transcripts. Therefore:

- A **TTL/eviction policy is mandatory**, not a nice-to-have — it is what bounds retention.
- Eviction MUST be safe at any moment, because a cold start is always a valid fallback. This is
  what makes the policy simple: evict aggressively; correctness never depends on a hit.
- A disk budget bounds growth (Risk 6).
- Retention is bounded by TTL, and the data is a copy of what hermiq already holds — so this adds
  no new *class* of retained data, only a new *location*. That location must be inside the same
  trust boundary as the runner's credentials and no wider.

### 3. The per-run token must not become persistent

A governed-MCP config carries a **per-run bearer token**. It MUST still be:

- written **per turn**, at `0600`, and
- **removed after the turn**.

Persistence of the *session* must never become persistence of the *token*. A short-lived
credential that gains indefinite lifetime because someone stopped `rm -rf`ing its directory is
exactly the kind of regression this change could cause by omission — the old code deleted it as a
side effect of deleting everything; the new code must delete it **on purpose**.

> **Verified discrepancy:** **no MCP config exists in `exapp/llm-runner/` at HEAD in this
> worktree.** `grep -rn "mcp" exapp/llm-runner/src/` returns nothing;
> `openspec/changes/cli-runner-governed-mcp-and-egress/` exists as a change but is not implemented
> here. This constraint is therefore a **forward constraint** binding on whichever of the two
> lands second. It is recorded here because the interaction is easy to miss when they land
> independently.

### 4. Credentials stay in env

Unchanged and worth restating: credentials are passed via `childEnv` (`selectCredentialEnv`), never
on argv or stdin. Adding `--session-id`/`--resume` to argv MUST NOT change that. The
`conversationId` on argv is not a secret; a token never goes there.

### 5. The env allowlist stays closed

`childEnv` is built from `PATH`/`HOME`/`TMPDIR`/`LANG` + `PASSTHROUGH_ENV` + credentials, and
"NOTHING the caller supplied beyond that". Threading `sessionKey` MUST NOT widen that — it changes
what `HOME`/`cwd` *point at*, not what the caller can inject into the env.

## Correctness

### Cold-start fallback — the guarantee

**A missing, evicted, corrupt, or unusable session home means "cache miss" ⇒ send the FULL history
and re-establish the session.** It MUST NEVER mean "send only the new message into a session that
does not exist" — that silently truncates the conversation to one turn, and the model answers
confidently with no history. **A silently truncated conversation is worse than a slow one.**

The decision must be made from **observed state**, not from an assumption: check the transcript
exists at the resolved address before choosing `--resume`. "We sent turn 1, so turn 2 can resume"
is not sound — the container may have been replaced and the TTL may have evicted it.

### Sessions are a cache; hermiq is authoritative

The runner container is **replaceable**. Hermiq's `conversation`/`message` objects are the source
of truth (180 conversations / 289 messages on the reference instance). Consequences:

- Every turn MUST be reconstructible from hermiq alone. Losing the entire session volume costs
  latency, never data.
- The runner MUST NOT be the only place any turn exists.
- A replaced container is a cache miss ⇒ cold start ⇒ today's behaviour. That is a **non-event**.

### Concurrency

Two turns resuming one session concurrently is a real race (double-send, overlapping retry). The
session file is a single append-target; two writers is unspecified. Options: serialise per
conversation (a per-conversation lock in the runner), or detect an in-flight turn and cold-start
the second into a throwaway. Either is acceptable; **doing nothing is not** — the failure is a
corrupt transcript, which then poisons every subsequent resume for that conversation. The chosen
mechanism must also survive a crashed turn holding a stale lock.

### Only-the-new-message

On a resumed turn, `buildPrompt()` MUST emit **only the new user message**. On a cold start it MUST
emit the full history exactly as today. This is the same function serving two modes, and the
mode MUST be driven by the observed resume decision above — never by a flag the caller sets
independently, which would let PHP and the runner disagree and truncate the conversation.

## File Structure

```
exapp/llm-runner/src/
  runner.js      # MODIFIED (centre): session home replaces mkdtemp+cleanup (118,124,139,144,175,182,226-228);
                 #                    HOME and cwd both = session home; buildPrompt() (50) gains resume mode
  server.js      # MODIFIED: /run accepts + validates optional sessionKey (110, 127)
  providers.js   # MODIFIED: args() (128) takes session params, not just model
lib/Service/Llm/
  ProviderFactory.php   # MODIFIED: sends sessionKey (~1048; messages flattener 1163-1174)
```

## Seed Data

**Not applicable — this change introduces no new schemas and no new entities.**

ADR-001/ADR-016 require seed data for every schema a change introduces or modifies. This change
introduces none:

- The conversation UUID that keys a session is the **existing** `Conversation` object's UUID. No
  property is added to `Conversation` or `Message`; their seed data is untouched.
- The only new persistent state is the runner's session directory — a **cache**, on the runner's
  filesystem, outside OpenRegister entirely. Seeding a cache is a contradiction: a pre-seeded
  session home would be indistinguishable from a stale one, and the cold-start fallback exists
  precisely so an absent cache is the normal, correct state. **On a fresh install the session root
  is empty and every conversation cold-starts — which is exactly today's behaviour.**
- The change is measured against **existing live data**: `conversation` (180 rows) and `message`
  (289 rows) on the reference instance provide the multi-turn conversations needed to observe a
  resume. A synthetic single-turn fixture cannot exercise the feature at all, since the entire
  benefit appears on turn 2.

**Net seed-data delta: none.**

## Declarative-vs-imperative decision

**Not applicable in the ADR-031 sense.** This change touches no lifecycle/status workflow, no
aggregations, no derived fields, no notifications, no relations, and no widgets. It edits a Node
sidecar's process/filesystem handling and one PHP payload builder. No register JSON is touched and
no declarative dialect is added or modified — `kind: code` throughout.

Worth stating because "map conversation → session" sounds like it could be modelled declaratively:
it cannot and should not. The mapping is a **cache key computed at dispatch**, not a persisted
relation. Modelling it as an OpenRegister relation (a `sessionId` on `Conversation`, say) would
make a disposable cache look authoritative — the precise misconception the Correctness section
exists to prevent — and would create a persisted pointer to state that a container replacement can
invalidate at any moment.

## Migration Plan

No data migration. Deployment needs one new thing: a **writable persistent volume** for the session
root, plus a disk budget. Without it the runner still works — every turn is a cache miss, i.e.
today's behaviour — which is a useful property: the volume is a performance dependency, not a
correctness one.

Rollout: ship with `sessionKey` **omitted** by `ProviderFactory` (runner behaviour identical to
today), then enable it once the volume is in place and the resume path is verified live. Rollback:
revert; orphaned session directories can be deleted outright.

## Risks / Trade-offs

### [Only `HOME` is stabilised] → silent cold start, zero benefit, no error
Covered above (Risk 2). **Both `HOME` and `cwd`.** Assert `resumed: true` on turn 2 — a test that
only asserts "turn 2 succeeded" passes against unfixed code and proves nothing.

### [Persisting transcripts] → a real posture change, paid for with isolation + TTL
Covered in Security Considerations 1-2. The boundary is per-conversation **and** per-tenant, never
shared; retention is bounded by TTL; the data is a cache of what hermiq already holds.

### [The saving is unmeasured] → do not promise a number

**This design promises a mechanism, not a speedup.** What is known:

- `llm` ≈ **9s for a two-character answer** — spawn + round-trip with essentially no generation.
- `llm` ≈ **17s** for a normal reply.
- Process spawn is **~1–2s** of that and **remains** — `claude -p` is still one-shot.

What is *not* known: how much of the remaining ~7s floor is re-reading the flattened history
versus fixed round-trip cost. Session reuse removes the re-read and enables prompt caching, but
**the size of that saving has not been measured.** It **MUST be measured during apply**, before
and after, on the reference instance. If the measured saving is negligible, that is a finding to
report — not a reason to quietly keep the change. The acceptance criteria are therefore written as
*structural* assertions (1 message not N; `llm` does not grow with N) plus a *measurement
obligation*, never as a target number.

### Rejected: pre-warm the CLI on chat open

**Superseded by this change.** Rejected on the mechanics: `claude -p` is one-shot (spawn → answer
→ exit), so there is no resident process to warm — a pre-warmed CLI has already exited by the time
the user types. And context cannot be precomputed because it depends on the **unsent** message.
Session reuse is the real mechanism: it keeps no process alive, it keeps the *conversation state*
alive so the next one-shot spawn resumes instead of re-reading everything.

### Rejected: `--continue` as the primary mechanism

`--continue` resumes *"the most recent conversation in the current directory"* — implicit, and
ambiguous the moment a directory holds more than one session or two turns interleave. `--session-id`
(turn 1) + `--resume <id>` (turn N) addresses the session **explicitly** by the conversation UUID
we already have. `--continue` remains available but is not the contract.

### Rejected: one shared session home for all conversations

Faster to build, and a cross-tenant read primitive via `--continue`. Non-starter (Security 1).

### Rejected: make the runner authoritative for conversation state

It is a replaceable container. Hermiq's objects are the source of truth. Any design where losing
the volume loses data is wrong.
