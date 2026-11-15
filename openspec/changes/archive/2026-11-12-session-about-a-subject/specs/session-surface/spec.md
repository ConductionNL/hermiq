# session-surface Delta

## ADDED Requirements

### Requirement: A session may name the record it is about

A session SHALL be able to carry its subject in `metadata.subject` (`objectUuid`, `register`, `schema`, `appId`). A session started from a turn whose page context names `objectUuid` SHALL record that record as its subject; later turns SHALL NOT change it.

#### Scenario: A chat started on a case page
- **GIVEN** a user sends a first message from a case page whose context names the case's `objectUuid`
- **WHEN** hermiq creates the session
- **THEN** the session's `metadata.subject.objectUuid` SHALL be the case's uuid

### Requirement: The session list can be narrowed to one record

`GET /api/sessions?subject=<objectUuid>` SHALL return only the sessions the caller may read whose subject is that record, most recent activity first. It SHALL NOT list a session the caller cannot read.

#### Scenario: The latest session about a case
- **GIVEN** the caller has two sessions about a case and one about another case
- **WHEN** the caller lists sessions with `subject` set to the first case and `limit=1`
- **THEN** the answer SHALL hold the most recently active of the two

#### Scenario: Another user's session about the same case
- **GIVEN** another user has a session about the case and the caller does not take part in it
- **WHEN** the caller lists sessions with `subject` set to the case
- **THEN** that session SHALL NOT be in the answer

### Requirement: A session's messages come with the approvals waiting on its owner

`GET /api/sessions/{uuid}/messages` SHALL return `pendingApprovals` for the session's agent, in the shape the stream's `final` event uses, to a caller who may read the session.

#### Scenario: A reopened session with a pending proposal
- **GIVEN** a session whose agent waits on one approval by the owner
- **WHEN** the owner reads the session's messages
- **THEN** the answer SHALL carry that approval in `pendingApprovals`
