# action-authorization

## ADDED Requirements

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
