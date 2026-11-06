# Design: hermiq-runner-git-capability

## Context

Hermiq's `llm-runner` ExApp is inference-only by construction. `providers.js` denies every
built-in that could touch a shell, the filesystem or the network; the CLI runs with
`--strict-mcp-config` and `--allowedTools mcp__hermiq__*`; `runner.js` points `HOME`/`TMPDIR` at a
`mkdtemp` scratch that `cleanup()` removes on every exit path; the container has no default route
and reaches the world only through a proxy that asks Hermiq's PDP per CONNECT; and
`ProviderFactory::assertPersonalScopeCredential()` refuses any `anthropic-cli` credential that is
not personal-scope, failing closed when scope cannot be established at all.

Hydra's builder is the opposite end of the same primitive: full `Bash`, a real checkout inside a
prebuilt Nextcloud tree with PostgreSQL running, `max_turns: 300` plus up to three 40-turn fix
passes across four chained CLI sessions, and a 1311-line entrypoint that exists mostly to keep
credentials away from the model. It is what edits code today.

The merged change `hydra-exec-personal-cli-runner` carried the analysis that authorises this one.
Its 9-axis matrix found exactly one blocking axis — lifting `Bash` — and named that as the option
to refuse; every other axis resolved to "needs a governed addition" pointing the same way. Its
recommendation names `hermiq-runner-git-capability` and sets its bar. The product owner resolved
its DEFERRED_QUESTION 2 on 2026-07-27: **design the toolset as shared from day one, with Hydra's
builder as a second consumer, and treat "Hydra cannot use it" as a design failure.**

Constraints this design inherits and does not relitigate:

- `DISALLOWED_BUILTIN_TOOLS` is not edited. Not one entry, not `Read` "just for the workspace".
- `ToolSearch` stays un-denied. It is how the CLI loads deferred MCP tools; denying it makes every
  governed tool silently unreachable with no error.
- There is exactly one tool-execution path (`FacadeToolInvoker`, reached from both the `http` loop
  and `McpRunController`). No second path, no side channel.
- The per-run token (`RunTokenService`, bound to `{runId, agentId, userId}` with a TTL) is the
  authorization. Identity never comes from a request body.
- Hydra's builder must keep running when Nextcloud is down. That is a stated property of its
  design, not a preference.

## Architecture Overview

```
  ┌── runner container (unchanged) ────────────────┐
  │  claude CLI                                    │
  │   • DISALLOWED_BUILTIN_TOOLS  (untouched)      │
  │   • --strict-mcp-config, --mcp-config <0600>   │
  │   • cwd = mkdtemp scratch  (still throwaway)   │
  │   • env: CLAUDE_CODE_OAUTH_TOKEN, HTTPS_PROXY  │
  │        └─ NO forge PAT, NO checkout, NO path   │
  └───────────────┬────────────────────────────────┘
                  │ MCP tools/call  (Bearer = per-run token)
                  ▼
  ┌── governed side (Nextcloud) ───────────────────────────────────┐
  │  McpRunController → ToolGrantResolver → FacadeToolInvoker      │
  │        │                  │                    │               │
  │        │                  │                    ├─ classification│
  │        │                  │                    ├─ approval gate │
  │        │                  │                    └─ redaction     │
  │        ▼                  ▼                                     │
  │   WorkspaceToolset ──► WorkspaceProvider (seam)                │
  │        │                  └─ ServerSideWorkspaceProvider        │
  │        │                       run-token-keyed dir, confined    │
  │        ├─ forge PAT ◄── credential broker (personal scope,      │
  │        │                 owned by the run owner)                │
  │        └─ git identity ◄── resolved run owner (NC UID)          │
  └────────────────────────────┬───────────────────────────────────┘
                               │ PDP-allowed CONNECT to the forge host
                               ▼
                            the forge
```

The load-bearing move is that **the workspace is on the governed side, not in the runner
container**. The model receives tools, never a directory and never a path. Three consequences
follow, and they are the reason the design is shaped this way:

