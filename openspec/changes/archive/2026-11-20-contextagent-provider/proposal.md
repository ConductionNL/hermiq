---
kind: code
depends_on: []
---

# Proposal: contextagent-provider

## Why

SPECTR-NEXTCLOUD-PLAN.md §8 move 3 (the flagship): Nextcloud's
`core:contextagent:interaction` task type is a confirmation-gated agent-chat loop
(`input` + `confirmation` 0/1 + `conversation_token` → `output` + new token +
`actions` JSON) that matches Hermiq's approval gate 1:1. Registering Hermiq as a
provider for it puts Hermiq's governed agents behind Nextcloud Assistant's agent
chat — WITH governance (approval gate, kill-switch, per-agent capability profile,
redacted audit).

NC already ships a stock provider for this task type — the `context_agent` ExApp
(LangChain/LangGraph, ~20 NC tools). Hermiq registers as the **alternative** provider
(an admin picks the preferred provider per task type); the differentiator is
governance, which the stock provider's simple confirmation flow does not have.

## What Changes

- **`lib/TaskProcessing/ContextAgentProvider.php`** (new) — a thin
  `ISynchronousProvider` for `core:contextagent:interaction` that maps the NC input
  onto a governed Hermiq turn and returns the ContextAgent output shape.
- **`lib/Service/ContextAgentInteractionService.php`** (new) — the governed turn:
  resolves the serving agent (configured `contextagent_agent`, else first active),
  applies the org **kill-switch** gate, binds `conversation_token` ↔ a Hermiq
  `Conversation`, maps `confirmation` (0/1) ↔ an **approval-gate decision** on the
  user's pending Approval for the agent, runs one turn through Hermiq's `Engine`,
  surfaces the per-agent **tool allowlist** in `actions`, and writes a redacted
  `contextagent-interaction` audit entry.
- **`lib/TaskProcessing/EmptyOptionalShapesTrait.php`** (new) — the eight empty
  optional-shape/enum/default `IProvider` accessors, shared with the text2text
  providers.
- **`lib/AppInfo/Application.php`** — registers the provider via
  `registerTaskProcessingProvider(...)`.

## Deferred (single-turn scope)

The stateful multi-turn "propose actions → pause → confirm → resume the exact tool
execution" loop is **deferred**; see `design.md`. This pass ships the provider
registration, the full interaction shape, a governed single-turn path, the
kill-switch gate, the conversation binding, the actions disclosure, and the
confirmation→approval mapping against pre-existing pending approvals — it does NOT
pause a turn awaiting confirmation.

## Non-Goals

- Superseding the stock `context_agent` ExApp — Hermiq coexists as an alternative.
- `core:contextagent:audio:interaction` (audio variant) — text interaction only.
- The MCP-into-stock-Assistant quick win (plan §8 move 4) and hermiq-exec custom task
  types (move 5) — separate future changes.
