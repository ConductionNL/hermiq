# action-authorization Specification

## Purpose

Hermiq gives people different rights at two levels. Governance actions (approving a quarantined template, exporting a compliance pack, attesting a skill's maturity, changing retention) are guarded by an action matrix that maps each action to the groups allowed to run it, as ADR-023 (`openspec/architecture/adr-023-action-authorization.md`) decides. Agents themselves are guarded by ownership: the person who builds an agent changes it, and the people it is shared with use it. Which objects a person may read or write stays OpenRegister's data RBAC; this spec covers only the two layers hermiq adds.

This spec describes what the code does today. Code: `lib/Service/ActionAuthService.php`, `lib/Repair/InitializeActions.php`, `lib/actions.seed.json` (23 actions), the controllers that call `requireAction()` or `can()` (ComplianceController, TenantOpsController, AiFeatureController, AgentTemplateController, AppTemplateOffersController, SkillMarketplaceController, SkillMaturityController, SkillVersionController, AssistantPromptController, ReportSimilarityController), `lib/Controller/TenantControlController.php:173` and `lib/Service/AgentAccessService.php`.

No screen edits the action matrix yet. The seed file's comment points at an admin settings page that does not exist; an administrator changes the matrix in the app config key `hermiq` / `actions`.

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
