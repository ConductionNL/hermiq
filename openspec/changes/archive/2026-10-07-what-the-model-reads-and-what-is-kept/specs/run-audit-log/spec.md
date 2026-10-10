# run-audit-log

## ADDED Requirements

### Requirement: Every AI run MUST carry the retention that applied when it was written

The system MUST resolve a retention period for every AI run from an instance default
an administrator sets, with an optional per-feature override. The resolved period MUST
be written onto the run entry, not referenced, so changing the default later MUST NOT
shorten or extend what an existing run was promised.

The instance MUST have a default. A retention nobody set is a retention of forever.

Candidate C-access-and-privacy-53 (`access-and-privacy.tsv:56`), relevance **`must`**,
driven passer openproject: `AI::TextTransformRun` with status, input and events, and
`ai_text_transform_run_retention_seconds` enforced by
`app/workers/ai/text_transform_runs/cleanup_job.rb`.

#### Scenario: A run knows its own expiry

- **GIVEN** an instance default of ninety days
- **WHEN** a run is recorded
- **THEN** its entry MUST carry ninety days as its retention

#### Scenario: Changing the default does not move an old promise

- **GIVEN** runs recorded under a ninety-day default
- **WHEN** the default is changed to thirty days
- **THEN** those runs MUST still carry ninety days, and new runs MUST carry thirty

#### Scenario: A feature may keep less

- **GIVEN** a feature overriding the retention to seven days
- **WHEN** it runs
- **THEN** the run entry MUST carry seven days

### Requirement: A scheduled job MUST enforce retention, and MUST report that it did

The system MUST run a scheduled job that removes the payload of every run entry past
its retention. The instance MUST report when the job last ran and how many entries it
acted on.

A retention setting without an enforcing job is worse than neither: the screen says
ninety days and the data is still there in year three. The report is what makes the
enforcement checkable rather than assumed.

#### Scenario: Expired runs lose their payload

- **GIVEN** run entries past their recorded retention
- **WHEN** the job runs
- **THEN** their payloads MUST be removed

#### Scenario: The last cleanup is an answerable question

- **WHEN** an administrator asks when retention last ran
- **THEN** the instance MUST answer with a time and a count

#### Scenario: A job that has never run is visible as such

- **GIVEN** an instance where the job has not yet run
- **WHEN** the report is read
- **THEN** it MUST say so, rather than reading as a successful run of zero

### Requirement: Retention MUST remove the payload and MUST NOT break the chain

The system MUST NOT delete an audit chain entry to satisfy retention. It MUST remove
the entry's payload and leave a tombstone carrying that a run happened, when, for
which feature, under which provider, and that the payload was deleted under retention
on a stated date.

Deleting an entry from a hash and `previousHash` chain invalidates every hash after
it, destroying the property the chain exists for. The tombstone keeps the article 30
record of the processing while the personal data is gone, which is what storage
limitation asks for.

#### Scenario: The chain still verifies after a cleanup

- **GIVEN** an audit chain containing entries whose payloads have been removed
- **WHEN** the chain is verified
- **THEN** it MUST verify

#### Scenario: The processing is still recorded after the data is gone

- **GIVEN** a run whose payload has been removed under retention
- **WHEN** its entry is read
- **THEN** it MUST report that the run happened, when, for which feature and provider,
  and that the payload was deleted under retention on a stated date

#### Scenario: No personal data survives the tombstone

- **GIVEN** the same entry
- **WHEN** it is read
- **THEN** the input text and the model output MUST be absent
