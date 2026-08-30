<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->

# Agent-engine parity harness (task 7.1)

Runs the same `(agent, prompt)` pair through **both** engine paths of a live
Nextcloud instance and reports **structural** parity:

- **old path** — OpenRegister's chat surface: `/apps/openregister/api/{conversations,chat}`
  backed by `OCA\OpenRegister\Service\ChatService` + the `openregister_*` tables;
- **new path** — Hermiq's route-for-route mirror: `/apps/hermiq/api/{conversations,chat}`
  backed by `OCA\Hermiq\Service\Engine\Engine` + `Agent`/`Conversation`/`Message`
  objects in the `hermiq` OpenRegister register.

The harness is **runnable, not a CI-blocking gate** (design.md "Parity
harness"): it exercises real LLM calls against a live instance, so it cannot
run in the unit-test container and is deliberately not wired into any CI
workflow. The PHPUnit-covered part is the pure comparison logic
(`lib/StructuralComparator.php`, tested by `tests/Unit/Parity/ParityReportTest.php`).

## What PASS means — and what is deliberately NOT asserted

Per the parity decision (Ruben, 2026-07-06, `openspec/changes/agent-engine-port/proposal.md`
"Decisions"), the bar is **structural-only**:

| Check | Asserted |
| --- | --- |
| `tool-call-sequence` | Same tool ids, same argument **key sets**, in the same order |
| `send-envelope-keys` / `usage-keys` / `timings-keys` | Identical sorted key sets on the `/chat/send` response, its `usage`, and its `timings` |
| `send-sources` / `persisted-assistant-sources` | Same RAG source **count** and source-entry **key shape** |
| `persisted-role-sequence` / `final-message-role` | Same persisted message roles (e.g. `user -> assistant`), same final role |
| `stream-terminal-event` | Both streams end in the same terminal event (`final` vs `error`) |
| gate checks (`--gate-check`) | Identical kill-switch refusal envelope on both engine-flag states |

Deliberately **NOT** asserted (logged as INFO for human review, can never flip
a check to FAIL):

- **response text** — a unified diff of the two assistant answers is printed
  and dumped; there is **no similarity threshold** (that calibration is
  `DEFERRED_QUESTIONS` item 2 in proposal.md);
- **tool-argument values** — LLM-authored free text (e.g. a search query),
  the same non-determinism class as response text; the **key shape** of the
  arguments is asserted, the values are logged;
- token counts, latencies, source contents, titles, uuids.

The overall exit code is 0 when every structural check passed, 1 otherwise.

## Observation channels (why the harness calls what it calls)

Verified against both controllers at HEAD 2026-07-06:

- **Send envelope** — `POST /api/chat/send` returns
  `{message, messageId, sources, timings, usage, conversation}` on both paths.
- **Persistence** — `GET /api/conversations/{uuid}/messages` returns
  `{results[], total, limit, offset}` on both paths; roles and the persisted
  assistant `sources` are read here.
- **Tool-call sequence** — the SSE `tool_call` events of
  `POST /api/chat/stream` (payload `{toolId, arguments}`, identical envelope
  on both paths). This is the **only** channel where either path surfaces tool
  invocations: the `/chat/send` response omits them and neither path persists
  tool-role messages (both engines store only user + assistant turns; LLPhant
  runs the tool loop in-process). Because of that, each path performs **two**
  LLM runs per prompt — a send leg and a stream leg in its own fresh
  conversation — and the tool-sequence comparison uses the stream legs while
  the envelope/persistence comparisons use the send legs.
- **Gate refusal** — `POST /apps/hermiq/api/schedules/{id}/run` returns
  `{scheduleId, status, error, nextRun}`; with the organisation kill-switch
  engaged, `ScheduleService::dispatch()` short-circuits **before** either
  engine path is reached and records `status='skipped_killswitch'`.

## Prerequisites for a live run

1. **Nextcloud >= 31** with:
   - **openregister** at a version that includes the ToolRegistryFacade merge
     (or#297 / `or-tool-registry-facade`) — Hermiq's tool loop consumes that
     facade;
   - **this hermiq branch** (`feat/agent-engine-port`) installed and enabled.
2. **The same LLM provider configured on BOTH paths**, pointing at the same
   model — otherwise structural differences are meaningless:
   - OR path: the `openregister` app's `llm` appconfig (admin settings → LLM);
   - Hermiq path: the `hermiq` app's `llm` appconfig.
   - Recommended: the fleet's Ollama qwen setup at `http://host:11434`
     (see `ollama-qwen-setup` in the project memory).
3. **Feature flag on** for the new path:
   `occ config:app:set hermiq engine.enabled --value=true`
4. **One agent per path with the SAME prompt/model/tools**:
   - an agent on the OR path (OpenRegister agents UI / `/apps/openregister/api/agents`);
   - an equivalent `agent` object in the `hermiq` register
     (`/apps/hermiq/api/agents` or the register UI).
   - Note: the downstream `agent-data-migration` change will make the
     hermiq-register copy automatic; until it lands, create the twin manually
     and keep prompt, model, provider, and the `tools` whitelist identical.
5. **Credentials**: an app password for a user that owns both agents
   (Settings → Security → app passwords). For `--gate-check`, the user must
   additionally be an instance admin or the organisation owner (the
   tenant-control toggle refuses otherwise) and must own the schedule
   (RunNowController's IDOR guard returns 404 for non-owners).

## Running — parity mode

```bash
export NEXTCLOUD_URL=http://localhost:8080
export NC_USER=admin
export NC_PASS=<app password>
export AGENT_UUID_OR=<agent uuid on the OpenRegister path>
export AGENT_UUID_HERMIQ=<agent object uuid in the hermiq register>

# Fixed documented prompt set (recommended):
php tests/parity/run-parity.php --prompt-set=tests/parity/prompts.json

# Or a single prompt:
PROMPT="Reply in one short sentence: what can you help me with?" \
  php tests/parity/run-parity.php
```

Report goes to stdout; raw per-path observations (create/send/messages/stream)
are dumped to `tests/parity/out/<timestamp>/` (gitignored) for post-hoc human
review of the response-text diffs.

## Running — gate-check mode

The harness cannot flip `hermiq engine.enabled` over HTTP (it is occ
appconfig), so gate-check runs **once per flag state** and compares the two
recorded refusal envelopes when both exist:

```bash
export NEXTCLOUD_URL=... NC_USER=... NC_PASS=...

# State 1: flag off (OR ChatService path behind the gate)
occ config:app:set hermiq engine.enabled --value=false
php tests/parity/run-parity.php --gate-check \
  --schedule-id=<schedule uuid> --organisation=<org uuid> --engine-flag-state=off

# State 2: flag on (in-app Engine path behind the gate)
occ config:app:set hermiq engine.enabled --value=true
php tests/parity/run-parity.php --gate-check \
  --schedule-id=<schedule uuid> --organisation=<org uuid> --engine-flag-state=on
```

Each run engages the organisation kill-switch, fires
`POST /api/schedules/{id}/run`, asserts the refusal
(`status='skipped_killswitch'`, HTTP 200), and **always restores the prior
kill-switch state** (try/finally). The second run adds the cross-state checks:
identical HTTP status, identical envelope key set, identical `status` value —
proving the kill-switch short-circuit is byte-identical regardless of which
engine sits behind `runAgentAsOwner()`.

The approval-gate variant of the short-circuit (`requiresApproval` →
`awaiting_approval`) is covered by the 95-test kill-switch/approval regression
suite (task 8.1), not duplicated here.

## Status of task 7.1 in this change

The harness is **built and unit-covered** in this chunk. The **live run**
half of task 7.1 is deferred with a written reason: no environment with
NC + openregister (>= or#297) + this hermiq branch + a configured LLM was
available to the build; the run must happen on a live instance following the
steps above before any default flips (`chat-appid-flip`,
`or-chat-proxy-deprecation`).
