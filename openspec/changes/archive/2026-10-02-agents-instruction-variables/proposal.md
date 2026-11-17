---
kind: code
depends_on: []
---

# Proposal: agents-instruction-variables

## Summary

An agent owner writes placeholders such as `{{user.displayName}}` or `{{today}}` in an agent's instructions, and hermiq fills them in at the start of every turn. An owner can also declare a few fields a person fills in before a conversation starts, such as a dropdown for the department or a text box for a case number, and the instructions use those answers as placeholders too.

## Why

Two rows of hermiq's capability matrix, agents area (core), decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:dm-prompt-placeholders` | no | build: core area, a featureRequest demand row and four competitors rate yes |
| `hermiq:dm-agent-form-fields` | no | build: core area, changelog demand and two competitors rate yes |

Demand, quoted from the matrix:

- `dm-prompt-placeholders`: featureRequest https://github.com/nextcloud/assistant/issues/598 ("Feature Request: Support additional placeholder variables in the system prompt").
- `dm-agent-form-fields`: changelog https://github.com/open-webui/open-webui/releases/tag/v0.11.0

Competitor cells rated yes, quoted from the matrix evidence:

- `dm-prompt-placeholders`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/add-agent-child-agent "To reference tools, variables, or add Power Fx formulas in your instructions, enter a slash (/)". Dify 1.17.1: `api/core/workflow/system_variables.py:23-38` "sys.user_id, sys.timestamp, sys.query". n8n 2.40.7: `options.ts:5-18` "the agent's System Message is an expression field", `workflow-data-proxy.ts:1690-1691` "$now and $today". Open WebUI v0.11.4: `backend/open_webui/utils/task.py:36-96` "prompt_template replaces {{CURRENT_DATE}}, {{CURRENT_DATETIME}}, {{USER_NAME}}".
- `dm-agent-form-fields`, Dify 1.17.1: `api/core/app/app_config/easy_ui_based_app/variables/manager.py:116-153` "user_input_form accepts text-input, select, paragraph, number variables". Open WebUI v0.11.4: `backend/open_webui/utils/chat_variables.py:127` "get_chat_variables_schema parses fields from the system prompt", `ModelEditor.svelte:1084` "'Chat Variables'".

## What hermiq already has

- The system prompt is `Agent.prompt`, then the Context preamble, the companion's app context and the retrieved context, with no substitution (`lib/Service/Engine/ResponseGenerationHandler.php:325-362`).
- A session starts from the chat page's "New session" button (`src/views/Chat.vue:39`, `newSession()` at `:1021`, `createSession()` in `src/api/chat.js:118`) straight into the composer.
- Scheduled runs send `Schedule.prompt` as the user message to the same engine (`ScheduleService::runAgentAsOwner()`, `lib/Service/ScheduleService.php:2199`).

## What this change builds

1. A fixed set of placeholders, filled in per turn: the person's display name, user id, language and organisation, today's date, the current time, the agent's name and the app the person is in.
2. `Agent.startFields`: fields a person fills in before the first message, of type short text, long text, choice, number or date, each optional or required.
3. The answers stored on the session and usable as `{{field.<key>}}`.
4. A placeholder picker in the prompt editor, and a preview of the filled-in instructions for the owner.

## Out of scope

- Expressions or formulas in instructions. A placeholder is replaced by a value, nothing is evaluated.
- Fields that change during a conversation. Answers are fixed for the session.
