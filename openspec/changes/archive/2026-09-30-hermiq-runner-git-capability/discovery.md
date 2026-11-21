# Discovery: hermiq-runner-git-capability

## Question

Two questions, one inherited and one it forces.

1. **Can a code-editing turn run inside Hermiq's governed personal-scope container without
   relaxing the CLI denylist?** The merged Hydra change `hydra-exec-personal-cli-runner` answered
   the feasibility half with a 9-axis decision matrix and a provisional recommendation ("yes to
   the capability, no to the shortcut, not in this change"). What it did not answer is *what
   exactly the governed addition is* — the tool surface, the workspace boundary, the credential
   split and the grant shape.
2. **Is that governed toolset shareable between Hermiq's runner and Hydra's build host, or does
   each need its own?** This was DEFERRED_QUESTION 2 on that change. The product owner resolved
   it on 2026-07-27: design it as shared from day one, and treat "Hydra cannot use it" as a
   design failure. Discovery's job is to find out *how much* is genuinely shareable, so the
   design does not promise convergence it cannot deliver.

## Approach Taken

- **Read the binding input in full**: `../hydra/openspec/changes/hydra-exec-personal-cli-runner/design.md`,
  in particular the `## Investigation` section — the hydra/hermiq posture comparison table, the
  9-axis decision matrix, "what the hydra builder needs that the runner cannot give", and the
  provisional recommendation naming this change.
- **Read the runner as it actually is**, not as its docblocks describe it:
  `exapp/llm-runner/src/providers.js` (the `DISALLOWED_BUILTIN_TOOLS` array and the anthropic
  adapter's `args()`), `src/runner.js` (`run()`, `selectCredentialEnv()`, `assertGovernedArgs()`,
  `buildEgressProxyEnv()`, `cleanup()`), `src/server.js` (`POST /run`, the auth-first ordering).
- **Read the governance side it would have to route through**:
  `lib/Controller/McpRunController.php` (the `tools/list` / `tools/call` route and its
  token-is-the-authorization posture), `lib/Service/Llm/RunTokenService.php`
  (`mint`/`verify`/`consume`, the TTL and the `{runId, agentId, userId}` binding),
  `lib/Service/Engine/ToolGrantResolver.php`, `lib/Service/ToolClassificationService.php`,
  `lib/Controller/EgressAuthorizeController.php` (the per-CONNECT PDP).
- **Read the credential posture**: `lib/Service/Llm/ProviderFactory.php`
  (`assertPersonalScopeCredential()` and the broker resolution path around it).
- **Read the grant grammar this change depends on**: the `agent-tool-governance` and
  `nc-native-tools` deltas of `openspec/changes/hydra-console-agent-leaves/`, plus its
  `contract.md` description of the argument-scoped grant string.
- **Checked the canonical spec surface** (`openspec/specs/`) for an existing home — there is
  none; `agent-tool-governance`, `human-approval-gate`, `agent-credentials` and `run-audit-log`
  all border this capability but none of them owns a workspace.

No proof-of-concept was built. This is deliberate: the investigation that authorised this change
was analysis-only, and a POC that edits code with a real PAT is exactly the thing that should not
exist before the confinement rules are written down.

## Findings

**1. The denylist is not the boundary — the emptiness of the container is.** `providers.js`
denies 30-odd built-ins, and its own comment is honest that a denylist has "the usual denylist
weakness: a newly-shipped built-in arrives un-denied". The comment then names the real backstop:
"no default route off the egress allowlist, read-only fs, no mounts, non-root". That backstop is
what actually holds. The consequence is decisive and was not spelled out in the investigation:
**putting a real checkout inside the runner container converts the un-denied-builtin gap from
theoretical into load-bearing**, because for the first time there would be something in the
container worth reading. A workspace inside the container is therefore the wrong place for it.

**2. Lifting `Bash` exfiltrates the subscription credential — concretely, not hypothetically.**
`runner.js` injects the resolved provider credential into the CLI child's environment
(`selectCredentialEnv()` → `Object.assign(childEnv, ...)`), and separately injects the per-run
token inside the proxy URL's userinfo (`buildEgressProxyEnv()` → `HTTPS_PROXY=http://run:<token>@…`).
Both are *environment variables of the process the model drives*. A shell in that process reads
both with `env`. So re-enabling `Bash` does not merely widen the tool surface; it hands the model
the personal Claude Max/Pro credential that `assertPersonalScopeCredential()` exists to protect,
plus the bearer token that identifies the run to the PDP. This is the single strongest argument
in the whole change and it belongs in the spec, not in a comment.

**3. `ToolSearch` is load-bearing and must survive any future denylist edit.** The CLI defers MCP
tools; the model loads them through `ToolSearch`. Denying it makes every governed tool silently
unreachable — `tools/list` still serves them and the model still says it has no such tool. Any
requirement that touches the denylist has to restate this carve-out or it will be re-broken.

**4. The runner's statelessness is a security property, not an implementation detail.**
`run()` creates a `mkdtemp` scratch, points `HOME` and `TMPDIR` at it, and `cleanup()` removes it
on *every* exit path including spawn failure, timeout, overflow and parse error. A per-run
workspace that persists across calls deletes that property — unless the workspace lives somewhere
else. Putting it on the governed side (keyed by the run token, which already carries
`{runId, agentId, userId}` and a TTL) preserves statelessness where it matters and puts the state
where the identity already is.

**5. There is already exactly one tool-execution path, and it must stay that way.**
`McpRunController` dispatches through `FacadeToolInvoker` — the same path the `http` tool loop
uses — so classification, the approval gate, redaction and the resolved-grant check all apply
across the CLI/HTTP inversion. Git tools that bypassed that (e.g. a side-channel HTTP route "just
for the workspace") would create the second path the whole design refuses.

**6. `.git/` is a code-execution surface, not a data directory.** A file-write tool that can
write anywhere in the working tree can write `.git/hooks/pre-commit` or `.git/config`
(`core.sshCommand`, `core.pager`, `core.fsmonitor`) — and the next git operation, invoked through
a *governed* tool, executes it with the tool implementation's privileges and the resolved PAT in
scope. Path confinement that only stops `..` and absolute paths does not stop this. `.git/` must
be deny-write in full, and that has to be a named requirement because it is not obvious.

**7. The grant grammar this needs already exists in flight.** `hydra-console-agent-leaves`
specifies the argument-scoped grant precisely because `openregister.runFlow` is "ONE tool id that
runs ANY flow on the instance". `git_push` is the identical shape: one tool id that pushes any
branch to any repo the credential can reach. The same grammar, same classification precedence,
same "narrowing restricts reach, it never downgrades a write to a read" rule applies unchanged.
This change adds **no new grant grammar** — which is the strongest available evidence that the
capability is being modelled correctly.

**8. Classification already fails closed for these tools.** `ToolClassificationService`'s
precedence puts hint-less, non-3-segment curated ids in the write/destructive bucket. Every tool
in this surface is a curated id. So `git_commit`, `file_write` and `git_push` classify
write/destructive by default, with no new rule needed.

**9. `assertPersonalScopeCredential()` is stricter than what Hydra is building, and it covers
only one of the two credentials.** It refuses organisation scope and refuses when scope cannot be
established at all, before the broker is touched. But it governs the *Anthropic* credential.
Nothing in Hermiq today has an opinion about a forge PAT, because the runner has never had one.
The attribution rule therefore has to be stated jointly: same owner, same namespace (Nextcloud
UID), and no-owner ⇒ no authorship and no push.

**10. Sharing is real for the contract and false for the runtime.** Three things Hydra's builder
needs are not "a governed addition" but a different machine: a running Nextcloud + PostgreSQL for
`phpunit` and the register-aware gates; ~420 turns across four chained CLI sessions against
Hermiq's `MAX_TOOL_ITERATIONS = 10` and 120 s CLI timeout; and a real PHP/npm toolchain for the
quality-gate loop. Hydra also must keep running when Nextcloud is down — its design.md states
that as a hard property ("git + forge are the persistence"). So a shared *transport* (Hydra's
builder calling Hermiq's MCP endpoint) cannot be mandatory. What is genuinely shareable is the
**contract and the governance rules**: tool names and argument shapes, the confinement rules, the
grant and classification model, the credential separation, the attribution rule, and the audit
record shape. That is a provider seam, not a service dependency.

**11. Approval, as it stands, cannot serve unattended dispatch.** The existing gate is a
per-invocation human decision. A code-editing run makes many writes. Hydra's runs start at 03:00
from a label. Either approval becomes a per-run decision bounded by the grant's pinned arguments,
or it gets bypassed in practice — and a bypassed gate is worse than an honest one. Hydra's own
dispatch act (the trigger label plus the `owner:<uid>` marker) is already a recorded human
decision with an accountable person behind it; that is the natural shape for a pre-authorisation.

## Recommendation

**Proceed to specs, with the following shape.**

1. **Go** with a closed, named tool surface — no raw-command tool, no subcommand argument, no
   refspec string. Anything that accepts a free-form command is a shell with extra steps and
   cannot be constrained by a grant.
2. **Go** with a **server-side, run-token-keyed workspace**. The model gets tools, not a
   directory. This is the finding that most changes the design relative to the investigation's
   framing (axis 3 assumed the workspace would live in the runner): it preserves the runner's
   statelessness, keeps the container empty, and neutralises the un-denied-builtin gap instead of
   making it load-bearing.
3. **Go** with hard confinement expressed as requirements: workspace-relative paths only, resolve
   symlinks before the containment check, refuse traversal and absolute paths, and treat `.git/`
   as deny-write in full.
4. **Go** with full credential separation: the Claude credential stays in the runner's child env
   exactly as today; the forge PAT never enters the container, is resolved at the tool boundary,
   is used for one push, and never appears in argv, a tool result, persisted repo config, or the
   transcript.
5. **Go** with the existing argument-scoped grant grammar, and add one rule on top: a
   repo-effecting tool MUST NOT resolve from a bare exact-id grant.
6. **Do not** relax `DISALLOWED_BUILTIN_TOOLS` by any entry — and restate the `ToolSearch`
   carve-out so a future edit cannot silently break tool delivery.
7. **Do not** attempt workload convergence. The turn budget, the build toolchain and the running
   Nextcloud stay on Hydra's side. Share the contract, not the machine.
8. **Do not** deliver it all at once. Stage it: read-only surface first, then in-workspace writes,
   then the forge credential and push. The credential must not appear until the confinement rules
   are implemented and tested. Delivery ends at one small, bounded, code-editing task running end
   to end — per the investigation's recommendation point 4 — not at a pipeline.

## Risks Uncovered

- **A `.git/`-write escalation was not on the investigation's matrix at all.** Axis 1 listed
  `write_file` as a governed addition without noting that the working tree contains an execution
  surface. Any implementation that lands confinement as "no `..`, no absolute paths" and stops
  there ships an RCE.
- **The workspace is a new class of retained data.** Checked-out source, per run, on the
  Nextcloud side, under a multi-tenant app. Retention and tenant isolation of that store are real
  questions the investigation never had to ask, because the runner had no store.
- **Approval fatigue is a security risk in this design, not a UX one.** If the gate fires per
  write on a run that makes dozens, the operational answer will be to grant broadly or disable
  it. The run-scoped pre-authorisation exists to prevent that outcome, and it needs to be
  specified as a real approval with a real record, not a flag.
- **"Shared from day one" can be satisfied on paper.** A contract that Hydra could theoretically
  implement, but that assumes a reachable Nextcloud, fails the PO's bar in practice. The seam has
  to be specified such that a Hydra-side implementation with no Nextcloud dependency is
  conformant.

## Next Steps

Proceed to `contract.md` (the tool contract both consumers implement against), `design.md` (the
decisions, the provider seam, the seed data and the ADR-031 position) and `specs/` (one new
capability, `agent-workspace-git-tools`). Implementation is a separate change and must not start
before the first bounded milestone is agreed.