1. The runner stays stateless per call — the property its entire security argument rests on.
2. The container stays empty. A future un-denied CLI built-in finds a `mkdtemp` scratch, exactly
   as today, instead of a checkout and a git credential.
3. The state lives where the identity already lives: keyed by the run token that already carries
   `{runId, agentId, userId}` and a TTL.

### The provider seam (what makes it shareable)

```
        WorkspaceToolset            ← the contract; identical for both consumers
        (confinement, credential
         separation, attribution,
         grant + approval, audit)
                 │
        ┌────────┴─────────┐
        ▼                  ▼
 ServerSideWorkspace   LocalCheckoutWorkspace
 Provider (Hermiq)     Provider (Hydra builder)
 run-token-keyed dir   the existing in-container /workspace/repo
 under app data        addressed by run id, no Nextcloud dependency
```

Everything above the seam is normative for both consumers. Everything below it is the runtime,
and the runtime is where the two genuinely diverge — see Decision 8.

## API Design

**No new HTTP endpoint.** Every tool is served through the existing
`POST /apps/hermiq/api/mcp/run` (`tools/list` / `tools/call`) route owned by
`cli-runner-governed-mcp-and-egress`, so there is exactly one tool-execution path. Tool names,
argument shapes, result shapes and error codes are specified in `contract.md` and are not
duplicated here.

The one endpoint-adjacent change is on the PDP: `EgressAuthorizeController` must allow the forge
host for a run whose agent holds the push grant, keyed on the run token's agent binding. There is
no second static allowlist — a static list is exactly what Hermiq refused to have, and it is why
Hermiq's deny-by-default actually holds where Hydra's declared allowlist historically did not.

## Nextcloud Integration

- **Controllers**: `McpRunController` (existing; serves the new tool ids through `tools/list` /
  `tools/call`), `EgressAuthorizeController` (existing; forge host becomes allowable per run).
- **Services**: `RunTokenService` (workspace key + lifetime), `ToolGrantResolver` (argument-scoped
  grants — grammar contributed by `hydra-console-agent-leaves`, not by this change),
  `ToolClassificationService` (curated ids already classify write/destructive fail-closed),
  `ApprovalService` (per-invocation and run-scoped pre-authorisation), `RedactionService` (tool
  results and errors), `FacadeToolInvoker` (single dispatch path), the OpenRegister credential
  broker (forge PAT resolution, same broker that resolves the Anthropic credential).
- **New services**: a workspace toolset and a `WorkspaceProvider` seam with a server-side
  implementation. These are the only genuinely new classes.
- **Mappers/Entities**: none. Hermiq owns no tables; the workspace is filesystem state keyed by
  the run token, and the audit records are OpenRegister objects on the existing run-audit path.
- **Events/Hooks**: none new. Audit records ride the existing run/tool-call audit path
  (`run-audit-log`), so a git write is auditable by the same mechanism as any other tool call.
- **OCP**: `IAppDataFactory` / app data root for the workspace store, `IUserSession`
  impersonation (already used by `McpRunController` so OpenRegister RBAC applies to the acting
  user), `LoggerInterface`.

## Decisions

### Decision 1 — The tool surface is closed and named; there is no raw-command tool

Thirteen tools, listed in `contract.md`. No `workspace.git`, no `workspace.exec`, no subcommand
argument, no refspec string, no remote URL.

A tool that accepts a free-form command is a shell with extra steps, and a shell cannot be
constrained by a grant: `git push --force origin +HEAD:main` and `git push origin feature/x` are
the same tool call if the argument is a string. The whole value of the flows-first framing is
that a capability is *declared*, and a declaration you cannot enumerate is not one.

*Alternatives considered.* (a) **A single `workspace.git` tool with a subcommand argument,
constrained by an argument-scoped grant on `subcommand`** — rejected: the constraint would pin
the verb but not the arguments, so `commit` is safe and `push` is not, in the same grant. (b) **An
allowlist of full command strings** — rejected: it moves the parsing problem into the grant and
makes every new operation a string-matching exercise. (c) **A broader surface including `merge`,
`rebase` and `reset`** — rejected: those rewrite history the run did not create, so a run's blast
radius stops being "what this run wrote".

