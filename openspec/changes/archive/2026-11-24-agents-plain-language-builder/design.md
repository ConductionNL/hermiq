# Design: agents-plain-language-builder

Kind: code. Size M. Rows `hermiq:ag-nl-builder`, `hermiq:dm-builder-upgrade`.

## Context at development db6b74dc

- `lib/Repair/SeedSkillCreator.php` seeds one `skill-creator` skill idempotently by name. `src/views/Chat.vue:329` renders "Save as skill" on each assistant message; `:474-479` mounts `SkillFormModal` with `initialBody` from the message. The spec `openspec/specs/skills-catalog/spec.md` requires the output to be produced by the existing engine and to land on the review path.
- `src/modals/AgentFormModal.vue` creates and edits agents; its docblock (line 13) lists the fields it merges through without editing (views, groups, invitedUsers, quotas).
- Agent creation: `POST /api/agents` (`appinfo/routes.php:506`, `AgentsController::create()`), new agents private by default (`lib/Controller/AgentsController.php:294-303`).
- Schedules: `src/modals/ScheduleFormModal.vue` with kinds once, interval and cron.
- Tool catalogue for a picker: `GET /api/agents/tools` (`appinfo/routes.php:504`).
- Model policy: `ProviderFactory` refuses a provider or model outside the organisation's allowlist (`lib/Service/Llm/ProviderFactory.php:686`); `src/api/modelPolicy.js` reads the policy.
- The open `hermiq-mcp-adoption` requires no `create`, `update` or `delete` verb on any hermiq schema's tool surface.

## D1. A draft is text in a fenced block, not a write

The `agent-builder` skill instructs the model to answer with prose plus one fenced block:

```
~~~hermiq-agent-draft
{"name": "...", "description": "...", "prompt": "...", "provider": "...", "model": "...",
 "tools": ["hermiq.listFiles"], "sharing": {"mode": "groups", "groups": ["legal"]},
 "schedule": {"kind": "cron", "cronExpr": "0 8 * * 1", "prompt": "..."},
 "startFields": [] }
~~~
```

`Chat.vue` detects the block on an assistant message and shows "Open as agent" next to "Save as skill". No tool writes anything, so the rule of `hermiq-mcp-adoption` holds.

Rejected: a `hermiq.createAgent` tool. It is exactly the write verb `hermiq-mcp-adoption` refuses, and it would let one prompt injection mint an agent with any tool list.

## D2. Validate, then open the full form

"Open as agent" posts the draft to `POST /api/agents/draft-check`, which returns the draft with findings: tools not in the catalogue, a model the organisation's policy forbids (with the nearest allowed model suggested), groups the user cannot see, an invalid cron expression. `AgentFormModal.vue` opens with the draft pre-filled and the findings shown beside their fields. `startFields` is prefilled when the change `agents-instruction-variables` has landed, else ignored.

Saving creates the agent through `POST /api/agents` as the person. The proposed schedule opens next in `ScheduleFormModal.vue` pre-filled, for the person to save or skip. Tool grants are whatever the person keeps; write and destructive tools stay default-denied and approval-gated as the archived `agent-capability-reach` requires.

## D3. The builder agent

A repair step seeds the `agent-builder` skill and an organisation-wide "Agent builder" agent with that skill installed and no tool grants. It needs no tools: `hermiq.searchTools` only searches the tools an agent already holds (`lib/Service/Engine/FacadeToolInvoker.php:180`, grant-filtered), so the builder names the tools it thinks the agent needs and the draft check resolves those names against the catalogue of `GET /api/agents/tools`, closest match first. When the open `tool-discovery-and-access-requests` lands, its discovery of tools not held is the better source. Model limits come from the draft check too, not from the prompt. Admins can switch it off like any agent (`agents-switch-off-and-stop`).

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| the builder agent and skill | declarative seed data | plain objects |
| draft validation | imperative, `AgentDraftService` | reads policy, catalogue and group membership |
| the form prefill | frontend only | no new write path |

## Seed data

- Skill `agent-builder`: frontmatter `name: agent-builder`, `description: Turn a plain description of an agent into a draft the person reviews`, body with the draft format of D1, three worked examples (a weekly objections digest for a legal team, a permit intake helper, a meeting minutes drafter) and the rule "never claim the agent exists; the person saves it".
- Agent "Agent builder": `isPrivate: false`, `skillInstalls: [agent-builder]`, `maxToolCalls: 5` when `agents-switch-off-and-stop` has landed.

## Risks

- A draft that asks for powerful tools. Mitigation: the person grants tools in the form, write tools stay default-denied and approval-gated.
- A model the organisation does not allow. Mitigation: the draft check flags it before the form opens, and `ProviderFactory` refuses it at run time anyway.
- A prompt injection inside the description. Mitigation: the draft is only text; saving is a human act in a form that shows every field.
