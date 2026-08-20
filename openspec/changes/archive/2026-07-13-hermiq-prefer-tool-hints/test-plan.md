# Test Plan: hermiq-prefer-tool-hints

Pure backend classification logic with no independent UI/API surface of its own (it is consumed
inside `ToolLoop`/`FacadeToolInvoker`, already covered by `agent-tool-governance-and-disclosure`'s
functional/API test plan) — coverage here is unit-level, `/test-functional` equivalents are captured
as PHPUnit scenarios instead of browser/API runs.

## Test Cases

### TC-1: Declared hints take precedence over the id's own verb-suffix shape
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-descriptor-hints-take-precedence-over-verb-suffix-classification`
- **type**: unit
- **preconditions**: A catalog descriptor carrying `scope`/`destructiveHint`/`readOnlyHint`
- **steps**: `ToolGrantResolver::isWriteOrDestructive($id, $descriptor)`
- **expected result**: The hint wins, including when it conflicts with the id's own verb suffix
- **covered by**: `ToolGrantResolverTest::testIsWriteOrDestructiveHintClassification`,
  `::testHintOverridesConflictingVerbSuffix`, `::testEmptyGrantsClassifiesCuratedToolsFromHints`

### TC-2: Hint-less 3-segment ids keep the exact pre-existing verb-suffix result (regression)
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-descriptor-hints-take-precedence-over-verb-suffix-classification`
- **type**: unit
- **preconditions**: A 3-segment `{app}.{schema}.{verb}` id, no descriptor
- **steps**: `ToolGrantResolver::isWriteOrDestructive($id)`
- **expected result**: Identical to the pre-hints result for every ADR-063 verb
- **covered by**: `ToolGrantResolverTest::testIsWriteOrDestructiveHintlessClassification`

### TC-3: A hint-less, non-3-segment id fails closed
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-an-unclassifiable-hint-less-id-fails-closed`
- **type**: unit
- **preconditions**: A 2-segment or bare id, no descriptor / no hint keys set
- **steps**: `ToolGrantResolver::isWriteOrDestructive($id)`
- **expected result**: `true` (was `false` before this change)
- **covered by**: `ToolGrantResolverTest::testIsWriteOrDestructiveHintlessClassification`,
  `::testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds`

### TC-4: A fail-closed id is stripped from the empty-grants ("all tools") resolution
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-an-unclassifiable-hint-less-id-fails-closed`
- **type**: unit
- **preconditions**: `Agent.tools = []`, a catalog containing a hint-less 2-segment id
- **steps**: `ToolGrantResolver::resolve([], $catalog)` / `ToolLoop::listAgentFunctions()`
- **expected result**: The id is absent from the resolved set; a `readOnlyHint:true` sibling id
  survives
- **covered by**: `ToolGrantResolverTest::testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds`,
  `ToolLoopTest::testEmptyWhitelistPostFiltersDefaultDenyWithoutASecondFacadeCall`

### TC-5: A fail-closed, un-granted id trips the approval gate instead of dispatching
- **spec_ref**: `openspec/changes/hermiq-prefer-tool-hints/specs/agent-tool-governance/spec.md#requirement-an-unclassifiable-hint-less-id-fails-closed`
- **type**: unit
- **preconditions**: A curated 2-segment tool NOT part of the agent's resolved set
- **steps**: `FacadeToolInvoker::__call()` for that tool
- **expected result**: A pending `Approval` is created; the facade is never invoked (previously: no
  gate at all, straight facade dispatch)
- **covered by**: `FacadeToolInvokerTest::testUngrantedCuratedTwoSegmentToolNowRoutesThroughApprovalGate`

### TC-6: RBAC/approval gate remain authoritative regardless of hints
- **spec_ref**: `openspec/specs/agent-tool-governance/spec.md#scenario-an-untrusted-read-only-hint-cannot-bypass-authorization`
- **type**: regression
- **preconditions**: N/A — unchanged pre-existing scenario
- **steps**: n/a (no code path in this change touches OR RBAC or the approval-gate's own state machine)
- **expected result**: Unaffected; `readOnlyHint`/`destructiveHint`/`scope` remain classification-only
  inputs, never consulted by RBAC or by the approval `Approval` state machine itself
- **covered by**: pre-existing `human-approval-gate` coverage (unchanged by this diff)