### Decision 2 — The workspace lives on the governed side, keyed by the run token

The model never receives a filesystem path. `workspace.open` returns an opaque `workspaceId`, a
resolved `headSha` and a file count; every subsequent tool takes workspace-relative paths only.

This is the decision that most changes the shape relative to the investigation's axis 3, which
assumed a run-scoped workspace *inside* the runner. Two findings pushed it out. First, the
runner's statelessness is load-bearing for its security argument, and a persistent in-container
workspace deletes it. Second — and decisively — `providers.js` is honest that the denylist has
"the usual denylist weakness: a newly-shipped built-in arrives un-denied", and names the empty
container as the backstop. Putting a checkout in that container converts the un-denied-builtin gap
from theoretical into load-bearing. Keeping the container empty keeps the backstop intact.

*Alternatives considered.* (a) **In-container workspace on a run-scoped volume** — rejected on
both grounds above. (b) **Workspace in the container but mounted read-only, writes proxied over
MCP** — rejected: it is the worst of both, since a read-only checkout is still a checkout for an
un-denied `Read`-shaped built-in, and it doubles the number of places the tree exists.
(c) **No persistent workspace at all — every tool call re-clones** — rejected: correct on paper,
unusable in practice, and a clone per call is a far larger egress and forge-load footprint than
a bounded per-run store.

### Decision 3 — Confinement is realpath-based, and `.git/` is deny-write in full

Every path argument is workspace-relative. The implementation resolves it against the workspace
root **after** symlink resolution and refuses anything that lands outside; absolute paths and
traversal segments are refused before that. Separately and independently, any write-shaped target
that resolves under `.git/` is refused.

The `.git/` rule is not defence in depth, it is the primary control for a distinct
vulnerability. A write into `.git/hooks/pre-commit`, or a `core.sshCommand` / `core.pager` /
`core.fsmonitor` entry in `.git/config`, is executed by the *next git operation* — which the model
invokes through a governed tool, running with the tool implementation's privileges and the
resolved forge PAT in scope. Confinement that stops at "no `..`, no absolute paths" ships a
remote-code-execution path with a governed tool as the trigger. The investigation's matrix listed
`write_file` as a governed addition without noticing this; it is called out here so no
implementation can miss it.

`apply_patch` is refused **whole** when any target path fails either check. A partially applied
patch leaves the tree in a state neither the model nor the audit record describes.

*Alternative considered:* a `.git/` allowlist (permit `.gitignore`-adjacent files, refuse hooks
and config). Rejected: the set of git config keys that execute a command grows with git, so the
allowlist would be a denylist wearing a different hat — the exact mistake this change refuses
elsewhere.

### Decision 4 — Two credentials, two holders; neither ever enters the other's blast radius

| Credential | Resolved by | Lives where | Model can see it? |
|---|---|---|---|
| Claude subscription (`anthropic-cli`) | broker → `ProviderFactory` | runner child's env, unchanged | no (no shell, no `Read`) |
| Forge PAT | broker → workspace toolset | governed side only | **never — it does not exist in the container** |

The forge PAT is never handed to the model, never placed in argv, never written into repository
config in a persisted form, never returned in a tool result, and never present in a raw git
transport error that reaches the transcript. The model's view of a push is a structured
success/failure result. Hydra learned this the hard way twice — it strips `GIT_TOKEN` and
`chmod 000`s the git credential file for Claude's turn, yet still leaves a plaintext PAT readable
in `~/.config/gh/hosts.yml`, and had to move every privileged act into its entrypoint. A governed
tool has neither problem, because the privileged act happens on the other side of the boundary.

*Alternative considered:* inject the PAT into the runner container the way the Claude credential
is injected, and let the CLI's git tooling use it. Rejected — that is the Hydra design, and it is
the one whose failure mode is documented above.

