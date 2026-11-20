# Proposal: ai-oversight-advisory-approvals

Refs: hydra ADR-041 (cross-app commands via typed events) · ADR-022 (apps consume OR abstractions) ·
procest `openspec/changes/page-topology-cleanup` (E1) · hermiq `openspec/specs/human-approval-gate/spec.md`.

## Why

Hermiq's `human-approval-gate` spec already claims EU AI Act Article 14, and its
`Approval` object already is "a recorded human decision, durably kept". But it
only covers the decisions hermiq **gates**: an agent wants to run, or to call a
tool, and a human must say yes before anything happens.

The fleet's other Art. 14 evidence is the opposite shape. When procest's AI
proposes a classification for an incoming document, nothing is blocked — a
handler sees a suggestion, accepts it, rejects it, or types something else, and
the work continues either way. That decision is exactly as much human-oversight
evidence as an approval, and hermiq has nowhere to put it.

So procest grew its own `aiAuditEntry` register and its own oversight page. Any
other app doing AI against its own objects would do the same, and the fleet's
Art. 14 trail would end up one silo per app — which is the arrangement that
makes an audit unanswerable.

## What changes

**One schema, not two.** `Approval` gains a sixth `sourceType`, `advisory`,
alongside the five gating ones. A gate and a record of a decision are the same
fact at different times; splitting them into separate schemas would split the
Art. 14 trail in two and force every future compliance query to union them.

**A fourth status: `overridden`.** A gate is binary — the action ran or it did
not. An advisory suggestion has a third outcome: the human took it seriously and
supplied a *different* value. Mapping that onto `denied` would erase the single
most useful signal an oversight audit has, namely that a human corrected the
model rather than ignoring it. `overridden` is advisory-only, terminal at
creation, and unreachable from `pending`.

**`advisoryContext`**, mirroring the gating variants' own context objects
(`flowContext`, `webhookContext`, `draftPayload`). It carries what the model
proposed and what the human did: `originApp`, `subjectType` + `subjectId`,
`model`, `suggestion`, `confidence`, `actualValue`, `responseTimeMs`.

**A typed cross-app event.** `AiOversightRecordedEvent` — procest dispatches,
hermiq's listener writes. Consumer apps never write into hermiq's register
directly; that is ADR-041, and it is what decidesk's `DecisionRequestedEvent`
already established in this fleet.

**A separate surface.** `/ai-oversight` is a dashboard filtered to
`sourceType=advisory`, deliberately NOT merged into `/approvals`. That page is an
inbox of pending decisions somebody must act on; this one is a closed record
nobody can act on. Mixing an actionable queue with an audit log makes both harder
to read, and an auditor scrolling past live work to find evidence is how evidence
gets missed.

## Decisions worth recording

**`advisoryContext.subjectId` is deliberately not a `$ref`.** Hermiq does not
resolve another app's register, and must not need to: the log has to stay
readable after the origin app is uninstalled. The subject is carried verbatim.

**The event takes one associative payload, not positional scalars.** decidesk's
`DecisionRequestedEvent` is the precedent for cross-app contracts here, and its
own docblock records the trap it fell into: ten positional parameters became a
published contract that could not be regrouped without silently breaking every
consumer at runtime. Consumers still construct this through a class-string so
they stay installable without hermiq — one array keeps that property while
letting the key set grow without shifting a position.

**A refused record is refused loudly, not stored half-formed.** A record missing
`originApp` / `subjectType` / `subjectId` / `humanAction` cannot say which
decision it documents. Storing it anyway would make the trail *look* complete,
which is worse than a gap.

**A storage failure never fails the origin app.** By the time the event fires the
human has already acted; raising would turn an audit outage into a functional
one. The consumer reads `isHandled()` and can retry.

**Advisory recording lives in its own service, not on `ApprovalService`.** That
class is the gate: reviewers, delivery, `pending → approved/denied`, resuming
what was blocked. Advisory does none of it. Threading it through would mean
guarding half of a 1700-line service with "unless advisory".

## Affected Projects

- [x] hermiq — schema, event, listener, service, surface (this change)
- [x] procest — dispatches the event, migrates its existing entries, retires its page (its own change, E1, lands after this)

## Out of scope

- Migrating procest's existing `aiAuditEntry` objects. That repair belongs in
  **procest**, replaying its own records through this same event, so old evidence
  travels the identical path as new evidence and hermiq never reads another app's
  register.
- Any change to the gating variants. `Approval`'s existing five source types and
  its `pending → approved/denied` machine are untouched.
