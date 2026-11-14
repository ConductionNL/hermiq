# Test Plan: hermiq-runner-git-capability

Capability under test: `agent-workspace-git-tools`. Spec refs below are relative to
`openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md`.

This capability has almost no user interface, so the plan is weighted towards **security** and
**api** cases exercised through the governed MCP route. Two framing rules apply throughout:

- **Negative cases are the deliverable.** A confinement guard that is only tested with paths that
  should pass has not been tested. Every refusal path below is mandatory, not illustrative.
- **A refusal must be distinguishable.** Where a test asserts a refusal it also asserts the
  specific error code, because conflating a policy denial with an outage is itself a defect
  (`spec.md` § Non-Functional Requirements, Observability).

## Test Cases

### TC-1: The tool surface exposes no free-form command
- **spec_ref**: `#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface`
- **type**: security
- **preconditions**: An agent granted every tool in the workspace surface, with a valid run token
- **steps**: Enumerate the agent's tools over the governed MCP endpoint and inspect every `inputSchema`
- **expected result**: No tool accepts a command string, subcommand name, refspec or remote URL; no tool expresses force push, ref deletion, tag push, merge, rebase, reset or cherry-pick
- **test command**: `/test-security`

### TC-2: The repository is named by slug and the host is server-resolved
- **spec_ref**: `#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface`
- **type**: security
- **preconditions**: A run holding a materialisation grant
- **steps**: Call the materialisation tool with a repository argument shaped as a full URL pointing at an attacker-controlled host
- **expected result**: The call is refused; the forge host is only ever taken from server-side configuration
- **test command**: `/test-security`

### TC-3: Workspace tools dispatch through the single governed path
- **spec_ref**: `#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface`
- **type**: api
- **preconditions**: A run with a materialised workspace
- **steps**: Invoke a workspace tool and trace the dispatch; then search the route table for any other route reaching the workspace
- **expected result**: The invocation passes through the same invoker as every other governed tool with classification, grant resolution, approval and redaction applied; no second route exists
- **test command**: `/test-api`

### TC-4: The denylist is unchanged and the tool-search carve-out survives
- **spec_ref**: `#requirement-the-cli-built-in-denylist-is-never-relaxed-to-provide-code-editing-capability`
- **type**: regression
- **preconditions**: The runner's disallowed built-in tool list as shipped before this change
- **steps**: Run the denylist-integrity guard against the delivered list
- **expected result**: Every shell and filesystem entry is still present; the tool-search built-in is still absent from the list; the guard fails loudly on any removal
- **test command**: `/test-regression`

### TC-5: A shell built-in would expose both live secrets
- **spec_ref**: `#requirement-the-cli-built-in-denylist-is-never-relaxed-to-provide-code-editing-capability`
- **type**: security
- **preconditions**: A governed run whose CLI child holds the provider credential and the per-run proxy URL in its environment
- **steps**: In a controlled harness, run the CLI child with the shell built-in temporarily permitted and read its environment
- **expected result**: Both the provider credential and the run's bearer token are readable, demonstrating that re-enabling the built-in is credential exfiltration; the harness is a proof, never a shipped configuration
- **test command**: `/test-security`

### TC-6: One run cannot address another run's workspace
- **spec_ref**: `#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **type**: security
- **preconditions**: Two concurrent runs, each with its own token and materialised workspace
- **steps**: From run A, supply run B's workspace identifier to every workspace tool in turn
- **expected result**: Every call is refused, or served against run A's own workspace; run B's content is never returned
- **test command**: `/test-security`

### TC-7: No filesystem path reaches the model
- **spec_ref**: `#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **type**: api
- **preconditions**: A run holding the materialisation grant
- **steps**: Materialise a workspace, then call every read tool and inspect all results and all error payloads
- **expected result**: Results carry an opaque identifier, repository, ref and head commit; no result and no error contains a filesystem path
- **test command**: `/test-api`

### TC-8: The runner stays stateless on every exit path
- **spec_ref**: `#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **type**: regression
- **preconditions**: A governed turn that used the workspace
- **steps**: Force each exit path in turn — normal exit, timeout, output overflow, spawn failure, parse failure — and inspect the container afterwards
- **expected result**: The per-call scratch directory is removed every time; no part of the workspace is ever present inside the container
- **test command**: `/test-regression`

### TC-9: A tool call before materialisation is refused
- **spec_ref**: `#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **type**: api
- **preconditions**: A run for which no workspace has been materialised
- **steps**: Call each file and git tool
- **expected result**: Each returns the workspace-absent error code and performs no filesystem operation
- **test command**: `/test-api`

