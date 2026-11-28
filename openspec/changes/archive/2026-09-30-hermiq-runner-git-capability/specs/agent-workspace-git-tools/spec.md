# agent-workspace-git-tools Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-runner-git-capability` (this change — introduces the capability)

## Purpose

Hermiq's governed CLI runner is inference-only: every built-in that could touch a shell, the
filesystem or the network is denied, the working directory is a throwaway scratch, and the only
route out is a proxy that asks Hermiq's PDP per CONNECT. This capability defines how a
**code-editing** turn becomes possible under that posture **without relaxing the denylist by a
single entry** — as a closed set of individually-grantable MCP tools over a bounded, run-scoped
workspace that lives on the governed side, with the forge credential held server-side and every
write attributed to a real person.

It is specified as a **contract with two consumers**: Hermiq's `llm-runner` and Hydra's builder
(`hydra-exec-personal-cli-runner`, merged — its `## Investigation` section is the binding input,
and the product owner resolved on 2026-07-27 that the toolset is shared from day one). Related:
ADR-005 (security), ADR-031 (declarative-vs-imperative — see design.md for the exception
argument), `cli-runner-governed-mcp-and-egress` (the governed MCP endpoint, run token and PDP this
builds on) and `hydra-console-agent-leaves` (the argument-scoped grant grammar this reuses).

## ADDED Requirements

### Requirement: Workspace and git capability is exposed only as a closed, named MCP tool surface
The system MUST expose workspace and git capability to an agent exclusively as named MCP tools with
typed arguments, and MUST NOT expose any tool that accepts a free-form command string, a
subcommand name, a git refspec, or a remote URL.

The surface MUST be closed: repository inspection (status, diff, log), workspace file access
(list, read, write, delete, apply patch), local history (create branch, checkout branch, commit),
workspace materialisation (open), and exactly one repo-effecting operation (push). Operations that
rewrite history the run did not create — merge, rebase, cherry-pick, reset, revert — and
operations that cannot be described by a grant — force push, ref deletion, tag push, remote
mutation — MUST NOT be part of the surface, so they remain unreachable even by a granted caller.

Forge operations other than push (pull-request creation, label writes, comments, merges) MUST NOT
be part of this surface; they remain with the flow and orchestrator layer.

All tools MUST be served through the existing governed MCP dispatch path, so that classification,
grant resolution, the approval gate and redaction apply to every call. The system MUST NOT
introduce a second tool-execution path or side channel for workspace access.

#### Scenario: A free-form command tool is not offered
- GIVEN an agent granted every tool in the workspace surface
- WHEN the agent enumerates its tools
- THEN no tool accepting a command string, a subcommand argument, a refspec or a remote URL is present
- AND there is no tool by which the agent can express a force push, a ref deletion or a history rewrite

#### Scenario: The repository is named by slug, never by URL
- GIVEN a tool that materialises or pushes a repository
- WHEN the agent supplies the repository argument
- THEN the argument MUST be a forge-relative repository slug
- AND the forge host MUST be resolved server-side from configuration, never from the argument

#### Scenario: Workspace tools dispatch through the single governed path
- GIVEN a workspace tool invocation arriving over the governed MCP endpoint
- WHEN the invocation is dispatched
- THEN it MUST pass through the same invoker used by every other governed tool
- AND classification, grant resolution, the approval gate and redaction MUST all apply
- AND no alternative route to the workspace MUST exist

### Requirement: The CLI built-in denylist is never relaxed to provide code-editing capability
The system MUST NOT remove any entry from the runner's disallowed built-in tool list in order to
grant filesystem, shell or network capability, and MUST NOT rely on the completeness of that list
as a security boundary.

The denylist is a denylist: a newly-shipped CLI built-in arrives un-denied. The boundary is
therefore the container itself — nothing worth reading inside it and no route out except the
policed proxy — and this capability MUST preserve that property rather than consume it.

Re-enabling a shell or a native file read MUST be treated as credential exfiltration, not merely
as a wider tool surface: the runner injects the resolved provider credential into the CLI child's
environment, and injects the per-run bearer token into the same environment inside the proxy URL's
userinfo, so any capability that can read that process's environment can read both secrets.

The tool-search built-in MUST remain un-denied. It is how the CLI loads deferred MCP tools;
denying it makes every governed tool silently unreachable, with the tool listing still serving
them and the model still reporting that it has none.

