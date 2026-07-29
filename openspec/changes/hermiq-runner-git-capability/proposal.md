---
kind: code
depends_on: [cli-runner-governed-mcp-and-egress, hydra-console-agent-leaves]
---

# Proposal: hermiq-runner-git-capability

## Summary

Hermiq's `llm-runner` ExApp is inference-only by construction: every built-in that touches a
shell, the filesystem or the network is denied, the CLI is locked to `mcp__hermiq__*` over
`--strict-mcp-config`, egress leaves only through a per-CONNECT PDP proxy, and the working
directory is a throwaway `mkdtemp` that is wiped on every exit path. It therefore cannot edit
code. Hydra's builder container can — it has full `Bash`, a real checkout, a running Nextcloud
and ~420 turns — and it is what actually edits code today, on a completely separate credential
and governance story. This change specifies the missing middle: a **governed git/file toolset,
exposed as individually-grantable MCP tools over a bounded, server-side, run-scoped workspace**,
so a code-editing turn can run under Hermiq's governance without the denylist being relaxed by a
single entry. Per the product-owner decision of 2026-07-27 the toolset is designed as **shared
from day one** — Hermiq's runner and Hydra's builder are both first-class consumers, and "Hydra
cannot use it" counts as a design failure.

## Motivation

The binding input is the `## Investigation` section of the merged Hydra change
`hydra-exec-personal-cli-runner` (`../hydra/openspec/changes/hydra-exec-personal-cli-runner/design.md`).
Its 9-axis decision matrix found exactly one blocking axis — lifting `Bash` — and named that as
the option to refuse. Every other axis resolved to "needs a governed addition", and all of them
pointed the same way: expose git and file operations as governed, individually-grantable
operations with server-side credentials, rather than handing the model a shell in a container
that happens to have `git` installed. Its recommendation names this change by name and sets its
bar: governed MCP git/file tools, server-side PAT handling, a run-token-scoped workspace, PDP
entries for the forge host, and an MCP-only tool surface.

Three forces make it worth doing now rather than re-litigating per sprint:

1. **Two execution planes, two credential stories.** Both Hydra and Hermiq run `claude -p`
   non-interactively against a personal-scope subscription and both refuse organisation scope
   fail-closed — Hermiq in `ProviderFactory::assertPersonalScopeCredential()`, Hydra at its
   dispatch chokepoint. They agree on the rule and share none of the mechanism. Every governed
   capability built on one side has to be rebuilt on the other.
2. **Hydra's actual code-editing posture is weaker than Hermiq's, not stronger.** Hydra's
   builder strips `GIT_TOKEN` for the model's turn yet leaves a plaintext PAT readable in
   `~/.config/gh/hosts.yml`, and had to push every privileged act into a 1311-line entrypoint to
   keep the model away from credentials. A governed tool has neither problem, because the
   credential never enters the container the model runs in.
3. **The chain head is flows-first.** `hydra-console-agent-leaves` already establishes that a
   command capability is an argument-scoped, approval-gated, attributed grant rather than a
   bespoke service. A push tool is the same shape of problem — a single multi-target operation
   that is useless if granted whole.

Doing nothing means the next code-editing capability lands wherever it is easiest, which is
inside a shell, which is where governance stops.

## Affected Projects

- [ ] Project: `hermiq` — new governed workspace/git MCP toolset, a run-scoped server-side
      workspace with path confinement, forge-credential resolution at the tool boundary, git
      identity derived from the run owner, per-write audit records, and PDP entries for the forge
      host. No change to `DISALLOWED_BUILTIN_TOOLS`.
- [ ] Project: `hydra` — named as the second consumer of the same tool contract and the same
      governance rules. This change specifies the contract and the seam; it does **not** migrate
      Hydra's builder onto it.

## Scope

### In Scope

- The **tool surface**: which git and file operations become governed MCP tools, which ones are
  deliberately excluded, and why an "escape hatch" tool (raw `git`, raw shell) is excluded by
  construction rather than by policy.
- The **workspace boundary**: a per-run workspace addressed by the run token, living on the
  governed side rather than inside the runner container, with confinement rules that survive
  `..`, absolute paths, symlinks, and writes into `.git/`.
- **Credential separation**: the forge PAT and the Claude subscription credential are different
  secrets with different scopes and different holders; neither may appear in argv, in a tool
  result, in the transcript, or in the other's blast radius.
- **Attribution**: which identity a commit is authored as and which identity a push is
  authorised as, and the rule that reconciles Hermiq's `assertPersonalScopeCredential()` posture
  with Hydra's per-run owner attribution.
