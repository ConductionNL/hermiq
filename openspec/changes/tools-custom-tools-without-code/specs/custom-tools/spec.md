# custom-tools Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-custom-tools-without-code

## Purpose

An organisation admin adds a named tool that calls a flow or an integriq endpoint, without code, and agents use it under the normal tool governance. Row `hermiq:tl-custom-tool`.

## ADDED Requirements

### Requirement: An organisation admin can define a custom tool without code (REQ-CUSTOOL-001)

Hermiq MUST let an organisation admin or instance admin create a `CustomTool` with a slug, a name, a description, an input schema of simple types and a target that is either an OpenRegister flow or an integriq source with a method, a path and a body template. Other users MUST NOT create or edit one.

#### Scenario: An organisation admin adds the permit check
- GIVEN an organisation admin on the MCP tools page
- WHEN they choose "Add a custom tool", name it "Vergunningcheck", add the inputs `adres` and `activiteit`, choose "Changes nothing", pick the flow "Vergunningcheck" and save
- THEN "Vergunningcheck" appears on the Custom tools page and in the MCP tools list as `hermiq.custom_vergunningcheck`

#### Scenario: An agent owner cannot create one
- GIVEN a user who owns agents but is not an organisation admin
- WHEN they post a new custom tool to the API
- THEN the request is refused, and no tool is created

### Requirement: A custom tool is offered under a two-segment id and only by exact grant (REQ-CUSTOOL-002)

Hermiq MUST expose each enabled custom tool of the acting user's organisation through `HermiqToolProvider` as `hermiq.custom_<slug>`, with hints from what the admin declared and a reach of `external` for an integriq target. A custom tool MUST only be granted by its exact id, and no wildcard grant MUST reach it.

#### Scenario: A wildcard grant does not reach custom tools
- GIVEN an agent whose grants include `hermiq.custom.*`
- WHEN its catalogue is built
- THEN no custom tool is in it
- @e2e exclude grant resolution per turn; covered by PHPUnit

#### Scenario: An agent owner grants the tool
- GIVEN an agent owner in the agent's Tool governance grant editor
- WHEN they tick `hermiq.custom_vergunningcheck` and save
- THEN the agent can call it, and the grant editor shows it with reach and "Changes nothing"

### Requirement: A custom tool validates its input and calls only through the flow tool or integriq (REQ-CUSTOOL-003)

Hermiq MUST refuse arguments that do not match the tool's input schema before any call. A flow target MUST run through `openregister.runFlow` fixed to that flow, under the existing owner rule. An integriq target MUST run through integriq's `CallService`, and hermiq MUST NOT open an HTTP connection itself. The answer MUST be capped at 20 KB and delimited as untrusted.

#### Scenario: A case handler's question uses the tool
- GIVEN an agent granted `hermiq.custom_vergunningcheck`
- WHEN a case handler asks "Heb ik een vergunning nodig voor een terras op Markt 3?"
- THEN the chat shows the tool step "Vergunningcheck" with `adres: Markt 3` and `activiteit: terras`, and the answer names the rule the flow returned

#### Scenario: Wrong input is refused before the call
- GIVEN the same tool
- WHEN the model calls it with `activiteit: "bouw"`
- THEN no flow is queued, and the tool answers `invalid_argument` naming `activiteit`
- @e2e exclude needs a model that sends a wrong value; covered by PHPUnit on validation

### Requirement: A custom tool passes the same governance as every tool (REQ-CUSTOOL-004)

Hermiq MUST send every custom tool call through the same grant constraints, guardrail classification, approval gate, tracing and redaction as the built-in tools, and the run MUST name the custom tool and its target.

#### Scenario: A tool that changes data waits for a reviewer
- GIVEN a custom tool marked "Changes data" and an organisation policy that classifies it `confirm`
- WHEN an agent calls it
- THEN an approval is created, and the call runs only after a reviewer approves it
