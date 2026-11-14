---
kind: code
depends_on: []
---

# Proposal: delivery-public-web-chat

## Summary

An organisation puts an agent on its public website as a chat window, without visitors signing in. The agent answers only from content the organisation has declared public, uses no tools, says that it is an AI, and is rate limited per website. The same entry point serves sibling apps in-process, so portaliq's "Ask a question" widget can talk to hermiq without carrying anyone's identity.

## Why

One row of hermiq's capability matrix, delivery area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:dl-embed-web` | no | build: three competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/publication-connect-bot-to-web-channels "publish to a custom website with an embed code (iframe) or customise the web chat canvas".
- Dify 1.17.1: `web/public/embed.js` "floating chat bubble script and iframe embed", `web/i18n/en-US/app-overview.json:50-52` "'Choose the way to embed chat app to your website'".
- n8n 2.40.7: `packages/@n8n/nodes-langchain/nodes/trigger/ChatTrigger/ChatTrigger.node.ts:408-452` "'public' chat with an embeddable widget or hosted chat page", `packages/frontend/@n8n/chat/README.md:1-3` "embeddable chat window with CORS allowed origins".

A sibling waits on this: portaliq's open change `search-assistant-from-public-content` (ConductionNL/portaliq development) calls "the entry point hermiq publishes for channel adapters, in-process, when hermiq is installed and the entry point exists; otherwise the feature is off", passing the question, portal slug, locale, a source scope and a conversation id, and never an identity (its design D1).

## What hermiq already has

- A tool-free assistant pipeline for a signed-in user: `AssistantService::converse()` (`lib/Service/Assistant/AssistantService.php:184`) with the guardrail input filter (`:192-200`) and a per-app agent with the `tools: ['__none__']` sentinel (`:357`), spec `case-assistant-surface`.
- Public endpoints that authenticate by their body and are rate limited: `WebhookTriggerController` with `#[PublicPage]` and `#[AnonRateLimit(limit: 30, period: 60)]` (`lib/Controller/WebhookTriggerController.php:117-126`).
- Every chat route today needs a signed-in user (`IntakeController::receive()` refuses without one, `lib/Controller/IntakeController.php:82-84`; the open `a-conversational-intake-that-files-for-the-citizen` keeps that).
- Retention per run and redaction before a model reads, in the open `what-the-model-reads-and-what-is-kept`.

## What this change builds

1. `PublicChannel` objects: a website or an app channel with its agent, allowed origins, a declared source scope, a disclosure text, a daily question quota, on or off.
2. One in-process entry point, `OCA\Hermiq\PublicChat\PublicChatEntryPoint::ask()`, for sibling apps.
3. A public endpoint and an embeddable window for websites: a script tag or an iframe per channel.
4. Answers only from anonymously readable content inside the declared scope, with sources, and no tools.
5. A `public-chat` AI feature, off by default, so an organisation decides to go public and records the DPO acknowledgement.

## Out of scope

- Signed-in visitors. A channel carries no identity; portaliq refuses a portal bearer on its route.
- Filing requests for a visitor. That is the open `a-conversational-intake-that-files-for-the-citizen`.
- Messenger channels such as WhatsApp. hermiq ADR-005 keeps outside chat platforms a non-goal.
