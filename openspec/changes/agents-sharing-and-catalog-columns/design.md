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
