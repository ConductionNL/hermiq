# Design — nc-mail-read-tools

Three read-only tools over Nextcloud Mail's internal API, plus the governance
that a tool grant alone cannot provide.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Mail reading | **Imperative** — `HermiqToolProvider` | Side-effecting calls into a Nextcloud subsystem (and, beneath it, IMAP). Owns no schema, no derived value, no lifecycle — the same external-integration exception `DeliveryService` claims for the outbound half of mail. |
| Authorisation to read mail at all | **Declarative** — existing AI-feature governance register | "This agent may read mail" is a registered, reviewable capability decision, not a service-side conditional. |
| Invocation record | **Declarative** — existing run trace | One `tool` step per call, with name, timing and outcome. This change adds fields to what is recorded, not a new recording mechanism. |

No `x-openregister-{lifecycle,aggregations,calculations,notifications,relations,widgets}`
block is added or modified.

## Why the internal API, and what it costs

Nextcloud Mail exposes no `OCP` contract. The options were an internal-API call,
raw IMAP with separately-held credentials, or nothing. Raw IMAP is strictly worse:
it needs credentials Hermiq has no business holding, and it bypasses Mail's own
account model and access checks. The internal API keeps the user's account
configuration, and its access scoping, where it already lives.

The cost is real: no deprecation contract, so a Mail release can change the shape
underneath us. Mitigations, all of them the Deck pattern already in the file:

- Lazy resolution from the server container behind `class_exists()`.
- A shape probe before use; a mismatch returns a structured error rather than a
  fatal.
- `invokeTool()` still never throws — Hermiq boots, and runs complete, when Mail is
  absent or has moved.
- A test asserting the absent path, so "Mail is gone" is a covered case rather than
  a discovered one.

## The exposure that is actually new

Hermiq already redacts **egress**: `DeliveryService` runs `RedactionService` before
output crosses the instance boundary by email or webhook, and leaves Talk and
notifications unredacted because they never leave. That control is in place and
this change does not weaken it.

What this change introduces is **inference-path exposure**. A message body read
into a run travels to whatever engine that run uses. On a local engine it stays on
the instance. On a hosted provider a third party processes the user's
correspondence — and no existing control speaks to that, because until now no tool
read personal correspondence into context.

Three consequences, all of them requirements rather than advice:

1. **A tool grant is not sufficient authorisation.** Mail reading must be enabled
   as a registered AI feature, so that the decision is made once, deliberately,
   by someone accountable — not assembled implicitly by ticking a box in a grant
   editor.
2. **The engine is part of the record.** An oversight entry for a mail read that
   does not say which engine ran it cannot answer "who saw this inbox".
3. **The record must not quote the mail.** Account id, mailbox, message id and
   counts only. An art.12 record that copies subjects and bodies into a log turns
   the oversight surface into a second copy of the inbox, in a place with different
   access rules.

## Bounded by construction

`listMailMessages` takes a mailbox and a page, with a hard maximum page size
enforced server-side. There is no form of any tool that returns a whole mailbox,
and no parameter an agent can set to remove the bound. This is not about
token cost: an unbounded listing is the difference between an assistant reading a
thread and an assistant enumerating an inbox.

## Refusals

- **No attachment bytes.** Metadata only — name, size, MIME. Attachments are the
  densest personal data in a mailbox and the least necessary for the driving use
  cases.
- **No credentials, no server settings.** `listMailAccounts` returns identity, not
  configuration. Mail stores IMAP passwords; nothing here goes near them.
- **No other user's accounts.** Every call scopes to
  `$userSession->getUser()->getUID()` before any data access, per the standing IDOR
  requirement.
- **No write verbs.** No delete, move, flag, mark-read or draft. Marking a message
  read is deliberately excluded: it is a visible change to the user's own mailbox
  state, made by something the user is not watching.
- **No chaining to `sendMail`.** These tools return content; they never compose,
  address or dispatch. `sendMail` is unchanged and remains separately granted.

### HTML bodies

Text is the default; HTML is returned only when a caller asks for it. Three
constraints ride with that, and none is optional:

- **Returned unsanitised, and marked unsanitised in the response shape.** We do not
  own an HTML sanitiser and should not acquire one for a secondary use case. What
  we can guarantee is that no consumer receives HTML that *looks* vetted.
- **Hermiq never renders it.** Remote references in a mail body are a tracking
  surface, and rendering is where that surface activates. Hermiq passes bytes; any
  consumer that renders them owns that decision knowingly.
- **It widens the prompt-injection surface, and this is the part worth stating
  plainly.** A mail body is attacker-controlled text going into a model's context —
  true of the text body too. HTML makes it worse in a specific way: markup can hide
  instructions from a human while leaving them fully legible to the model
  (white-on-white text, `display:none`, off-screen positioning). A human reviewing
  the same message and approving an action sees a different document than the model
  did. That is not mitigated by sanitising, because the text is still there once
  markup is stripped — it is mitigated by never letting a mail body's content
  authorise anything, which is what the approval gate and default-deny on writes
  already provide.

### The read-plus-send combination

One agent granted both mail reading and `sendMail` can read a user's
correspondence and send it elsewhere. That is an exfiltration primitive, and it is
also exactly what a reply assistant is, so it is not forbidden.

It is made **visible**: because both halves are individually granted and mail
reading additionally requires an AI-feature authorisation, an operator cannot
assemble the combination without two deliberate acts, one of which is a registered
capability decision. What must never happen is the combination arriving as a side
effect of a convenience grant — which is why no wildcard grant can expand to reach
these tools, and why `sendMail` gains nothing from this change.

## Grants and the oversight surface

All three tools are `scope: 'read'`, `readOnlyHint: true` — honest, and therefore
**not** default-denied by write classification. This is worth being explicit about
rather than quietly relying on: the grant default that protects the write tools in
`nc-native-write-tools` does **not** protect these. The AI-feature gate is what
carries that weight here, and it is a requirement in the spec for that reason.

They appear in the agent detail page's Tool governance grant editor with no UI
work — `ToolOversightController::toolCatalog()` enumerates
`ToolRegistryFacade::listTools([])` and `ToolGrantEditor.vue` renders it — and each
is individually grantable. That is verified by test, not assumed.

## Verification

- Absent-Mail path returns a structured error and the run completes.
- Shape-mismatch path (internal API moved) returns a structured error, not a fatal.
- Another user's account, mailbox and message each refused, touching no data.
- Attachment bytes asserted absent from every response shape.
- Page size bound asserted enforced server-side and not overridable by argument.
- With the AI feature disabled, all three tools refuse **even when granted** — the
  positive control is that they succeed once it is enabled, so the gate is shown to
  be load-bearing rather than merely present.
- An invocation record asserted to contain the engine identity and to contain no
  subject, body or address.
- All three ids present in `toolCatalog()`, individually grantable.
- Zero new PHPUnit failures against a self-measured baseline; scoped `phpcs` clean.

## Seed data

None. No OpenRegister schema is introduced or modified.

## DEFERRED_QUESTIONS

1. **Should mail reading be restricted to local engines only?** Provisional
   decision: no — allowed on any engine, but the engine is recorded and the AI
   feature must be enabled. A hard local-only rule would be the stronger privacy
   position and would make the capability unusable on most current deployments.
   Affects: proposal, spec requirement on engine recording.
2. ~~Text only, or also HTML?~~ **RESOLVED (2026-08-15): text by default, HTML on
   explicit request.** Layout carries meaning often enough — an invoice table, a
   formatted decision letter — that text-only extraction loses real information.
   Constraints that come with it, in §HTML bodies: returned unsanitised and marked
   as such, never rendered by Hermiq.
3. ~~Should the mailbox owner be notified?~~ **RESOLVED (2026-08-15): no
   notification in this change.** Visibility stays in the operator oversight
   surface. The counter-argument is not withdrawn and is recorded deliberately:
   the mailbox owner is the data subject, and an oversight surface they never open
   is not transparency *to them*. If a DPO or a deployment raises this, a periodic
   digest — rather than a per-read notification, which a reply assistant would turn
   into noise nobody reads — is the option to reach for first.
