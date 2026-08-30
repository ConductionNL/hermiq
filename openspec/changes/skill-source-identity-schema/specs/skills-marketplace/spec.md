## ADDED Requirements

### Requirement: An installed skill records its canonical origin

A skill installed from a published bundle SHALL record the canonical location it came
from in `sourceUrl`, in the form `https://<host>/<owner>/<repo>/skills/<bundleName>`.

The recorded URL SHALL NOT include a git ref. A branch is not identity, and pinning
one would make the same skill on two branches two different skills.

#### Scenario: The schema accepts a canonical source URL
- **WHEN** a skill object is written with `sourceUrl` set to
  `https://github.com/ConductionNL/buildiq-hydra/skills/example-skill`
- **THEN** the value is stored and returned unchanged

#### Scenario: A skill without an origin is still valid
- **WHEN** a skill object is written with no `sourceUrl` at all
- **THEN** the write succeeds
- **AND** every one of the 101 pre-existing skills remains valid

### Requirement: The refresh clock is separate from the publish clock

A skill SHALL record when it was last updated FROM its source in `sourceUpdatedAt`,
distinct from the existing `publishedAt`.

`publishedAt` records publication TO a remote and is empty on an instance that only
installs. Answering "has anything changed since we last synced?" against it would
therefore always answer no, so the install direction SHALL have its own field.

#### Scenario: The two clocks are independent
- **WHEN** a skill has `sourceUpdatedAt` set and `publishedAt` empty
- **THEN** both values are stored as given, and neither is derived from the other