#### Scenario: The denylist is unchanged by this capability
- GIVEN the runner's disallowed built-in tool list before this capability exists
- WHEN the capability is delivered
- THEN the list MUST contain the same entries, including the shell and filesystem built-ins
- AND no entry MUST be removed to let the model reach the workspace directly

#### Scenario: A shell built-in would expose both live secrets
- GIVEN a governed run whose CLI child holds the provider credential and the per-run proxy URL in its environment
- WHEN a shell or environment-reading built-in is proposed for re-enablement
- THEN the proposal MUST be refused
- AND the refusal MUST be recorded on the grounds that the model would gain the personal subscription credential and the run's bearer token

#### Scenario: Tool search stays available
- GIVEN the disallowed built-in tool list
- WHEN it is reviewed or edited for any reason
- THEN the tool-search built-in MUST remain absent from it
- AND its carve-out MUST be documented at the point of edit

### Requirement: Each run gets a bounded workspace on the governed side that the model cannot address by path
The system MUST materialise a workspace whose key is derived from the run token and never from a
caller-supplied argument, and MUST place that workspace outside the container in which the model's
CLI runs.

The runner's per-call scratch directory, and its `HOME` and `TMPDIR`, MUST continue to point at a
throwaway location that is removed on every exit path; the workspace MUST NOT be that location and
MUST NOT be reachable as it. The runner therefore remains stateless per call.

The system MUST NOT return a filesystem path to the model. Workspace materialisation MUST return
an opaque workspace identifier together with the resolved repository, ref and head commit, and all
subsequent tools MUST accept workspace-relative paths only.

The workspace MUST be bounded by a size and file-count budget, and its lifetime MUST be bound to
the run token. A tool other than workspace materialisation that is called before a workspace
exists for the run MUST be refused.

#### Scenario: One run cannot address another run's workspace
- GIVEN two concurrent runs each holding their own run token
- WHEN one run supplies a workspace identifier belonging to the other
- THEN the invocation MUST be refused
- AND the workspace served MUST be the one derived from the caller's own token

#### Scenario: The model never learns a filesystem path
- GIVEN a successful workspace materialisation
- WHEN the result is returned to the model
- THEN the result MUST contain an opaque identifier, the repository, the ref and the head commit
- AND the result MUST NOT contain any filesystem path

#### Scenario: The runner stays stateless per call
- GIVEN a governed turn that used the workspace
- WHEN the CLI child exits by any path, including timeout, output overflow, spawn failure or parse failure
- THEN the container-side scratch directory MUST be removed
- AND no part of the workspace MUST remain inside the container

#### Scenario: A tool call before materialisation is refused
- GIVEN a run for which no workspace has been materialised
- WHEN the agent calls a file or git tool
- THEN the call MUST be refused with a stable error code indicating the workspace is absent
- AND no filesystem operation MUST be performed

### Requirement: Every path argument is confined to the workspace after symlink resolution
The system MUST reject any path argument that is absolute or that contains a parent-directory
traversal segment, and MUST additionally resolve every path argument through its symbolic links
before testing containment, rejecting any path whose resolved target lies outside the workspace
root.

Lexical containment checking alone MUST NOT be treated as sufficient: a workspace-relative path
that resolves through a symbolic link to a location outside the root MUST be refused.

A patch-application tool MUST validate every target path in the patch against these rules before
applying any part of it, and MUST refuse the patch in whole when any target fails. A partially
applied patch MUST NOT be a possible outcome.

#### Scenario: A traversal path is refused
- GIVEN an agent holding a file-write grant
- WHEN it supplies a path containing a parent-directory traversal segment
- THEN the call MUST be refused with a stable error code indicating the path is outside the workspace
- AND no file MUST be created, modified or read

#### Scenario: A symlink escape is refused
- GIVEN a workspace containing a symbolic link whose target is outside the workspace root
- WHEN the agent supplies a workspace-relative path that resolves through that link
- THEN the call MUST be refused after symlink resolution
- AND the refusal MUST NOT depend on the lexical form of the supplied path

#### Scenario: A patch touching a refused path is rejected whole
- GIVEN a patch whose hunks target several files, one of which resolves outside the workspace
- WHEN the patch is applied
- THEN the whole patch MUST be rejected
- AND no hunk from that patch MUST be present in the workspace afterwards