- **Grant model**: every tool individually grantable; every repo-effecting tool grantable only in
  argument-scoped form; classification, default-deny and the approval gate all apply.
- **The explicit argument against re-enabling `Bash`**, recorded as a requirement rather than a
  comment, including the denylist's structural weakness.
- **The shared design**: the seam that lets Hydra's builder consume the same contract, and an
  honest statement of where the two consumers genuinely diverge.

### Out of Scope

- **Migrating Hydra's builder.** Hydra keeps its current execution plane, its container images,
  its entrypoints and its credential loader. The contract is specified and the seam is built so
  that a later change can adopt it; that change is not this one, and no Hydra file is touched.
- **A production build workload.** Delivery ends at one small, bounded, code-editing task running
  end to end under this posture — the milestone the investigation's recommendation names. Planning
  a pipeline on top of it is a later decision with real numbers behind it.
- **Raising the multi-turn budget.** `MAX_TOOL_ITERATIONS` stays where it is. A ~420-turn
  build-shaped workload is not moving into the runner, and this change does not pretend it can.
- **A build toolchain.** PHP/composer/npm/PHPUnit, `phpcbf` autofix and the Hydra gate loop need
  a build host with a running Nextcloud and a database. Exposing them as MCP tools would move
  where the model *asks*, not where the work *runs*. Deliberately deferred.
- **Forge operations other than push.** PR creation, label flips, issue comments and merges stay
  with the orchestrator and the flow layer, per `hydra-exec-personal-cli-runner` Decision 7.
- **Relaxing `DISALLOWED_BUILTIN_TOOLS` by any entry**, including `Read` "just for the
  workspace". A denylist hole is indistinguishable from a bypass.

## Approach

Four moves, in dependency order.

1. **Define a bounded tool surface.** A small, closed set of MCP tools covering repository
   inspection (`status`, `diff`, `log`), file access within the workspace (`list`, `read`,
   `write`, `delete`, `apply_patch`), local history (`branch`, `checkout`, `commit`) and exactly
   one repo-effecting operation (`push`). No tool accepts a raw command string, a raw refspec, or
   a subcommand name — every operation is a named tool with typed arguments, because that is the
   only shape a grant can constrain.

2. **Put the workspace on the governed side.** The model never gets a directory; it gets tools.
   The workspace is materialised by the tool implementation, keyed to the run token, and lives
   outside the container the CLI runs in. This preserves the runner's statelessness — the
   property its whole security argument rests on — and it means a future un-denied CLI built-in
   finds nothing in the container worth reading.

3. **Keep both credentials server-side.** The Claude subscription credential stays where it is
   today: resolved through the broker, injected into the CLI child's environment, allowlisted to
   the provider's own keys. The forge PAT never goes near the container at all — it is resolved
   at the tool boundary, used for one push, and discarded. The commit identity is derived from
   the resolved run owner, never from the model and never from repository-local config.

4. **Grant it like the pipeline command capability.** Reuse the argument-scoped grant grammar
   from `hydra-console-agent-leaves` verbatim: a repo-effecting tool must be pinned to a repo and
   constrained to a branch pattern before it resolves at all, it stays classified
   write/destructive, and it routes through the approval gate — with a run-scoped
   pre-authorisation form so unattended dispatch is possible without making approval a
   rubber stamp.

## New Dependencies

None at the package level. The change composes existing Hermiq subsystems (`RunTokenService`,
`ToolGrantResolver`, `FacadeToolInvoker`, `ToolClassificationService`, `ApprovalService`,
`RedactionService`, the OpenRegister credential broker, the `EgressAuthorizeController` PDP) and
one new class of operating-system resource: a per-run workspace directory under the app's data
root. A `git` binary is required on the governed side, not in the runner container.

## Impact

- `openspec/specs/agent-workspace-git-tools/` — new capability (this change ships the delta).
- `lib/Controller/McpRunController.php` — would serve the new tools through the existing
  `tools/list` / `tools/call` path; no second dispatch path is introduced.
- `lib/Service/Engine/ToolGrantResolver.php` — consumes the argument-scoped grant form already
  being added by `hydra-console-agent-leaves`; this change adds no new grammar.
- `lib/Service/ToolClassificationService.php` — the git write tools must classify
  write/destructive; the hint-less fail-closed rule already produces that result.
- `lib/Controller/EgressAuthorizeController.php` — the forge host becomes PDP-allowed for
  runs holding the push grant, keyed on the run token's agent binding. No static second allowlist.
- `exapp/llm-runner/src/providers.js` — **unchanged by design.** The denylist is not edited.
- Hydra's `images/builder/` — unchanged by this change; named as the second consumer.

## Cross-Project Dependencies

