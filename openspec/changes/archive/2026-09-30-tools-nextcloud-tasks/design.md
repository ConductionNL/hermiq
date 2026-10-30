# Design: tools-nextcloud-tasks

Kind: code. Size M. A `TaskWriteService` beside `CalendarWriteService`, a descriptor list merged into `HermiqToolProvider`, and three dispatch cases. It follows the archived `nc-native-write-tools` design point by point.

## Context at development db6b74dc

- `lib/Service/NcNative/CalendarWriteService.php:76-102` `create()`, `:111-127` `validate()`, `:140-185` `store()` (build, mark in the same serialised object, `createFromString()`), `:199-220` `resolveWritableCalendar()` (skips anything that is not `ICreateFromString` or is not writable), `:234-247` `withAgentProperty()`.
- `lib/Service/NcNative/NcNativeWriteService.php:55-148` the facade the provider calls; `lib/Service/NcNative/ErrorEnvelopeTrait.php` the error envelope.
- `lib/Mcp/NcNativeWriteToolDescriptors.php:53-97` the `createCalendarEvent` descriptor with reach, hints and a description that states the visible effect first.
- `lib/Mcp/HermiqToolProvider.php:648-657` `getTools()` merges descriptor lists; `:675-765` `invokeTool()`; `:890-906` `listCalendarEvents()` over `ICalendar::search()`.
- `lib/Service/Engine/FacadeToolInvoker.php:232-235` `ARTEFACT_WRITE_TOOL_IDS`, the tools that receive the run-injected `agentId`.
- `lib/Service/NcNative/AgentArtefactMarker.php` `OBJECT_PROPERTY` and `objectPropertyValue()`.
- `composer.lock:3715-3716` `nextcloud/ocp` v34.0.3.
- `openspec/changes/archive/2026-08-15-nc-native-write-tools/specs/nc-native-tools/spec.md:117-186`.

## D1. Task lists are calendars with VTODO

A Nextcloud task list is a CalDAV calendar whose component set includes VTODO. `TaskWriteService` finds them through `ICalendarManager::getCalendarsForPrincipal('principals/users/{uid}')`, the IDOR scope every calendar tool uses, and keeps only calendars that support VTODO.

For writing it keeps only the user's own lists: writable (`ICalendarIsWritable`) and not shared with the user by someone else. `resolveWritableCalendar()` checks writability but not ownership today, while the `createCalendarEvent` description says "Only calendars the user owns"; the task tools add the ownership check, using the OCP 34 calendar interface for shares where it has one, and otherwise the calendar's owner principal. Task 1 verifies which of the two OCP 34 offers before anything else is built.

### Task 1 outcome (verified 30 Sep 2026 against nextcloud/server tag v34.0.3, the `nextcloud/ocp` version in composer.lock)

- **Shared-in lists.** OCP 34 has `OCP\Calendar\ICalendarIsShared::isShared()` (since 31). `OCA\DAV\CalDAV\CalendarImpl::isShared()` delegates to `Calendar::isShared()`, which is true when the calendar's `owner-principal` differs from the principal it is listed for. So a list is the user's own when it implements `ICalendarIsShared` and `isShared()` is false; a calendar that does not implement the interface is treated as not own.
- **Component set.** No OCP interface exposes a calendar's supported component set. The DAV backend does: `CalDavBackend::getCalendarsForUser($principalUri)` returns every calendar of the principal, own and shared-in, with `{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set`, keyed by the same `uri` that `CalendarImpl::getUri()` returns. A task list is a calendar whose set holds `VTODO`.
- **createFromString does not replace.** `CalendarImpl::createFromString()` goes through `createFromStringInServer()`, which calls Sabre `Server::createFile()` on `calendars/<user>/<calendar>/<name>`, a CREATE; `CalDavBackend::createCalendarObject()` refuses a UID that already exists in the collection (RFC 4791 no-uid-conflict, a Conflict that surfaces as `CalendarException`). So completing goes through `CalDavBackend::getCalendarObject()` and `updateCalendarObject($calendarId, $objectUri, $data)`, resolved lazily behind `class_exists()` and a method probe, like `NotesWriteService` resolves Notes. `$calendarId` is `ICalendar::getKey()` of a calendar the calendar manager returned for the user's own principal, and the object uri comes from `ICalendar::search()` on that same calendar, so the backend is never handed an id the user was not already scoped to.
- **Consequence.** With the backend unavailable, `listTasks` and `createTask` answer `tasks_not_available` (they cannot tell a task list from an event calendar) and `hermiq.completeTask` is left out of the catalogue. The DAV app ships with every server, so on a normal instance all three are offered.

