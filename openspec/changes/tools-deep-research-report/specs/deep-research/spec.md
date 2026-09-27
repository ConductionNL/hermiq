# deep-research Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-deep-research-report

## Purpose

An agent researches a question across many sources on its own and writes a cited report into the user's Files, within caps. Row `hermiq:dm-deep-research`.

## ADDED Requirements

### Requirement: A user can start deep research and sees the plan (REQ-RESEARCH-001)

Hermiq MUST let a user start a research run from the chat when the agent holds `hermiq.webSearch` and `hermiq.webFetch`, and MUST let an agent granted `hermiq.startResearch` start one. The run MUST run in the background and MUST post its plan of three to eight sub-questions in the conversation before it searches.

#### Scenario: A policy officer asks for research
- GIVEN an agent with the web research tools granted
- WHEN a policy officer switches on "Deep research" and asks "Welke gemeenten gebruiken een algoritmeregister en wat publiceren ze?"
- THEN the conversation shows "Research plan" with the sub-questions within a minute, and a "Stop research" button

### Requirement: The research goes through the agent's governed tools and records every source (REQ-RESEARCH-002)

Hermiq MUST run every search and fetch of a research run through the same governed tool chain as a chat turn, and MUST give every fetched page a number in the source ledger with its URL, title, fetch time and text.

#### Scenario: A fetch the egress guard refuses
- GIVEN a research run
- WHEN the model tries to fetch an address on the instance's own network
- THEN the fetch is refused by the egress guard as in chat, and the ledger does not list it
- @e2e exclude needs a model that picks a private address; covered by PHPUnit on the runner

### Requirement: Every citation is checked against what was read (REQ-RESEARCH-003)

Hermiq MUST check each citation in a report: the cited ledger number MUST exist and the quoted passage MUST occur in that source's fetched text. A citation that fails MUST be removed and its sentence marked "(unverified)". The report MUST end with its sources list, and the run MUST record the counts of checked and failed citations.

#### Scenario: An invented quote is caught
- GIVEN a draft report citing source 4 with a passage that is not in source 4's text
- WHEN hermiq checks the citations
- THEN that citation is removed, the sentence ends with "(unverified)", and the conversation message says "1 unverified"
- @e2e exclude depends on model output; covered by PHPUnit on the checker

### Requirement: The report is saved in the user's Files and marked as agent-authored (REQ-RESEARCH-004)

Hermiq MUST write the report as the requesting user into their own Files under `Hermiq/Onderzoek`, MUST apply the agent-authored mark in the same operation, and MUST treat a failed mark as a failed run with no file left behind. The conversation MUST get a summary with the counts and a link to the file.

#### Scenario: The report arrives
- GIVEN a finished research run
- WHEN the policy officer opens the conversation
- THEN a message shows a five-line summary, "14 sources, 31 citations checked, 2 unverified" and a link, and the file in Files carries the tag "Agent authored"

### Requirement: A research run stops at its caps, the budget, the kill switch or the user (REQ-RESEARCH-005)

Hermiq MUST stop a research run's gathering when it reaches its tool call, source, time or token cap, and MUST then write the report from what it has with the reason in its first line. Hermiq MUST check the budget hard cap and the kill switch before every model call and MUST end the run without a report when either blocks. "Stop research" MUST end the run at the next step without a report.

#### Scenario: The source limit is reached
- GIVEN a research run with a limit of 20 sources
- WHEN the twentieth page is read
- THEN no more pages are fetched, and the report starts with "This report stopped early: the source limit of 20 was reached."
- @e2e exclude needs a live search backend and model; covered by PHPUnit with stubbed tools

#### Scenario: The user stops it
- GIVEN a running research run
- WHEN the policy officer presses "Stop research"
- THEN the run ends at its next step, no file is written, and the conversation says "Research stopped."
