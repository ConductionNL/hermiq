# skills-catalog

## ADDED Requirements

### Requirement: A person can export a skill to a file (REQ-SPCOV-010)

Each skill row in the catalogue SHALL offer an Export action. Export SHALL download the skill
as an agentskills.io package file that can be imported again without loss.

Rows: `sk-export`. Board: `hermiq/HmSkills`.

#### Scenario: A skill is downloaded
- **GIVEN** the skill "meeting-notes-cleanup" in the catalogue
- **WHEN** a person chooses "Export" on its row
- **THEN** a file with the skill's frontmatter and body downloads
- **AND** importing that file creates a skill with the same frontmatter and body
