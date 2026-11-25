# Design: oversight-ask-and-hold

Kind: code. Size M. Rows `hermiq:dm-ask-mid-run`, `hermiq:ov-draft-hold`.

## Context at development db6b74dc

- `ApprovalService` (`lib/Service/ApprovalService.php`): `ensurePendingApproval()` for schedules (`:209`), flows (`:278`), webhooks (`:355`), tool invocations (`:433`) and tool calls (`:506`); `approve()` (`:1251`) re-dispatches the gated run, `deny()` (`:1370`) records the reason. `listPendingForReviewer()` (`:1059`), `isReviewer()` (`:1218`).
- Approval schema: `status`, `sourceType` (enum above), `correlationId`, `flowContext`, `webhookContext`, `toolId`, `toolArguments`, `agentId`, `prompt`, `reviewer`, `reviewerType`, `decidedAt`, `decidedBy`, `reason`, `taskUuid`, `talkMessageId`, `decidedVia`, `draftPayload` (`lib/Settings/hermiq_register.json`, Approval).
- Delivery: `DeliveryService::deliver(channel, output, schedule, conversationUuid)` (`lib/Service/DeliveryService.php:281`) for Talk, notification, email and webhook; empty output is a no-op.
- Tools: `hermiq.sendMail` (`lib/Mcp/HermiqToolProvider.php:243,696`), invoked through `FacadeToolInvoker::__call()` (`lib/Service/Engine/FacadeToolInvoker.php:448`), which already routes confirm-classified and un-granted destructive calls to the approval gate.
- Chat rendering: `src/views/Chat.vue` shows answers, sources and tool steps (`:266-288`, `:384-396`); the SSE envelope has six fixed event types (hydra ADR-034 decision 6).

## D1. askPerson is a hermiq tool that ends the turn

`HermiqToolProvider` adds `hermiq.askPerson` with input `{question: string (max 500), options: string[0..4] (each max 80), allowOther: bool}`, reach `internal`, no side effect, and classified `read` so it needs no grant beyond the agent's tool list. `FacadeToolInvoker` handles it like `searchTools`: it records the question on the run trace, sets the turn's stop flag, and returns "Question sent to the person. End your answer now."

- Interactive chat: the question travels in the `final` event's payload as `question: {text, options, allowOther}`, so the six-event envelope is unchanged. `Chat.vue` renders the options as buttons and a text box; a click sends the option as the next user message.
- Unattended run (schedule, flow, webhook): `ApprovalService::ensurePendingQuestion(runContext, question)` creates an Approval with `sourceType: question`, the options in `draftPayload`, routed to the schedule's reviewer, or to the agent owner when none is set. The inbox and Talk show the question with its options. Answering calls `approve()` with the chosen answer, which re-dispatches the run with the prompt plus "The person answered: <answer>". No answer within 7 days expires it as denied.

Rejected: blocking the PHP request until the person answers. A web request or background job cannot wait for days.

## D2. Holding written output

A new boolean `holdOutput` on Schedule and on Agent (the schedule's value wins when set).

- Scheduled delivery: `ScheduleService::deliver()` checks `holdOutput`; if set it calls `ApprovalService::ensurePendingDraft()` instead of `DeliveryService::deliver()`: an Approval with `sourceType: draft`, `draftPayload: {channel, output, conversationUuid}`. Release delivers the (possibly edited) text through `DeliveryService` exactly as the run would have; discard records the reason.
- Outbound message tools: for an agent with `holdOutput`, `FacadeToolInvoker` treats `hermiq.sendMail` and any tool whose descriptor declares `sendsMessage: true` as a held draft: it creates the draft Approval with the tool arguments, returns "Draft held for review" to the model, and the tool runs with the released arguments on release. A reviewer may edit only the message fields the descriptor marks `editable` (subject and body for mail), never the recipients.

The inbox gets a "Drafts" filter; the draft view shows the text in an editor, the recipients read-only, and the buttons "Release", "Edit and release" and "Discard".

## D3. Who decides

The reviewer rules of the approval gate apply unchanged (`isReviewer()`), including Talk reactions for release and discard. An edit is recorded on the Approval as `editedOutput` with the diff against the original, so the audit shows what the agent wrote and what was sent.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| `holdOutput`, the two new `sourceType` values, `editedOutput` | declarative, schema properties and enum | plain data |
| expiring an unanswered question after 7 days | declarative, `x-openregister-lifecycle` timeout on Approval if OpenRegister's lifecycle supports timers, else the existing approval sweep | ADR-031 first |
| holding and releasing | imperative, `ApprovalService` and `DeliveryService` | the gate is imperative today |

## Seed data

The seeded example schedule "Weekly supplier digest" gets `holdOutput: true` so a fresh install shows a held draft after its first run.

## Risks

- An agent asking questions instead of working. Mitigation: `askPerson` counts toward the tool call cap of `agents-switch-off-and-stop`, and one open question per run.
- A reviewer editing recipients to send elsewhere. Mitigation: recipients are read-only in the draft view and rejected server-side if changed.
- Many held drafts piling up. Mitigation: the inbox count, and the same notifications and Talk posts approvals use.
