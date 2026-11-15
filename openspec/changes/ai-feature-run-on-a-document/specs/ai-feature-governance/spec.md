# ai-feature-governance Specification Delta

## ADDED Requirements

### Requirement: An app can run an AI feature on a document it names, and the gates apply to that document

hermiq SHALL offer `POST /api/ai-features/{slug}/run-on-document` taking a
`documentReference` (a Nextcloud file id) and an `instruction`. It SHALL run as
the signed-in person and SHALL read the document in that person's own Files.
Before any request leaves, hermiq SHALL apply the feature's pre-call gates to
that document in their specified order: model policy, data use, residency and
redaction. A request without a document reference SHALL be refused before
anything is read. A gate's refusal SHALL answer 422 naming the gate.

#### Scenario: A run without a document reference is refused before anything is read
@e2e exclude Asserted in PHPUnit: the refusal is thrown before the file, the feature register or any provider is touched.

- **GIVEN** a registered, enabled AI feature
- **WHEN** an app asks to run it with no document reference
- **THEN** hermiq SHALL answer 400
- **AND** no file SHALL be read and no provider SHALL be called

#### Scenario: The document reference reaches the redaction gate
@e2e exclude Asserted in PHPUnit: the reference is handed to ProviderFactory::generateText(), whose createChatDriver() runs the gates.

- **GIVEN** a feature that declares `requiresRedaction`
- **WHEN** an app runs it on a document
- **THEN** the redaction gate SHALL be applied to that document's reference

#### Scenario: A refusal names the gate that refused
@e2e exclude Asserted in PHPUnit against the controller: each gate's exception maps to 422 with its step.

- **GIVEN** a document filinq has not redacted, and a feature that requires redaction
- **WHEN** an app runs the feature on it
- **THEN** hermiq SHALL answer 422 with `gate: redaction` and the gate's sentence

#### Scenario: A document the person cannot open is not found
@e2e exclude Asserted in PHPUnit: the file is resolved in the caller's own Files.

- **GIVEN** a file id the signed-in person has no access to
- **WHEN** an app runs a feature on it
- **THEN** hermiq SHALL answer 404 and SHALL read nothing
