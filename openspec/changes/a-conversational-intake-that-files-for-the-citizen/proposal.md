---
kind: code
---

# Proposal: a-conversational-intake-that-files-for-the-citizen

Round 4 discovery sweep, cluster 46 "AI, and what it is allowed to read"
(`procest/_round4/discovery/build-plan.md` in ConductionNL/market-intelligence,
2026-09-14). Owner hermiq on **decision D13**. Umbrella:
`competitor-parity-2026-09`.

## Summary

A citizen describes a problem in their own words on any channel, and the assistant
either answers it or files it correctly. hermiq runs the conversation and the
classification. The app that owns the record decides what is created.

## Why

The lane's clause on the lead candidate names the stake: **"the digitale balie every
gemeente is being sold, and the row that decides whether a case system or a separate
chatbot owns it"**. Every municipality in the Netherlands is currently being quoted
for a chatbot by somebody. The question this candidate settles is whether that
chatbot is part of the platform or a separate purchase that then needs integrating.

hermiq already answers the inside-out half. `case-assistant-surface` gives a leaf app
a grounded, tool-free chat about the object on screen, and its tool-free property is
deliberate: "a leaf app embedding a chat box on a case does not want that box able to
act on the case". That is exactly right for a handler reading a case, and exactly
wrong for a citizen who has not got one yet. Intake has to be able to file.

The sweep read dossiq `no` on it, with the note "AssistantController.php assists the
handler". The assistant we have helps the person behind the desk. This one stands in
front of it.

## The candidates, with their lane citations

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-intake-9 | A conversational intake answers or files on the citizen's behalf, on every channel. | should | none, jira-service-management documented | `intake.tsv:13` |
| C-communication-64 | The product tells the handler the writer is upset, from what they wrote. | should | none, jira-service-management documented | `communication.tsv:76` |
| C-integrations-1 | A building plan is checked against the rules by a plan-review application from inside the case. | could | none, rx-mission documented | `integrations.tsv:33` |

**All three have no driven passer**, admitted under **decision D21** and labelled in
the spec. That is unusual and worth saying plainly: this is the least measured change
in hermiq's wave 3, and its requirements rest on vendor documentation. Jira Service
Management's virtual service agent is documented across a dozen pages, from intents
and the flow builder to the portal, widget, e-mail, Slack and Teams channels.
RX Mission's plan review is one documented page, `/modules/ Koppelingen`.

**`C-communication-64` is the exception in a second way: dossiq already passes it,
and better.** `lib/Service/Kcc/SentimentService.php` scores Dutch contactmoment text
for klacht, advocaat and wethouder and returns an escalation level,
**deterministically rather than by model**. The sweep's own note reads "and dossiq has
the better shape already". So this change does not replace it. It requires that the
conversational intake **uses** the deterministic signal where one exists, and reaches
for a model only where none does.

## What hermiq builds

- **An intake conversation that can file.** A surface distinct from
  `case-assistant-surface`: it holds a conversation with a person who has no record
  yet, and may create one through a declared intake tool of the owning app.
- **A classification with a confidence and an abstention.** The assistant proposes
  what kind of request this is. Below a threshold it says it does not know and hands
  over, rather than filing a guess.
- **A handover that never dead-ends.** Every path reaches either a filed request or a
  human, with what was said carried across. A citizen who gives up is the failure this
  prevents.
- **The same conversation on every channel.** Portal, e-mail and chat reach one
  intake, so a person who starts by e-mail and continues in the portal is not starting
  again.
- **A deterministic signal preferred over a model one.** Where the owning app supplies
  an escalation signal, the conversation uses it and records that it did. A model
  sentiment is a fallback, and is labelled as a model output wherever it is shown.
- **An external check called from inside the conversation.** A declared external
  review, such as a building plan check, is a tool the owning app declares. hermiq
  calls it, carries its verdict into the conversation and records it on the run.

## How dossiq consumes it

1. dossiq declares an intake tool: what may be created, with which fields, by whom.
   hermiq calls it. dossiq validates and creates.
2. dossiq declares its request catalogue, so the classification proposes from the
   municipality's own list rather than from a model's idea of one.
3. dossiq's `SentimentService` stays the escalation signal on a contactmoment. hermiq
   reads it and does not replace it.
4. A handover lands in dossiq's existing routing. hermiq creates no queue.

## The existing specs this extends

- `case-assistant-surface` is untouched and **stays tool-free**. Its
  `tools: ['__none__']` sentinel is the guarantee a chat box on a case cannot act on
  the case, and this change must not weaken it. Intake is a separate surface with a
  separate, narrowly declared tool.
- `agent-guardrails`, whose prompt-injection filtering applies here as it does
  everywhere. An intake surface takes text from the public, which makes it the most
  exposed surface hermiq has.
- `ai-feature-governance`, which registers intake as its own feature with its own risk
  category. An assistant that files on a citizen's behalf is not a minimal-risk
  feature and the register must be able to say so.
- `run-audit-log`, which records the conversation and the filing as a run.

## Size and dependencies

**Size: L.** A conversational surface, a classification with abstention, a channel
join, a handover path and an external tool call.

**Depends on:** `the-declared-tool-surface-and-the-prompt-library` for the tool
declaration shape, and `what-the-model-reads-and-what-is-kept` for the retention on
conversations that contain what a citizen typed.

## What this change does not do

- It does not decide what gets created. The owning app validates and creates. hermiq
  proposes.
- It does not replace a working deterministic signal with a model. Where dossiq
  already answers better, hermiq reads the answer.
- It does not perform a plan review. RX Mission's capability is an external
  application; this change calls a declared tool and carries its verdict.
