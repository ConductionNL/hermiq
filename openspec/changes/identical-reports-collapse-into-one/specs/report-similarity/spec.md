# report-similarity

## ADDED Requirements

### Requirement: hermiq MUST answer which group a report belongs to, and MUST NOT act on the answer

The system MUST answer, for an incoming report and an open window of recent reports,
which existing group it belongs to or that it starts a new one, with a score. The
system MUST NOT create, merge, close or delete any record as a result.

What a group means, and what is created for it, belongs to the app that owns the
reports. That decision is statutory and is not hermiq's.

Candidate C-intake-30 (`intake.tsv:30`), relevance **`must`**. **No driven passer.**
The evidence is jira-service-management's documented alert grouping: configure alert
grouping, group alerts using Rovo, view related alert groups, view alerts by signal or
noise, view similar alerts and past responders. Admitted under decision D21 and
labelled documented here.

The lane's clause: two hundred meldingen about one straat-brede storing. Ledger row
2.24 is one person filing twice, which is a different test and stays with the owning
app.

#### Scenario: Two hundred reports of one power cut answer as one group

- **GIVEN** two hundred reports describing one street-wide outage within the window
- **WHEN** each is evaluated
- **THEN** they MUST answer as one group with a count of two hundred

#### Scenario: hermiq creates nothing

- **WHEN** a group is formed
- **THEN** no case, no merge and no deletion MUST have occurred in any app as a direct
  result

### Requirement: A report MUST fall into one of three bands, not two

The system MUST classify a report against a group in three bands: above an upper
threshold it joins the group, below a lower threshold it stands alone, and between
them it joins the group **flagged as uncertain** and MUST be listed beside the group
rather than counted silently into it.

Both thresholds MUST be administered. The cost of each kind of mistake is the
municipality's to weigh.

A boolean answer forces every borderline report into one of two failures: a genuinely
separate report buried inside a group of two hundred, or two hundred and one separate
items. The middle band is where a human's attention belongs.

#### Scenario: A near-duplicate is visible, not buried

- **GIVEN** a report scoring between the thresholds
- **WHEN** the group is read
- **THEN** the report MUST be attached and flagged uncertain, and MUST appear beside
  the group for a human

#### Scenario: A clearly separate report stands alone

- **GIVEN** a report scoring below the lower threshold
- **WHEN** it is evaluated
- **THEN** it MUST start its own group

#### Scenario: The thresholds are the administrator's

- **WHEN** an administrator reads the grouping settings
- **THEN** both thresholds MUST be shown and MUST be editable

### Requirement: Grouping MUST be additive and reversible without loss

A group MUST be a set of references with a score. Every report in a group MUST remain
a complete record of its own, with its own reporter. Removing a report from a group
MUST leave it standing alone and MUST move the group's count.

Nothing MUST be discarded to form a group, so nothing MUST need recovering to undo
one.

#### Scenario: Pulling one report out costs nothing

- **GIVEN** a group of two hundred
- **WHEN** one report is removed from it
- **THEN** that report MUST stand alone unchanged, and the count MUST read one hundred
  and ninety-nine

#### Scenario: No report is destroyed by grouping

- **WHEN** the reports in a group are read
- **THEN** each MUST carry its own reporter and its own content, unmodified by the
  grouping

### Requirement: Grouping MUST NOT reduce the confirmations of receipt owed

The system MUST NOT reduce, suppress or combine the acknowledgements owed to the
people who filed the reports in a group. A group is a view for the handler.

Awb 4:3a owes every electronic request a confirmation of receipt. Two hundred people
who wrote to the gemeente are owed two hundred confirmations. A handler seeing one
item where two hundred people wrote will find it natural that one confirmation went
out, which is why this is stated in the spec of the feature that would cause it.

#### Scenario: Two hundred reporters, two hundred confirmations

- **GIVEN** two hundred reports collapsed into one group
- **WHEN** acknowledgement is evaluated
- **THEN** two hundred confirmations MUST be owed, one per report

#### Scenario: Grouping carries no acknowledgement effect

- **WHEN** the grouping answer is read
- **THEN** it MUST carry no instruction about acknowledgement, and the owning app's
  duty MUST be unchanged by it

### Requirement: A deterministic key MUST be preferred over a model judgement

Where the owning app supplies a deterministic grouping key, such as the same location
and category within a stated period, the system MUST use it and MUST record that a
deterministic key decided. A model similarity MUST be used only for what the key does
not catch, and the group MUST record which decided each membership.

#### Scenario: The key decides where it can

- **GIVEN** an owning app supplying a deterministic key and two reports matching it
- **WHEN** they are evaluated
- **THEN** they MUST group on the key, and the membership MUST record that

#### Scenario: The model covers what the key misses

- **GIVEN** two reports of one outage filed from different addresses
- **WHEN** they are evaluated
- **THEN** the key MUST NOT match, the model MUST decide, and the membership MUST
  record that the model decided

### Requirement: The comparison window MUST be bounded and administered

The system MUST compare an incoming report only against reports inside an open window
whose length is administered per report type. The window in force MUST be recorded on
the group.

A report about a streetlight in March and one in October are not one event, however
alike the text. An unbounded comparison is both expensive and wrong.

#### Scenario: An old report is out of scope

- **GIVEN** a window of twenty-four hours and a similar report from last month
- **WHEN** a new report is evaluated
- **THEN** the old report MUST NOT be considered

#### Scenario: The window is part of the record

- **WHEN** a group is read
- **THEN** the window in force when it was formed MUST be readable on it

### Requirement: A group MUST carry the reasons its members were grouped

The system MUST record, per group, the terms that matched, the window in force, the
score per member, and whether a deterministic key or the model decided that member.
The reasons MUST be readable by a handler.

A citizen told their melding was folded into an existing one will sometimes disagree,
and the answer cannot be that the model said so. A handler needs enough to defend the
grouping or to undo it, which are the only two useful outcomes of that conversation.

#### Scenario: Why these are one thing is answerable

- **GIVEN** a group of two hundred
- **WHEN** a handler asks why a given report is in it
- **THEN** the terms, the window, the score and the deciding method MUST be shown

#### Scenario: Each judgement is on the audit trail

- **WHEN** a similarity judgement is made
- **THEN** it MUST be recorded as a run on the audit trail, like any other model
  output
