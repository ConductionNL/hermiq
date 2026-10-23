# conversational-intake

## ADDED Requirements

### Requirement: An intake conversation MUST be able to file, on its own surface

The system MUST provide a conversational intake surface, distinct from the tool-free
case assistant surface, that holds a conversation with a person who has no record yet
and MAY create one through an intake tool the owning app declares.

The intake tool grant MUST cover creation only, scoped to the record types the owning
app declares. The intake surface MUST NOT be able to read or change an existing
record. The tool-free case assistant surface MUST be unchanged, and its no-tool
sentinel MUST remain in force.

Candidate C-intake-9 (`intake.tsv:13`), relevance `should`. **No driven passer.** The
evidence is jira-service-management's documented virtual service agent: intents, the
flow builder, AI answers, and the portal, widget, e-mail, Slack and Teams channels.
Admitted under decision D21 and labelled documented here. The lane's clause: the
digitale balie every gemeente is being sold, and the row that decides whether a case
system or a separate chatbot owns it.

#### Scenario: A conversation ends in a filed request

- **GIVEN** a citizen describing a broken streetlight
- **WHEN** the intake conversation completes
- **THEN** the owning app MUST have been asked to create a record of the declared
  type, and MUST have validated it itself

#### Scenario: Intake cannot touch an existing record

- **GIVEN** an intake conversation and an existing case id
- **WHEN** any read or write of that case is attempted from the intake surface
- **THEN** it MUST be refused

#### Scenario: The case assistant stays tool-free

- **WHEN** the case assistant surface's tool list is resolved
- **THEN** it MUST still resolve to no tools

### Requirement: A classification MUST carry a confidence and MUST be able to abstain

The system MUST propose which kind of request a conversation is, drawn from the
catalogue the owning app declares, and MUST carry a confidence with the proposal.
Below an administered threshold the system MUST abstain and hand over to a human
rather than file.

The threshold MUST be administered and readable by whoever set it. A wrong confident
filing loses more than an abstention: a bezwaar filed as a melding loses a statutory
term, and nobody notices until the term has run.

#### Scenario: An uncertain intake does not guess

- **GIVEN** a conversation whose classification confidence is below the threshold
- **WHEN** the conversation reaches its end
- **THEN** the system MUST hand over to a human and MUST NOT create a record

#### Scenario: The catalogue is the municipality's

- **WHEN** a classification is proposed
- **THEN** it MUST be drawn from the request catalogue the owning app declares, and
  MUST NOT be invented

#### Scenario: The threshold is readable

- **WHEN** an administrator asks what the abstention threshold is
- **THEN** the system MUST answer with the value in force

### Requirement: No conversation MUST dead-end

The system MUST end every intake conversation in either a filed request or a handover
to a human, and MUST carry the transcript to the handover. A reply that cannot help
MUST be a handover, not an ending.

A citizen who gives up leaves no case, no complaint and no measurement, so an intake
that loses people is invisible in exactly the way that matters. This is a requirement
rather than guidance for that reason.

#### Scenario: An unhelpable conversation reaches a person

- **GIVEN** a conversation the assistant cannot resolve or classify
- **WHEN** it ends
- **THEN** a handover MUST exist, carrying the transcript

#### Scenario: Every terminal state is one of two

- **WHEN** the terminal states of the intake conversation are enumerated
- **THEN** each MUST be a filed request or a handover

### Requirement: One conversation MUST span the channels a person uses

The system MUST key an intake conversation by the person and the subject rather than
by the channel, and MUST attach an incoming message on any channel to an open
conversation for that person and subject.

Channel adapters belong to the apps that own the channels. hermiq MUST hold the
conversation and MUST NOT implement a channel transport of its own.

#### Scenario: Starting by e-mail and continuing in the portal

- **GIVEN** a citizen who started an intake by e-mail
- **WHEN** they open the portal and continue
- **THEN** the same conversation MUST continue, and they MUST NOT be asked to repeat
  what they already said

#### Scenario: hermiq transports nothing

- **WHEN** hermiq is inspected for a channel transport
- **THEN** none MUST exist, and each channel MUST arrive through its owning app

### Requirement: A deterministic escalation signal MUST be preferred over a model one

Where the owning app supplies a deterministic escalation or sentiment signal, the
system MUST use it and MUST record that it did. A model-produced score MUST be used
only where no deterministic signal exists, and MUST be labelled as a model output on
every surface that shows it.

Candidate C-communication-64 (`communication.tsv:76`), relevance `should`. **No driven
passer**; jira-service-management documented. The sweep read dossiq **`yes`** on it,
noting `lib/Service/Kcc/SentimentService.php` scores Dutch contactmoment text for
klacht, advocaat and wethouder and returns an escalation level, deterministically
rather than by model, and adding "dossiq has the better shape already". This
requirement exists to keep that shape, not to replace it.

#### Scenario: The deterministic score wins

- **GIVEN** an owning app supplying a deterministic escalation level for a message
- **WHEN** the intake evaluates that message
- **THEN** it MUST use that level, and the run MUST record that a deterministic signal
  was used

#### Scenario: A model score is labelled as one

- **GIVEN** a message for which no deterministic signal exists
- **WHEN** a model produces a sentiment
- **THEN** every surface showing it MUST label it as a model output

#### Scenario: A reader can tell them apart

- **GIVEN** one message scored deterministically and one scored by a model
- **WHEN** both are shown to a handler
- **THEN** the handler MUST be able to tell which is which without asking

### Requirement: An external review MUST be a declared tool whose verdict is recorded

The system MUST let an owning app declare an external review as a tool, MUST call it
from within a conversation when the conversation asks for it, and MUST record the
verdict on the run with the reviewing party's identity and the time.

hermiq MUST form no opinion of its own about the subject of the review. It carries
another party's verdict and records whose it was.

Candidate C-integrations-1 (`integrations.tsv:33`), relevance `could`. **No driven
passer.** The evidence is rx-mission's documented `/modules/ Koppelingen`, admitted
under decision D21 and labelled documented here. The lane notes this is the one
VTH-specific external check named anywhere in the corpus, so the requirement is
written as a general declared review rather than as a plan-review feature.

#### Scenario: A verdict travels into the conversation with its author

- **GIVEN** an owning app declaring an external review tool
- **WHEN** a conversation invokes it
- **THEN** the verdict MUST be carried into the conversation, and the run MUST record
  the reviewing party and the time

#### Scenario: hermiq does not review

- **WHEN** hermiq is inspected for logic that evaluates the subject of an external
  review
- **THEN** none MUST exist

### Requirement: Intake MUST be registered as an AI feature with its own risk category

The system MUST register the conversational intake as its own `AiFeature`, so it
carries its own EU AI Act risk category, its own acknowledgement state and its own
provider binding.

An assistant that files on a citizen's behalf is not a minimal-risk feature, and the
register must be able to say so independently of the assistant that helps a handler.

#### Scenario: Intake is classified separately from the handler assistant

- **WHEN** the AI feature register is read
- **THEN** the conversational intake MUST appear as its own feature with its own risk
  category

#### Scenario: The existing gate applies

- **GIVEN** intake registered as high risk with no acknowledgement recorded
- **WHEN** enabling it is attempted
- **THEN** the existing acknowledgement gate MUST refuse it
