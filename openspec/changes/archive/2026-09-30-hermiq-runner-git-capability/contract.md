# Contract: hermiq-runner-git-capability

This contract defines the **governed workspace/git toolset** as an interface, not as a Hermiq
implementation. It has two consumers with different runtimes, so the contract is deliberately
split into two layers:

- **Layer A — the tool contract.** Tool names, argument shapes, result shapes, error codes, and
  the invariants every implementation must uphold (confinement, credential handling,
  attribution, audit). This layer is **normative for both consumers**.
- **Layer B — the transport.** How a caller reaches the tools. For Hermiq this is the existing
  governed MCP endpoint. For Hydra it MAY be the same endpoint, or a local implementation of
  Layer A with no Nextcloud dependency. This layer is **not required to be shared** — see
  Versioning.

No new HTTP endpoint is introduced. Every tool below is served through the existing
`POST /apps/hermiq/api/mcp/run` route (`tools/list` / `tools/call`), so there is exactly one
tool-execution path and no side channel.

## Consumers

- `hermiq` (`exapp/llm-runner`): the CLI's MCP client calls `tools/call` over the per-run
  governed endpoint. Uses Layer A + Layer B. Turn shape is one CLI turn per `POST /run`, driven
  by Hermiq's `ToolLoop`.
- `hydra` (`images/builder`): consumes Layer A only, as the requirements source and as a future
  implementation target against its own in-container checkout. Its builder must keep running
  when Nextcloud is unreachable, so it MUST NOT be required to use Layer B. A Hydra-side
  implementation that upholds every Layer A invariant is conformant.
- `hermiq` (`lib/Service/Engine/ToolLoop`): the `http` execution path reaches the same tools
  through `FacadeToolInvoker`. The tool surface is transport-agnostic by construction.

## Tool surface (Layer A)

All tools are namespaced `workspace.*`. All identifiers are curated (non-3-segment) tool ids and
therefore classify **write/destructive by default** unless the table marks them read-only.

| Tool | Class | Repo-effecting | Grant form required |
|---|---|---|---|
| `workspace.open` | write | no | exact-id or argument-scoped |
| `workspace.status` | read | no | exact-id |
| `workspace.diff` | read | no | exact-id |
| `workspace.log` | read | no | exact-id |
| `workspace.list_files` | read | no | exact-id |
| `workspace.read_file` | read | no | exact-id |
| `workspace.write_file` | write | no | exact-id |
| `workspace.delete_file` | write | no | exact-id |
| `workspace.apply_patch` | write | no | exact-id |
| `workspace.create_branch` | write | no | exact-id |
| `workspace.checkout_branch` | write | no | exact-id |
| `workspace.commit` | write | no | exact-id |
| `workspace.push` | destructive | **yes** | **argument-scoped only** |

**Deliberately absent, and why.** `merge`, `rebase`, `cherry_pick`, `reset`, `revert`, `tag`,
`remote_add`, `submodule`, `clean`, `stash` — each either rewrites history the run did not
create, or reaches a ref the grant cannot describe. `pull`/`fetch` are absent because
`workspace.open` already materialises the checkout at a pinned base. `force_push`, ref deletion
and refspec arguments are absent so they cannot be reached even by a granted caller. There is no
`workspace.git` / `workspace.exec` / `workspace.run` tool taking a subcommand or command string:
a free-form command argument is a shell, and a shell cannot be constrained by a grant.

Forge operations beyond `push` (PR creation, label writes, comments, merges) are **not in this
contract**. They belong to the flow/orchestrator layer per `hydra-exec-personal-cli-runner`
Decision 7.

## Endpoints

### `POST /apps/hermiq/api/mcp/run` — `tools/call` (existing route, new tool ids)

**Auth**: the per-run bearer token minted by `RunTokenService` (`Authorization: Bearer <token>`).
The token IS the authorization: acting user, agent and run are resolved from it only, never from
the request body. Missing/invalid/expired/consumed ⇒ 401 before any tool is resolved.

Argument shapes below are the tool `arguments` object of a JSON-RPC `tools/call`.

#### `workspace.open`

Materialises the run's workspace. Idempotent within a run: a second call with identical arguments
returns the existing workspace rather than re-cloning.

**Request:**
```json
{
  "repository": "example-org/example-app",
  "ref": "development",
  "depth": 20
}
```

`repository` is a forge-relative slug, never a URL — a URL argument would let the model choose the
host and turn a PAT into an exfiltration channel. The host is resolved server-side from
configuration. `repository` MUST match the grant's pinned value when the grant is argument-scoped.

**Response (200):**
```json
{
  "workspaceId": "00000000-0000-0000-0000-000000000000",
  "repository": "example-org/example-app",
  "ref": "development",
  "headSha": "0000000000000000000000000000000000000000",
  "fileCount": 128
}
```

The response carries **no filesystem path**. The model never learns where the workspace is,
because a path it can name is a path it can try to escape.

#### `workspace.read_file` / `workspace.list_files`

**Request:**
```json
{ "path": "lib/Service/Example.php", "maxBytes": 262144 }
```

**Response (200):**
```json
{ "path": "lib/Service/Example.php", "bytes": 4211, "truncated": false, "content": "<file text>" }
```

#### `workspace.write_file` / `workspace.delete_file` / `workspace.apply_patch`

**Request (`write_file`):**
```json
{ "path": "lib/Service/Example.php", "content": "<file text>", "mode": "overwrite" }
```

**Request (`apply_patch`):**
```json
{ "patch": "<unified diff>" }
```

A patch is applied with rename/copy detection disabled and with every target path subjected to the
same confinement check as a direct write. A patch that touches a path outside the workspace, or
any path under `.git/`, is refused **whole** — never partially applied.

