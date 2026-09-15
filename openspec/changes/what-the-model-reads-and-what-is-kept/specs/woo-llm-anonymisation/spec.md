# woo-llm-anonymisation

## ADDED Requirements

### Requirement: A feature MAY require that a document was redacted before it is read

The system MUST let an `AiFeature` declare `requiresRedaction`. When it is set and a
run is handed a document reference, the run MUST be given filinq's redaction outcome
for that reference, and MUST be refused otherwise. The refusal MUST name the feature
and the document reference, and MUST happen before any request reaches the provider.

The requirement MUST attach to the document reference, not to the whole run. A feature
requiring redaction MUST still run on text that carries no document reference, so the
declaration stays usable on a handler's own note.

Candidate C-access-and-privacy-3 (`access-and-privacy.tsv:42`), relevance **`must`**.
**No driven passer.** The evidence is decos-join's documented `/zaaksysteem/joni-plus`
page, admitted under decision D21 and labelled documented here. The lane's clause:
"no citizen data reaches the model" is the question every functionaris
gegevensbescherming asks about an assistant, and it is answerable as a capability
rather than as a policy.

#### Scenario: An unredacted document does not reach the model

- **GIVEN** a feature declaring `requiresRedaction` and a document reference with no
  redaction outcome
- **WHEN** the feature runs
- **THEN** no request MUST reach the provider, and the refusal MUST name the feature
  and the reference

#### Scenario: A note with no document still runs

- **GIVEN** the same feature and a run carrying only typed text
- **WHEN** it runs
- **THEN** it MUST proceed

#### Scenario: A redacted document proceeds

- **GIVEN** the same feature and a document reference carrying filinq's redaction
  outcome
- **WHEN** the feature runs
- **THEN** it MUST proceed, and the outcome MUST be recorded on the run

### Requirement: hermiq MUST NOT redact, and MUST NOT treat detection as redaction

The system MUST NOT perform redaction. Redaction belongs to filinq under decision D13.
A `detect-pii` result MUST NOT satisfy `requiresRedaction`, in any code path.

Detection returns spans over the original text and requires the model to see that
text. Redaction removes the spans and runs before any model reads anything. Treating
the first as evidence of the second would mark a run safe on the strength of having
sent the model exactly what it was not supposed to see.

#### Scenario: A detection result does not unlock a redaction-requiring feature

- **GIVEN** a document with a completed `detect-pii` result and no filinq redaction
- **WHEN** a feature declaring `requiresRedaction` runs against it
- **THEN** the run MUST be refused

#### Scenario: hermiq ships no redactor

- **WHEN** hermiq's code is inspected for a path that removes personal data from a
  document
- **THEN** none MUST exist, and the only redaction reference MUST be to filinq's
  outcome

### Requirement: A missing redaction client MUST fail closed

When filinq's redaction outcome cannot be resolved, the system MUST refuse every run
of every feature declaring `requiresRedaction`, and MUST say that the redaction client
is unavailable. It MUST NOT substitute its own detection, and MUST NOT proceed
unredacted.

This follows the posture `ai-feature-governance` already sets with its
acknowledgement gate: a declarative refusal protects every path, where an imperative
check protects only the call site that remembers it.

#### Scenario: Without filinq, a redaction-requiring feature does not run

- **GIVEN** an instance where filinq cannot be resolved
- **WHEN** a feature declaring `requiresRedaction` runs
- **THEN** it MUST be refused, naming the unavailable redaction client

#### Scenario: Features that require nothing are unaffected

- **GIVEN** the same instance
- **WHEN** a feature without `requiresRedaction` runs
- **THEN** it MUST proceed

### Requirement: The redaction check MUST sit in the ordered pre-call path

The system MUST check redaction in the same ordered pre-call path as the model policy
and the residency check: resolve the feature binding, narrow by policy, check
residency, check redaction, then call. Each step MUST name itself when it refuses.

One ordered path means there is one place to read to know what a run must pass.

#### Scenario: Nothing is sent before every check has passed

- **GIVEN** a run that would fail the redaction check
- **WHEN** it is executed
- **THEN** no request MUST reach the provider

#### Scenario: The refusing step is identifiable

- **GIVEN** runs refused on policy, on residency and on redaction
- **WHEN** each refusal is read
- **THEN** each MUST name the step that refused it
