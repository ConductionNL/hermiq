# Design: a conversational intake that files for the citizen

## D1. Why this cannot be `case-assistant-surface`

`case-assistant-surface` is tool-free by construction, and its spec says the
tool-freeness is the feature: a chat box on a case must not be able to act on the
case. It enforces that with a `tools: ['__none__']` sentinel verified against
`ToolLoop::listAgentFunctions()`.

Intake needs the opposite property. A citizen describing a broken streetlight needs a
melding to exist at the end of the conversation, or the conversation was theatre.

Two ways to reconcile them.

1. Give `case-assistant-surface` an optional tool set. This removes the guarantee for
   every existing caller, and the guarantee is load-bearing.
2. A second surface, with exactly one narrowly declared tool.

Option 2. The existing surface keeps its sentinel untouched, and intake's tool grant
is per owning app, per record type, and covers creation only. Nothing about intake can
read or change an existing record, because it is talking to somebody who does not have
one.

## D2. Abstention is the requirement, not the classification

Every chatbot classifies. The ones that fail in a gemeente fail by classifying
confidently and wrongly: a bezwaar filed as a melding loses a statutory term, and
nobody notices until the term has run.

So the classification carries a confidence, and there is a threshold below which the
assistant says it does not know and hands over. The threshold is administered, because
the right value is a municipality's appetite, not ours.

The measurable property: a wrong confident filing is worse than an abstention, so the
spec requires the abstention path to exist and to be reachable, and requires the
threshold to be readable by whoever set it.

## D3. No path may dead-end

The failure mode of a bad intake bot is not a wrong answer. It is a citizen who gives
up, and it leaves no trace at all: no case, no complaint, no measurement. An intake
that loses people is invisible in exactly the way that matters.

So: every conversation ends in a filed request or a handover to a human, and the
transcript travels with the handover. "I could not help" is a handover, not an ending.

This is stated as a requirement rather than as design guidance because it is the one
property nobody will notice is missing.

## D4. One conversation, several channels

Jira Service Management's documented agent runs in the portal, a widget, e-mail, Slack
and Teams. The interesting part is not the channel count, it is that a person moving
between them keeps their thread.

A gemeente's version: somebody e-mails, gets an acknowledgement, then opens the portal
and continues. Making them repeat themselves is the most common complaint about
municipal digital service.

So a conversation is keyed by the person and the subject, not by the channel, and a
channel adapter attaches to an existing conversation where one is open. Channel
adapters themselves belong to the apps that own the channels; hermiq holds the
conversation.

## D5. Deterministic beats model, and the label says which

dossiq's `SentimentService` scores Dutch contactmoment text for klacht, advocaat and
wethouder, deterministically. It is explainable, testable, stable across releases, and
a handler can be told exactly why it fired.

A model sentiment score has none of those properties. It is still useful where nothing
deterministic exists, and it must never be presented as though it had them.

So: use the deterministic signal where the owning app supplies one, record that it was
used, and where a model produced the score, label it as a model output on every
surface that shows it. A reader must be able to tell the two apart without asking.

## D6. The external check is a tool, and its verdict is evidence

RX Mission documents a plan review from inside the case (DigEplan). The sweep notes it
is "the one VTH-specific external check named anywhere in the corpus", which is a fair
warning that this is a narrow capability and not a category.

Modelling it as a general "external review tool" rather than as a plan-review feature
keeps it honest: hermiq calls a tool the owning app declared, receives a verdict, and
records the verdict on the run with the reviewer's identity and time.

hermiq forms no opinion about a building plan. It carries somebody else's opinion into
a conversation and writes down whose it was.
