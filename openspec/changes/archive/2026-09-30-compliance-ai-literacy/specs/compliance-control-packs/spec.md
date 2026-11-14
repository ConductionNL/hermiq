# compliance-control-packs Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- compliance-ai-literacy

## Purpose

Help people build AI literacy inside hermiq and give the organisation evidence of it. Row `hermiq:td-ai-literacy`, tender Molenlanden 415259 requirement 71423.

## ADDED Requirements

### Requirement: People can follow short lessons on working with AI (REQ-AILIT-001)

The system MUST offer a page "Working with AI" with six lessons in English and Dutch, each with a check question. A right answer MUST record the person's completion of that lesson version. A wrong answer MUST explain why and allow another try.

#### Scenario: A new case handler completes a lesson
- GIVEN a case handler who has not done the course
- WHEN they open "Working with AI", read "Check the sources before you use an answer" and pick "Check the figure in the source system before you use it"
- THEN the lesson shows as done and the page shows "1 of 6 done"

#### Scenario: A changed lesson is asked again
- GIVEN a person who completed lesson 4 and an admin who then edits it
- WHEN the person opens the page
- THEN lesson 4 shows as not done for the new version
- @e2e exclude needs an admin edit of a seeded lesson between two sessions; covered by LiteracyTest::testAChangedLessonIsAskedAgain

### Requirement: An organisation admin sees completion and may require the course (REQ-AILIT-002)

The system MUST show an organisation admin completion per person and group with a CSV export. When the organisation requires the course, the system MUST refuse to start a chat, a run by hand or a Talk session with an agent for a person who has not completed the current lessons, with a link to the course. Scheduled and flow runs MUST NOT be blocked by it.

#### Scenario: A person who skipped the course is sent to it
- GIVEN an organisation that requires the course and a person with 3 of 6 lessons done
- WHEN they open chat with an agent and send a message
- THEN the chat shows "Finish the short course Working with AI first." with a link, and no model is called

### Requirement: The AI Act article 4 control reads its status from completions (REQ-AILIT-003)

The system MUST seed an EU AI Act control `art.4` "AI literacy" whose status is computed from completions: met when every user who used an agent in the last 90 days completed all current lessons, partial when some did, gap when none did, with the counts in the detail. The status MUST NOT be settable by hand.

#### Scenario: The compliance officer checks article 4
- GIVEN an organisation where 40 of 50 recent agent users completed the course
- WHEN the compliance officer opens the compliance dashboard
- THEN the control "AI literacy" shows partial with "40 of 50 people who used an agent in the last 90 days completed the course"
- @e2e exclude needs 50 recorded agent users; the status is covered by LiteracyTest::testTheArticle4ControlReadsCompletions and ComplianceServiceTest::testTheAiLiteracyControlReadsTheLiteracyReport
