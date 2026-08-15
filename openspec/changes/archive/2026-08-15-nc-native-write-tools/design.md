# Design — nc-native-write-tools

Five write tools across three Nextcloud subsystems, all reached through
Nextcloud's own abstractions, all default-denied, none able to delete.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Calendar / Contacts / Notes writes | **Imperative** — `HermiqToolProvider` | Side-effecting calls into Nextcloud subsystems. Owns no schema, no derived value, no lifecycle — the same legitimate external-integration exception `DeliveryService` already claims for Talk / Notifications / Mail / HTTP, and the same one the existing six read tools operate under. |
| Record of what an agent invoked | **Declarative** — existing run trace | Every invocation is already one `tool` step in `RunTraceCollector` with name, timing and outcome. Nothing new is recorded and no new schema is introduced. |

No `x-openregister-{lifecycle,aggregations,calculations,notifications,relations,widgets}`
block is added or modified.

## Why these are not office-suite concerns

Worth stating because the surrounding programme is about documents and office
suites: none of this touches one. Calendar is CalDAV/iCalendar through
`OCP\Calendar`, contacts are CardDAV/vCard through `OCP\Contacts`, notes are the
Notes app's own storage. Collabora, LibreOffice and Euro-Office are nowhere in
these call paths, and ADR-087 does not apply. The per-suite question is
documents-only.

## The tools

| Tool | Interface | scope | Guard |
|---|---|---|---|
| `createCalendarEvent` | `ICalendarEventBuilder` / `ICreateFromString` | `create` | calendar owned by acting user **and** `ICalendarIsWritable` |
| `upsertContact` | `IContactsManager::createOrUpdate()` | `create` | target book supplied by argument, resolved against books the acting user owns; system book and shared books refused |
| `listNotes` | Notes, lazy | `read` | acting user's notes only |
| `createNote` | Notes, lazy | `create` | acting user's notes only |
| `updateNote` | Notes, lazy | `update` | note owned by acting user |

Authorisation happens **before** any data access, by scoping to
`$userSession->getUser()->getUID()` — the rule the class docblock already
documents and the `nc-native-tools` spec already requires. `invokeTool()`
continues to never throw; every failure returns a structured error envelope.

## Attendees reach third parties, so the tool is destructive

Creating an event with attendees makes Nextcloud dispatch iMIP invitation emails.
`hermiq.createCalendarEvent` therefore has an outbound effect that its name does
not suggest, and the failure to avoid is an operator granting it believing it only
writes to the user's own calendar — a grant whose blast radius is invisible at the
point of granting.

Refusing attendees would remove the risk and most of the value with it: scheduling
a meeting with people is what meeting scheduling is. So attendees are supported and
the risk is made **visible** instead, three ways, none of which is sufficient alone:

1. **`destructiveHint: true`** — the same classification `sendMail` carries. The
   tool is default-denied, reachable only by explicit exact-id grant, and
   approval-gated at `FacadeToolInvoker`. This is what the grant editor renders, so
   an operator sees a destructive tool rather than an innocuous one.
2. **The description states the outbound effect in its first sentence.** A
   description that buries "sends invitations" in a third clause is a description
   nobody reads that far into.
3. **The invocation record captures the attendee count** — not the addresses, per
   the no-content rule. "This agent invited eleven people to something" is the fact
   an overseer needs; who they were is in the calendar, under its own access rules.

An event with no attendees still creates no outbound traffic, so the common local
case costs nothing extra beyond the grant.

## Notes has no OCP contract

Notes exposes no `OCP` interface, so the provider resolves the Notes service
lazily from the server container behind a `class_exists()` guard and returns a
structured error when it is absent. This is not a new pattern: `listDeckBoards`
already does exactly this for Deck's `BoardService`, and Hermiq boots and keeps
working when Deck is not installed.

The cost is honest: an internal API can change across Notes releases with no
deprecation contract. The mitigation is that absence and shape-mismatch both fail
**soft** into the structured error envelope rather than breaking a run, and the
guard is asserted by test.

## Marking and recording (ADR-088)

Three subsystems, and **no single marking mechanism covers them** — which is why
ADR-088 mandates the mark rather than a specific mechanism:

| Object | Mark | Why this one |
|---|---|---|
| Note | Nextcloud system tag | Notes are files. Visible and filterable in Files, where the user already looks. |
| Contact (vCard) | `X-` property on the object | System tags do not apply to CardDAV objects. An `X-` property travels with the card through sync and export. |
| Calendar event (iCalendar) | `X-` property on the object | Same reason; same benefit. |

