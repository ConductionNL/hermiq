# Test Plan: agent-memory-tools

## Test Cases

### TC-1: Agent remembers a fact about itself
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool`
- **type**: functional
- **persona**: Priya (ZZP developer/integrator — builds and tests agents directly)
- **preconditions**: An agent A with `hermiq.rememberMemory` in its `tools` allowlist (or an empty allowlist) is running a chat turn
- **steps**: Prompt the agent so it decides to call `hermiq.rememberMemory` with `content` and `scope: agent`
- **expected result**: The agent's `Memory` object gains a new entry; the entry is visible on the `AgentMemory` operator page afterward
- **test command**: /test-functional

### TC-2: Agent remembers a fact about the acting user
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent A chatting with user U
- **steps**: Prompt the agent so it calls `hermiq.rememberMemory` with `scope: user`
- **expected result**: U's `UserProfile` object for agent A gains the new entry
- **test command**: /test-functional

### TC-3: Agent recalls memory and session history together
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool`
- **type**: functional
- **persona**: Priya
- **preconditions**: Prior `Memory` entries and `SessionTurn`s exist matching a query term
- **steps**: Prompt the agent so it calls `hermiq.recallMemory` with that query
- **expected result**: The tool result contains both matching memory/profile entries and matching session turns
- **test command**: /test-functional

### TC-4: Recall never crosses tenant boundaries
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool`
- **type**: security
- **preconditions**: Two organisations each have agents with memory containing the same search term
- **steps**: Call `hermiq.recallMemory` as a user in organisation A with a query matching organisation B's entries
- **expected result**: No organisation-B entry or turn appears in the result
- **test command**: /test-security

### TC-5: Agent forgets a fact (soft delete, not hard delete)
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **type**: functional
- **persona**: Priya
- **preconditions**: A `Memory` entry with a known id exists
- **steps**: Call `hermiq.forgetMemory` with that id; then inspect the underlying `Memory` object directly
- **expected result**: The entry's `deletedAt` is set; the entry is still present in the stored `entries` array; `hermiq.recallMemory` no longer returns it
- **test command**: /test-functional

### TC-6: Forgetting an unknown id fails soft, not fatal
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **type**: functional
- **preconditions**: An id not present in the agent's `Memory` or the acting user's `UserProfile`
- **steps**: Call `hermiq.forgetMemory` with that id
- **expected result**: A structured not-found result is returned; the agent's turn continues; no exception is thrown/logged as an error
- **test command**: /test-functional

### TC-7: Forget is IDOR-scoped to the acting user's own profile
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **type**: security
- **preconditions**: Agent A maintains `UserProfile` entries for two different subject users U1 (acting/calling) and U2
- **steps**: As U1, call `hermiq.forgetMemory` with an entry id that exists only in U2's `UserProfile`
- **expected result**: Not-found — U2's entry is never inspected or modified by U1's call
- **test command**: /test-security

### TC-8: Memory writes are redacted before persist
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-writes-are-redacted-before-persist`
- **type**: security
- **preconditions**: An agent about to call `hermiq.rememberMemory` with `content` containing a recognised credential pattern (e.g. `sk-liveXXXXXXXXXXXXXXXX`)
- **steps**: Call `hermiq.rememberMemory` with that content; inspect the persisted entry and its AuditTrail record
- **expected result**: The credential substring is masked in both the stored entry and the AuditTrail; surrounding text is preserved
- **test command**: /test-security

### TC-9: Operator-seeded memory (existing endpoint) is also redacted
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-writes-are-redacted-before-persist`
- **type**: regression
- **preconditions**: An operator uses the existing `MemoryController::addMemory()` endpoint with text containing a recognised credential pattern
- **steps**: POST the memory-add request
- **expected result**: The persisted entry is redacted identically to the agent-tool path (same `appendEntry()` call site)
- **test command**: /test-api

### TC-10: An org denies an agent memory-write via the existing tool allowlist
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-tool-governance-is-fully-inherited-not-reimplemented`
- **type**: security
- **preconditions**: An `Agent` whose `tools` array lists other tool ids but omits `hermiq.rememberMemory`/`hermiq.forgetMemory`
- **steps**: Assemble the LLM's available functions for a chat turn with that agent
- **expected result**: `hermiq.rememberMemory` and `hermiq.forgetMemory` are absent from the offered functions; `hermiq.recallMemory` remains available if listed
- **test command**: /test-api

### TC-11: A memory tool call appears in the run trace
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-tool-governance-is-fully-inherited-not-reimplemented`
- **type**: functional
- **preconditions**: A run with `RunTraceCollector` attached
- **steps**: Trigger a run where the agent calls any of the three memory tools; export the run trace
- **expected result**: One `tool`-type step appears for the call, named with the `hermiq.*` tool id, timed like any other tool step
- **test command**: /test-functional

### TC-12: Forgotten entries render distinctly in the operator memory panel
- **spec_ref**: `openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only`
- **type**: accessibility
- **preconditions**: A `Memory` object with at least one soft-deleted entry and one active entry
- **steps**: Open the `AgentMemory` page for that agent
- **expected result**: The soft-deleted entry is visually distinguishable (not identical to an active entry, not silently absent); the distinction is conveyed by more than color alone (WCAG 2.1 AA)
- **test command**: /test-accessibility

## Coverage Summary
- Agent self-service memory write tool — covered (TC-1, TC-2)
- Agent self-service memory recall tool — covered (TC-3, TC-4)
- Agent self-service memory forget tool (soft delete only) — covered (TC-5, TC-6, TC-7, TC-12)
- Memory writes are redacted before persist — covered (TC-8, TC-9)
- Memory tool governance is fully inherited, not reimplemented — covered (TC-10, TC-11)

## Out of Scope
- Automatic system-prompt injection of Memory/UserProfile entries at run start is
  explicitly deferred (see design.md Decision 1) — no test case is written for behavior
  this change does not implement.
- Cross-subject-user forget (an agent retracting a fact about a user other than the one
  currently acting) is explicitly out of scope (design.md Decision 3) — TC-7 asserts the
  denial, not a success path, since no success path exists.
