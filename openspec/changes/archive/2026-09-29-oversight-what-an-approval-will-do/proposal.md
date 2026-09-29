---
kind: code
depends_on: []
---

# Proposal: oversight-what-an-approval-will-do

## Summary

Before a reviewer approves, the approvals inbox shows what the action will do, why it was held and what it touches: which tool, with which arguments, whether it only reads or also writes, sends or deletes, and how far it reaches (the person's own data, the organisation, or outside the instance). For a held scheduled run it shows the agent's tools with the same labels. The reviewer opens this per row before choosing approve or deny.

## Why

One row of hermiq's capability matrix that is `building`, decided in the build-all pass of 2026-09-28.

| row | own rating | decision |
|---|---|---|
| `hermiq:ov-approval-context` | partial, building | build: two competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- Hermes Agent v2026.9.24: "gateway/platforms/base_exec_approval.py:1-16 every approval card states what Hermes wants to run, why it was flagged (EA_REASON_LABEL_TEXT) and that silence means it does not run".
- Nextcloud Assistant v4.0.0: "each pending action shows the tool name and its arguments with More/Less (assistant:src/components/ChattyLLM/AgencyAction.vue:6-17)".

The matrix note on the built half: "The prompt column gives some 'what will run' context, but there is no blast-radius, lineage or rollback preview before approving."

## What hermiq already has

- The Approval schema stores `sourceType`, `toolId`, redacted `toolArguments`, `agentId`, `prompt` and `requestedAt` (`lib/Settings/hermiq_register.json` Approval).
- `ApprovalService::listPendingForReviewer()` (`lib/Service/ApprovalService.php:1059`) returns only id, scheduleId, agentId, prompt, requestedAt, reviewer and status (`:1088-1096`); `sourceType`, `toolId` and `toolArguments` never reach the inbox.
- `src/views/ApprovalInbox.vue:52-90`: schedule, agent, prompt and requested-at columns.
- Every tool in OpenRegister's catalog declares `readOnlyHint`, `destructiveHint`, `scope` and a reach from `ToolReachResolver` (`OCA\OpenRegister\Service\Capability\ToolReachResolver`, used by `lib/Mcp/HermiqToolProvider.php`); `ToolLoop` reads the catalog through `ToolRegistryFacade::listTools()` (`lib/Service/Engine/ToolLoop.php:305`).

## What this change builds

1. The inbox record gains `sourceType`, the redacted `toolArguments`, and a derived `preview`: for a tool call the tool's name, what it does (reads, changes, sends or deletes), its reach, and its arguments; for a scheduled, flow or webhook run the agent's granted tools with the same labels. Nothing new is stored.
2. A "Details" action per inbox row opening `src/modals/ApprovalPreviewModal.vue` with that preview, the reason it was held, and approve and deny buttons.
3. The Talk approval message gains one line naming the tool and its reach when the approval is a tool call.

## Out of scope

- A dry run that executes the tool against a copy. The preview describes; it does not run anything.
- Rollback of an approved action.