Inventing one fleet-wide mechanism would mean a side table mapping object UIDs to
"an agent wrote this", which is a second source of truth that no client, sync
target or export would ever carry. The native mechanism is the one that survives
the object leaving Nextcloud.

Two rules, both easy to soften and neither of which may be:

- **Marking is part of the write, not a follow-up.** An object that exists unmarked
  even briefly is one the user can mistake for their own.
- **A failed mark is a failed write.** The tool reports failure. Reporting success
  on an unmarked object produces the one artefact nothing downstream will
  re-examine.

Recording is the other half and is equally required: the `tool` trace step carries
the written object's identity (file id or object UID) and the acting agent, so an
operator can follow a record to the object. It carries **no field values** — no
contact details, no event description, no note body — consistent with the line
`nc-mail-read-tools` draws for reads.

The mark is a hint, not a guarantee: a user can remove a system tag, and an `X-`
property survives most but not all client round-trips. Hermiq's record is the
authoritative account; the mark is what makes it discoverable from the object.

## No delete verb

Create and update only. Deleting a calendar entry, contact or note is a
destructive act on the user's own data with no undo in any of the three
subsystems, and nothing in the driving use cases needs it. Adding it later is a
deliberate decision that should be argued on its own, not inherited from a change
about being able to write at all.

## Grants and the oversight surface

All four write tools carry honest hints and therefore classify as write. Two
consequences follow automatically, and both are verified rather than assumed:

1. `ToolGrantResolver` **default-denies** them — an empty `Agent.tools` does not
   reach them, and a schema wildcard cannot expand to them. Only an explicit
   exact-id grant does.
2. `FacadeToolInvoker` routes each invocation through the approval gate unless
   the grant waives it.

They appear in the agent detail page's Tool governance grant editor without any UI
work: `ToolOversightController::toolCatalog()` enumerates
`ToolRegistryFacade::listTools([])`, and `ToolGrantEditor.vue` renders whatever
that returns. Per `hermiq-prefer-tool-hints`, classification **fails closed** on a
hint-less non-3-segment id, so a forgotten `scope` produces an over-restricted
tool rather than an under-restricted one — the safe direction, but the hints are
declared correctly regardless so the grant editor tells the truth.

## Verification

- Each tool's guard asserted by unit test to enforce the same scope as its
  subsystem's own access rules: a calendar the user does not own, a read-only
  calendar, another user's address book, the system address book, and another
  user's note each refused.
- `createCalendarEvent` with an attendee list asserted refused, with a test that
  would fail if attendees were silently dropped instead of rejected — dropping
  them quietly is a worse outcome than refusing.
- Notes absent: assert a structured error, not an exception, and assert Hermiq
  still completes the run.
- All five ids present in `toolCatalog()` with correct classification; the four
  write tools asserted default-denied under an empty `Agent.tools`.
- Zero new PHPUnit failures against a self-measured baseline; scoped `phpcs`
  clean.

## Seed data

None. No OpenRegister schema is introduced or modified.

## DEFERRED_QUESTIONS

1. ~~Should `updateNote` exist?~~ **RESOLVED (2026-08-15): yes, included.** A note
   is the one place a human expects an assistant to keep something current. Residual
   risk stays on the record: Notes has no version history, so an overwrite is not
   recoverable the way an in-place document edit is — the approval gate and the
   ADR-088 tag are the only controls, and neither restores lost prose.
2. ~~**Which address book receives an upserted contact when the user has several?**~~
   **RESOLVED (2026-08-15): agent-supplied address book id.** People organise
   contacts across books and a fixed target makes the tool useless for the case it
   exists to serve. The widening this causes — one grant reaching every book the
   user owns — is closed by the **argument-scoped grant form** already built for
   exactly this shape (`hermiq.upsertContact?addressBookId=…`, enforced at
   `FacadeToolInvoker` before the facade call, from `hydra-console-agent-leaves`):
   an operator who wants a single-book agent pins the argument in the grant, and
   an unconstrained grant still means every book the user owns, explicitly. Server
   side, the id is resolved only against books the acting user owns — the system
   book and shared books are refused regardless of what the grant permits, because
   narrowing a grant never substitutes for the ownership guard.
3. ~~Refuse attendees, or accept and classify destructive?~~ **RESOLVED
   (2026-08-15): accept, `destructiveHint: true`.** Refusing would have left the
   tool unable to do the thing it exists for. The invisibility concern is answered
   by classification + description + attendee count in the record, not by removing
   the capability. See §Attendees.