### Decision 5 — Never lift `Bash`, and say why in a spec requirement

The investigation called lifting `Bash` "blocking if lifted; recommend never lifting". This
design states the concrete mechanism, because "a shell is unbounded" is an argument people
negotiate with and this one is not:

`runner.js` injects the resolved provider credential into the CLI child's **environment**
(`selectCredentialEnv()`), and injects the per-run bearer token into the same environment inside
the proxy URL's userinfo (`buildEgressProxyEnv()` → `HTTPS_PROXY=http://run:<token>@…`). A shell
in that process reads both with `env`. Re-enabling `Bash` therefore does not merely widen the
tool surface — **it hands the model the personal Claude Max/Pro credential that
`assertPersonalScopeCredential()` exists to protect, plus the token that identifies the run to the
PDP.** The same applies to `Read` (the env is readable via `/proc/self/environ`).

Two structural points ride along. First, the denylist is a **denylist**: a newly-shipped CLI
built-in arrives un-denied, so the boundary can never be "the list is complete" — it must be "the
container holds nothing worth reaching and has no route out", which is precisely what Decision 2
preserves. Second, `ToolSearch` must stay un-denied; denying it makes every governed tool silently
unreachable, with `tools/list` still serving them and the model still reporting it has none.

### Decision 6 — Repo-effecting tools require an argument-scoped grant; a bare exact-id grant does not resolve

`workspace.push` is the same shape of problem as `openregister.runFlow`: one tool id whose target
comes from an argument, so an exact-id grant is a grant to the whole target space —
"push any branch to any repository this credential can reach". `hydra-console-agent-leaves`
already built the abstraction for this. This change contributes **no new grant grammar**; it adds
one rule on top: for a tool declared repo-effecting, a bare exact-id grant MUST NOT resolve at
all. Not "resolve and then fail at invocation" — not resolve, so `tools/list` never advertises it
and the failure is visible at configuration time rather than at 03:00.

Classification needs no new rule either: every id here is curated (non-3-segment, hint-less), and
`ToolClassificationService`'s precedence already puts those in the write/destructive bucket
fail-closed. Narrowing by argument scope does not downgrade the classification — that invariant is
already stated in the grant-governance spec and is restated here because a push tool is exactly
where someone would be tempted to.

### Decision 7 — Approval is per-run pre-authorisation, bounded by the grant, not per-write

The existing gate is a per-invocation human decision. A code-editing run makes many writes, and
Hydra's runs start unattended from a label. Per-write approval on such a run has exactly one
outcome in practice: someone grants broadly or turns the gate off, and a bypassed gate is worse
than an honest one.

So: a **run-scoped pre-authorisation** is specified as a first-class approval form. It is a real
human decision, recorded before dispatch, bounded by the grant's pinned arguments (this
repository, this branch constraint) and by the run — it expires with the run token and covers no
other run. It is not a flag that disables the gate.

This also gives the two consumers one story. Hydra's dispatch act — the trigger label plus the
`owner:<uid>` marker naming the flow's owner — is already a recorded decision with an accountable
person behind it. That act **is** the pre-authorisation for a Hydra-dispatched run. Per-write
approval remains available and remains the default for interactive, unpinned or newly-granted
capabilities.

*Alternative considered:* keep per-write approval only and let unattended dispatch be impossible.
Rejected: it does not remove the risk, it relocates it into a broader grant.

### Decision 8 — Attribution: one namespace, and no owner means no authorship

Identity namespace is the **Nextcloud UID**, matching `hydra-exec-personal-cli-runner` Decision 8.
Rules:

1. Commit author and committer are derived **server-side from the resolved run owner**. The commit
   tool's schema carries no author/committer/name/email argument, so a model cannot propose one.
2. The forge PAT used for a push MUST be personal-scope and owned by the **same** UID that owns
   the Anthropic credential the turn ran on. Two personal credentials belonging to two different
   people in one run is a refusal, not a warning — it is the org-pool shape both systems exist to
   remove, assembled from two halves.
