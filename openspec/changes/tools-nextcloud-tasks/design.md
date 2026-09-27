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
