# code-sandbox Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-code-sandbox

## Purpose

Agents and flows run short pieces of code in an isolated sandbox, on the organisation's own server or at a hosted provider the admin chooses. Rows `hermiq:tl-code-exec`, `hermiq:fl-code-step` and `hermiq:dm-sandbox-choice`.

## ADDED Requirements

### Requirement: The sandbox runs code without network, without data mounts and within limits (REQ-SANDBOX-001)

The `hermiq-code-sandbox` ExApp MUST run each piece of code in a fresh work directory, as a non-root user, with no network route and no mounted volume. It MUST stop a run at its time limit and MUST enforce its memory, process and output limits. It MUST remove the work directory when the run ends.

#### Scenario: Code cannot reach the internet
- GIVEN the sandbox ExApp installed
- WHEN a run executes Python that opens `https://example.org`
- THEN the run ends with a non-zero exit and a network error in stderr, and no connection leaves the container
- @e2e exclude container network isolation; covered by the ExApp's own test suite

#### Scenario: An endless loop is stopped
- GIVEN a run with a 30 second limit
- WHEN the code loops forever
- THEN the run is stopped at 30 seconds and answers `timedOut: true`
- @e2e exclude timing inside the container; covered by the ExApp's own test suite

### Requirement: An agent runs code only with a grant and an enabled AI feature (REQ-SANDBOX-002)

Hermiq MUST expose `hermiq.runCode` as a write-classified tool, so it is default-denied. Hermiq MUST refuse every call while the AI feature `agent-code-execution` is absent or not enabled, whatever the agent's grants. The tool's reach MUST be `self` when code runs in the own sandbox and `external` when it runs at a hosted provider, and at least `user` when the call reads the acting user's files.

#### Scenario: A policy officer asks for a calculation
- GIVEN the AI feature enabled and an agent granted `hermiq.runCode`
- WHEN a policy officer asks "Wat is 3,5 procent indexatie over 1.284.000 euro, afgerond op hele euro's?"
- THEN the chat shows a tool step with the code and its output, and the answer "44.940 euro"

#### Scenario: The AI feature is off
- GIVEN an agent granted `hermiq.runCode` and the AI feature disabled
- WHEN the model calls the tool
- THEN nothing is sent to the sandbox, and the tool answers "Running code must be enabled as an AI feature before an agent can run code."
- @e2e exclude needs a model that calls the tool; covered by PHPUnit on the gate

### Requirement: A flow can run a code step over each item (REQ-SANDBOX-003)

Hermiq MUST contribute a `hermiq.code-step` flow node that sends each item's JSON to the sandbox on stdin, replaces the item with the JSON the code prints, and marks the item failed with the error output otherwise. The node MUST limit a run to at most 30 seconds, MUST need the same AI feature, and MUST NOT appear in the palette when no sandbox is available.

#### Scenario: A flow author normalises postcodes
- GIVEN a flow author on the flow canvas with the sandbox installed and the AI feature enabled
- WHEN they add a "Code step" with Python that upper-cases `postcode` and removes its space, and run the flow on an item with `postcode: "1234 ab"`
- THEN the item continues with `postcode: "1234AB"`

### Requirement: The admin chooses where code runs (REQ-SANDBOX-004)

Hermiq MUST let an admin choose between the own sandbox ExApp and a hosted sandbox reached through an integriq source whose key is in the credential broker. Hermiq MUST send hosted runs only through integriq, and MUST show which backend is live.

#### Scenario: An admin switches to a hosted sandbox
- GIVEN an admin in Admin settings, Code sandbox, with an integriq source "hosted-sandbox"
- WHEN they choose "Hosted sandbox", pick that source and press "Run a test"
- THEN the section shows "Test passed: 2" and "Code runs at: hosted-sandbox (outside this server)", and the grant editor shows `hermiq.runCode` with reach external

### Requirement: Every code run is on the run record, redacted and capped (REQ-SANDBOX-005)

Hermiq MUST record each code run as a tool step with the code, the language, the limits, the exit code, the duration and whether it timed out, with stdout and stderr cut to 4 KB and redacted before they are persisted.

#### Scenario: An auditor reads what ran
- GIVEN a run in which an agent called `hermiq.runCode`
- WHEN an auditor opens the run on the Runs page
- THEN the tool step shows the code and at most 4 KB of each output, with any e-mail address in the output replaced by the redaction marker