3. A run with **no resolvable owner** may run inference on an organisation API key (Hydra's
   Decision 4 escape hatch) but MUST NOT author a commit or push. Authorship is a claim about a
   person; there is no person.
4. An organisation-owned run MAY push as an explicitly declared machine identity, and the audit
   record says so. It never falls back to a person's PAT.

*Alternative considered:* let the commit identity come from the repository's local git config, as
a normal checkout would. Rejected: that is model-influenceable state inside the workspace, and it
is precisely the input that must not decide who a commit is attributed to.

### Decision 9 — Shared contract, not shared runtime; and where the two genuinely diverge

Shared, normative for both consumers: tool ids and argument shapes, result shapes, error codes,
the confinement rules, the grant + classification model, the credential separation, the
attribution rule, and the audit record shape. That is `contract.md` Layer A.

Not shared, and this design does not pretend otherwise:

| Divergence | Hermiq runner | Hydra builder |
|---|---|---|
| Where the workspace bytes live | governed-side dir, run-token-keyed | the existing in-container `/workspace/repo` |
| Nextcloud reachability | required (it *is* the governed side) | **must not be required** — Hydra runs when NC is down |
| Turn budget | one CLI turn per `/run`; `ToolLoop` caps at `MAX_TOOL_ITERATIONS = 10` | ~420 turns across four chained sessions |
| Quality-gate loop | none — no PHP toolchain, no database, no repo | `composer check:strict`, `phpcbf`, `run-hydra-gates.sh --scope-to-diff`, `npm ci` |
| Approval shape | per-write by default; run-scoped where pre-authorised | run-scoped, from the label + owner marker |

The turn budget and the gate loop are not tuning knobs that a shared design can absorb.
`MAX_TOOL_ITERATIONS` is a security parameter and the 120 s CLI timeout is another; moving them
by two orders of magnitude would be a different change with a different risk argument. This change
therefore does **not** raise them, and does not plan a 420-turn build inside the runner. The
convergence being pursued is governance convergence — one personal-scope credential story, one
tool-governance model, one egress PDP — exactly as the investigation recommended.

"Hydra cannot use it" is a design failure. The test of that is concrete: **a Hydra-side
implementation of Layer A against its own checkout, with no Nextcloud dependency, must be
conformant.** If a requirement can only be satisfied by calling Hermiq's endpoint, the requirement
is wrong.

### Declarative-vs-imperative decision (ADR-031)

ADR-031 says: when OpenRegister exposes an `x-openregister-*` schema extension that fits the
requirement, declare the behaviour in the register JSON rather than writing a PHP service class.
**This change is an exception domain, on two grounds — and it maximises the declarative surface
everywhere else.**

*First, the guard cannot be expressed declaratively.* No schema extension can state "resolve this
path, follow its symlinks, and refuse it if the result is outside this root", or "this credential
must never appear in this string". Path confinement and credential separation are properties of an
execution, not of a stored object. `x-openregister-lifecycle` / `-calculations` /
`-notifications` run inside OpenRegister's schema engine on a request that saves an object; there
is no object being saved here.

*Second, this is a security guard, and guards belong in the imperative chokepoint.* The value of
`FacadeToolInvoker` being the single dispatch path is that there is exactly one place a tool can
be reached, and that place enforces. Expressing the confinement rules as declarative policy
consumed by a separate enforcement layer would create a second thing that can drift out of sync
with the first — the precise failure mode ADR-031 exists to prevent in the app layer.

*What is declarative here, and deliberately so:*

- **The grants.** `Agent.tools` is data on an OpenRegister object; which repository and which
  branches an agent may push to are argument constraints declared on the grant string, not
  hard-coded anywhere.
- **The tool catalog descriptors**, including each tool's classification hints and its declared
  repo-effecting flag — the flag that makes Decision 6's rule apply is a descriptor property, not
  an `if` on a tool id.