### Requirement: The repository metadata directory is never writable through a governed tool
The system MUST refuse any write-shaped operation whose target resolves under the repository
metadata directory, independently of and in addition to the workspace containment check.

This includes hooks, configuration, and any other file beneath that directory. A write there
converts a file-write capability into command execution, because the written content is executed
by the next git operation — which the model invokes through a governed tool, running with the tool
implementation's privileges and the resolved forge credential in scope.

The system MUST NOT implement this as an allowlist of permitted metadata files: the set of
configuration keys that cause a command to be executed grows with the version control system, so
an allowlist would reproduce the denylist weakness this capability exists to avoid.

#### Scenario: A hook write is refused
- GIVEN an agent holding a file-write grant over a materialised workspace
- WHEN it writes to a path resolving under the repository metadata directory's hooks location
- THEN the call MUST be refused with a stable error code indicating a forbidden path
- AND no file MUST be created

#### Scenario: A configuration write is refused
- GIVEN the same agent
- WHEN it writes to the repository's own configuration file, whether directly or through a patch
- THEN the call MUST be refused
- AND the refusal MUST occur even though the path lies inside the workspace root

#### Scenario: The refusal is a blanket rule, not an allowlist
- GIVEN a write target under the repository metadata directory that no known configuration key makes executable
- WHEN the write is attempted
- THEN it MUST still be refused
- AND no permitted-file allowlist MUST be consulted

### Requirement: The forge credential and the model credential are separate, and neither reaches the model
The system MUST treat the forge credential and the model provider credential as distinct secrets
with distinct scopes and distinct holders.

The model provider credential MUST continue to be resolved through the credential broker and
injected only into the CLI child's environment, restricted to the provider adapter's own
allowlisted keys, exactly as it is today.

The forge credential MUST be resolved at the tool boundary on the governed side and MUST NOT enter
the container in which the model's CLI runs. It MUST NOT appear on any command line, MUST NOT be
persisted into repository configuration inside the workspace, MUST NOT be returned in any tool
result, and MUST NOT appear in any error, log line or transcript.

Version-control transport errors MUST be mapped to stable error codes and redacted before they
cross the boundary; raw transport output MUST NOT be forwarded to the model.

The workspace MUST NOT be reachable as the CLI child's home or temporary directory, so that a
credential file written by any tooling cannot land inside the workspace and be read back through a
file-read tool.

#### Scenario: The forge credential is absent from the model's container
- GIVEN a run whose agent holds the push grant
- WHEN the CLI child's environment, command line and working directory are inspected
- THEN the forge credential MUST NOT be present in any of them
- AND the push MUST nevertheless succeed, because it is performed on the governed side

#### Scenario: A failed push does not leak the credential
- GIVEN a push that the forge refuses
- WHEN the failure is returned to the model
- THEN the result MUST carry a stable error code and a redacted message
- AND the message MUST NOT contain the credential, a remote URL carrying credentials, or raw transport output

#### Scenario: The workspace is not the CLI child's home
- GIVEN a governed turn with a materialised workspace
- WHEN the CLI child's home and temporary directory settings are inspected
- THEN they MUST point at the per-call throwaway scratch
- AND they MUST NOT point at, or contain, the workspace

### Requirement: Commits are authored, and pushes authorised, as the resolved run owner
The system MUST derive the commit author and committer identity server-side from the resolved run
owner, and MUST NOT accept an author, committer, name or email argument on any tool. Repository-local
configuration inside the workspace MUST NOT determine the identity of a commit.

The identity namespace MUST be the Nextcloud user id, matching the run-owner namespace used by the
Hydra execution plane, so that attribution joins across both systems.

The forge credential used for a push MUST be personal-scope and owned by the same user as the
model provider credential the turn ran on. A run assembling two personal credentials belonging to
two different people MUST be refused, not warned about.

A run with no resolvable owner MUST NOT author a commit and MUST NOT push, even when it is
permitted to run inference on an organisation-owned credential. An organisation-owned run MAY push
as an explicitly declared machine identity, which MUST be recorded as such, and MUST NOT fall back
to any person's credential.

#### Scenario: Commit identity comes from the owner, not the model
- GIVEN an agent invoking the commit tool
- WHEN it attempts to supply an author or committer value
- THEN the tool schema MUST NOT accept such an argument
- AND the resulting commit MUST be authored as the resolved run owner

