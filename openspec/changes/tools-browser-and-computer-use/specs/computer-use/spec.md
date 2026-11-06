# computer-use Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-browser-and-computer-use

## Purpose

An agent operates a desktop application on a Windows machine the organisation registers, through integriq, with every action approved. Row `hermiq:dm-computer-use`.

## ADDED Requirements

### Requirement: An admin registers a machine reached only through integriq (REQ-COMPUTER-001)

Hermiq MUST let an admin register a `ComputerTarget` with a name, the applications a reviewer should expect, and an integriq source. Hermiq MUST send every screenshot and action through integriq's `CallService` on that source and MUST NOT connect to the machine itself.

#### Scenario: An admin registers a workstation
- GIVEN an admin on Admin settings, Computer use, and an integriq source "wz-burgerzaken-03"
- WHEN they register "Werkplek Burgerzaken 03" with applications "Suite4Sociaal Domein, versie 12" and press "Take a test screenshot"
- THEN a screenshot of that machine is shown, and the target is listed as switched off until they enable it

### Requirement: Computer use is a high-risk AI feature that a grant alone cannot unlock (REQ-COMPUTER-002)

Hermiq MUST refuse every computer tool call while the AI feature `computer-use` is absent or not enabled, and the feature MUST be high-risk so it cannot be enabled before the DPO acknowledgement. Hermiq MUST NOT offer the computer tools to a model path that cannot read images in a tool result.

#### Scenario: The feature waits for the privacy officer
- GIVEN an admin on the AI feature register
- WHEN they try to enable "Computer use" before the DPO acknowledgement
- THEN enabling is blocked with the register's DPO message, and a granted agent's computer tool calls are refused

#### Scenario: A text-only model does not see the tools
- GIVEN an agent on an Ollama text model with the computer tools granted
- WHEN its catalogue is built for a turn
- THEN the computer tools are absent, and the agent form says "Computer use needs a model that can read images."
- @e2e exclude catalogue assembly per provider; covered by PHPUnit

### Requirement: Every computer action passes the approval gate (REQ-COMPUTER-003)

Hermiq MUST route every click, typing and key action through the approval gate whatever the organisation's guardrail policy says. A reviewer MUST be able to approve one action, or the actions on one machine for one run for at most 15 minutes as a recorded approval with a named decision maker. A screenshot MUST NOT need an approval.

#### Scenario: A reviewer approves one click
- GIVEN an agent working on "Werkplek Burgerzaken 03"
- WHEN it wants to click at the "Opslaan" button
- THEN the reviewer sees the machine, the action and the screenshot with the point marked, and the click runs only after they approve it

#### Scenario: A pre-authorisation ends
- GIVEN a reviewer approved actions on that machine for this run for 15 minutes
- WHEN the agent acts 16 minutes later
- THEN a new approval is required
- @e2e exclude needs a registered machine and a 15 minute wait; covered by PHPUnit with a fake clock

### Requirement: The run records actions and never stores screenshots (REQ-COMPUTER-004)

Hermiq MUST record every computer action with the machine, the action, the coordinates, the redacted typed text and the approval id, and MUST record a screenshot only as its hash and size.

#### Scenario: An auditor reviews a session on the workstation
- GIVEN a run with ten computer actions
- WHEN an auditor opens it on the Runs page
- THEN each action shows its machine, coordinates and approver, and no screenshot image is stored
