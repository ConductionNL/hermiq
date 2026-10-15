# Design: tools-agent-workspace-and-installed-tools

Kind: code. Size L. A lifetime mode on the governed workspace, copy-in and copy-out around sandbox runs, an installer service in the sandbox ExApp, two tools, an `AgentBuild` schema, and two screens (the chat's Files panel and the agent's builds).

## Context at development db6b74dc

- `openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md:101-116` (workspace on the governed side, opaque id, size budget, lifetime bound to the run token), `:142` (path confinement), design Decision 2 (no workspace inside the runner).
- `lib/Service/Llm/RunTokenService.php:20` the binding `(runId, agentId, userId, conversationId)`, `:130-168` issue with the run TTL.
- `openspec/changes/tools-code-sandbox/design.md` D2 (no network, tmpfs per run, the `files` in and out of a run) and D3 (`hermiq.runCode`).
- `lib/Service/AgentVersionService.php:146` `listVersions()`, `:223` `rollback()`, `:268` `currentVersionId()`; `lib/Service/ScheduleService.php:1814` pins `agentVersion` on the run.
- `lib/Settings/hermiq_register.json:2609-2618` `Agent.skillInstalls` holds skill uuids, not versions.
- `lib/Service/NcNative/AgentArtefactMarker.php` `markFile()`: a file an agent writes into Files carries the ADR-088 mark in the same operation.
- `lib/Service/Engine/ResponseGenerationHandler.php:196-198` the run reads the live agent object.

## D1. A session lifetime for the governed workspace

`Agent` gains `workspaceMode`: `none` (default), `run` (the behaviour `hermiq-runner-git-capability` specifies) or `session`. With `session`, the workspace is keyed by `(agentId, conversationId, userId)` from the run token instead of by the run id, so the next run in the same conversation finds the same workspace. A run token still only reaches the workspace of its own conversation, user and agent.

A session workspace ends when its conversation is deleted, or 14 days after the conversation's last activity, whichever comes first. Its budget: 200 MB and 5,000 files by default, set per instance.

This widens the lifetime rule of the open `hermiq-runner-git-capability` requirement "Each run gets a bounded workspace on the governed side" (lifetime bound to the run token) for agents that opt in. When that change is archived, its requirement needs a MODIFIED delta that names the session mode; until then this change's own requirement states it.

Rejected: a workspace in the user's Files. Model-written files would appear among the user's documents before anyone looked at them, and every write would need the agent-authored mark.

## D2. Sandbox runs work on the workspace

`hermiq.runCode` gains `useWorkspace` (default true when the agent has a workspace). Before the run hermiq packs the workspace (within the sandbox's input cap) into the run's `files`; after the run it writes back the files the run created or changed, through the same path confinement as `workspace.write_file`. The sandbox stays stateless and mount-free, as `tools-code-sandbox` D2 requires.

A workspace over the sandbox's input cap is refused with "The workspace is too large to run code on (limit 10 MB). Remove files or run on fewer files." and the call can name `paths` to send a subset.

## D3. Files the user can see

The chat gets a "Files" panel for a conversation with a workspace: name, size, changed time, download, and "Save to my Files". Saving copies the file into the user's own Files under "Hermiq" and applies the agent-authored tag in the same operation (`AgentArtefactMarker::markFile()`); a failed mark is a failed save, as ADR-088 requires. The routes are `#[NoAdminRequired]` and check that the workspace belongs to the session user.

## D4. Installing a tool without opening the sandbox to the internet

The sandbox ExApp gains a second service, `installer`, on its own network whose only way out is an egress proxy allowing only the registries an admin allowlisted (default: `pypi.org` and `files.pythonhosted.org`, `registry.npmjs.org`). Code runs never touch that network.

`hermiq.installTool` takes `manager` (`pip` or `npm`), `package` and an optional `version`. The installer runs `pip install --only-binary=:all: --no-deps` per resolved requirement, or `npm install --ignore-scripts`, into an empty prefix, so no package's own install script runs. It returns a tool layer: an archive of the prefix with its SHA-256 and the resolved versions of every package in it. Hermiq stores the layer beside the workspace and records it on the agent's tool list for that conversation.

Descriptor: reach `external` (the package name goes to a registry), `scope: create`, `destructiveHint: true` (it brings third-party code into the agent's runs). So it is default-denied and approval-gated when un-granted. An organisation can also keep a package allowlist in its guardrail policy; a package outside it is refused before the installer is called.

## D5. Running an installed tool

`hermiq.runInstalledTool` takes `tool` (the installed binary's name) and `args` (a list of strings). Hermiq sends the layer's hash with the run; the sandbox unpacks the layer from its content cache, or receives the bytes on a miss, verifies the hash, and executes the binary with `execve` semantics: an argument list, no shell, the workspace as working directory, the same limits and no network as any code run. The descriptor matches `hermiq.runCode`.

Rejected: a `runCommand` with a command string. A string needs a shell, and a shell turns every argument into a place to inject a second command.

## D6. Builds

An `AgentBuild` object: `agentId`, `agentVersionId` (from `currentVersionId()`), `workspaceArchive` (a content hash and the stored archive of the workspace at publish time), `tools` (`[{manager, package, version, sha256}]`), `createdBy`, `createdAt`, `note`. "Publish build" on the agent page creates it from the owner's current test conversation workspace and sets `Agent.pinnedBuildId`.

When an agent has a pinned build, every new session workspace starts as a copy of the build's archive with the build's tool layers, and nothing the run changes flows back into the build. The run record pins `buildId` next to `agentVersion`. Rolling the agent back to a version whose build exists re-pins that build.

A run of an agent whose pinned build no longer matches its current version, because someone edited the prompt after publishing, runs from the build's files and tools and the live prompt, and the agent page says "Unpublished changes since build 3" until the owner publishes again.

Rejected: freezing the prompt and model too, so a build is a full immutable agent. Agent versions already record those fields and roll them back; duplicating them would give two sources for one fact.

## Declarative versus imperative

`AgentBuild`, `Agent.workspaceMode` and `Agent.pinnedBuildId` are declared in the register. Workspace storage, copying, installing and running are runtime work on the governed side and in the ExApp. The workspace expiry could become an `x-openregister` retention rule on the conversation; until then a daily job removes expired workspaces.

## Seed data

- Agent template "Data-analist" (not installed by default): `workspaceMode: "session"`, tools `hermiq.runCode`, `hermiq.installTool`, `hermiq.runInstalledTool`, `workspace.read_file` and `workspace.write_file` (the ids `hermiq-runner-git-capability` names).
- An `AgentBuild` example: `note: "Build 3: csvkit 2.1.0 en de sjablonen voor de kwartaalrapportage"`, `tools` `[{"manager": "pip", "package": "csvkit", "version": "2.1.0", "sha256": "<hash>"}]`.

## Risks

- A malicious package. Mitigation: registry allowlist, no install scripts, binary wheels only, approval gate, optional package allowlist, and every run of it inside the no-network sandbox.
- Disk growth. Mitigation: per-workspace budgets, the 14-day end, and builds deduplicated by content hash.
- A stale build hides a fix the owner made in the prompt. Mitigation: the prompt is always live; only files and tools are pinned, and the agent page shows unpublished changes.
