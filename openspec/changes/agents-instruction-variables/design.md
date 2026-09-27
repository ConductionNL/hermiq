# Design: agents-instruction-variables

Kind: code. Size M. Rows `hermiq:dm-prompt-placeholders`, `hermiq:dm-agent-form-fields`.

## Context at development db6b74dc

- System prompt: `ResponseGenerationHandler` (`lib/Service/Engine/ResponseGenerationHandler.php:325-362`) builds `$systemPrompt` from `$agentData['prompt']`, appends the Context preamble, the `CURRENT APP CONTEXT` block and the RAG block, then prepends it as the system message.
- Agent schema: `prompt` (`lib/Settings/hermiq_register.json`, Agent), no field list.
- Session: created by `createSession(agentUuid, title)` (`src/api/chat.js:118`) from `Chat.vue` `newSession()` (`src/views/Chat.vue:1021`, `:1097`). The session schema is being renamed by the open chain `session-schema-declaration`, `session-data-migration`, `session-api-rename`, `session-frontend-rename`.
- Scheduled runs: `ScheduleService::runAgentAsOwner()` (`lib/Service/ScheduleService.php:2199`) runs as the schedule owner through `Engine::processMessage()`.
- Guardrails: the input filter runs on user text before every turn (archived `agent-guardrails`).

## D1. A closed list of placeholders

`PromptVariableResolver::fill(string $prompt, VariableScope $scope): string` replaces:

| placeholder | value |
|---|---|
| `{{user.displayName}}` | the acting person's display name |
| `{{user.id}}` | their Nextcloud user id |
| `{{user.language}}` | their language, for example `nl` |
| `{{organisation.name}}` | the active OpenRegister organisation's name |
| `{{today}}` | the date in the person's time zone, `YYYY-MM-DD` |
| `{{now}}` | date and time in the person's time zone |
| `{{agent.name}}` | the agent's name |
| `{{app.id}}` | the companion's `context.appId`, empty outside an app |
| `{{field.<key>}}` | a start field answer (D2) |

An unknown placeholder is left as written and listed as a warning in the owner's preview. The resolver runs on `Agent.prompt` only, before the preamble is appended, so no retrieved text or user text is ever treated as a template. On a scheduled run the acting person is the schedule owner.

Rejected: a template engine such as Twig. It evaluates expressions, and instructions are text an owner types, not code.

## D2. Start fields

`Agent.startFields`: an array of `{key, label, type, options, required, default}` with `type` one of `text`, `paragraph`, `select`, `number`, `date`, at most ten fields, `key` matching `^[a-z][a-z0-9_]{0,31}$`. When a person starts a session with an agent that has fields, the chat page shows them above the composer; required fields must be filled before the first message is sent. Answers are stored on the session as `startValues` and shown in the session header. A scheduled run uses each field's `default`, and a schedule may override them in `Schedule.startValues`.

Answers pass through the guardrail input filter once when the session starts, because they reach the model inside the instructions.

## D3. The owner's editor

The prompt field on the agent form gets a "Insert placeholder" menu and a "Preview" button. Preview calls `POST /api/agents/{id}/prompt-preview` with sample field values and shows the filled-in instructions with the owner's own details.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| `startFields`, `startValues` | declarative, schema properties | plain data |
| filling placeholders | imperative, `PromptVariableResolver` | per-turn text assembly in the engine |

## Seed data

The seeded starter template "Meeting minutes drafter" gains the instruction line "Address {{user.displayName}}. Today is {{today}}." and one start field `{key: "meeting", label: "Which meeting?", type: "text", required: true}`.

## Risks

- A value that changes the meaning of the instructions, such as a field answer written as an instruction. Mitigation: the guardrail input filter on answers, and answers are inserted as quoted text.
- Personal data in instructions reaching a remote model. Mitigation: only the listed values, all already known to the provider through the conversation, and the redaction before persist that already applies to the run audit.
