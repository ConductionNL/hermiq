# Forge Tools Specification

**Status**: active — tool registered, brokered and invoked end to end from an OpenRegister flow

**Feature tier**: V1

**OpenSpec changes:**
- `forge-tools` — DONE: a `hermiq.github` tool contributed to OpenRegister's tool registry through `ToolRegistrationEvent`, every call brokered by OpenRegister's `CredentialBrokerService`, plus a `hermiq.github-await-label` flow node that holds a run until a person labels an issue. Verified 2026-09-23 by a twenty-nine step flow on `ConductionNL/planninq` that opened an issue, waited for its `accepted` label, branched, wrote OpenSpec files, wrote code, reviewed it twice and opened a draft pull request.

## Purpose

Lets an agent act on a git forge without Hermiq ever holding the credential, and lets a flow wait on
a decision that only exists on that forge. Registration goes through the same OpenRegister tool
registry as every other tool, so one registration reaches the in-app chat, the `hermiq.agent-step`
flow node and the MCP server alike.

## Requirements

### Requirement: Forge tools are registered through OpenRegister's tool registry

The system MUST contribute its forge tools by listening for OpenRegister's `ToolRegistrationEvent`
and calling `registerTool()`, rather than by exposing a second registration mechanism of its own.

#### Scenario: A registered forge tool is offered to an agent

- GIVEN Hermiq's tool-registration listener is registered for `ToolRegistrationEvent`
- WHEN OpenRegister builds its tool catalogue
- THEN the `hermiq.github` tool MUST appear in that catalogue
- AND its functions MUST be filtered by the calling agent's own grants, like every other tool

### Requirement: The forge credential never enters the app

Every outbound forge call MUST go through OpenRegister's credential broker, which holds the secret,
pins the host and matches the method and path against the provider's allow-rules. The system MUST
NOT read, store or log the token.

#### Scenario: A call the allow-rules do not permit is refused

- GIVEN a `github` credential admitted for this app
- WHEN a tool function attempts a method and path no allow-rule matches
- THEN the broker MUST refuse the call
- AND the refusal MUST NOT depend on which functions the app happens to expose

#### Scenario: A missing credential is named, not guessed

- GIVEN no `github_credential` app setting is configured
- WHEN any forge function is called
- THEN the call MUST fail naming the missing setting
- AND it MUST NOT reach the broker

### Requirement: A tool result is bounded, and says when it was cut

A tool result is model input, so the system MUST cap what one read returns, cut on a character
boundary, and state in the result both that it was cut and how large the whole is. It MUST offer a
way to read past the cut.

#### Scenario: A file larger than the cap is read

- GIVEN a file whose content exceeds the cap
- WHEN it is read with no offset
- THEN the result MUST carry at most the cap in bytes, including its truncation marker
- AND the result MUST report `truncated` true and the full size
- AND reading again from an offset past the excerpt MUST return the following bytes

#### Scenario: A cut never produces invalid UTF-8

- GIVEN a file whose content is multibyte around the cap
- WHEN it is read and cut
- THEN the returned content MUST be valid UTF-8
- AND the whole result MUST survive JSON encoding

### Requirement: Absence and failure are different answers

A read MUST distinguish "the forge says this is not there" from "the call did not complete". The
system MUST answer absence as an ordinary negative result and MUST raise on anything else.

#### Scenario: A transport failure is not reported as a missing file

- GIVEN the forge answers a read with a server error
- WHEN the caller asked whether a path exists
- THEN the system MUST raise rather than answer that the path is absent
- AND a create-or-update decision MUST NOT be taken on that answer

### Requirement: A run waits for a forge decision, and a gate that cannot resolve its subject stops

A flow step MUST be able to hold a run until a named label appears on a named issue, re-reading on a
heartbeat. A failed read MUST count as "not yet" rather than as a decision. A step whose repository
or issue reference resolves to nothing MUST stop the run rather than pass.

#### Scenario: The label is not yet present

- GIVEN a step waiting for a label on an issue that does not carry it
- WHEN the step runs
- THEN the run MUST suspend and be re-checked later

#### Scenario: The issue cannot be read

- GIVEN the forge refuses the read
- WHEN the step runs
- THEN the run MUST suspend rather than conclude the label will never come

#### Scenario: The step cannot tell which issue it is gating

- GIVEN a step whose repository or issue template resolves to an empty value
- WHEN the step runs
- THEN the run MUST stop with an error naming the template and the value it produced
- AND the run MUST NOT continue past the gate

#### Scenario: The wait is given up on a deadline that survives a suspension

- GIVEN a step with a timeout
- WHEN the run suspends and wakes repeatedly
- THEN the deadline MUST be the one fixed on the first pass, not recomputed each wake
- AND on expiry the result MUST distinguish a label nobody applied from an issue that could not be read