- **The egress policy.** One allowed-URL policy source, consulted per CONNECT by the PDP. No
  static second allowlist in the tool implementation.
- **The approvals**, including the run-scoped pre-authorisation, as approval objects on the
  existing gate.
- **The audit records**, on the existing `run-audit-log` path.

So the imperative surface this change adds is confined to two things a declaration cannot do:
resolve a path safely, and hold a credential without leaking it. Everything a policy *can*
express is expressed as data.

## Security Considerations

- **Auth**: the per-run bearer token is the authorization (`RunTokenService`). Acting user, agent
  and run come from the token only; a `runId`/`userId` in a request body can never redirect which
  run is served. Missing/invalid/expired/consumed ⇒ 401 before any tool resolves. Unchanged from
  `cli-runner-governed-mcp-and-egress`; restated because the workspace key derives from it.
- **Authorization**: OpenRegister RBAC applies to the impersonated acting user, plus the grant
  check, plus the classification-driven approval gate. Three independent checks, all fail-closed.
- **Input validation**: every path argument through one confinement routine (Decision 3); every
  patch validated per-target before any hunk is applied; `repository` is a slug and never a URL,
  so the model cannot choose the host; no argument is ever interpolated into a command line.
- **Credential handling**: Decision 4. Additionally, the workspace MUST NOT be reachable as the
  runner child's `HOME` or `TMPDIR` — those stay on the throwaway scratch, so a stray credential
  file written by any tooling cannot land in the workspace and be read back through
  `workspace.read_file`.
- **CSRF**: not applicable to the MCP route (`#[PublicPage]` + `#[NoCSRFRequired]` with the token
  as the authorization — the caller is a container with no session and no cookie jar). No new
  route, so no new CSRF surface.
- **Egress**: one policy source, consulted per CONNECT. The forge host becomes allowable for a run
  whose agent holds the push grant, keyed on the token's agent binding — not a static list.
- **Redaction**: tool results and error strings pass through `RedactionService` before leaving the
  governed side. Raw git transport errors are mapped to stable codes rather than forwarded.
- **Multi-tenancy**: the workspace store is per run, and the run is bound to a user and an agent.
  Workspace ids are opaque and never accepted as a caller-supplied selector — the key is derived
  from the token, so one run cannot name another's workspace.
- **Denial of service**: per-workspace size and file-count budgets (`workspace_quota`), a distinct
  timeout for `workspace.open` (the only long operation), and per-tool timeouts elsewhere.

## File Structure

```
openspec/changes/hermiq-runner-git-capability/
  proposal.md  discovery.md  contract.md  design.md  tasks.md  test-plan.md
  specs/agent-workspace-git-tools/spec.md

lib/Service/Workspace/
  WorkspaceProvider.php              # new — the seam
  ServerSideWorkspaceProvider.php    # new — run-token-keyed store, budgets, lifetime
  WorkspacePathGuard.php             # new — realpath containment + .git deny-write
  WorkspaceToolset.php               # new — the 13 tools, above the seam
  ForgeCredentialResolver.php        # new — broker-held PAT, never leaves this class
lib/Controller/McpRunController.php            # serves new tool ids; NO new dispatch path
lib/Controller/EgressAuthorizeController.php   # forge host allowable per run, one policy source
lib/Service/Engine/ToolGrantResolver.php       # consumes the argument-scoped form; no new grammar
lib/Service/ApprovalService.php                # run-scoped pre-authorisation form
tests/Unit/Service/Workspace/                  # confinement, credential, attribution, grant

exapp/llm-runner/src/providers.js   # UNCHANGED BY DESIGN — the denylist is not edited
images/, scripts/ in hydra/         # UNTOUCHED — Hydra is a consumer of the contract, not a target
```

## Seed Data

Per ADR-001 this change defines data shapes (grant strings on `Agent.tools`, a forge-credential
declaration, and a git-write audit record), so example data is specified here.
**Every value below is an obvious placeholder — no real token, UID, repository or UUID appears in
this repository.**

### Agent object — `Agent.tools` grants

