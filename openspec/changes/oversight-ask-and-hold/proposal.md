---
kind: code
depends_on: []
---

# Proposal: oversight-ask-and-hold

## Summary

An agent can stop and ask a person a question with up to four suggested answers, and go on with the answer. In chat the question shows as buttons under the answer; in an unattended run it goes to the approval inbox and Talk like an approval, and the run continues after it is answered. An owner can also hold what an agent writes to people, a scheduled report or a mail it wants to send, until a reviewer has read it, edited it if needed and released it.

## Why

Two rows of hermiq's capability matrix, oversight area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:dm-ask-mid-run` | partial, built | build: changelog demand and five competitors rate yes for the missing half, a question with suggested answers instead of approve or reject |
| `hermiq:ov-draft-hold` | no | build: three competitors rate yes |

Demand, quoted from the matrix:

- `dm-ask-mid-run`: changelog https://github.com/open-webui/open-webui/releases/tag/v0.11.1

Competitor cells rated yes, quoted from the matrix evidence:

- `dm-ask-mid-run`, Hermes Agent v2026.9.24: `tools/clarify_tool.py:1-3` "'structured multiple-choice / open-ended questions to the user', :9 up to 4 choices plus 'Other'". Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/authoring-ask-a-question "generates 'questions to ask the user for missing information'". Dify 1.17.1: `dify-agent/src/dify_agent/layers/ask_human/layer.py:1-60` "ask_human deferred tool the model calls mid-run". n8n 2.40.7: `Chat.node.ts:141,172` "'Wait for User Reply' ... pause the run for the person's answer". Open WebUI v0.11.4: `backend/open_webui/tools/builtin.py:518` "ask_user (1-3 questions with options and free text)".
- `ov-draft-hold`, Nextcloud Assistant v4.0.0: `context_agent:ex_app/lib/all_tools/mail.py:16`, `talk.py:49,137` "send_email, send_message_to_conversation and reply_to_message are dangerous tools ... so the drafted text is shown for confirmation before it is sent". Dify 1.17.1: "a workflow can put a Human Input node between the LLM/Agent node and the reply so a person reviews, edits". n8n: `agent-json-config.schema.ts:429` "requireApproval on node tools such as Gmail or Send Email".

## What hermiq already has

- An approval gate at four points, scheduled run, flow run, webhook run and tool call, each with exactly one pending Approval and approve or deny with a reason (`docs/approvals.md:20-35`; `lib/Service/ApprovalService.php:209-583`, `approve()` at `:1251`, `deny()` at `:1370`). `Approval.sourceType` is one of `schedule`, `flow`, `webhook`, `tool`, `toolcall`, `skill-draft`, `advisory` (`lib/Settings/hermiq_register.json`, Approval).
- Reviewers decide in the approval inbox (`src/views/ApprovalInbox.vue`) or by a Talk reaction (archived `talk-approval-reactions`).
- A refused confirm-classified tool call is retried after approval rather than resumed (archived `agent-guardrails`, "approve, then retry"), because hermiq has no checkpoint of a run.
- Run output goes out through `DeliveryService::deliver()` (`lib/Service/DeliveryService.php:281`), called from `ScheduleService::deliver()` (`lib/Service/ScheduleService.php:2857`). `hermiq.sendMail` is an agent tool (`lib/Mcp/HermiqToolProvider.php:243`, invoked at `:696`).
- The open change `approval-task-convergence` mirrors each pending Approval into an OpenRegister task.

## What this change builds

1. `hermiq.askPerson`: a tool an agent calls with a question and up to four suggested answers plus free text.
2. In chat: the question ends the turn and shows as buttons; the chosen answer is the next message.
3. Unattended: the question becomes an Approval with `sourceType: question` for the run's reviewer; the answer re-dispatches the run with the answer added.
4. `holdOutput` on a schedule and on an agent: delivery and outbound message tools create an Approval with `sourceType: draft` holding the text; the reviewer releases it as is, edits and releases, or discards it.

## Out of scope

- Resuming a run mid-turn at the exact point it asked. hermiq has no checkpoint layer (archived `run-replay-and-dry-run`); the run continues by re-dispatch with the answer, the same shape as "approve, then retry".
- Holding chat answers in an interactive session. The person in the chat is the reviewer there.
