---
kind: code
---

# Proposal: nc-native-write-tools

# Why

`HermiqToolProvider` exposes six Nextcloud-native tools (`nc-native-tools` spec):
`listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`, `sendMail`,
`listDeckBoards`. Read the list for what it can *change* and a strange shape
appears:

**Every capability is read-only except one — and that one is the irreversible
one.** An agent can send mail on the user's behalf but cannot create the calendar
event the mail is about, cannot save the contact it just mailed, and cannot write
a note about either. The one thing it can do is the one thing that cannot be
undone.

That is not a deliberate posture, it is the order the tools were built in. The
practical effect is that an assistant asked to do the obvious follow-through —
"zet die afspraak in mijn agenda", "sla dit nummer op bij Jansen", "maak er een
notitie van" — has to answer that it can only tell someone else to do it, while
being perfectly able to email them about it.

The interfaces are already present. `OCP\Calendar\ICreateFromString` and
`ICalendarEventBuilder` are vendored in the repo today; `OCP\Contacts\IManager`
already carries `createOrUpdate()` and the provider already injects it for
`searchContacts`. Notes has no OCP contract, but Deck has none either and the
provider already resolves `BoardService` lazily behind a `class_exists()` guard —
the pattern exists and is proven.

The governance to make writes safe also already exists and is doing nothing for
these capabilities: `ToolGrantResolver` default-denies write-classified tools,
`FacadeToolInvoker` routes them through the approval gate, and
`agent-tool-governance` gives them an art.12/14 oversight surface. Adding writes
here means declaring them honestly and letting machinery that already runs do its
job.

# What Changes

- **`hermiq.createCalendarEvent`** — creates an event in a calendar the acting
  user owns and can write to, via `ICalendarEventBuilder` / `ICreateFromString`.
  Writable calendars only, verified with `ICalendarIsWritable`, never a
  subscription or a read-only share.
- **`hermiq.upsertContact`** — creates or updates a contact in one of the acting
  user's own address books via `IContactsManager::createOrUpdate()`. The system
  address book and address books shared from other users are refused targets.
- **`hermiq.listNotes` / `hermiq.createNote` / `hermiq.updateNote`** — the Notes
  app, resolved lazily behind a `class_exists()` guard exactly as Deck already is,
  returning a structured error when Notes is not installed rather than throwing.
- **Attendees are supported, and the tool is classified `destructiveHint: true`
  because of them.** An event carrying attendees causes Nextcloud to dispatch iMIP
  invitation emails, so `createCalendarEvent` can reach third parties. Scheduling
  a meeting with people is most of what meeting scheduling is, so refusing
  attendees would have left the tool nearly pointless — but the outbound effect
  must not be invisible at the point of granting. It is made visible three ways:
  the destructive classification (default-denied, approval-gated, exactly as
  `sendMail`), a tool description that **states in its first sentence** that
  creating an event with attendees sends invitations, and an invocation record
  that captures the attendee count. An operator reading the grant editor sees a
  destructive tool; an operator reading the description learns why.
- **Everything written is marked and recorded** (ADR-088). Notes are files, so they
  take a Nextcloud system tag. Calendar events and contacts are CalDAV/CardDAV
  objects, which system tags do not cover, so they carry an `X-` property on the
  object itself — which has the useful property of travelling with the object
  through sync and export. Marking happens in the same operation as the write, and
  a write whose mark fails reports failure. Each write is recorded by Hermiq with
  the object's identity and never its field values.
- **No delete verb anywhere.** Create and update only, across all three
  subsystems. Deleting a user's calendar entry, contact or note is not a
  capability this change grants.
- **Honest hints, so grants work.** Each tool declares `scope: 'create'` or
  `'update'`, `readOnlyHint: false`, `destructiveHint: false`. All are therefore
  default-denied and approval-gated, and appear with write classification in the
  agent detail page's Tool governance grant editor.
- **The existing IDOR rule extends unchanged**: every call authorises by scoping
  to `$userSession->getUser()->getUID()` **before** any data access, and
  `invokeTool()` still never throws.

# Capabilities

**Modified Capabilities**
- `nc-native-tools` — gains write verbs on Calendar and Contacts, and Notes as a
  new NC-native subsystem.

# Impact

- `lib/Mcp/HermiqToolProvider.php` gains five descriptors and five branches (or a
  sibling provider class if the file grows unreasonably — the DI alias stays
  singular per ADR-034/035).
- No new OpenRegister schema; no seed data.
- No change to `sendMail`, which keeps its existing classification.