### TC-10: The workspace budget is enforced
- **spec_ref**: `#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path`
- **type**: performance
- **preconditions**: A materialised workspace near its size and file-count budget
- **steps**: Write until the budget would be exceeded, by single write and by patch
- **expected result**: The workspace-quota error code is returned; the store never grows past the budget; the failure is not a crash or an unbounded write
- **test command**: `/test-performance`

### TC-11: Traversal and absolute paths are refused
- **spec_ref**: `#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution`
- **type**: security
- **preconditions**: A run holding a file-write grant over a materialised workspace
- **steps**: Supply absolute paths and paths containing parent-directory traversal segments, including encoded and mixed-separator forms, to every path-taking tool
- **expected result**: Every call is refused with the path-outside-workspace code; no file is created, modified or read
- **test command**: `/test-security`

### TC-12: A symlink escape is refused after resolution
- **spec_ref**: `#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution`
- **type**: security
- **preconditions**: A workspace containing a symbolic link whose target is outside the root, and a second link created during the same run
- **steps**: Supply lexically-clean workspace-relative paths that resolve through each link, for read and for write
- **expected result**: Both are refused after symlink resolution; refusal does not depend on the lexical form of the supplied path
- **test command**: `/test-security`

### TC-13: A patch touching a refused path is rejected whole
- **spec_ref**: `#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution`
- **type**: security
- **preconditions**: A patch whose hunks target several files, one resolving outside the workspace
- **steps**: Apply the patch and inspect the workspace afterwards
- **expected result**: The patch-rejected code is returned; no hunk from that patch is present; the workspace is byte-identical to its pre-call state
- **test command**: `/test-security`

### TC-14: Repository-metadata writes are refused, including via patch
- **spec_ref**: `#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool`
- **type**: security
- **preconditions**: A run holding a file-write and patch grant over a materialised workspace
- **steps**: Attempt writes to a hook path, to the repository configuration file, and to an inert file under the metadata directory — each by direct write, by patch, and via a symlink pointing into the metadata directory
- **expected result**: All are refused with the path-forbidden code, independently of the containment check, with no permitted-file allowlist consulted; the inert target is refused too
- **test command**: `/test-security`

### TC-15: A metadata write cannot escalate to execution
- **spec_ref**: `#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool`
- **type**: security
- **preconditions**: The refusals of TC-14 in place
- **steps**: Attempt the full escalation chain — write a hook or a command-executing configuration key, then invoke a git tool that would trigger it
- **expected result**: The chain fails at the write; no command is executed by any subsequent git operation
- **test command**: `/test-security`

### TC-16: The forge credential is absent from the model's container
- **spec_ref**: `#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model`
- **type**: security
- **preconditions**: A run holding the push grant, mid-push
- **steps**: Inspect the CLI child's environment, command line, working directory and the workspace's own repository configuration
- **expected result**: The forge credential appears in none of them; the push nevertheless succeeds, because it is performed on the governed side
- **test command**: `/test-security`

### TC-17: A failed push leaks nothing
- **spec_ref**: `#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model`
- **type**: security
- **preconditions**: A forge configured to refuse the update
- **steps**: Push and capture the tool result, the error, the log lines and the run transcript
- **expected result**: A stable error code and a redacted message; no credential, no credentialed remote URL, no raw transport output in any of the four surfaces
- **test command**: `/test-security`

### TC-18: The workspace is not the CLI child's home
- **spec_ref**: `#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model`
- **type**: regression
- **preconditions**: A governed turn with a materialised workspace
- **steps**: Inspect the CLI child's home and temporary directory settings; then write a credential-shaped file to the child's home and attempt to read it back through the file-read tool
- **expected result**: Both point at the per-call throwaway scratch and neither points at or contains the workspace; the read-back fails
- **test command**: `/test-regression`

