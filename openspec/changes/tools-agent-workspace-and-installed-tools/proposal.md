---
kind: code
depends_on: [hermiq-runner-git-capability, tools-code-sandbox]
---

# Proposal: tools-agent-workspace-and-installed-tools

## Summary

An agent gets a workspace that lasts as long as the conversation: files it writes in one turn are there in the next, the code it runs in the sandbox works on them, and the user can see them and save any of them to their own Files. An agent owner can let an agent install a command line tool from an allowlisted package registry into its sandbox and then run it. When the owner publishes a build of the agent, the workspace files and the installed tools are frozen with that agent version, and every run of the published agent starts from exactly that build.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-agent-workspace` | partial, built | build: changelog demand and two competitors rate yes for the missing half, a workspace that lasts a session |
| `hermiq:dm-install-cli-tool` | no | build: changelog demand and two competitors rate yes |
| `hermiq:dm-build-snapshot` | no | build: core area, changelog demand and two competitors rate yes; it only has meaning once an agent keeps files and installed tools, so it rides with the workspace |

Demand and competitor cells rated yes, quoted from the pack:

- `dm-agent-workspace`: changelog https://github.com/n8n-io/n8n/releases/tag/n8n%402.36.0.
  - hermes-agent v2026.9.24: "tools/terminal_tool.py:1489-1490 and tools/file_tools.py give the agent a writable working directory in its terminal backend ...; hermes_cli/commands.py:78-80 /worktree creates an isolated git worktree per session".
  - dify 1.17.1: "api/services/agent/workspace_service.py:1-60 AgentWorkspace and binding lifecycle backed by the dify-agent runtime; ... web/features/agent-v2/agent-detail/configure/components/preview/working-directory-panel.tsx:268-475 working directory file browser".
- `dm-install-cli-tool`: changelog https://github.com/langgenius/dify/releases/tag/1.16.0.
  - hermes-agent v2026.9.24: "tools/terminal_tool.py:1249,1489-1490 the terminal tool runs any shell command in the agent's backend, so the agent installs a package (pip, npm, apt) and uses it in later calls".
  - dify 1.17.1: "web/i18n/en-US/agent-v-2.json:237,251 'Add a CLI Tool', 'Install a CLI tool from any package registry. The agent gains shell access to the binary it provides'".
- `dm-build-snapshot`: changelog https://github.com/langgenius/dify/releases/tag/1.17.0.
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/agents-experience/publication-publish-agent: publishing "creates a live version of the current draft"; draft changes do not reach users until the next publish.
  - dify 1.17.1: "api/models/agent.py:218 AgentHomeSnapshot captured when a build is applied; release 1.17.0 'Build-time Home Snapshots' restores the sandbox home on each published run".

## What hermiq already has

- A per-step scratch tree inside flows only: `hermiq.workload-step` clones a ref into a throwaway checkout the stage command can edit (`lib/Flow/HermiqWorkloadNode.php:117`, `exapp/llm-runner/src/stage.js`), removed on exit. Chat and scheduled runs have no workspace.
- The open change `hermiq-runner-git-capability` specifies governed `workspace.*` file and git tools over a workspace on the governed side, never in the runner, bound to the run token (`openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md:101-116`, design Decision 2). The run token already binds `(runId, agentId, userId, conversationId)` (`lib/Service/Llm/RunTokenService.php:20`, `:159-168`).
- The open change `tools-code-sandbox` specifies the sandbox where code runs, with no network and no mounts.
- No install path: the llm-runner denies the CLI's shell (`exapp/llm-runner/src/providers.js:140-184`) and runs only operator-allowlisted stage commands (`exapp/llm-runner/src/stage.js:137-142`).
- Agent versions are AuditTrail entries used for diff and rollback (`lib/Service/AgentVersionService.php:146` `listVersions()`, `:223` `rollback()`, `:268` `currentVersionId()`), and every run pins the version that ran (`lib/Service/ScheduleService.php:1814`). Runs read the live agent (`lib/Service/Engine/ResponseGenerationHandler.php:196-198`); nothing freezes files or tools with a version.

## What this change builds

1. A session workspace: the workspace of `hermiq-runner-git-capability`, keyed by the conversation instead of a single run when the agent's workspace mode is `session`, with a size budget and an end.
2. Sandbox runs that work on the session workspace: files copied in before a run and changed files copied back after.
3. A Files panel in the chat: the user sees the workspace, downloads a file, or saves it to their own Files marked as agent-authored.
4. `hermiq.installTool`: install a package from an admin-allowlisted registry (PyPI, npm) into the agent's tool layer, in a separate installer with registry-only egress, without running install scripts.
5. `hermiq.runInstalledTool`: run an installed binary in the sandbox with an argument list and no shell.
6. Builds: "Publish build" on an agent freezes its workspace files and installed tools with the current agent version; the agent then runs from the pinned build, and each run records the build it started from.

## Out of scope

- A shell for the agent. There is no `sh -c` and no free command string; `hermiq-runner-git-capability` rules out `workspace.exec` for the same reason.
- Git operations. They are `hermiq-runner-git-capability`'s tools.
- System packages (`apt`). The sandbox image is fixed.
- Sharing a workspace between conversations or users.