**Response (200):**
```json
{ "path": "lib/Service/Example.php", "action": "modified", "bytes": 4260 }
```

#### `workspace.commit`

**Request:**
```json
{ "message": "feat(example): add the thing", "paths": ["lib/Service/Example.php"] }
```

The request carries **no author, committer, name or email**. Identity is derived server-side from
the resolved run owner. An `author`/`committer` argument is not part of the schema, so a model
cannot propose one.

**Response (200):**
```json
{
  "sha": "0000000000000000000000000000000000000000",
  "branch": "feature/example",
  "author": "example-uid",
  "filesChanged": 1
}
```

#### `workspace.push`

**Request:**
```json
{ "repository": "example-org/example-app", "branch": "feature/example" }
```

No refspec, no `force`, no `delete`, no remote name. `repository` and `branch` MUST both satisfy
the argument-scoped grant's constraints; the branch constraint MAY be a closed set or a pattern.

**Response (200):**
```json
{
  "repository": "example-org/example-app",
  "branch": "feature/example",
  "remoteSha": "0000000000000000000000000000000000000000",
  "commits": 3,
  "authorisedAs": "example-uid"
}
```

The response never contains a remote URL with credentials, a PAT, or a git transport error
verbatim — transport errors are mapped to the codes below after redaction.

## Error Codes

Errors are returned as MCP tool errors with a stable `code`, plus the corresponding HTTP status
where the failure happens before tool dispatch.

| Code | HTTP | Meaning | Condition |
|------|------|---------|-----------|
| `token_invalid` | 401 | Run token missing, malformed, expired or already consumed | Before any tool is resolved |
| `grant_missing` | 403 | The agent holds no grant that resolves to this tool | Grant resolution |
| `grant_unscoped` | 403 | A repo-effecting tool was granted by bare exact id | Grant resolution — the grant does not resolve at all |
| `argument_not_permitted` | 403 | An argument value falls outside the argument-scoped grant's permitted set | Invocation, before any side effect |
| `approval_required` | 409 | A write/destructive tool has no valid approval or run-scoped pre-authorisation | Before any side effect |
| `approval_denied` | 403 | The approval was refused or revoked | Before any side effect |
| `owner_unresolvable` | 403 | The run has no resolvable owner, so it may not author or push | `commit` / `push` |
| `credential_scope_refused` | 403 | The forge credential is not personal-scope, or is not owned by the run owner | `push` |
| `path_outside_workspace` | 400 | Absolute path, traversal segment, or a symlink resolving outside the root | Any path-taking tool |
| `path_forbidden` | 400 | The target resolves under `.git/` | Any write-shaped path tool |
| `workspace_absent` | 409 | A tool was called before `workspace.open` succeeded for this run | Any tool other than `open` |
| `workspace_quota` | 413 | The write would exceed the workspace's size or file-count budget | Write-shaped tools |
| `patch_rejected` | 422 | The patch does not apply cleanly, or targets a refused path | `apply_patch` |
| `egress_denied` | 403 | The PDP refused the forge host for this run | `open` / `push` |
| `push_rejected` | 409 | The remote refused the update (non-fast-forward, protected branch) | `push` |
| `tool_timeout` | 504 | The operation exceeded its per-tool budget | Any |

Every error message is redacted before it leaves the governed side. No error carries a
credential, a filesystem path, or an internal host name.

## Versioning

Layer A is versioned as a capability contract, not a URL. `tools/list` advertises each tool
descriptor including its `inputSchema`; the descriptor set is the version surface. The route
(`/api/mcp/run`) and its JSON-RPC envelope are owned by `cli-runner-governed-mcp-and-egress` and
are not versioned by this change.

Backward-compatibility guarantees for Layer A:

- Tool **ids are stable**. A rename is a breaking change.
- Arguments may be **added** only as optional with a safe default.
- Result fields may be **added**. Removing or retyping a field is breaking.
- Error `code` values are stable; new codes may be added.
- **Narrowing is never breaking.** Adding a confinement rule, tightening a grant requirement, or
  reclassifying a tool from write to destructive may break a *caller's expectations* and is
  still permitted without a major bump, because a security narrowing that requires consumer
  consent is not a security control.

Layer B compatibility is explicitly **not** guaranteed to Hydra. Hydra conforms by implementing
Layer A; it is not entitled to assume the endpoint is reachable, and Hermiq is not obliged to
keep the transport stable for an out-of-Nextcloud consumer.

## Breaking Change Policy

- A Layer A breaking change requires an OpenSpec change that names **both** consumers in its
  `Affected Projects` and is accepted by both before implementation, per the shared-from-day-one
  decision (PO, 2026-07-27).
- Adding a tool to the surface is **not** additive-by-default here: a new tool widens what a
  wildcard-shaped grant could reach, so every addition must state its classification and its
  required grant form in the same change.
- Removing an entry from `DISALLOWED_BUILTIN_TOOLS` is treated as a breaking change to this
  contract even though it touches no tool id, because it changes what the model can do without
  a grant.
- Emergency narrowing (a confinement or credential fix) ships without consumer sign-off and is
  announced afterwards.

## SLA

- `workspace.open` is the only long operation: it clones. Budget it in seconds-to-tens-of-seconds
  and give it its own timeout, distinct from the read/write tools.
- Read and write tools are local filesystem operations and must complete well inside the CLI's
  120 s per-turn wall clock; a tool that cannot must return `tool_timeout` rather than hold the
  turn open.
- `workspace.push` involves the forge and the PDP. It must fail fast on PDP denial rather than
  wait on a network timeout, so a policy denial never reads as "the forge is down".
- Availability of Layer B follows the Nextcloud instance. Hydra's builder MUST NOT have a
  liveness dependency on it — that is the whole reason Layer B is not required to be shared.