- **Depends on** `cli-runner-governed-mcp-and-egress` (Hermiq) for the governed MCP endpoint, the
  per-run token, the 0600 MCP config file and the PDP proxy this change builds on.
- **Depends on** `hydra-console-agent-leaves` (Hermiq) for the argument-scoped grant grammar and
  the flow-run attribution rule. A bare exact-id grant on a push tool would be unacceptably
  broad, so this change cannot ship before that grammar exists.
- **Consumes the conclusions of** `hydra-exec-personal-cli-runner` (Hydra, merged) — the
  investigation, the decision matrix, and the personal-scope credential posture.
- **Affects** Hydra as a second consumer of the tool contract. No Hydra code changes here.

## Risks

### Risk 1: The workspace becomes a way to reach the host or another tenant's data

**Severity:** High — **Mitigation:** Every path argument is workspace-relative and resolved
against the workspace root after symlink resolution; absolute paths, traversal segments and any
target that resolves outside the root are refused. `.git/` is deny-write in full, because a write
to `.git/config` or `.git/hooks/` converts a file-write capability into arbitrary command
execution on the next git operation. The workspace root is derived from the run token, never from
a caller-supplied argument, so one run cannot name another run's workspace.

### Risk 2: A push grant that is broader than intended

**Severity:** High — **Mitigation:** Repo-effecting tools do not resolve from a bare exact-id
grant at all; they require an argument-scoped grant pinning the repository and constraining the
branch. Force-push, ref deletion, tag push and pushes to a protected branch are not expressible
in the tool surface, so they cannot be reached even with a grant.

### Risk 3: The forge PAT leaks into the transcript or the process table

**Severity:** High — **Mitigation:** The PAT is never handed to the model, never placed in argv,
never persisted into repository config, and never returned in a tool result. Tool results and
error strings pass through redaction before they leave the governed side. The model's only view
of a push is a structured success/failure result.

### Risk 4: Attribution drifts between the two consumers

**Severity:** Medium — **Mitigation:** One identity namespace (the Nextcloud UID) for both, and
one rule: a run with no resolvable owner may not author or push at all. Hydra's `HYDRA_RUN_OWNER`
and Hermiq's acting user are required to be the same kind of value, and the commit identity is
derived from it rather than from either system's local configuration.

### Risk 5: The approval gate makes unattended runs impossible, or gets bypassed to make them possible

**Severity:** Medium — **Mitigation:** A run-scoped pre-authorisation is specified as a
first-class approval form, bounded by the grant's pinned arguments and by the run. It is a real
human decision recorded before dispatch, not a flag that disables the gate, and it expires with
the run.

### Risk 6: "Shared from day one" turns into a design that fits neither consumer

**Severity:** Medium — **Mitigation:** The shared part is named precisely — the tool contract,
the confinement rules, the grant and classification model, the credential separation, the
attribution rule and the audit shape. The divergent parts are named just as precisely — where
the workspace bytes live, whether Nextcloud must be reachable, the turn budget, and the quality
gate loop — and are specified as a provider seam rather than papered over.

### Risk 7: The denylist gains a new hole later, under pressure

**Severity:** Low — **Mitigation:** The prohibition on relaxing `DISALLOWED_BUILTIN_TOOLS` is
written as a requirement with a scenario, so removing an entry becomes a spec change with a
visible reviewer, not a one-line edit. `ToolSearch` remains deliberately un-denied and that
carve-out is restated, because denying it would silently make every governed tool unreachable.

## Rollback Strategy

The capability is additive and grant-gated, so rollback is a data operation before it is a code
operation. Removing the workspace/git tool grants from every `Agent.tools` returns the runner to
its current inference-only behaviour without touching the container, the denylist or the
credential path — the tools remain enumerable but resolve to nothing, which
`resolvesToNothing()` reports rather than failing open. Removing the forge host from the PDP
allowlist independently disables push while leaving local editing usable for diagnosis. Because
this change edits no runner code and no denylist entry, there is no image rebuild and no registry
round-trip in any rollback path.

## Open Questions

1. Does the run-scoped workspace outlive the run token, and if so under what retention rule? A
   failed run whose workspace is reaped immediately is unmaintainable to debug; one that is
   retained indefinitely is an unbounded store of checked-out source. Provisional position:
   bind the workspace lifetime to the run token, retain on failure for a bounded window, and
   make the retention window operator-configurable.
2. Can Hydra's builder consume the governed MCP endpoint directly, or only the tool *contract*?
   Hydra must keep running when Nextcloud is down, which argues for a local implementation of the
   same contract rather than a network dependency on Hermiq. Provisional position: the contract
   and governance rules are shared, the transport is not required to be.
