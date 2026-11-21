# Design: agents-sharing-and-catalog-columns

Kind: code. Size M. Rows `hermiq:ag-visibility`, `hermiq:ag-list`.

## Context at development db6b74dc

- Fields: `Agent.isPrivate`, `invitedUsers`, `groups` at `lib/Settings/hermiq_register.json:2566-2590`. The `Agent` schema's `authorization` block is `{"read": ["authenticated"]}`.
- Predicate: `AgentAccessService::canUserAccessAgent()` (`lib/Service/AgentAccessService.php:107-133`) and `canUserModifyAgent()` (`:151`). Its class docblock says it is the only layer, because OpenRegister's register RBAC is open for a schema without a rule, and that the four private copies were left in place on purpose as follow-up work.
- Private copies: `AgentsController::canUserAccessAgent()` (used at `lib/Controller/AgentsController.php:191,247`), `AgentVersionController.php:267-280`, `ChatStreamController.php:727-745`, `ToolOversightController.php:838-852`.
- List: `AgentsController::index()` (`:155-200`) pages with `findAll(limit, offset)` and filters afterwards, so a page can come back short and the total is not the visible total.
- Catalog: `src/manifest.json` page `AgentCatalog`, `type: index`, `register: hermiq`, `schema: agent`, columns name and model, `rowRoute: AgentDetail`.
- Form: `src/modals/AgentFormModal.vue`, docblock line 13 on the fields that are merged through.
- Organisation admin: `TenantControlController::mayAdminister()` (`lib/Controller/TenantControlController.php:173`), instance admin or organisation owner.

## D1. Three choices, stored in the fields that exist

| choice on the form | isPrivate | invitedUsers | groups |
|---|---|---|---|
| Only me | true | [] | [] |
| People and groups I choose | true | chosen user ids | chosen group ids |
| Everyone in my organisation | false | kept | kept |

The form shows a radio group labelled "Who can use this agent" and, for the second choice, an `NcSelect` for people (`inputLabel` "People") and one for groups (`inputLabel` "Groups"), fed by Nextcloud's user and group search. No new schema property is needed.

## D2. One predicate that reads groups

`canUserAccessAgent()` adds: the user is a member of any id in `groups` (`IGroupManager::isInGroup()`). The four private copies are replaced by calls to `AgentAccessService`, which the service's own docblock names as the follow-up. A refusal stays a 404 on routes that take an agent id, so a colleague cannot confirm that a private agent exists.

## D3. The catalog reads the filtered list

The catalog's index page reads `GET /api/agents` instead of OpenRegister's object API for the `agent` schema. `AgentsController::index()` filters inside the query: `findAll` gets an `_or` filter of `isPrivate: false`, `owner: <uid>`, `invitedUsers` contains uid, `groups` overlaps the user's groups, so paging and totals are right. Each row gains `owner` (user id and display name), `sharing` (`only me`, `people and groups`, `organisation`) and `active` (from `agents-switch-off-and-stop`).

Columns: Name, Owner, Who can use it, Status ("On" or "Switched off"), Model.

Rejected: a calculated `visibleTo` field for OpenRegister to filter on. Group membership changes outside the object, so a stored value goes stale.

## D4. An organisation admin sees every agent

When the caller passes `mayAdminister()` for their active organisation, `index()` skips the visibility filter and marks each row the caller could not otherwise use with `visibleBecause: "organisation admin"`. Opening such an agent's page shows the name, owner, status and sharing, and the run history, which the archived `agent-lifecycle-governance` access review already exposes to admins. The prompt and tools of a private agent stay hidden from the admin unless they also pass the normal predicate.

## D5. The object API stays open, and this change says so

OpenRegister's object API still answers reads of `agent` objects for any authenticated member of the organisation, because the schema's rule is `read: authenticated`. That is how the catalog saw private agents until now. This change moves hermiq's own screens off that path. A task tests whether an object-level `authorization` written on save can close the API as well; if OpenRegister supports it, the task writes it, and if not, the finding is filed on OpenRegister and named in the PR.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| the three sharing fields | declarative, existing schema properties | plain data |
| who may read an agent | imperative, `AgentAccessService` | group membership is outside the object; ADR-005 per-object check |
| catalog columns | declarative, manifest columns over the filtered list | a page definition |

## Seed data

The seeded starter agents stay organisation-wide (`isPrivate: false`). The seeded "Hydra Triage" agent keeps `isPrivate: false` (`lib/Repair/SeedHydraTriageAgent.php:306`). One example agent, "Planning desk helper", is seeded as shared with the group `planning-desk`, to show the second choice.

## Risks

- A group that grants more than intended, such as `everyone`. Mitigation: the group picker shows member counts, and the audit trail records every change of the three fields.
- Moving four controllers onto the service changes working code. Mitigation: each controller keeps its existing PHPUnit tests, run before and after, with a new group case added.

## As built (2026-09-30, first PR)

- **D2.** Already true at HEAD before this change: `AgentAccessService::canUserAccessAgent()` reads `groups`, the four private copies call it, and the Agent schema's read rule (hermiq#976) admits only an open agent, an invited user or a member of one of its groups (plus OpenRegister's owner and instance-admin bypasses). `AgentsControllerTest::testIndexAndShowHonourTheAgentsGroups` and `AgentAccessServiceTest::testGroupMemberMayReadButNotModify` prove it; `AgentReadRuleTest` pins the rule. What changed here: `GET /api/agents/{id}` answers 404, not 403, to someone who may not see the agent.
- **D3.** `AgentsController::index()` reads through `AgentCatalog::page()`, a paginated search through the read rule, so OpenRegister filters inside the query and paging and `total` count only usable agents; the predicate is applied again per row. Rows gain `ownerDisplayName`, `sharing` (`only-me`, `people-and-groups`, `organisation`) and `active`.
- **D3, the page.** The AgentCatalog page cannot read `/api/agents`: a manifest index page reads OpenRegister's object API or a named source, and nextcloud-vue does not let an app register a source (`indexSources` is not exported). The page stays on the object API, which since hermiq#976 applies the same read rule inside the query, so a user's catalog is right. The five columns are there: `owner`, `isPrivate` with the `agentSharing` formatter, `active` with the `agentStatus` formatter (both registered on CnAppRoot from `src/utils/agentSharing.js`).
- **D4.** `AgentCatalog` gives an organisation admin (instance admin, or owner of the active organisation) every agent of the organisation on `/api/agents`, with `visibleBecause: "organisation admin"` and no prompt or tools on a row they could not otherwise use; `GET /api/agents/{id}` gives them the same reduced row. **Not yet on the pages:** the catalog and the agent page read OpenRegister's object API, where an instance admin already sees every agent in full (OpenRegister's admin bypass, prompt included) and an organisation owner who is not an instance admin sees only what the rule lets them. Closing that needs either a nextcloud-vue source registry (drafted for Ruben) or custom pages; it is a decision for Ruben, so REQ-AGSHARE-004's page half stays open and the change is not archived.
- **D1.** The form's three choices are written through `sharingFields()` and read through `sharingOf()` (`src/utils/agentSharing.js`, proven by `tests/agent-sharing.spec.js`); people and groups come from Nextcloud's autocomplete.
- **Seed.** "Planning desk helper", shared with the group `planning-desk`, is in the demo register next to "Weekly supplier digest".
