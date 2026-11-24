# action-authorization Specification

## Purpose

Hermiq gives people different rights at two levels. Governance actions (approving a quarantined template, exporting a compliance pack, attesting a skill's maturity, changing retention) are guarded by an action matrix that maps each action to the groups allowed to run it, as ADR-023 (`openspec/architecture/adr-023-action-authorization.md`) decides. Agents themselves are guarded by ownership: the person who builds an agent changes it, and the people it is shared with use it. Which objects a person may read or write stays OpenRegister's data RBAC; this spec covers only the two layers hermiq adds.

This spec describes what the code does today. Code: `lib/Service/ActionAuthService.php`, `lib/Repair/InitializeActions.php`, `lib/actions.seed.json` (23 actions), the controllers that call `requireAction()` or `can()` (ComplianceController, TenantOpsController, AiFeatureController, AgentTemplateController, AppTemplateOffersController, SkillMarketplaceController, SkillMaturityController, SkillVersionController, AssistantPromptController, ReportSimilarityController), `lib/Controller/TenantControlController.php:173` and `lib/Service/AgentAccessService.php`.

An administrator edits the matrix in the section "Rechten per actie" on the tenant management page (`lib/Controller/ActionMatrixController.php`, `GET /api/actions`, `PUT /api/actions/{action}`); it is stored in the app config key `hermiq` / `actions`.

## Requirements

### Requirement: A governance action runs only for an administrator or a group the matrix names

Every controller method that performs a governance action SHALL call `ActionAuthService::requireAction()` with a dot-separated action name (for example `compliance.export-pack`, `tenantops.reassign-agent`, `aifeature.acknowledge`) before it acts. A Nextcloud administrator SHALL always pass. Any other person SHALL pass only when one of their groups is in the matrix entry for that action. An action missing from the matrix SHALL default to `["admin"]`, an entry of `["admin"]` or an empty entry SHALL refuse everyone but administrators, and a refusal SHALL raise `OCSForbiddenException` naming the action. A page that only needs to know whether to show something SHALL use the non-throwing `can()`.

#### Scenario: A DPO group is given one action
- **GIVEN** the matrix maps `aifeature.acknowledge` to `["admin", "dpo"]`
- **AND** a person who is not an administrator and is a member of `dpo`
- **WHEN** they acknowledge an AI feature
- **THEN** the action runs
- **AND** the same person is refused `aifeature.bind`, which still maps to `["admin"]`
- @e2e exclude needs a second Nextcloud user in a configured group; covered by PHPUnit in tests/Unit/Service/ActionAuthServiceTest.php

#### Scenario: An action nobody configured stays with administrators
- **GIVEN** an action name that has no entry in the matrix
- **WHEN** a person who is not an administrator calls it
- **THEN** the call is refused with "Action '<name>' requires admin rights"
- @e2e exclude covered by PHPUnit in tests/Unit/Service/ActionAuthServiceTest.php

### Requirement: The matrix is seeded once and an administrator's changes survive upgrades

The matrix SHALL be stored as JSON in the app config key `actions`. On install and on every upgrade the repair step `InitializeActions` SHALL write `lib/actions.seed.json` into the matrix only when the matrix is empty, and SHALL leave an existing matrix untouched. A missing, unreadable or malformed seed SHALL leave the matrix empty, which means admin-only for every action. Reading the matrix SHALL drop entries that are not a list of group ids, so a broken value fails closed.

#### Scenario: An upgrade keeps the administrator's matrix
- **GIVEN** an administrator broadened `compliance.view-dashboard` to the group `auditors`
- **WHEN** hermiq is upgraded and `InitializeActions` runs
- **THEN** the step reports that the matrix already has entries and preserves it
- **AND** members of `auditors` still open the compliance dashboard
- @e2e exclude a repair step with no page; InitializeActions has no unit test yet either

### Requirement: An organisation's owner may switch its agents off

The kill switch for an organisation's agents SHALL be available to a Nextcloud administrator and to the owner of that OpenRegister organisation, and to no one else. A request without an organisation, or for an organisation that cannot be found, SHALL be refused.

#### Scenario: A plain member cannot pull the kill switch
- **GIVEN** a person who is a member, not the owner, of organisation A
- **WHEN** they try to switch off organisation A's agents
- **THEN** the request is refused and the agents keep running
- @e2e exclude needs an organisation with a non-owner member; covered by PHPUnit in tests/Unit/Controller/TenantControlControllerTest.php

### Requirement: The builder of an agent changes it, the people it is shared with use it

Every signed-in person SHALL be able to build agents of their own. Only an agent's owner SHALL be able to change it (`AgentAccessService::canUserModifyAgent()`). An agent that is not private SHALL be readable by everyone in the organisation; a private agent SHALL be readable only by its owner, the users it invites and the members of its groups (`canUserAccessAgent()`).

#### Scenario: A group member uses a shared agent but cannot edit it
- **GIVEN** a private agent owned by Anna and shared with the group `team-vergunningen`
- **AND** Bas, a member of that group
- **WHEN** Bas opens the agent
- **THEN** he can read and run it
- **AND** saving a change to it is refused
- @e2e exclude needs two users; covered by PHPUnit in tests/Unit/Service/AgentAccessServiceTest.php and by the Playwright file of agents-sharing-and-catalog-columns

### Requirement: An administrator edits the action matrix on the tenant management page (REQ-ACTMX-001)

The tenant management page SHALL show a Nextcloud administrator a section "Rechten per actie" on
`hermiq/HmTenantOps` that lists every governance action in the matrix with a plain-language label
and description and the groups allowed to run it, and SHALL let the administrator add and remove
groups per action and reset an action to its seeded value. A saved change SHALL take effect on the
next call of that action. The section SHALL state that the matrix applies to every organisation on
the instance. A person who is not a Nextcloud administrator SHALL NOT see the section.

Rows: `op-rbac`. Board: `hermiq/HmTenantOps`.

#### Scenario: An administrator gives the DPO group one action
- **GIVEN** an administrator on the tenant management page and a group `dpo`
- **WHEN** they add `dpo` to the action labelled for acknowledging an AI feature and save
- **THEN** the stored matrix maps `aifeature.acknowledge` to `["admin", "dpo"]`
- **AND** a member of `dpo` can acknowledge an AI feature from then on

#### Scenario: Reset to the seeded value
- **GIVEN** `compliance.view-dashboard` maps to `["admin", "auditors"]` and the seed says `["admin"]`
- **WHEN** the administrator resets that action
- **THEN** the stored matrix maps it to `["admin"]` and the section no longer marks it as changed

#### Scenario: A member does not see the section
- **GIVEN** a person who is not a Nextcloud administrator
- **WHEN** they open the tenant management page
- **THEN** the section "Rechten per actie" is not shown

@e2e exclude needs a second, non-admin Nextcloud user; covered by a Vitest test of the page and the controller test of REQ-ACTMX-002.

### Requirement: The matrix endpoints are administrator-only and validate what they store (REQ-ACTMX-002)

The endpoints that read and change the action matrix SHALL be reachable only by a Nextcloud
administrator, refused by the framework before the method runs for anyone else. A change SHALL be
refused when it names an action that is neither seeded nor stored (404) or a group that does not
exist (400, naming the group). `admin` SHALL stay in every entry, so no change can lock
administrators out of an action.

Rows: `op-rbac`. Board: `hermiq/HmTenantOps`.

#### Scenario: A member cannot change the matrix through the API
- **GIVEN** a person who is not a Nextcloud administrator, and is in every group the matrix names
- **WHEN** they send a change for `compliance.export-pack`
- **THEN** the request is refused with 403 and the stored matrix is unchanged

@e2e exclude needs a second, non-admin Nextcloud user; covered by ActionMatrixControllerTest.

#### Scenario: An unknown group is refused
- **WHEN** an administrator saves `skill.publish-hub` with the group `bestaat-niet`
- **THEN** the request is refused with 400 naming `bestaat-niet` and the stored matrix is unchanged

@e2e exclude covered by ActionMatrixControllerTest; the page offers only existing groups.

#### Scenario: Removing admin keeps admin
- **WHEN** an administrator saves `tenantops.update-retention` with only the group `beheer`
- **THEN** the stored entry is `["admin", "beheer"]`

@e2e exclude covered by ActionMatrixControllerTest.
