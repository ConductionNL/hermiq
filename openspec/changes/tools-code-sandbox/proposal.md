---
kind: code
---

# Proposal: tools-code-sandbox

## Summary

An admin can install an optional sandbox app that runs short pieces of Python or JavaScript with no network, no access to anyone's files, and caps on time and memory. An agent owner can then grant an agent `hermiq.runCode`, so the agent can calculate, parse a file it was handed or check its own reasoning by running code. A flow author gets a "Code step" on the flow canvas that runs a small script over each item. The admin chooses where code runs: in that sandbox on the organisation's own server, or at a hosted sandbox provider reached through integriq.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:tl-code-exec` | no | build: five competitors rate yes |
| `hermiq:fl-code-step` | no | build: two competitors rate yes; hermiq contributes flow nodes to the OpenRegister canvas |
| `hermiq:dm-sandbox-choice` | no | build: changelog demand and three competitors rate yes |

Demand and competitor cells rated yes, quoted from the pack:

- `tl-code-exec`:
  - hermes-agent v2026.9.24: "tools/code_execution_tool.py:1-9 code execution through a session kernel; tools/environments/ docker.py, modal.py, daytona.py, singularity.py, vercel_sandbox.py, ssh.py isolated terminal backends".
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/code-interpreter-for-prompts: code interpreter lets agents "write and run Python code".
  - dify 1.17.1: "dify-agent/src/dify_agent/layers/shell/layer.py:303 agent runs scripts in a per-agent Linux sandbox (dify-agent-runtime/docker/Dockerfile); workflow Code node (web/i18n/en-US/workflow.json blocks.code) runs in dify-sandbox".
  - n8n@2.40.7: "Code node and Custom Code Tool (packages/@n8n/nodes-langchain/nodes/tools/ToolCode/ToolCode.node.ts) run JS or Python in task runners, Python in a sandbox".
  - open-webui v0.11.4: "backend/open_webui/tools/builtin.py:633 execute_code; src/lib/components/admin/Settings/CodeExecution.svelte:20 engines pyodide (browser WASM sandbox, src/lib/pyodide/pyodideSandboxHost.ts) and jupyter".
- `fl-code-step`:
  - dify 1.17.1: "web/i18n/en-US/workflow.json:6 blocks.code, Python or JavaScript run in the dify-sandbox service (docker/docker-compose.yaml sandbox)".
  - n8n@2.40.7: "packages/nodes-base/nodes/Code runs JavaScript or Python as a workflow step (task runners, packages/@n8n/config/src/configs/runners.config.ts:16-17)".
- `dm-sandbox-choice`: changelog https://github.com/langgenius/dify/releases/tag/1.17.0.
  - hermes-agent v2026.9.24: "tools/terminal_tool_backends.py:54 built-in backends 'local, docker, singularity, modal, daytona, vercel_sandbox, ssh'; hermes_cli/config_defaults.py:277-279 terminal.backend (default local)".
  - copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/configure-where-computer-use-runs: "computer use runs on a hosted browser / Windows 365 Cloud PC pool (Microsoft cloud) or on the customer's own registered machine".
  - dify 1.17.1: "docker/.env.example:271 DIFY_AGENT_RUNTIME_BACKEND=local, docker/docker-compose.e2b.yaml ships the E2B backend".

## What hermiq already has

- The only place hermiq runs a process is the `hermiq-llm-runner` ExApp. Its `/run` route executes exactly one LLM turn and no tool work (open change `llm-cli-runner-exapp`, requirement "The runner executes exactly one governed LLM turn"), and it denies the CLI's shell and file tools (`exapp/llm-runner/src/providers.js:125-185`). Its hardening is the model for a sandbox: non-root, no default route, no volumes, a per-call tmpfs (`exapp/llm-runner/README.md:85-110`).
- Its `/stage` route runs only allowlisted commands over a cloned tree (`exapp/llm-runner/src/stage.js:137-142`, `RUNNER_STAGE_COMMANDS` default `scripts/run-hydra-gates.sh,claude`), set by the operator, dispatched from `hermiq.workload-step` (`lib/Flow/HermiqWorkloadNode.php:117`) through AppAPI (`lib/Service/StageDispatchService.php:59`, `:218-253`).
- Earlier changes named a sandbox ExApp and left it blocked: "a heavy sandboxing concern that belongs to the blocked `hermiq-exec` ExApp" (`openspec/changes/archive/2026-07-14-web-research-tool/proposal.md:88-90`).
- A high-risk AI feature `skill-code-execution`, disabled by default, is seeded with no tool behind it (`lib/Repair/SeedAiFeatures.php:169-175`). The AI feature gate that a tool grant alone cannot pass already exists for mail reading (`lib/Service/NcNative/MailReadService.php:79`, `:363-395`).
- Flow nodes are contributed through `lib/Flow/HermiqFlowNodeListener.php:73-76`, and the tenant kill switch vetoes every `hermiq.*` hop (`lib/Flow/TenantKillSwitchCheck.php:3-12`).

## What this change builds

1. An optional ExApp `hermiq-code-sandbox`: Python and JavaScript, no network, no volumes, non-root, a fresh work directory per run, caps on time, memory, processes and output.
2. `hermiq.runCode`: an agent tool, write-classified so it is default-denied, behind an AI feature `agent-code-execution` that is off until an admin enables it.
3. `hermiq.code-step`: a flow node that runs a short script over each item, contributed next to `hermiq.workload-step`.
4. A choice for the admin: code runs in the sandbox ExApp on the own server, or at a hosted sandbox provider through an integriq source with its key in the credential broker. The tool's reach follows the choice.
5. Every run of code on the run record: the code, the limits, the exit code and a capped, redacted output.

## Out of scope

- Network access, package installation and files that last longer than one run. `tools-agent-workspace-and-installed-tools` builds on this change for those.
- A browser in the sandbox (`tools-browser-and-computer-use`).
- Changing the llm-runner ExApp. It stays an LLM transport without tool work.
- Vendor adapters for hosted sandboxes. Integriq maps a hosted provider's API; hermiq speaks one run contract.