`Agent.tools` remains `string[]`; only the meaning of each string is extended, so no OpenRegister
schema migration is required.

```json
{
  "@self": { "register": "hermiq", "schema": "agent", "slug": "example-code-agent" },
  "name": "Example Code Agent",
  "tools": [
    "workspace.open",
    "workspace.status",
    "workspace.diff",
    "workspace.list_files",
    "workspace.read_file",
    "workspace.write_file",
    "workspace.apply_patch",
    "workspace.create_branch",
    "workspace.commit",
    "workspace.push{repository:'example-org/example-app',branch:'feature/*'}"
  ]
}
```

Note the shape of the last entry: `workspace.push` appears **only** in argument-scoped form. A
bare `"workspace.push"` in this array resolves to nothing and the tool is never advertised —
that is Decision 6, visible in the seed data rather than only in prose.

### Credential declaration — forge PAT

```json
{
  "@self": { "register": "hermiq", "schema": "credential", "slug": "example-forge-pat" },
  "name": "Example Forge PAT",
  "type": "forge",
  "scope": "personal",
  "owner": "YOUR_NEXTCLOUD_UID_HERE",
  "allowedApps": ["hermiq"],
  "secret": "YOUR_FORGE_PAT_HERE"
}
```

Self-identification (ADR-001): `YOUR_FORGE_PAT_HERE` and `YOUR_NEXTCLOUD_UID_HERE` are fixed
placeholder strings that fail any real-token shape check. The `secret` is broker-held; it appears
in this document only to show the declaration's shape, never in a repository file.

### Audit record — one git write

Rides the existing `run-audit-log` path; a git write is auditable by the same mechanism as any
other tool call.

| Field | Example value |
|---|---|
| `runId` | `00000000-0000-0000-0000-000000000000` |
| `agentId` | `00000000-0000-0000-0000-000000000000` |
| `tool` | `workspace.push` |
| `classification` | `destructive` |
| `owner` | `YOUR_NEXTCLOUD_UID_HERE` |
| `credentialName` | `example-forge-pat` |
| `credentialScope` | `personal` |
| `approval` | `run-scoped-preauthorisation` |
| `arguments` | `{"repository":"example-org/example-app","branch":"feature/example"}` |
| `result` | `{"remoteSha":"0000…0000","commits":3}` |
| `outcome` | `success` |

**Related items per record:** the commit shas produced in the same run; the approval object that
authorised the write; the run trace the tool call belongs to. No file, note or contact attaches to
a workspace record — the workspace itself is never an audit attachment, because it contains
checked-out third-party source.

## Risks / Trade-offs

- **[A write into `.git/` turns a governed file tool into arbitrary command execution]** →
  Decision 3: `.git/` deny-write in full, refused independently of the containment check, and
  `apply_patch` refused whole rather than partially applied.
- **[Confinement bypass via symlink]** → containment is checked after symlink resolution, not on
  the lexical path. A lexical-only check passes `docs/link → /etc` and is the classic form of this
  bug.
- **[A bare exact-id push grant would be catastrophically broad]** → Decision 6: it does not
  resolve at all, so the failure is visible at configuration time and the tool is never
  advertised.
- **[Approval fatigue drives someone to widen the grant or disable the gate]** → Decision 7's
  run-scoped pre-authorisation is a real recorded decision bounded by the grant and the run, so
  the operationally-easy path is also the governed one.
- **[The forge PAT leaks through a raw git error into the transcript]** → transport errors are
  mapped to stable codes and redacted; no raw stderr crosses the boundary.
- **[The workspace becomes an unbounded, multi-tenant store of checked-out source]** → per-run
  key, bounded size/file-count budget, lifetime bound to the run token, bounded retention on
  failure. The retention window is Open Question 1.
- **[Two personal credentials from two different people in one run]** → refused. It is the
  org-pool shape assembled from halves, and a warning would normalise it.
