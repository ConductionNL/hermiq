# skills-marketplace (delta)

Adds ADR-023 action-level authorization to the two privileged marketplace mutations
(`approve`, `publish`), closing the gap where any authenticated tenant member could
override a `dangerous` content-scan verdict or publish a skill externally.

## ADDED Requirements

### Requirement: Approving a quarantined skill requires action authorization
The system MUST require the caller to hold the `skill.approve-quarantined` action (via
`ActionAuthService::requireAction()`) before transitioning a `quarantined` skill towards
`active`. A caller without the action MUST receive `403 Forbidden` and the skill MUST
remain unchanged.

#### Scenario: A non-admin tenant member attempts to approve a quarantined skill
- **GIVEN** a `quarantined` `Skill` and a caller whose groups are not mapped to
  `skill.approve-quarantined` in the action matrix
- **WHEN** the caller calls `POST /api/skills/{id}/approve`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** the skill's `state` MUST remain `quarantined`

### Requirement: Overriding a dangerous scan verdict requires a stricter action
The system MUST require the caller to additionally hold the `skill.override-scan-verdict`
action before applying `force=true` to a skill whose content-scan verdict is `dangerous`.
Holding `skill.approve-quarantined` alone MUST NOT be sufficient to override a dangerous
verdict.

#### Scenario: A caller with approve rights but not override rights forces a dangerous skill
- **GIVEN** a `quarantined` `Skill` with a `dangerous` scan verdict
- **AND** a caller granted `skill.approve-quarantined` but not `skill.override-scan-verdict`
- **WHEN** the caller calls `POST /api/skills/{id}/approve` with `force=true`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** the skill's `state` MUST remain `quarantined`

#### Scenario: An admin overrides a dangerous scan verdict
- **GIVEN** a `quarantined` `Skill` with a `dangerous` scan verdict
- **AND** an instance admin caller
- **WHEN** the admin calls `POST /api/skills/{id}/approve` with `force=true`
- **THEN** the system MUST transition the skill's `state` to `active`

### Requirement: Publishing a skill to a hub requires action authorization
The system MUST require the caller to hold the `skill.publish-hub` action before submitting
a skill to an external hub via OpenConnector.

#### Scenario: A non-admin tenant member attempts to publish a skill
- **GIVEN** a caller whose groups are not mapped to `skill.publish-hub`
- **WHEN** the caller calls `POST /api/skills/{id}/publish`
- **THEN** the system MUST respond `403 Forbidden`
- **AND** no outbound OpenConnector call MUST be made