#### Scenario: Mismatched credential owners are refused
- GIVEN a run whose model provider credential is owned by one user and whose forge credential is owned by another
- WHEN a push is attempted
- THEN the push MUST be refused with a stable error code indicating credential scope refusal
- AND the refusal MUST NOT be downgraded to a warning

#### Scenario: An unowned run cannot author or push
- GIVEN a run with no resolvable owner, executing on an organisation-owned model credential
- WHEN the agent calls the commit or push tool
- THEN the call MUST be refused with a stable error code indicating the owner is unresolvable
- AND inference for that run MUST remain permitted

#### Scenario: Repository-local configuration cannot redirect attribution
- GIVEN a workspace whose repository-local configuration names a different identity
- WHEN a commit is made through the governed tool
- THEN the commit MUST still be attributed to the resolved run owner

### Requirement: Repo-effecting tools resolve only from an argument-scoped grant
The system MUST classify every tool in this surface that produces an effect on the remote
repository as repo-effecting, and MUST refuse to resolve such a tool from a bare exact-identifier
grant.

A repo-effecting tool MUST resolve only when the grant is argument-scoped — pinning the repository
and constraining the branch to a single value, a closed set, or a pattern. A grant that does not
resolve MUST NOT cause the tool to be advertised in the tool listing, so that an over-broad or
under-specified configuration fails visibly at configuration time rather than during an unattended
run.

Argument constraints carried by the grant MUST be enforced at invocation, before any side effect.
Narrowing a grant MUST NOT change the classification of the tool it narrows: every write-shaped and
destructive tool in this surface remains write-shaped or destructive however narrowly it is
granted.

Every other tool in the surface MUST remain individually grantable, so that read-only inspection,
in-workspace editing and pushing are three separately-conferrable capabilities.

#### Scenario: A bare push grant does not resolve
- GIVEN an agent whose tool list contains the push tool as a bare exact identifier
- WHEN the agent's grants are resolved
- THEN the push tool MUST NOT resolve
- AND it MUST NOT appear in the agent's tool listing

#### Scenario: An argument-scoped push grant resolves and is enforced
- GIVEN an agent holding a push grant pinning one repository and constraining the branch
- WHEN the agent pushes a branch outside the constraint
- THEN the invocation MUST be refused with a stable error code indicating the argument is not permitted
- AND the refusal MUST occur before any network operation

#### Scenario: Narrowing does not downgrade classification
- GIVEN an argument-scoped grant over a destructive tool in this surface
- WHEN the tool's classification is resolved
- THEN it MUST remain destructive
- AND the approval requirement for destructive tools MUST still apply

#### Scenario: Read, edit and push are separately grantable
- GIVEN an agent granted only the inspection and file-read tools
- WHEN it attempts a file write or a push
- THEN the attempt MUST be refused for want of a resolving grant
- AND the inspection tools MUST continue to work

### Requirement: Write-shaped tools route through the approval gate, with a run-scoped pre-authorisation form
The system MUST route every write-shaped and destructive tool in this surface through the existing
human-approval gate before any side effect occurs.

The system MUST additionally support a **run-scoped pre-authorisation**: a recorded human decision,
taken before dispatch, that authorises the write-shaped capability for one run within the bounds of
the grant's pinned arguments. A run-scoped pre-authorisation MUST expire with the run token, MUST
NOT extend to any other run, and MUST NOT widen the grant it is bounded by.

A run-scoped pre-authorisation MUST be a real approval record with a named human decision-maker. It
MUST NOT be implemented as a configuration flag that disables the gate, and the system MUST NOT
provide any other means of bypassing the gate for these tools.

Where the dispatching system already records an accountable human decision to start an unattended
run, that decision MAY serve as the pre-authorisation, provided it names the owner and is recorded
in the same auditable form.

#### Scenario: An unapproved write is refused before it happens
- GIVEN an agent holding a resolving write grant but no valid approval or pre-authorisation
- WHEN it calls a write-shaped tool
- THEN the call MUST be refused with a stable error code indicating approval is required
- AND no file MUST be written and no commit MUST be created

#### Scenario: A run-scoped pre-authorisation covers the run and nothing else
- GIVEN a run-scoped pre-authorisation bounded by a grant pinning one repository and one branch pattern
- WHEN the same agent starts a different run
- THEN the earlier pre-authorisation MUST NOT apply to it
- AND the new run MUST require its own approval or pre-authorisation

