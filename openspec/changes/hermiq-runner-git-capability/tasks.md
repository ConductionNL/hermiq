# Tasks: hermiq-runner-git-capability

Ordered by the delivery staging in `design.md` § Migration Plan: the provider seam and the
confinement guard land first, the read-only surface next, in-workspace writes after that, and the
forge credential does not appear until task 7. `exapp/llm-runner/src/providers.js` is not edited by
any task — task 8 exists to keep it that way.

## Implementation Tasks

### Task 1: Workspace provider seam and run-scoped server-side workspace store
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **files**: `lib/Service/Workspace/WorkspaceProvider.php`, `lib/Service/Workspace/ServerSideWorkspaceProvider.php`, `tests/Unit/Service/Workspace/ServerSideWorkspaceProviderTest.php`
- **acceptance_criteria**:
  - GIVEN two concurrent runs WHEN one supplies the other's workspace identifier THEN the call is refused and the workspace served is the one derived from the caller's own token
  - GIVEN a workspace materialisation WHEN the result is built THEN it carries an opaque identifier, repository, ref and head commit, and no filesystem path
  - GIVEN a write exceeding the size or file-count budget WHEN it is attempted THEN a workspace-quota error code is returned and nothing is written
  - GIVEN a run token that has expired or been consumed WHEN the workspace is next addressed THEN it is no longer reachable
- [ ] Implement
- [ ] Test

### Task 2: Path confinement guard with repository-metadata deny-write
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution`
- **files**: `lib/Service/Workspace/WorkspacePathGuard.php`, `tests/Unit/Service/Workspace/WorkspacePathGuardTest.php`
- **acceptance_criteria**:
  - GIVEN an absolute path or a parent-directory traversal segment WHEN it is supplied THEN the call is refused before any filesystem operation
  - GIVEN a workspace-relative path resolving through a symbolic link to a target outside the root WHEN containment is checked THEN it is refused after symlink resolution, not on the lexical form
  - GIVEN any write-shaped target resolving under the repository metadata directory WHEN it is supplied THEN it is refused independently of the containment check, with no permitted-file allowlist consulted
  - GIVEN a patch whose targets include one refused path WHEN it is applied THEN the whole patch is rejected and no hunk is present afterwards
- [ ] Implement
- [ ] Test

### Task 3: Read-only tool surface over the existing governed MCP dispatch path
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface`
- **files**: `lib/Service/Workspace/WorkspaceToolset.php`, `lib/Controller/McpRunController.php`, `tests/Unit/Service/Workspace/WorkspaceToolsetReadTest.php`
- **acceptance_criteria**:
  - GIVEN an agent granted the whole surface WHEN it enumerates its tools THEN no tool accepts a command string, subcommand, refspec or remote URL
  - GIVEN a materialisation call WHEN the repository argument is supplied THEN it is a forge-relative slug and the host is resolved server-side
  - GIVEN a workspace tool invocation WHEN it is dispatched THEN it passes through the same invoker as every other governed tool, with no alternative route to the workspace
  - GIVEN a file or git tool called before materialisation WHEN it runs THEN a workspace-absent error code is returned and no filesystem operation occurs
- [ ] Implement
- [ ] Test