### Where the code sits (changed from the plan above at HEAD 7175ff2b)

The three tools are routed by `HermiqToolProvider::routed()` to `TaskWriteService`, the way the knowledge-graph tools reach `GraphTools`, instead of through `NcNativeWriteService` and the dispatch switch: the switch already carries a complexity suppression and the provider is at its coupling limit. The iCalendar text is built and rewritten by `TaskCalendarObject` without Sabre VObject, which is not in this app's vendor tree: completing edits only the four property lines of the top-level VTODO and leaves every other line byte for byte, which is a stronger guarantee than re-serialising. The run record gets `artefact: {type: task, id: <listUri>/<uid>}`, which `FacadeToolInvoker` already lifts as identity only.

## D2. Listing

`hermiq.listTasks` `{status: open | completed | all, dueBefore, taskListUri, limit}` searches each list for VTODO components through `ICalendar::search()` and returns at most 50 tasks: `uid`, `summary`, `due`, `status`, `priority`, `list`. Descriptor: reach `user`, `scope: read`, `readOnlyHint: true`.

## D3. Creating

`hermiq.createTask` `{summary, due, description, priority, taskListUri}`. The VTODO is built by hermiq (OCP has an event builder, not a task builder), serialised through Sabre's VObject that Nextcloud ships, with `X-HERMIQ-AGENT-AUTHORED` inside the same object, and stored with `createFromString()` under a `hermiq-<random>.ics` name, as events are. Validation mirrors `CalendarWriteService::validate()`: a summary is required, `due` must be an ISO-8601 date or date-time, `priority` 1 to 9.

Descriptor: reach `instance`, `scope: create`, `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: false`. Reach is `instance` and not `user` because a list the user owns can be shared with colleagues, who then see the new task; the description says so in its first sentence: "Creates a task in one of your own task lists. People you share that list with will see it."

## D4. Completing

`hermiq.completeTask` `{uid, taskListUri}` loads the task from the user's own list, sets `STATUS:COMPLETED`, `COMPLETED` to now in UTC and `PERCENT-COMPLETE:100`, adds or replaces the agent property, and writes the object back under its own URI. Everything else in the object stays as the user wrote it.

Writing back needs a way to replace an existing calendar object. `createFromString()` is the one write OCP guarantees; whether it replaces an existing URI in OCP 34 is verified in Task 1. If it does not, the service resolves the DAV app's calendar backend lazily behind a `class_exists()` guard and updates the object there, the pattern `NotesWriteService` uses for the Notes app, and returns `tasks_not_writable` when neither path is available. The tool is then not offered, because a write capability that cannot write is not exposed (`nc-native-write-tools`, "A capability cannot mark what it writes").

Descriptor: reach `instance`, `scope: update`, `readOnlyHint: false`, `destructiveHint: false`. Completing is not destructive: the user reopens a task in the Tasks app with one click and nothing is lost.

## D5. Marking and recording

`hermiq.createTask` and `hermiq.completeTask` join `ARTEFACT_WRITE_TOOL_IDS`, so the run injects the agent id into the mark. A failed mark is a failed write. The tool step records the list and the task's uid, and never its summary or description, as the archived requirement "Every write is recorded with the object's identity, and without its content" asks.

## D6. Honest errors

`no_task_list` "You have no task list. Create one in the Tasks app.", `task_list_not_writable` "That task list is shared with you by someone else and cannot be written.", `task_not_found`, `invalid_argument`, `artefact_not_marked`. `invokeTool()` never throws.

## Declarative versus imperative

Nothing is declared in the register: tasks live in CalDAV, not in OpenRegister. The descriptors are a constant list like `NcNativeWriteToolDescriptors`.

## Seed data

None: the tools act on the user's own CalDAV data. The Playwright check uses a task list created by the test fixture for the test user: "Werkvoorraad Burgerzaken" with the task "Terugbellen mevrouw De Vries over parkeervergunning", due next Friday.

## Risks

- A write back overwrites a change the user made a second earlier. Mitigation: the object is read and written in one call and keeps every property it does not touch.
- An agent marks the wrong task done. Mitigation: `completeTask` takes a uid from `listTasks`, it is default-denied and gated when un-granted, and the user reopens it in one click.
- OCP offers no replace. Mitigation: the lazy DAV path, and otherwise `completeTask` is not offered.
- Sabre VObject is shipped by the server, not promised by OCP, so a server upgrade can change it. Mitigation: parsing and serialising sit in `TaskWriteService` only, pinned by tests on fixture tasks exported from the Tasks app.