- **[Shared-by-name-only: a contract Hydra cannot actually implement]** → the conformance test is
  stated concretely (a Hydra-side Layer A implementation with no Nextcloud dependency must be
  conformant); any requirement that fails it is wrong and must be rewritten.
- **[Someone removes a denylist entry later, under delivery pressure]** → the prohibition is a
  requirement with a scenario, so it becomes a spec change with a visible reviewer instead of a
  one-line edit. The `ToolSearch` carve-out is restated in the same place.
- **[Scope creep into a build host]** → the quality-gate loop, the PHP/npm toolchain and the turn
  budget are explicit non-goals. Exposing gates as MCP tools would move where the model *asks*,
  not where the work *runs*.

## Migration Plan

There is nothing to migrate: no database change, no OpenRegister schema change (`Agent.tools`
stays `string[]`), no container image rebuild, and no edit to `DISALLOWED_BUILTIN_TOOLS`. The
sequence below is the delivery staging, and `tasks.md` follows it in order — each step is usable
and reviewable on its own, and the credential only appears at step 3.

1. Land the tool contract and the confinement routine with a read-only surface first
   (`open`, `status`, `diff`, `log`, `list_files`, `read_file`). No credential, no writes.
2. Add the in-workspace write tools (`write_file`, `delete_file`, `apply_patch`,
   `create_branch`, `checkout_branch`, `commit`). Still no forge credential, still no egress.
3. Add forge-credential resolution and `push`, gated on the argument-scoped grant and the
   approval gate, with the PDP entry for the forge host.
4. Run the first bounded milestone: one small code-editing task end to end under this posture,
   with real numbers on turn count and wall clock.
5. Only then evaluate whether Hydra's builder adopts Layer A, and against which provider.

**Rollback.** Removing the workspace/git grants from every `Agent.tools` returns the runner to
inference-only behaviour without touching code, the container or the credential path; the tools
remain enumerable but resolve to nothing, which `resolvesToNothing()` reports rather than failing
open. Removing the forge host from the PDP policy independently disables push while leaving local
editing usable for diagnosis. Because no denylist entry and no image is touched, no rollback path
requires a registry round-trip.

## Trade-offs

- **Governed tools over a shell**: more surface to specify and implement, and the model cannot do
  anything nobody anticipated. That is the point — the cost is exactly the guarantee.
- **Server-side workspace over in-container**: an extra hop per file operation, and a new store to
  bound and retain. Bought: the runner stays stateless and the container stays empty, so the
  denylist's structural weakness never becomes load-bearing.
- **Contract-sharing over runtime-sharing**: two implementations to keep conformant, versus one
  service with a liveness dependency Hydra cannot accept. Chosen because a shared runtime would
  fail the harder constraint (Hydra must run when Nextcloud is down) while a shared contract
  fails neither.
- **Run-scoped pre-authorisation over per-write approval**: coarser than the ideal, and honest
  about what unattended dispatch requires. The alternative is not finer-grained approval; it is
  a disabled gate.
- **No turn-budget increase**: the runner cannot host a build-shaped workload, and this change
  says so instead of shipping a design that only works if two security parameters move by two
  orders of magnitude.

## Open Questions

1. **Workspace retention.** Does the workspace outlive the run token, and for how long on
   failure? Immediate reaping makes a failed run undebuggable; indefinite retention is an
   unbounded multi-tenant store of checked-out source. Provisional position: lifetime bound to
   the run token, bounded retention window on failure, operator-configurable.
2. **Hydra's transport.** Can Hydra's builder call the governed MCP endpoint directly when
   Nextcloud happens to be up, or should it always use a local Layer A implementation? Both are
   conformant; the question is whether two code paths on the Hydra side are worth the freshness.
   Provisional position: local implementation only, until there is a reason.
3. **`workspace.open` base pinning.** Should `ref` be constrainable by the grant the way
   `repository` and `branch` are on `push`? Cloning an arbitrary ref of a pinned repository is far
   less dangerous than pushing to one, but "pinned repo, any ref" is still wider than it needs to
   be for a task that always starts from the mainline.
