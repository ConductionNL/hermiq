## ADDED Requirements

### Requirement: An oversight holder downloads a subject's records

The system SHALL offer a subject's advisory Approvals as a CSV download to an administrator or a member of the `ai-oversight` group who may also open the subject, and SHALL refuse anyone else before reading a record.

#### Scenario: an oversight holder downloads
- **GIVEN** Olga is in the ai-oversight group and may open case-1
- **WHEN** she requests the export for dossiq case-1
- **THEN** she SHALL receive a CSV with a header row and one row per record, newest first

#### Scenario: a handler without the role
- **GIVEN** Alice may open case-1 but is not in the ai-oversight group
- **WHEN** she requests the export
- **THEN** the system SHALL answer 403 and read no record

#### Scenario: a formula in a prompt
- **GIVEN** a record whose prompt starts with "="
- **WHEN** it is exported
- **THEN** the cell SHALL start with a quote
