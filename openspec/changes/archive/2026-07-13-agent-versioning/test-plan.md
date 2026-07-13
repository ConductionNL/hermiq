# Test Plan: agent-versioning

## Test Cases

### TC-1: List an agent's version history newest-first
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history`
- **type**: api
- **persona**: Priya (ZZP developer / integrator, configures her own agents)
- **preconditions**: An Agent owned by the test user has been edited 3 times since creation (4 AuditTrail entries total)
- **steps**: `GET /api/agents/{id}/versions` as the owner
- **expected result**: 4 versions returned, newest-first, each with a version id, timestamp, and acting user
- **test command**: /test-api

### TC-2: Non-owner without access cannot list a private agent's versions
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history`
- **type**: security
- **persona**: Noor (municipal CISO / functional admin, cares about IDOR/authorization boundaries)
- **preconditions**: Agent A is private, owned by user1, with no invited users; user2 is a different, unrelated user
- **steps**: `GET /api/agents/{A}/versions` authenticated as user2
- **expected result**: request denied; no version data leaked
- **test command**: /test-security

### TC-3: Diff two versions shows only changed versioned-config fields
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set`
- **type**: api
- **preconditions**: Agent edited once, changing only `prompt`; `name` also happens to differ between the two points in time (edited via a separate unrelated call)
- **steps**: `GET /api/agents/{id}/versions/diff?from={A}&to={B}`
- **expected result**: diff contains exactly `prompt` (old/new); `name` does NOT appear in the diff
- **test command**: /test-api

### TC-4: Diffing a version against itself yields no changes
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set`
- **type**: api
- **preconditions**: Any existing version id V of an agent
- **steps**: `GET /api/agents/{id}/versions/diff?from={V}&to={V}`
- **expected result**: empty diff (no changed fields)
- **test command**: /test-api

### TC-5: Rollback restores versioned-config fields and creates a new version
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history`
- **type**: api
- **preconditions**: Agent's current `prompt`/`tools` differ from an earlier version V's recorded values
- **steps**: `POST /api/agents/{id}/versions/{V}/rollback` as the owner, then `GET /api/agents/{id}/versions`
- **expected result**: agent's live `prompt`/`tools` now match version V; a new version appears on top of the history list; version V's own entry is unchanged (same timestamp/actor as before)
- **test command**: /test-api

### TC-6: Rollback never touches identity, visibility, or quota fields
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history`
- **type**: regression
- **preconditions**: Agent's `name`, `isPrivate`, and `tokenQuota` differ between current state and the target rollback version
- **steps**: roll back to that version, then re-fetch the agent
- **expected result**: `name`, `isPrivate`, `tokenQuota` retain their CURRENT (pre-rollback) values; only versioned-config fields changed
- **test command**: /test-regression

### TC-7: Non-owner cannot roll back another user's agent
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history`
- **type**: security
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: Agent A owned by user1; user2 is a different user
- **steps**: `POST /api/agents/{A}/versions/{V}/rollback` authenticated as user2
- **expected result**: request denied; agent A's live configuration unchanged
- **test command**: /test-security

### TC-8: A scheduled run's audit entry pins the executing agent version
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it`
- **type**: api
- **preconditions**: A Schedule bound to an Agent currently at version V
- **steps**: trigger the schedule's run-now endpoint, then read the schedule's run history
- **expected result**: the resulting run record's `agentVersion` equals V (the version current at run start)
- **test command**: /test-api

### TC-9: A version pin failure never breaks the run itself
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it`
- **type**: regression
- **preconditions**: `AgentVersionService::currentVersionId()` forced to throw (unit-test level fault injection)
- **steps**: run a schedule end-to-end
- **expected result**: the run's audit entry is still written (without `agentVersion`); run status/output is unaffected
- **test command**: /test-regression

### TC-10: Run history displays the pinned agent version, gracefully handling older runs
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-run-history-surfaces-the-pinned-agent-version`
- **type**: functional
- **preconditions**: A schedule with a mix of runs — some with a pinned `agentVersion`, one older run predating this capability (no `agentVersion` in its stored context)
- **steps**: open the schedule's run history in AgentDetail.vue
- **expected result**: each run row shows its pinned agent version where present; the older run shows an empty/blank version indicator, no error, no broken row
- **test command**: /test-functional

### TC-11: Version-history and diff dialogs render correctly and are keyboard/screen-reader accessible
- **spec_ref**: `openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set`
- **type**: accessibility
- **preconditions**: An agent with at least 3 versions
- **steps**: open "Version history" on AgentDetail, select two versions, open "Compare"
- **expected result**: both dialogs (NcDialog-based) are keyboard-navigable, all interactive elements have accessible labels, no color-only status indication
- **test command**: /test-accessibility

## Coverage Summary

- Requirement "List an Agent's version history" — covered (TC-1, TC-2)
- Requirement "Diff two agent versions across the versioned-config field set" — covered (TC-3, TC-4, TC-11)
- Requirement "Roll back an agent to a previous version without mutating history" — covered (TC-5, TC-6, TC-7)
- Requirement "A run's audit entry pins the exact Agent version that executed it" — covered (TC-8, TC-9)
- Requirement "Run history surfaces the pinned agent version" — covered (TC-10)

## Out of Scope

- Flow-triggered and webhook-triggered run version-pinning are covered at the
  unit/PHPUnit level (Task 3) rather than a dedicated end-to-end TC here —
  both reuse the identical `currentVersionId()` capture point already
  end-to-end-tested via TC-8/TC-9 for the scheduled-run path; a full separate
  E2E harness for those two trigger paths is not newly introduced by this
  change (their dispatch mechanics are unchanged, only their audit context
  gains one field).
- Performance/load testing of version-history listing under very large
  edit counts — deferred; not expected to be a real-world volume given
  Agents are human-edited, not machine-frequency (see design.md Risks).
