# Design: delivery-public-web-chat

Kind: code. Size L. Row `hermiq:dl-embed-web`.

## Context at development db6b74dc

- `AssistantService::converse(userId, sessionId, message, context)` (`lib/Service/Assistant/AssistantService.php:184`): resolves a session (`:188`), applies the organisation's guardrail input filter (`:192-200`), resolves a per-app agent with the `__none__` tool sentinel (`:357`), and runs a grounded, tool-free turn.
- Public endpoint pattern: `WebhookTriggerController` (`lib/Controller/WebhookTriggerController.php:9-16` docblock on why `#[PublicPage]` is safe only when the method body authenticates; `:117-126` attributes and `AnonRateLimit`).
- AI features: `AiFeature` with risk category and DPO acknowledgement before enabling a high-risk feature (spec `ai-feature-governance`); seeded by `lib/Repair/SeedAiFeatures.php`.
- Guardrails: organisation policy, input and output filters (spec `agent-guardrails`).
- portaliq's expectation: `ConductionNL/portaliq` `openspec/changes/search-assistant-from-public-content/design.md` D1 (in-process call, no identity, question, portal slug, locale, source scope, conversation id) and D2 (public is a declaration: published pages, glossary terms, published publications).

## D1. A channel is a declaration

New schema `PublicChannel`: `name`, `kind` (`website` or `app`), `agentId` ($ref agent), `allowedOrigins` (website only, exact origins), `sourceScope` (list of `{register, schema}` pairs), `disclosureText` (default "You are chatting with an AI assistant. It can make mistakes. Do not share personal details."), `dailyQuestionLimit` (default 500), `enabled` (default false), `channelToken` (random, shown in the embed code). Organisation admins manage channels on a new settings page "Public chat". The chosen agent must have no tool grants; saving a channel on an agent with grants is refused.

## D2. The in-process entry point for sibling apps

`PublicChatEntryPoint::ask(string $channelKey, string $question, string $locale, array $sourceScope, ?string $conversationToken): PublicAnswer` returns `{answer, sources[], conversationToken, disclosure}`. A sibling app checks `class_exists()` and `IAppManager::isEnabledForUser('hermiq')`, as portaliq's design does, and registers its channel by key (`app:portaliq:<portal-slug>`). The `sourceScope` the app passes is intersected with the channel's declared scope, never widened. The call carries no user: hermiq runs it under no session.

## D3. The website endpoint and window

- `POST /apps/hermiq/api/public/{channelToken}/messages` with `#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit(limit: 20, period: 60)]`, plus the channel's daily limit. It answers CORS only for `allowedOrigins`. A request carrying a Nextcloud session is answered as anonymous anyway.
- `GET /apps/hermiq/public/{channelToken}`: a standalone chat page for an iframe, with the disclosure text above the input.
- `/apps/hermiq/js/public-chat.js`: a script that adds a chat button to a website and opens that page in an iframe. The settings page shows both embed snippets.

## D4. What the model may read

Retrieval for a public turn searches only objects OpenRegister serves to an anonymous caller inside the channel's `sourceScope`, through OpenRegister's public object read path, so the anonymous read rule is the ceiling. No files, no memory, no user profile, no tools. The answer lists its sources as links. The turn goes through the organisation's guardrail input and output filters; a blocked question gets "I cannot help with that here." The conversation is kept under a `conversationToken` in a `PublicConversation` object with the retention of the `public-chat` AI feature (default 30 days) from the open `what-the-model-reads-and-what-is-kept`; no IP address is stored.

## D5. Governance

`public-chat` is seeded as an AI feature with risk category `limited`, disabled by default; enabling it records the DPO acknowledgement. Every public turn is a run on the audit trail with channel and token, never an identity. The kill switch and `agents-switch-off-and-stop` apply: the channel answers "This assistant is not available right now." when either stops the agent.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| `PublicChannel`, `PublicConversation`, retention | declarative, schemas and the AI feature | plain data and ADR-031 retention |
| rate limit and origin check | imperative, controller attributes and a CORS guard | request-time checks |
| scoped anonymous retrieval | imperative, `PublicRetrievalService` | narrows OpenRegister's public read by a declaration |

## Seed data

- AI feature `public-chat`, risk `limited`, disabled.
- One disabled example channel "Website of Gemeente Voorbeeld", kind `website`, `allowedOrigins: ["https://www.example.nl"]`, scope the OpenCatalogi publications register when present.

## Risks

- Leaking non-public content. Mitigation: OpenRegister's anonymous read path is the ceiling, the scope narrows it further, and a test reads a non-public object through the channel and expects no source.
- Abuse and cost. Mitigation: per-IP rate limit, a daily limit per channel, the budget gate on the channel's agent, and the kill switch.
- Prompt injection from the public. Mitigation: no tools on the channel's agent, and the guardrail filters on both sides of the model.