### Task 4: In-workspace write tools with owner-derived commit identity
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner`
- **files**: `lib/Service/Workspace/WorkspaceToolset.php`, `tests/Unit/Service/Workspace/WorkspaceToolsetWriteTest.php`
- **acceptance_criteria**:
  - GIVEN the commit tool WHEN its schema is inspected THEN it accepts no author, committer, name or email argument
  - GIVEN a workspace whose repository-local configuration names a different identity WHEN a commit is made THEN it is attributed to the resolved run owner
  - GIVEN a run with no resolvable owner WHEN commit is called THEN an owner-unresolvable error code is returned and inference for that run remains permitted
  - GIVEN every write-shaped tool WHEN a path argument is handled THEN it goes through the task 2 guard
- [ ] Implement
- [ ] Test

### Task 5: Repo-effecting grant rule and classification wiring
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `lib/Service/ToolClassificationService.php`, `tests/Unit/Service/Engine/ToolGrantResolverWorkspaceTest.php`
- **acceptance_criteria**:
  - GIVEN a bare exact-identifier push grant WHEN grants are resolved THEN the tool does not resolve and is not advertised in the tool listing
  - GIVEN an argument-scoped push grant pinning a repository and constraining a branch WHEN a branch outside the constraint is pushed THEN an argument-not-permitted error is returned before any network operation
  - GIVEN an argument-scoped grant over a destructive tool WHEN classification is resolved THEN it remains destructive and the approval requirement still applies
  - GIVEN an agent granted only inspection and read tools WHEN it attempts a write or push THEN the attempt is refused for want of a resolving grant while inspection keeps working
- [ ] Implement
- [ ] Test

### Task 6: Approval gate wiring and run-scoped pre-authorisation
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form`
- **files**: `lib/Service/ApprovalService.php`, `lib/Service/Workspace/WorkspaceToolset.php`, `tests/Unit/Service/ApprovalServiceRunScopedTest.php`
- **acceptance_criteria**:
  - GIVEN a resolving write grant with no approval or pre-authorisation WHEN a write-shaped tool is called THEN an approval-required error is returned and nothing is written or committed
  - GIVEN a run-scoped pre-authorisation bounded by a grant WHEN the same agent starts a different run THEN it does not apply and the new run needs its own
  - GIVEN any configuration of the system WHEN a write-shaped tool in this surface is invoked THEN an approval or valid pre-authorisation is present and no setting permits the invocation without one
  - GIVEN a pre-authorisation record WHEN it is read THEN it names a human decision-maker and the pinned arguments it is bounded by
- [ ] Implement
- [ ] Test

### Task 7: Forge credential resolution, push tool, egress authorisation and redaction
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model`
- **files**: `lib/Service/Workspace/ForgeCredentialResolver.php`, `lib/Controller/EgressAuthorizeController.php`, `tests/Unit/Service/Workspace/ForgeCredentialResolverTest.php`
- **acceptance_criteria**:
  - GIVEN a run holding the push grant WHEN the CLI child's environment, command line and working directory are inspected THEN the forge credential is absent from all of them and the push still succeeds
  - GIVEN a push the forge refuses WHEN the failure is returned THEN it carries a stable error code and a redacted message with no credential, credentialed remote URL or raw transport output
  - GIVEN a model credential and a forge credential owned by different users WHEN a push is attempted THEN a credential-scope-refused error is returned rather than a warning
  - GIVEN a run whose agent holds no resolving grant needing the forge WHEN egress to the forge host is attempted THEN the single policy source denies it and the denial is returned as an egress-denied code, not a timeout
- [ ] Implement
- [ ] Test

### Task 8: Workspace write audit records and denylist-integrity regression guard
- **spec_ref**: `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval`
- **files**: `lib/Service/Workspace/WorkspaceToolset.php`, `tests/Unit/Service/Workspace/WorkspaceAuditTest.php`, `exapp/llm-runner/test/denylist-integrity.test.js`
- **acceptance_criteria**:
  - GIVEN a successful push WHEN the run's audit trail is read THEN a record naming run, agent, tool, owner, credential, approval, arguments and outcome is present
  - GIVEN a write refused for a confinement, grant or approval reason WHEN the trail is read THEN the refusal and its reason are recorded
  - GIVEN any workspace audit record WHEN it is read THEN it contains no credential, filesystem path or internal host name, and no workspace is attached
  - GIVEN the runner's disallowed built-in tool list WHEN the guard runs THEN every shell and filesystem entry is still present and the tool-search built-in is still absent from it
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off and every acceptance criterion demonstrated
- [ ] `openspec validate hermiq-runner-git-capability --strict` passes
- [ ] The bounded milestone runs end to end: one small code-editing task materialises a workspace, edits a file, commits and pushes under an argument-scoped grant, with turn count and wall clock recorded

## Quality checklist

- PHPUnit unit tests for the confinement guard, the credential resolver, grant resolution, attribution and audit (`tests/Unit/Service/Workspace/`), plus a node test for denylist integrity
- Negative tests are mandatory, not optional: traversal, symlink escape, repository-metadata write, partial-patch rejection, bare push grant, missing approval, mismatched credential owners, unresolvable owner, egress denial
- No Newman/Postman work — this change adds no HTTP endpoint; the tools ride the existing governed MCP route
- No Playwright work beyond the approval surface, if and only if the pre-authorisation UI is touched (ADR-008)
- Feature documentation updated in `docs/` for the operator-facing grant and pre-authorisation model (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings for approval prompts and operator-facing errors; tool ids, argument names and error codes stay untranslated (ADR-007)
- `composer check:strict` clean, including any pre-existing issues encountered in the files touched
