# Tasks: hermiq-prefer-tool-hints

## Implementation Tasks

### Task 1: `ToolGrantResolver::isWriteOrDestructive()` — hint-preferred classification precedence
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-descriptor-hints-take-precedence-over-verb-suffix-classification`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a descriptor with `scope`/`destructiveHint`/`readOnlyHint` set WHEN classified THEN the
    hint wins over the id's own verb-suffix shape, even when they conflict
  - GIVEN a hint-less 3-segment `{app}.{schema}.{verb}` id WHEN classified THEN the verb-suffix
    fallback result is UNCHANGED from before this change (regression)
- [x] Implement
- [x] Test

### Task 2: `ToolGrantResolver::resolve()`/`applyDefaultDeny()` — thread each id's own descriptor
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-descriptor-hints-take-precedence-over-verb-suffix-classification`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`
- **acceptance_criteria**:
  - GIVEN an empty `Agent.tools` (default-deny path) over a catalog with hint-carrying descriptors
    WHEN resolved THEN each id is classified from ITS OWN descriptor's hints, not id text alone
- [x] Implement
- [x] Test

### Task 3: Fail closed on an unclassifiable (hint-less, non-3-segment) id
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-an-unclassifiable-hint-less-id-fails-closed`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`, `tests/Unit/Service/Engine/ToolLoopTest.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN a hint-less, non-3-segment id (2-segment curated/hand-written, or bare) WHEN classified
    THEN the result is `true` (write/destructive) — was `false` before this change
  - GIVEN such an id is NOT explicitly granted WHEN an empty `Agent.tools` ("all tools") is resolved
    THEN it is stripped by default-deny
  - GIVEN such an id is invoked without being part of the agent's resolved set WHEN
    `FacadeToolInvoker::__call()` dispatches THEN it routes through the `human-approval-gate` approval
    gate instead of the facade
- [x] Implement
- [x] Test

### Task 4: Update pre-existing tests whose fixtures relied on the old fail-open behaviour
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-an-unclassifiable-hint-less-id-fails-closed`
- **files**: `tests/Unit/Service/Engine/ToolLoopTest.php`
- **acceptance_criteria**:
  - GIVEN `testEmptyWhitelistAllowsAllTools`'s fixture ids (previously bare/dot-less, unclassifiable
    by the old rule too, but now fail closed) WHEN updated to genuinely read-classified derived ids
    (`.search`) THEN the test still verifies the `listTools([])` call contract without asserting the
    now-closed hole
  - GIVEN `testEmptyWhitelistPostFiltersDefaultDenyWithoutASecondFacadeCall`'s `hermiq.sendMail`
    fixture WHEN the suite runs THEN the assertion reflects it now being stripped, and a new
    `readOnlyHint:true` fixture id is added to prove the hint-preserved survival path
- [x] Implement
- [x] Test

### Task 5: Canonical spec sync — `agent-tool-governance`
- **spec_ref**: `openspec/specs/agent-tool-governance/spec.md`
- **files**: `openspec/specs/agent-tool-governance/spec.md`
- **acceptance_criteria**:
  - GIVEN the "Known upstream gap" Note describing the pre-forwarding-fix, verb-suffix-only state
    WHEN this change ships THEN it is replaced with the new hint-preferred / fail-closed description
  - GIVEN the "An empty `Agent.tools` preserves 'all discovered tools allowed'..." acceptance
    criterion WHEN this change ships THEN it is corrected to state the fail-closed carve-out
- [x] Implement (doc-only)

## Non-Goals (see proposal.md "Out of Scope")
- No change to `GuardrailPolicyService`/agent-guardrails — verified orthogonal, not touched.
- No change to `{app}.{schema}.*` wildcard candidate matching (`schemaVerbIds()`).
