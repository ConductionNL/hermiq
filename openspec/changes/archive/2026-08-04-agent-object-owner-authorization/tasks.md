# Tasks: agent-object-owner-authorization

Blocks `agent-capability-reach`. The hole is reproduced (see proposal.md); the
fix is verified live. These tasks land it in the repo and prove it in CI.

## Implementation Tasks

### Task 1: Declare owner-only writes on the Agent schema

- **spec_ref**: `openspec/changes/agent-object-owner-authorization/specs/agent-management-ui/spec.md#requirement-only-an-agents-owner-may-change-it`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN `components.schemas.Agent` WHEN the block is added THEN it is exactly `{"read":["authenticated"]}` — `create`/`update`/`delete` are OMITTED on purpose, which is what makes them owner-only
  - GIVEN the omission WHEN a reviewer reads it THEN a comment or changelog line states that omission is the mechanism, because the block reads like it only grants read
  - GIVEN `scope` WHEN considered THEN it is NOT used: it is a single key covering every action and would close reads for invited users too
  - GIVEN the register WHEN it changes THEN `info.version` is bumped and the changelog line appended to `info.description`
  - GIVEN the import WHEN it runs THEN it uses `force: true`, and the block is read back FROM THE LIVE SCHEMA — a version bump alone is not evidence
- [x] Implement
- [x] Test

### Task 2: Prove the fix four ways, live

- **spec_ref**: `openspec/changes/agent-object-owner-authorization/specs/agent-management-ui/spec.md#requirement-closing-the-write-path-must-not-close-the-read-path`
- **files**: `openspec/changes/agent-object-owner-authorization/proposal.md`
- **acceptance_criteria**:
  - Non-owner UPDATE is refused with 403 — previously HTTP 200
  - Non-owner READ still succeeds — the row that catches "fixed it by breaking sharing"
  - Owner UPDATE still succeeds — the row that catches "fixed it by denying everybody"
  - The stored grants after a refused attack are byte-identical to what the owner left
  - Record the observed codes in the proposal; a green unit suite is not evidence for an authorization boundary that lives in another app's permission handler
- [x] Implement
- [x] Test

### Task 3: Regression test the boundary

- **spec_ref**: `openspec/changes/agent-object-owner-authorization/specs/agent-management-ui/spec.md#requirement-only-an-agents-owner-may-change-it`
- **files**: `tests/Unit/Settings/`, `tests/e2e/spec-coverage/`
- **acceptance_criteria**:
  - GIVEN the register file WHEN parsed THEN a test asserts the Agent block grants `read` and omits every write action, so a later "tidy-up" that adds `"update":["authenticated"]` fails loudly
  - GIVEN the assertion WHEN written THEN it names the omission as intentional, or the next reader will read a missing key as an oversight and complete it
  - Cross-user API checks stay in the live verification: the browser session model cannot express "a second user PUTs an object it does not own" without fighting the harness
- [x] Implement
- [x] Test
