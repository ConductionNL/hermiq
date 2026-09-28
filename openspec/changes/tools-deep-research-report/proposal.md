---
kind: code
depends_on: [flows-hand-off-and-wait, models-several-models-per-turn]
---

# Proposal: tools-deep-research-report

## Summary

A user can ask an agent for deep research instead of a quick answer. The agent writes a short plan, searches and reads many sources on its own, and writes a report in which every claim that rests on a source carries a numbered citation with the passage it came from. The report is saved in the user's Files, marked as written by an agent, and a summary with a link arrives in the conversation. The research stops at a step cap, a source cap, a time cap and the organisation's budget, and the user can stop it at any moment.

## Why

One row, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-deep-research` | partial, built | build: the featureRequest demand row asks for the missing half, a research mode that writes a cited report |

Demand and competitor cells rated yes, quoted from the pack:

- featureRequest https://github.com/open-webui/open-webui/discussions/9321 (Deep Research).
- hermes-agent v2026.9.24: "skills/research/grounded-citations/SKILL.md:1-25 bundled skill: numbered citations from a retrieval ledger, verbatim quote checks, `verify --evidence` fails uncited claims, for reports; tools/web_tools.py:519,525 web_search and web_extract; tools/delegate_tool.py:738 delegate_task parallel sub-agents". The pack notes there is no research button there; the agent does it with the skill and tools.

## What hermiq already has

- `hermiq.webSearch` and `hermiq.webFetch` (`lib/Mcp/HermiqToolProvider.php:465-512`, handlers `:1185-1210`), with an admin-configured search backend (`src/modals/WebResearchSettingsModal.vue`), the egress guard, a size cap on fetched text (`lib/Service/WebResearch/WebFetchService.php:164`, `:207-214`) and the untrusted delimiter (`:72`). Spec `openspec/specs/web-research-tool/spec.md`.
- `hermiq.delegateAgent` for bounded sequential sub-tasks (`lib/Mcp/HermiqToolProvider.php:513-553`, `lib/Service/DelegationService.php:203`).
- The system prompt asks the model to cite its sources (`lib/Service/Engine/ResponseGenerationHandler.php:358`), and one turn allows at most ten tool rounds on the direct-HTTP path (`lib/Service/Llm/ProviderFactory.php:219`).
- Agent-authored marking of files in Files, in the same operation as the write (`lib/Service/NcNative/AgentArtefactMarker.php`, `markFile()`), used today for notes.
- No research mode, no report file, and no check that a citation points at something that was read.

## What this change builds

1. A research run: started from the chat with "Deep research", or by an agent granted `hermiq.startResearch`, running in the background as a handoff of kind `research` from `flows-hand-off-and-wait`.
2. A visible plan: three to eight sub-questions shown in the conversation before the searching starts.
3. A source ledger: every page fetched gets a number, its URL, title and fetch time, and the passages the model took from it.
4. A cited report: every citation names a ledger number and quotes its passage; hermiq checks that each quote occurs in the fetched text and marks a sentence whose check fails "(unverified)".
5. The report in the user's Files as Markdown, marked agent-authored in the same operation, with a summary and a link in the conversation.
6. Caps and control: steps, sources, time and tokens, the budget hard cap before every model call, the kill switch before every step, and a "Stop research" button.

## Out of scope

- A vendor deep-research service. Research runs on the organisation's own model and search backend.
- Research over internal documents through vectors. That is `vector-rag`; a research run uses whatever read tools the agent is granted.
- Parallel sub-agents. Delegation is sequential by design (archived `sub-agent-delegation`).