### TC-19: Attribution is owner-derived and cannot be redirected
- **spec_ref**: `#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner`
- **type**: security
- **preconditions**: A run with a resolvable owner, in a workspace whose repository-local configuration names a different identity
- **steps**: Inspect the commit tool's schema for author/committer arguments; then commit and read the resulting commit's author and committer
- **expected result**: The schema accepts no such argument; the commit is attributed to the resolved run owner in the Nextcloud user-id namespace, not to the repository-local identity
- **test command**: `/test-security`

### TC-20: Mismatched credential owners and unresolvable owners are refused
- **spec_ref**: `#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner`
- **type**: security
- **preconditions**: (a) a run whose model credential and forge credential are owned by different users; (b) a run with no resolvable owner on an organisation-owned model credential
- **steps**: Attempt commit and push in each
- **expected result**: (a) credential-scope-refused, as a refusal and not a warning; (b) owner-unresolvable on commit and push, while inference for that run remains permitted
- **test command**: `/test-security`

### TC-21: A bare push grant does not resolve
- **spec_ref**: `#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant`
- **type**: security
- **persona**: Noor Yilmaz (Municipal CISO / functional admin configuring the agent)
- **preconditions**: An agent whose tool list contains the push tool as a bare exact identifier
- **steps**: Resolve the agent's grants and enumerate its tools
- **expected result**: The push tool does not resolve and is not advertised; the misconfiguration is visible at configuration time rather than during an unattended run
- **test command**: `/test-persona-noor`

### TC-22: An argument-scoped grant is enforced before any side effect
- **spec_ref**: `#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant`
- **type**: security
- **preconditions**: An agent holding a push grant pinning one repository and constraining the branch
- **steps**: Push a branch outside the constraint, and push to a repository outside the pin
- **expected result**: Both return argument-not-permitted before any network operation; the classification of the tool remains destructive despite the narrowing
- **test command**: `/test-security`

### TC-23: Read, edit and push are separately conferrable
- **spec_ref**: `#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant`
- **type**: api
- **preconditions**: An agent granted only the inspection and file-read tools
- **steps**: Attempt a file write and a push; then re-run the inspection tools
- **expected result**: Write and push are refused for want of a resolving grant; inspection continues to work
- **test command**: `/test-api`

### TC-24: An unapproved write never happens
- **spec_ref**: `#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form`
- **type**: security
- **preconditions**: An agent holding a resolving write grant with no approval and no pre-authorisation
- **steps**: Call each write-shaped tool
- **expected result**: Each returns approval-required; no file is written and no commit is created; the audit trail records the refusal
- **test command**: `/test-security`

### TC-25: A pre-authorisation covers one run and cannot be widened
- **spec_ref**: `#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form`
- **type**: security
- **persona**: Noor Yilmaz (approving an unattended run)
- **preconditions**: A run-scoped pre-authorisation bounded by a grant pinning one repository and one branch pattern
- **steps**: Use it in its own run; then start a second run with the same agent; then attempt a write outside the pinned arguments within the authorised run; then sweep the configuration surface for any setting that skips the gate
- **expected result**: It applies only to its own run and only within the pinned arguments; the second run needs its own; no configuration disables the gate; the record names a human decision-maker
- **test command**: `/test-persona-noor`

### TC-26: Every write, successful or refused, is audited without secrets
- **spec_ref**: `#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval`
- **type**: security
- **preconditions**: A run that performs one successful push and at least one write refused for each of a confinement, grant and approval reason
- **steps**: Read the run's audit trail and inspect every record
- **expected result**: The push record names run, agent, tool, classification, owner, credential, approval, arguments and outcome; each refusal is recorded with its reason; no record contains a credential, filesystem path or internal host name; no workspace is attached
- **test command**: `/test-security`

### TC-27: Forge egress is decided by the single policy source
- **spec_ref**: `#requirement-forge-egress-is-authorised-per-run-by-the-single-policy-source`
- **type**: security
- **preconditions**: (a) a run whose agent holds no resolving grant needing the forge; (b) the same run with the policy source configured to deny
- **steps**: Attempt egress to the forge host in each; then search the workspace tooling for any static host list
- **expected result**: Both denied by the policy decision point; the result carries the egress-denied code and does not present as a timeout or generic transport failure; no second static allowlist exists in the tooling
- **test command**: `/test-security`