#### Scenario: The gate cannot be disabled by configuration
- GIVEN any configuration of the system
- WHEN a write-shaped tool in this surface is invoked
- THEN an approval or a valid run-scoped pre-authorisation MUST be present
- AND no setting MUST exist that permits the invocation without one

### Requirement: Every governed workspace write is audited with owner, credential and approval
The system MUST write an audit record for every write-shaped and destructive workspace tool call,
on the existing run-audit path, so that a version-control write is auditable by the same mechanism
as any other tool call.

Each record MUST name the run, the agent, the tool, its classification, the resolved owner, the
credential used, the approval or pre-authorisation that permitted it, the invocation arguments, and
the outcome. A refused call MUST be recorded with its refusal reason as faithfully as a successful
one.

Audit records MUST pass through redaction before persistence, and MUST NOT contain any credential,
any filesystem path, or any internal host name. The workspace itself MUST NOT be attached to an
audit record.

#### Scenario: A push produces a complete attribution record
- GIVEN a successful push through a governed tool
- WHEN the run's audit trail is read
- THEN a record naming the run, the agent, the tool, the owner, the credential and the approval MUST be present
- AND its arguments and outcome MUST be recorded

#### Scenario: A refused write is audited too
- GIVEN a write refused for a confinement, grant or approval reason
- WHEN the run's audit trail is read
- THEN the refusal MUST be recorded with its reason
- AND the record MUST NOT be omitted merely because no side effect occurred

#### Scenario: Audit records carry no secrets or paths
- GIVEN any workspace audit record
- WHEN it is read
- THEN it MUST NOT contain a credential, a filesystem path or an internal host name

### Requirement: Forge egress is authorised per run by the single policy source
The system MUST authorise egress to the forge host through the same per-request policy decision
point that governs all other agent egress, keyed on the run's token binding, and MUST NOT
introduce a second, static allowlist inside the workspace tooling.

The forge host MUST be reachable only for a run whose agent holds a resolving grant for a tool that
needs it. A policy denial MUST fail fast and MUST be surfaced as a distinct, stable error code, so
that a policy decision is never indistinguishable from the forge being unreachable.

#### Scenario: The forge host is denied without the grant
- GIVEN a run whose agent holds no resolving grant for a repo-effecting or materialising tool
- WHEN egress to the forge host is attempted for that run
- THEN the policy decision point MUST deny it
- AND the denial MUST NOT depend on any list held inside the workspace tooling

#### Scenario: A policy denial is distinguishable from an outage
- GIVEN a run for which the policy decision point denies the forge host
- WHEN the tool result is returned
- THEN it MUST carry the egress-denied error code
- AND it MUST NOT present as a network timeout or a generic transport failure

### Requirement: The toolset is a contract with two consumers, and its runtime is not required to be shared
The system MUST specify the tool surface, argument and result shapes, error codes, confinement
rules, grant and classification model, credential separation, attribution rule and audit record
shape as a **contract**, normative for every consumer.

The transport by which a consumer reaches the tools MUST NOT be part of that contract. A consumer
that implements the contract against its own workspace provider, without a dependency on the
Nextcloud instance being reachable, MUST be conformant.

The system MUST express the workspace as a provider seam, so that a governed-side workspace and an
existing in-container checkout are two implementations of one contract rather than two designs.

No requirement in this capability MAY be satisfiable only by calling the Hermiq endpoint. A
requirement that cannot be met by a conformant consumer with its own provider is defective and MUST
be rewritten.

Divergences between consumers MUST be stated rather than absorbed. The multi-turn iteration cap and
the per-turn wall clock are security parameters and MUST NOT be raised by this capability; a
build-shaped workload requiring a repository toolchain, a database and hundreds of turns remains
outside this capability's execution model.

#### Scenario: A consumer with its own provider is conformant
- GIVEN a consumer implementing the contract against an in-container checkout, with no Nextcloud dependency
- WHEN its implementation is assessed against this capability
- THEN it MUST be able to satisfy every requirement
- AND any requirement it cannot satisfy for want of the Hermiq endpoint MUST be treated as a defect in this specification

#### Scenario: The iteration cap is not raised
- GIVEN this capability as delivered
- WHEN the governed tool loop's iteration cap and the per-turn wall clock are inspected
- THEN they MUST be unchanged
- AND no requirement here MUST depend on either being raised

