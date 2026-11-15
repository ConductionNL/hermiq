# skills-marketplace

## ADDED Requirements

### Requirement: The curator SHALL propose merging duplicate skills

The curator background job SHALL find active skills that duplicate each other and propose a
merge for each pair, naming the skill that stays and the one that goes. A merge SHALL wait for
a person's approval. On approval the system SHALL keep the surviving skill active, archive the
duplicate, and move every agent that used the duplicate to the survivor. It SHALL NOT
hard-delete either skill.

Rows: `sk-curator` (hermiq matrix).

#### Scenario: Two duplicate skills get one merge proposal
- **GIVEN** two active skills that do the same thing
- **WHEN** the curator job runs
- **THEN** one merge proposal names the skill that stays and the skill that goes
- **AND** both skills stay active until a person decides

#### Scenario: An approved merge archives the duplicate and moves its agents
- **GIVEN** a merge proposal and an agent that uses the duplicate skill
- **WHEN** a person approves the merge
- **THEN** the duplicate is archived, not deleted
- **AND** the agent now uses the surviving skill

#### Scenario: A rejected merge changes nothing
- **WHEN** a person rejects a merge proposal
- **THEN** both skills stay active and the curator does not propose the same pair again
