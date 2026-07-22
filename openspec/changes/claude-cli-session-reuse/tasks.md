# Tasks: claude-cli-session-reuse

<!-- Verified at HEAD, do not re-derive:
     * CLI 2.1.211 in the runner supports `--session-id <uuid>` ("Use a specific session ID for the
       conversation (must be a valid UUID)"), `-c/--continue` ("in the current directory"),
       `--resume`, `--fork-session`.
     * A session lives at `$HOME/.claude/projects/<escaped-cwd>/<session-id>.jsonl` — so a session
       is addressed by (HOME, CWD, session-id). Stabilising only HOME yields a SILENT cold start.
     * `exapp/llm-runner/src/runner.js`: scratch = fs.mkdtempSync (~195); env HOME: scratch (~225);
       spawn cwd: scratch (254); cleanup(scratch) rm -rf on EVERY exit path (~215/259/290/302).
     * buildPrompt() (~90) flattens the WHOLE history into one prompt every turn.
     * Baselines: llm ~9s (2-char answer) – ~17s (normal reply). Process spawn stays.
     MEASURE the effect; do not assume it. -->

## Implementation Tasks

### Task 1: Give a conversation a stable session home (HOME and CWD)
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-a-session-is-addressed-by-home-cwd-and-session-id-together`
- **files**: `exapp/llm-runner/src/runner.js`, `exapp/llm-runner/src/sessionHome.js`
- **acceptance_criteria**:
  - GIVEN two turns of one conversation WHEN each spawns the CLI THEN both receive the SAME `HOME` and the SAME `cwd`, and the second finds the first's transcript
  - GIVEN only `HOME` were stabilised WHEN a turn runs THEN the CLI cannot resolve the session — a test MUST pin that both halves are stable, because this failure is silent
  - GIVEN a turn with no conversation id WHEN it runs THEN it keeps today's throwaway mkdtemp behaviour unchanged

- [ ] Add a session-home resolver keyed by tenant/user + conversation UUID
- [ ] Use that directory as BOTH `HOME` and `cwd`; keep mkdtemp for turns with no conversation
- [ ] Test: two turns of one conversation share HOME+CWD; a session-less turn still gets a throwaway

### Task 2: Map the conversation UUID to the CLI session
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-a-conversation-maps-to-exactly-one-claude-cli-session`
- **files**: `exapp/llm-runner/src/providers.js`, `exapp/llm-runner/src/runner.js`
- **acceptance_criteria**:
  - GIVEN a conversation with no session home WHEN its first turn runs THEN argv carries `--session-id <conversationUuid>`
  - GIVEN a conversation whose session home exists WHEN a later turn runs THEN it resumes that session rather than creating a second
  - GIVEN the runner mints no identifier of its own WHEN any turn runs THEN the only session id used is the conversation UUID

- [ ] Pass `--session-id` on first turn; resume on subsequent turns
- [ ] Test argv for both first-turn and resumed-turn shapes

### Task 3: Send only the new message on a resumed turn
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/agent-engine-port/spec.md#requirement-a-resumed-turn-sends-only-the-new-user-message`
- **files**: `exapp/llm-runner/src/runner.js`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN a resumed turn WHEN the prompt is built THEN exactly one user message is sent, not the flattened history
  - GIVEN a cold start WHEN the prompt is built THEN the full history is sent, byte-identical to today
  - GIVEN a conversation's tenth resumed turn WHEN its payload is measured THEN it is the same size as its second — it does not scale with prior turns

- [ ] Build the resumed-turn prompt from the new message only
- [ ] Test: payload size is flat across turns 2..N; cold start still sends everything

### Task 4: Guarantee the cold-start fallback
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-the-session-home-is-a-cache-and-never-the-source-of-truth`
- **files**: `exapp/llm-runner/src/runner.js`
- **acceptance_criteria**:
  - GIVEN a missing, evicted or unreadable session home WHEN a turn runs THEN it cold-starts with full history, answers correctly, and does NOT fail
  - GIVEN a cold start occurs WHEN it happens THEN it is logged — a silent cold start is the defect this change removes, so it must never be indistinguishable from a resume
  - GIVEN the container is replaced WHEN existing conversations continue THEN no history is lost, because hermiq's `conversation`/`message` objects remain authoritative

- [ ] Detect an unusable session home and fall back to full history
- [ ] Log resume-vs-cold-start per turn
- [ ] Test: evicted home → correct full answer, no error, cold start observable

### Task 5: Isolate the session home per tenant and conversation
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-the-session-home-is-isolated-per-conversation-and-per-tenant`
- **files**: `exapp/llm-runner/src/sessionHome.js`
- **acceptance_criteria**:
  - GIVEN two tenants each holding a conversation WHEN their turns run THEN the homes are distinct and neither turn is given the other's directory
  - GIVEN a conversation or tenant identifier that is not a valid UUID WHEN a turn is dispatched THEN the runner refuses it rather than building a path from it — path traversal is refused structurally
  - GIVEN a session home is created WHEN its mode is checked THEN it is not world-readable

- [ ] Validate both path segments as UUIDs before use; refuse otherwise
- [ ] Create homes non-world-readable
- [ ] Test: traversal attempt refused; two tenants never share a home

### Task 6: Evict session homes on a bounded policy
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-session-homes-are-evicted-on-a-bounded-policy`
- **files**: `exapp/llm-runner/src/sessionHome.js`, `exapp/llm-runner/src/server.js`
- **acceptance_criteria**:
  - GIVEN a session home older than the TTL WHEN eviction runs THEN it is removed and the next turn cold-starts correctly
  - GIVEN eviction may run at any moment WHEN it does THEN no turn is broken by it — correctness never depends on a cache hit
  - GIVEN the TTL bounds how long transcripts persist in the container WHEN it is configured THEN its default is documented alongside the disk budget

- [ ] Add TTL + disk-budget eviction, safe to run at any time
- [ ] Test: expired home evicted; a turn concurrent with eviction still answers

### Task 7: Keep the per-run token non-persistent
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/claude-cli-session-reuse/spec.md#requirement-the-per-run-token-never-becomes-persistent`
- **files**: `exapp/llm-runner/src/runner.js`
- **acceptance_criteria**:
  - GIVEN a governed turn completes, errors or times out WHEN it ends THEN its `0600` MCP config no longer exists on disk, while the session home survives
  - GIVEN the home is now persistent WHEN the MCP config is written THEN it is not left where it outlives the run whose bearer token it carries (`cli-runner-governed-mcp-and-egress`)

- [ ] Write the MCP config per turn and remove it on every exit path, independent of home cleanup
- [ ] Test: token file gone after success, error and timeout; home retained

### Task 8: Measure the effect and report it honestly
- **spec_ref**: `openspec/changes/claude-cli-session-reuse/specs/agent-engine-port/spec.md#requirement-a-resumed-turn-sends-only-the-new-user-message`
- **files**: `openspec/changes/claude-cli-session-reuse/design.md`
- **acceptance_criteria**:
  - GIVEN baselines llm ~9s (2-char) / ~17s (normal reply) WHEN turns 1..N of one conversation are timed after the change THEN the measurements are recorded against those baselines
  - GIVEN the process spawn is not removed WHEN results are written up THEN no speedup is claimed beyond what was measured
  - GIVEN measurement might show no improvement WHEN that happens THEN it is reported as such rather than the change being justified by its premise

- [ ] Time turns 1..N before/after; record results in design.md

## Quality Checklist

- Runner tests pass (`npm test` in `exapp/llm-runner`)
- PHP suite passes; `composer check:strict` clean for touched files
- No secrets in logs: a session id is a conversation UUID, never a token
- Deployment note: existing conversations have no session home and cold-start once — expected, not a regression
