## ADDED Requirements

### Requirement: Installing a skill that is already present updates it

A bundle install SHALL match each incoming skill against the skills already on the
instance and update the match in place. It SHALL NOT create a second object for a
skill it already has.

Matching resolves in order: an exact `sourceUrl` match; failing that, a ONE-TIME
fallback to the normalised skill name among skills that carry no `sourceUrl`. On any
match the resolved `sourceUrl` SHALL be stamped, so every later install matches
exactly and the fallback is never needed twice for the same skill.

#### Scenario: Re-installing a bundle does not duplicate its skills
- **WHEN** a bundle of 94 skills is installed onto an instance that already holds
  those 94 skills
- **THEN** no new skill objects are created
- **AND** the total skill count is unchanged

#### Scenario: A skill installed before identity existed is matched once by name
- **WHEN** a skill exists with no `sourceUrl` and the same normalised name as an
  incoming skill
- **THEN** the existing skill is updated rather than duplicated
- **AND** its `sourceUrl` is stamped with the incoming canonical URL

#### Scenario: A name collision against an already-identified skill does not match
- **WHEN** an incoming skill shares a normalised name with an existing skill that
  ALREADY carries a DIFFERENT `sourceUrl`
- **THEN** the name fallback does not match it
- **AND** the incoming skill is installed as a distinct skill

### Requirement: The same skill from a mirror is the same skill

Repositories are mirrored across hosts. Before `sourceUrl` is compared or stored,
known mirror hosts SHALL be normalised to a single canonical host, so that a skill
installed from a mirror and from the origin resolves to one object.

#### Scenario: A mirrored origin resolves to the canonical one
- **WHEN** a skill previously installed from `https://github.com/OWNER/REPO/skills/example`
  is installed again from the equivalent mirrored host
- **THEN** it matches the existing skill and updates it
- **AND** no second object is created

### Requirement: Curated state survives an update

Updating a skill SHALL replace only content carried by the bundle — `body`,
`frontmatter`, `files`, `description`. It SHALL NOT modify `maturityLevel`,
`targetLevel`, `levelEvidence`, `installedOn`, `createdBy`, `publishedAt`,
`archivedAt` or `lastAcceptedVersionAt`.

Those record human decisions and earned history. An app shipping new content has no
standing to reset them.

#### Scenario: An update leaves curation untouched
- **WHEN** a skill with a maturity level, evidence and two agent installations is
  updated from its bundle
- **THEN** its body and files reflect the bundle
- **AND** its maturity, evidence and agent installations are unchanged

### Requirement: A content change returns a skill to quarantine

When the incoming content differs from what is stored, the updated skill SHALL be set
to `quarantined` with a reason naming the change, regardless of its previous state.

Preserving an approval across a content change would allow unreviewed content to run
under a decision made about different content.

#### Scenario: An approved skill whose content changed is re-quarantined
- **WHEN** an `active` skill is updated and the incoming body differs
- **THEN** its state becomes `quarantined`
- **AND** the quarantine reason identifies that the content changed on update

#### Scenario: An unchanged skill keeps its state
- **WHEN** a skill is re-installed and the incoming content is identical
- **THEN** its state is unchanged
- **AND** it is reported as unchanged rather than updated

### Requirement: Local learnings are never overwritten by an update

A skill accumulates local learnings in `learnings.md` (ADR-068 §3). When learnings
have been accepted locally since the last sync from source, an incoming
`learnings.md` SHALL NOT replace the local one. Every other part of the update
SHALL still apply, and the outcome SHALL report that the local learnings were kept.

The condition SHALL require BOTH that `lastAcceptedVersionAt` postdates
`sourceUpdatedAt` AND that the incoming `learnings.md` actually differs, so that it
cannot fire for a skill nobody has taught anything.

This SHALL NOT be derived from `publishedAt`, which records publication TO a remote
and is empty on an instance that only installs — a check against it would silently
never fire.

#### Scenario: Local learnings survive an update that would replace them
- **WHEN** a skill whose `lastAcceptedVersionAt` postdates its `sourceUpdatedAt` is
  updated from a bundle carrying a DIFFERENT `learnings.md`
- **THEN** the stored `learnings.md` is unchanged
- **AND** the body and other files are updated
- **AND** the outcome reports the local learnings were kept

#### Scenario: A skill with no local learnings takes the incoming ones
- **WHEN** a skill whose `lastAcceptedVersionAt` does NOT postdate `sourceUpdatedAt`
  is updated from a bundle carrying a different `learnings.md`
- **THEN** the incoming `learnings.md` is stored
- **AND** nothing is reported as kept

### Requirement: Every installed skill records when it was refreshed

On every successful install or update from a bundle, `sourceUpdatedAt` SHALL be
stamped. Without it the learnings comparison has no clock and would never fire twice.

#### Scenario: The refresh clock advances on update
- **WHEN** a skill is updated from its bundle
- **THEN** its `sourceUpdatedAt` reflects that update
- **AND** its `lastAcceptedVersionAt` is unchanged

### Requirement: A person can see why a skill needs review and where it came from

Everything this change decides — that a skill was re-quarantined, why, where it was
installed from, when it was last refreshed, and whether local learnings are ahead of
the source — SHALL be visible on the skill's detail page.

Before this, all of it was reported ONLY in the install API response, which is
nowhere a person looks. A warning that never reaches a person is not a warning.

The learnings notice SHALL render only when `lastAcceptedVersionAt` postdates
`sourceUpdatedAt`, the same condition under which an update preserves the local
file, so a skill nobody has taught anything never shows it.

The surface SHALL be read-only: it reports state and never changes it. Approval
stays with the existing quarantine review gate.

#### Scenario: A quarantined skill shows its state and the reason
- **WHEN** a skill that was re-quarantined by a content change is opened
- **THEN** its detail page shows that it is awaiting review
- **AND** shows the recorded quarantine reason

#### Scenario: An installed skill shows where it came from and when it was refreshed
- **WHEN** a skill installed from a bundle is opened
- **THEN** its detail page shows the canonical source URL it was installed from
- **AND** shows when it was last updated from that source

#### Scenario: The learnings notice appears only when learnings are ahead of the source
- **WHEN** a skill whose `lastAcceptedVersionAt` postdates its `sourceUpdatedAt` is opened
- **THEN** its detail page warns that local learnings are ahead of the source
- **AND** a skill whose learnings are NOT ahead shows no such warning
