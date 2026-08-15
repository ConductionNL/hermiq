# Tasks — nc-native-write-tools

## 1. Calendar write

- [ ] 1.1 Add `hermiq.createCalendarEvent` to `TOOL_DESCRIPTORS` with `scope: 'create'`, `readOnlyHint: false`, **`destructiveHint: true`**, and a description whose FIRST sentence states that creating an event with attendees dispatches invitation emails to them.
- [ ] 1.2 Implement the branch via `ICalendarEventBuilder` / `ICreateFromString`, resolving only calendars owned by the acting user and passing `ICalendarIsWritable`. Refuse subscriptions and read-only shares with a structured error.
- [ ] 1.3 Support attendees, and record the attendee COUNT (never the addresses) on the invocation record so an overseer can see that an agent invited people.

## 2. Contacts write

- [ ] 2.1 Add `hermiq.upsertContact` with `scope: 'create'`, `readOnlyHint: false`, `destructiveHint: false`.
- [ ] 2.2 Implement via `IContactsManager::createOrUpdate()` with the target address book supplied as a tool argument. Resolve it against the acting user's own address books only; refuse the system address book, any book shared from another user, and any unknown id. Default to the user's personal book when the argument is omitted.
- [ ] 2.3 Document in the tool description that the target is grantable-narrowable via the argument-scoped grant form (`hermiq.upsertContact?addressBookId=…`), and assert by test that such a grant is enforced at `FacadeToolInvoker` before the write.

## 3. Notes

- [ ] 3.1 Add `hermiq.listNotes` (`scope: 'read'`, `readOnlyHint: true`), `hermiq.createNote` (`scope: 'create'`) and `hermiq.updateNote` (`scope: 'update'`) descriptors.
- [ ] 3.2 Resolve the Notes service lazily from the server container behind a `class_exists()` guard, mirroring the existing Deck `BoardService` resolution. Return a structured error — never an exception — when Notes is absent.
- [ ] 3.3 Scope every notes call to the acting user; refuse a note the acting user does not own.

## 4. Guards and governance

- [ ] 4.1 Authorise before any data access in every new branch by scoping to `$userSession->getUser()->getUID()`; assert `invokeTool()` still never throws for any new branch.
- [ ] 4.2 Confirm no delete verb exists on any new tool: `grep -n "delete" lib/Mcp/HermiqToolProvider.php` shows no delete branch.
- [ ] 4.3 ADR-088 marking: system tag via `ISystemTagManager` / `ISystemTagObjectMapper` for notes; an `X-` agent-authored property on the vCard and iCalendar objects for contacts and events. Applied in the SAME operation as the write; a failed mark returns failure, never success.
- [ ] 4.4 Record the written object's identity (file id or object UID) and the acting agent on Hermiq's `tool` trace step for every write; assert no contact field, event description or note body reaches the record.

## 5. Verify

- [ ] 5.1 Unit-test each refusal: calendar not owned, calendar not writable, another user's address book, the system address book, another user's note — each returns a structured error and touches no data.
- [ ] 5.2 Assert `createCalendarEvent` is classified destructive and therefore default-denied and approval-gated; assert an attendee-bearing event records the attendee count and no addresses; assert an attendee-free event produces no outbound traffic.
- [ ] 5.3 Unit-test the Notes-absent path returns a structured error and the run still completes.
- [ ] 5.4 Assert all five ids appear in `ToolOversightController::toolCatalog()` and that the four write tools are default-denied under an empty `Agent.tools`, and reachable only via an explicit exact-id grant.
- [ ] 5.5 Assert each write tool routes through `FacadeToolInvoker`'s approval gate on first invocation.
- [ ] 5.6 Scoped `phpcs` clean; zero new PHPUnit failures vs a self-measured baseline; CHANGELOG entry.

## Acceptance criteria

- An agent can create a calendar event, upsert a contact, and list/create/update notes, each scoped to the acting user.
- Every written object carries its agent-authored mark from the moment it is visible, and a forced mark failure surfaces as a failed write rather than a silent success.
- Every write is recorded with the object's identity and the acting agent, and with none of the object's field values.
- No tool can delete anything.
- An event may carry attendees; because that dispatches invitations, the tool is classified destructive, default-denied and approval-gated, its description leads with the outbound effect, and the record carries the attendee count but no addresses.
- Notes being uninstalled degrades to a structured error and never breaks a run.
- All five tools appear in Tool governance with correct classification; the four write tools are default-denied and approval-gated.
