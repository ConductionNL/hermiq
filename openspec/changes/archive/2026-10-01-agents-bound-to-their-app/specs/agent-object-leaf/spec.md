# agent-object-leaf Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-bound-to-their-app

## Purpose

Show an AI-written summary of a record on its page, through hermiq's agent leaf. Row `buildiq:ai-record-summary`.

## ADDED Requirements

### Requirement: A record page can show an AI-written summary of the record (REQ-APPAG-004)

The system MUST let a user who may read an object ask for a summary of it on the agent leaf. The summary MUST be written by the app's assistant through the tool-free assistant pipeline with the administered record-summary prompt. It MUST be labelled "Written by AI on <date>. Check it before you rely on it." It MUST be kept for that object version and shown again without a new model call until the record changes. It MUST be off when the organisation switches off the `record-summary` AI feature.

#### Scenario: A case handler reads a summary of a long application
- GIVEN a case handler on the page of a subsidy application in a built app, with the agent leaf shown
- WHEN they choose "Write a summary"
- THEN a summary of at most five sentences appears above the run list with the AI label and the date

#### Scenario: The summary is not rewritten until the record changes
- GIVEN a record with a stored summary
- WHEN another user opens the record without it having changed
- THEN the same summary shows and no model call is made
- @e2e exclude model call counting, covered by PHPUnit on the summary service

#### Scenario: No summary for a record the user cannot read
- GIVEN a user without read rights on an object
- WHEN they call `POST /api/assistant/summarise` for it
- THEN the answer is HTTP 404 and no model is called
- @e2e exclude authorization contract, covered by PHPUnit and Newman
