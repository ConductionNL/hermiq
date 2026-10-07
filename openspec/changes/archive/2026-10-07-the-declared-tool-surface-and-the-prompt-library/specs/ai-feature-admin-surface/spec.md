# ai-feature-admin-surface

## ADDED Requirements

### Requirement: The prompts the assistant offers MUST be administered objects

The system MUST provide an `AssistantPrompt` carrying a label, the exact prompt text
that will be sent, a `usageScope` naming where it is offered, an order, and an
`enabled` flag. An administrator MUST be able to read the exact text, edit it,
reorder the library and scope a prompt without a release.

A prompt built in code MUST NOT be offered on a case surface unless it exists as an
`AssistantPrompt`, so what an administrator reads is what the model is told.

The lane's clause: a gemeente that cannot read the prompt cannot defend the output.
When a citizen asks why the assistant summarised their bezwaar as it did, the answer
is the prompt, and it must be retrievable by somebody who does not read code.

Candidate C-configuration-44 (`configuration.tsv:43`), relevance `should`, driven
passers opencase and openproject. OpenProject's evidence: Administration, Text
transform actions, `resources :text_transform_actions` with `toggle`, `enable_all` and
`disable_all`, and `AI::TextTransformAction` carrying a `usage_scope` and a prompt.

#### Scenario: The text that will be sent is the text on screen

- **GIVEN** an administrator reading a prompt object
- **WHEN** the assistant runs that prompt
- **THEN** the text sent MUST be the text the object carries

#### Scenario: Scope decides where a prompt appears

- **GIVEN** a prompt scoped to one record type
- **WHEN** the assistant surface opens on a record of another type
- **THEN** the prompt MUST NOT be offered

#### Scenario: Order is the administrator's

- **GIVEN** a library reordered by an administrator
- **WHEN** the surface renders
- **THEN** the prompts MUST appear in that order, unsorted

### Requirement: Disabling every prompt MUST be one recorded act

The system MUST let an administrator disable every prompt, wholesale or within one
scope, in a single action. The act MUST be recorded with the actor and the time.

Re-enabling MUST be per prompt. Disabling in bulk is an incident response; re-enabling
in bulk would restore a prompt that had been switched off weeks earlier for a
different reason.

An incident response that requires editing twelve rows is not a response, and the
first question after an incident is when the assistant was switched off. That answer
must not be somebody's memory.

#### Scenario: Everything stops in one act

- **GIVEN** a library of twelve enabled prompts
- **WHEN** an administrator disables all
- **THEN** none MUST be offered on any surface, after one action

#### Scenario: The switch-off is on the record

- **WHEN** the audit is read after a disable-all
- **THEN** it MUST name the administrator and the time

#### Scenario: Coming back is deliberate

- **GIVEN** a library that was disabled wholesale
- **WHEN** an administrator re-enables
- **THEN** they MUST do it per prompt, and no bulk re-enable MUST exist

### Requirement: A consuming app MAY ship an initial library and MUST NOT hold the edited state

A consuming app MAY ship prompts as an initial library. Once an administrator has
edited, reordered, scoped or disabled one, that state MUST live in hermiq, and the
consuming app MUST NOT hold or restore it.

#### Scenario: An edit survives the shipping app

- **GIVEN** a prompt shipped by a consuming app and then edited by an administrator
- **WHEN** the consuming app is updated
- **THEN** the edited text MUST stand

#### Scenario: A disabled prompt stays disabled

- **GIVEN** a shipped prompt an administrator disabled
- **WHEN** the consuming app is updated
- **THEN** it MUST stay disabled
