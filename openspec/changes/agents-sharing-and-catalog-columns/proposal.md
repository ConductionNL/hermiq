---
kind: code
depends_on: [agents-switch-off-and-stop]
---

# Proposal: agents-sharing-and-catalog-columns

## Summary

An agent owner decides who can see and use an agent: only themselves, named colleagues and groups, or the whole organisation. The choice is on the agent form and it is enforced wherever an agent is listed or run, including for groups, which hermiq stores today but never checks. The agent catalog shows each agent's owner, who can use it and whether it is switched on, and an organisation admin sees every agent in the organisation in that one list.

## Why

Two rows of hermiq's capability matrix, agents area, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:ag-visibility` | no | build: core area (agents), two competitors rate yes |
| `hermiq:ag-list` | partial, built | build: two competitors rate yes for the missing half, owner and status per agent |

Competitor cells rated yes, quoted from the matrix evidence:

- `ag-visibility`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/agents-experience/authoring-share-agent "share an agent with individual users, security groups or the entire organisation; unshared agents are visible only to owner and editors". Open WebUI v0.11.4: `src/lib/components/workspace/common/AccessControl.svelte:19` "principal_type user|group|anyone", `backend/open_webui/routers/models.py:1035` "POST /model/access/update".
- `ag-list`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/admin-agent-inventory "admin agent inventory in Power Platform admin center gives a tenant-wide list of draft and published agents with createdBy, ownerId". Open WebUI v0.11.4: `src/lib/components/workspace/Models.svelte:808` "owner name per row, :899 Enabled/Disabled switch", `backend/open_webui/routers/models.py:162` "admins see all".

## What hermiq already has

- `Agent.isPrivate` (default true), `Agent.invitedUsers` and `Agent.groups` (`lib/Settings/hermiq_register.json:2566,2572,2581`). New agents default to private (`lib/Controller/AgentsController.php:294-303`).
- One read predicate, `AgentAccessService::canUserAccessAgent()` (`lib/Service/AgentAccessService.php:107`): not private, or the owner, or an invited user. It never reads `groups`. Four controllers carry private copies of the same predicate (`AgentsController`, `AgentVersionController.php:267`, `ChatStreamController.php:727`, `ToolOversightController.php:838`).
- No screen sets the three fields: `src/modals/AgentFormModal.vue:13` says they are merged through from the stored object, never edited, and the agent page disables the generic object sidebar.
- The catalog `/agents` (`src/manifest.json`, page `AgentCatalog`) is a `type:index` page over OpenRegister's object API with two columns, name and model. Its `_note` records that schedule and last-run columns were dropped because OpenRegister cannot join Schedule to Agent. `AgentsController::index()` (`lib/Controller/AgentsController.php:155`) applies the visibility rule, but the catalog does not call it.

## What this change builds

1. A "Who can use this agent" field on the agent form with three choices: only me, people and groups I choose, everyone in my organisation.
2. `groups` enforced by the one predicate, and the four private copies moved onto `AgentAccessService`.
3. The catalog reads through the filtered agent list and shows owner, who can use it, and on or off.
4. An organisation admin sees every agent of the organisation in the catalog, with the same columns.

## Out of scope

- Editing rights for people other than the owner. Changing an agent stays owner-only (`agent-object-owner-authorization`, archived).
- Schedule and last-run columns. The reverse join is still not available in OpenRegister; last run stays on the agent page.
- Moving the owner of an agent. Reassignment exists for leavers (archived `agent-lifecycle-governance`).