### TC-28: Refusal codes are mutually distinguishable
- **spec_ref**: `#requirement-forge-egress-is-authorised-per-run-by-the-single-policy-source`
- **type**: functional
- **preconditions**: One run configured to reach each refusal in turn
- **steps**: Trigger a confinement failure, a forbidden path, a missing grant, a missing approval, an unresolvable owner, a credential scope refusal, a quota breach and an egress denial
- **expected result**: Eight distinct stable error codes, each distinguishable in the operator's logs; none collapses into a generic failure
- **test command**: `/test-functional`

### TC-29: A consumer with its own provider is conformant
- **spec_ref**: `#requirement-the-toolset-is-a-contract-with-two-consumers-and-its-runtime-is-not-required-to-be-shared`
- **type**: regression
- **preconditions**: A second workspace provider implementing the seam against a local checkout, with the Nextcloud instance unreachable
- **steps**: Run the contract conformance suite (TC-1, TC-6, TC-7, TC-9 through TC-28 where provider-independent) against it
- **expected result**: Every contract-level assertion passes; any assertion that can only pass by calling the Hermiq endpoint is reported as a specification defect, not a consumer failure
- **test command**: `/test-regression`

### TC-30: The iteration cap and per-turn wall clock are unchanged
- **spec_ref**: `#requirement-the-toolset-is-a-contract-with-two-consumers-and-its-runtime-is-not-required-to-be-shared`
- **type**: regression
- **preconditions**: The capability as delivered
- **steps**: Read the governed tool loop's iteration cap and the CLI per-turn wall clock; then run the bounded milestone task end to end within them
- **expected result**: Both are at their pre-change values; the milestone completes without either being raised, and its turn count and wall clock are recorded
- **test command**: `/test-regression`

## Coverage Summary

| # | Requirement | Test cases | Covered |
|---|---|---|---|
| 1 | Workspace and git capability is exposed only as a closed, named MCP tool surface | TC-1, TC-2, TC-3 | yes |
| 2 | The CLI built-in denylist is never relaxed to provide code-editing capability | TC-4, TC-5 | yes |
| 3 | Each run gets a bounded workspace on the governed side that the model cannot address by path | TC-6, TC-7, TC-8, TC-9, TC-10 | yes |
| 4 | Every path argument is confined to the workspace after symlink resolution | TC-11, TC-12, TC-13 | yes |
| 5 | The repository metadata directory is never writable through a governed tool | TC-14, TC-15 | yes |
| 6 | The forge credential and the model credential are separate, and neither reaches the model | TC-16, TC-17, TC-18 | yes |
| 7 | Commits are authored, and pushes authorised, as the resolved run owner | TC-19, TC-20 | yes |
| 8 | Repo-effecting tools resolve only from an argument-scoped grant | TC-21, TC-22, TC-23 | yes |
| 9 | Write-shaped tools route through the approval gate, with a run-scoped pre-authorisation form | TC-24, TC-25 | yes |
| 10 | Every governed workspace write is audited with owner, credential and approval | TC-26 | yes |
| 11 | Forge egress is authorised per run by the single policy source | TC-27, TC-28 | yes |
| 12 | The toolset is a contract with two consumers, and its runtime is not required to be shared | TC-29, TC-30 | yes |

All twelve requirements are covered. TC-11 through TC-17 and TC-24 through TC-27 are the mandatory
security core; a green run without them is not evidence of anything.

After implementation, TC-4, TC-8, TC-11, TC-12, TC-14, TC-16 and TC-21 carry ongoing regression
value — they are the cases that catch a silent widening of the boundary — and should be promoted to
reusable test scenarios with `/test-scenario-create` so they survive archiving of this change.

## Out of Scope

- **A production build workload.** Turn count and wall clock are measured once, on the bounded
  milestone (TC-30). No load or throughput testing of a pipeline-shaped run, because no such run
  is in scope.
- **The Hydra builder's own pipeline.** TC-29 tests the contract against a second provider; it does
  not test Hydra's stages, entrypoints or credential loader, none of which this change touches.
- **The governed MCP endpoint, the run token and the PDP as such.** They are covered by
  `cli-runner-governed-mcp-and-egress`. This plan tests only what this capability adds on top —
  which tools are served, and what the PDP is asked about the forge host.
- **Accessibility beyond the approval surface.** The capability has no user interface of its own;
  the approval and pre-authorisation views inherit the approval gate's existing WCAG 2.1 AA coverage
  and are tested there.
