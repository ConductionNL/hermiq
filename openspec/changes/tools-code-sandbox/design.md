# Design: tools-code-sandbox

Kind: code. Size L. A new ExApp under `exapp/code-sandbox/`, a dispatch client, one tool, one flow node, one admin section, one seeded AI feature.

## Context at development db6b74dc

- `exapp/llm-runner/README.md:85-110` hardening: non-root with `cap_drop: [ALL]`, an `internal: true` network whose only way out is the egress proxy asking hermiq's PDP, no volumes, per-call tmpfs.
- `exapp/llm-runner/src/providers.js:125-184` the denied CLI built-ins; `exapp/llm-runner/src/stage.js:137-142` the operator's command allowlist; `exapp/llm-runner/src/server.js:403-488` the route layout.
- `lib/Service/StageDispatchService.php:52-66` AppAPI `PublicFunctions`, ExApp id and route; `:218-253` `dispatch()` through `exAppRequest()`.
- `lib/Flow/HermiqWorkloadNode.php:117` node id, `:162` `isAvailableForScope()`, `:177` `validateConfig()`, `:210` `execute()`; `lib/Flow/HermiqFlowNodeListener.php:73-76`; `lib/Flow/HermiqWorkloadCollectNode.php:3-40` on why a flow step must not hold the worker.
- `lib/Mcp/HermiqToolProvider.php:648-657` `getTools()` merges descriptor lists; `:675` `invokeTool()`.
- `lib/Service/NcNative/MailReadService.php:79`, `:363-395` the AI feature gate that fails closed.
- `lib/Repair/SeedAiFeatures.php:169-175` the seeded `skill-code-execution`.
- `lib/Service/SkillMarketplaceService.php:458-470` integriq's `CallService` by canonical name.
- `openspec/specs/agent-capability-reach/spec.md` reach vocabulary `self` < `user` < `instance` < `external`, and default-deny keyed on write classification or reach `instance` and up.

## D1. A separate ExApp, not the llm-runner

`hermiq-code-sandbox` is its own AppAPI ExApp. The llm-runner holds provider credentials and has governed egress to model APIs; a sandbox that runs model-written code must have neither. Keeping them apart means a flaw in one cannot reach the other's secrets.

Rejected: a new route on the llm-runner. It would put model-written code one process away from a vendor token.

## D2. What the sandbox guarantees

- No network: the container's network is `internal: true` with no egress proxy attached. There is no way out, not even to hermiq.
- No data mounts: no volumes. The only writable place is a tmpfs work directory per run, removed when the run ends.
- Non-root, `cap_drop: [ALL]`, read-only root filesystem, `no-new-privileges`.
- Each run is a fresh child process under a separate UID with resource limits: wall time default 30 s (max 300 s), memory default 512 MB (max 2 GB), at most 64 processes, stdout and stderr each capped at 64 KB, created files capped at 10 MB in total.
- Languages: Python 3 with the standard library and a fixed set (`pandas`, `numpy`, `python-dateutil`), and Node without extra packages. The image pins them.
- The deploy notes recommend running the container with gVisor (`runsc`) where the host offers it.

The run contract is `POST /run` with `{language, code, stdin, files: [{name, content}], limits}` and the answer `{exitCode, stdout, stderr, durationMs, timedOut, files: [{name, size, content}]}`. Files are text or base64 and count against the caps.

## D3. `hermiq.runCode`

Descriptor: `scope: create`, `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: false`. The write classification makes it default-denied: an agent needs it in `Agent.tools`, and an un-granted call goes through the approval gate. An organisation's guardrail policy can classify it `confirm` to put every call before a reviewer.

Reach follows the admin's choice (D5): `self` when code runs in the own sandbox, because nothing leaves the container and no one else sees an effect; `external` when it runs at a hosted provider, because the code and its input leave the instance. `getTools()` builds the descriptor from the current setting, so the grant editor shows the true reach.

Before any dispatch, `runCode` requires the AI feature `agent-code-execution` to be enabled, the gate `MailReadService` uses: absent, unreadable or disabled means refused with "Running code must be enabled as an AI feature before an agent can run code." A tool grant alone never enables it. The feature is seeded high-risk and disabled, next to `skill-code-execution`, so the DPO acknowledgement of `ai-feature-governance` applies.

The input may name files from the acting user's Files (`files: [{path}]`). Hermiq reads them as the acting user, the IDOR rule of `nc-native-tools`, and copies their text into the run payload. In that case the resolved reach is at least `user`.

## D4. `hermiq.code-step`

A flow node contributed by `HermiqFlowNodeListener` next to the four existing nodes. Config: `language`, `code`, `timeoutSeconds` (default 10, max 30). For each item the node sends the item's JSON on stdin and expects one JSON value on stdout, which replaces the item; a non-zero exit or unparsable output marks the item failed with the stderr tail. The cap is low because `FlowRunWorker` advances runs one at a time, the reason `HermiqWorkloadCollectNode` exists; longer work belongs in a workload step.

The node needs the same AI feature and is refused by the tenant kill switch like every `hermiq.*` hop.

## D5. Own server or hosted

An admin setting `codeSandbox.backend`: `exapp` (default) or `hosted`. With `hosted`, the admin picks an integriq source that answers the same run contract; its key sits in the credential broker, and hermiq sends the run through integriq's `CallService`. Integriq maps the contract onto the vendor's API. The admin section shows which backend is live, its limits, and a "Run a test" button that runs `print(1 + 1)`.

When neither backend is available, `runCode` answers "No code sandbox is installed on this instance." and `code-step` does not appear in the palette (`isAvailableForScope()` returns false).

Rejected: a per-agent choice. The data-protection question "where does code run" is answered once for the instance, and a per-agent switch would let an agent owner send data to a hosted provider on their own.

## D6. What the run records

Each call adds a tool step with the code, the language, the limits, the exit code, the duration, `timedOut`, and stdout and stderr cut to 4 KB and passed through `RedactionService` before they are persisted. The full output goes back to the model, not into the audit trail.

## Declarative versus imperative

The AI feature is seeded data. The admin setting lives in app configuration. The tool, the node and the dispatch are imperative by nature. No `x-openregister-*` lifecycle, aggregation, notification or relation is involved.

## Seed data

- AI feature `agent-code-execution`: name "Agent code execution", description "An agent runs code it wrote in an isolated sandbox.", `riskCategory: high`, `lifecycle: disabled`.
- An example agent template "Rekenhulp begroting" (not installed by default): tools `hermiq.runCode`, prompt "Reken bedragen na met code voordat je ze noemt."

## Risks

- A sandbox escape. Mitigation: no network and no mounts mean an escape reaches an empty container; gVisor where available; the image is rebuilt with the ExApp's release.
- Runaway cost of hosted runs. Mitigation: the per-run caps travel with the request, and each run is a tool call counted on the run record.
- Personal data in code output. Mitigation: the audit copy is cut and redacted; the files a run reads are the acting user's own.
