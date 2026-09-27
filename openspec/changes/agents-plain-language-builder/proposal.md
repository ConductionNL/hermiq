---
kind: code
depends_on: []
---

# Proposal: agents-plain-language-builder

## Summary

A person describes in chat the agent they want, for example "an agent that summarises new objections every Monday and posts them in the legal team's room", and the builder agent proposes a complete draft: name, instructions, model, tools, sharing and a schedule. The person opens the draft in the full agent form, changes what they like, and saves it themselves. Nothing is created until they save, and the builder cannot grant itself or anyone a tool.

## Why

Two rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:ag-nl-builder` | no | build: core area (agents), two competitors rate yes |
| `hermiq:dm-builder-upgrade` | no | build: changelog demand and two competitors rate yes |

Demand, quoted from the matrix:

- `dm-builder-upgrade`: changelog https://learn.microsoft.com/en-us/microsoft-copilot-studio/whats-new

Competitor cells rated yes, quoted from the matrix evidence:

- `ag-nl-builder`, Dify 1.17.1: `api/controllers/console/agent/roster.py:846` "build-draft/checkout and :941 build-draft/apply"; `web/i18n/en-US/agent-v-2.json` "Build your agent by chatting ... it fills in the form on the left". n8n 2.40.7: Instance AI "builds workflows from chat (packages/@n8n/instance-ai/src/workflow-builder/)", a default module.
- `dm-builder-upgrade`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/microsoft-365-copilot-extend-with-agents "copy agents created in Microsoft 365 Copilot (Agent Builder) into Copilot Studio to add workflows and integrations". n8n: `packages/cli/src/modules/agents/instance-ai-builder-delegate.adapter.ts:143-150` "the in-chat n8n Assistant's build-agent tool ... creates a real agent", bound as the builder target.

## What hermiq already has

- The pattern for authoring by chat: the seeded `skill-creator` skill (`lib/Repair/SeedSkillCreator.php`) teaches an agent to write a SKILL.md, and "Save as skill" on an assistant message (`src/views/Chat.vue:329`, `:474-479`) opens `SkillFormModal` pre-filled for review (spec `skills-catalog`, "A chat assistant message can be saved as a reviewable skill").
- One agent form: `src/modals/AgentFormModal.vue`, used for create and edit.
- The open change `hermiq-mcp-adoption` forbids any write verb on hermiq's own schemas on the agent tool surface ("No write verb on any Hermiq schema"), because `Agent.tools` and `Agent.prompt` are privileges.

## What this change builds

1. A seeded `agent-builder` skill and a seeded "Agent builder" agent that turn a description into an agent draft in a fenced `hermiq-agent-draft` block.
2. "Open as agent" on an assistant message carrying a draft: the full agent form opens pre-filled, with the proposed schedule and sharing.
3. The draft is validated before the form opens: unknown tools, models outside the organisation's model policy and unknown groups are flagged in the form, not silently dropped.
4. Saving is the person's own save through the normal create path, so tool grants follow the existing default-deny and approval rules.

This is also the upgrade path the second row asks for: the in-chat draft becomes a full agent in the full form without starting over, and any agent made this way stays editable there.

## Out of scope

- A builder that writes the agent itself. The open `hermiq-mcp-adoption` forbids write tools on hermiq's schemas, and this change keeps it that way.
- Building flows from a description (`integriq:auto-ai-builder`, deferred in this pass).