#### Scenario: Divergence is recorded rather than papered over
- GIVEN two consumers whose workspace location, turn budget, quality-gate loop and approval shape differ
- WHEN the contract is documented
- THEN each divergence MUST be named explicitly
- AND the shared part MUST be limited to what both consumers can genuinely uphold

## Non-Functional Requirements

- **Performance:** Workspace materialisation is the only long operation and MUST carry its own
  timeout, distinct from the read and write tools. Every other tool MUST complete well inside the
  CLI's per-turn wall clock or return a timeout error code rather than hold the turn open. A policy
  denial on the forge host MUST fail fast rather than wait on a network timeout.
- **Resource bounds:** Each workspace MUST be bounded by a size and file-count budget, and its
  lifetime MUST be bound to the run token. Exceeding the budget MUST produce a stable error code
  rather than an unbounded store.
- **Accessibility:** This capability has no direct user interface. Where an approval or
  pre-authorisation decision is surfaced to a human, it MUST meet the approval gate's existing
  WCAG 2.1 AA obligations and MUST present the repository, the branch constraint and the requesting
  agent so the decision is informed rather than nominal.
- **Internationalization:** Dutch and English MUST both be supported for every human-facing string
  this capability produces — approval prompts and operator-facing error surfaces. Tool identifiers,
  argument names and error codes are stable machine identifiers and MUST NOT be translated.
- **Observability:** Every refusal MUST be distinguishable by error code from every other refusal,
  so that a confinement failure, a missing grant, a missing approval, an owner failure, a credential
  scope refusal and an egress denial are never conflated in an operator's logs.

## Acceptance Criteria

- [ ] The tool surface is closed and named, with no free-form command, subcommand, refspec or remote-URL argument anywhere in it
- [ ] The runner's disallowed built-in tool list is byte-for-byte unchanged, and the tool-search carve-out is documented
- [ ] A workspace is keyed from the run token, lives outside the model's container, and no tool result returns a filesystem path
- [ ] Absolute paths, traversal segments and symlink escapes are all refused, and a patch touching a refused path is rejected whole
- [ ] Every write-shaped target under the repository metadata directory is refused, with no permitted-file allowlist
- [ ] The forge credential is absent from the model's container, its command line, its results, its errors and the transcript
- [ ] Commits are authored as the resolved run owner; mismatched credential owners and unresolvable owners are refused
- [ ] A bare exact-identifier push grant does not resolve and the tool is not advertised; an argument-scoped grant is enforced before any side effect
- [ ] No configuration disables the approval gate; a run-scoped pre-authorisation is a real record bounded by the grant and the run
- [ ] Every write-shaped call, successful or refused, produces a redacted audit record naming owner, credential and approval
- [ ] Forge egress is decided by the single policy source per run, and a denial is distinguishable from an outage
- [ ] A conformant consumer can implement the contract against its own workspace provider with no Nextcloud dependency
- [ ] The governed tool loop's iteration cap and per-turn wall clock are unchanged

## Notes

- **Binding input:** the `## Investigation` section of
  `../hydra/openspec/changes/hydra-exec-personal-cli-runner/design.md` — the posture comparison,
  the 9-axis decision matrix, "what the hydra builder needs that the runner cannot give", and the
  provisional recommendation that names this change.
- **Product-owner decision (2026-07-27):** the toolset is designed as shared from day one; "Hydra
  cannot use it" counts as a design failure. That is why the final requirement exists and why the
  transport is deliberately excluded from the contract.
- **Depends on** `cli-runner-governed-mcp-and-egress` for the governed MCP endpoint, the per-run
  token and the policy decision point, and on `hydra-console-agent-leaves` for the argument-scoped
  grant grammar. This capability contributes **no new grant grammar**.
- **The repository-metadata write rule** was not on the investigation's matrix. It is the primary
  control for a distinct vulnerability, not defence in depth: without it, a file-write tool becomes
  command execution on the next git operation.
- **Non-goals**, restated so they are not read back in later: no migration of the Hydra builder and
  no Hydra file touched, no increase to the iteration cap or per-turn wall clock, no build toolchain
  or quality-gate loop, no forge operations beyond push, no production build workload, and no
  relaxation of the built-in denylist by any entry.
- **Open questions** are carried in `design.md`: workspace retention on failure, whether a
  non-Hermiq consumer should ever use the Hermiq transport, and whether the materialisation ref
  should be constrainable by the grant the way the push repository and branch are.
