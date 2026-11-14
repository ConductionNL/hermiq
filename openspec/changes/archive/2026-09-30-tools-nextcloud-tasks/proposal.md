---
kind: code
---

# Proposal: tools-nextcloud-tasks

## Summary

An agent can list, create and complete tasks in the acting user's own Nextcloud task lists, the CalDAV lists the Tasks app shows. "Zet dit op mijn takenlijst voor vrijdag" and "Welke taken staan nog open voor deze week?" then work in the chat. The write tools are off until an agent owner grants them, say honestly that colleagues who share the list will see the change, carry the agent-authored mark on the task itself, and never delete a task.

## Why

One row, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:tl-tasks` | no | build: four competitors rate yes |

Competitor cells rated yes, quoted from the pack:

- nextcloud-assistant (assistant v4.0.0, context_agent v2.8.0): "add_task, list_tasks, complete_task, update_task, delete_task on CalDAV task lists (context_agent:ex_app/lib/all_tools/calendar.py:240-241,294-295,337-338,392-393,431-432)".
- hermes-agent v2026.9.24: "skills/apple/apple-reminders/SKILL.md:1-3 'add, list, complete' reminders (macOS); tools/kanban_tools_schemas.py:89 kanban_complete and :371 kanban_create". The pack notes there is no CalDAV there.
- copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/advanced-connectors: "Planner and To Do connectors create and complete tasks".
- n8n@2.40.7: "packages/nodes-base/nodes/Google/Task/GoogleTasks.node.ts:29, Microsoft/ToDo/MicrosoftToDo.node.ts:33 and Todoist/v2/TodoistV2.node.ts:61 are usableAsTool with create and update (complete) task operations". The pack notes there is no Nextcloud Tasks node.

## What hermiq already has

- The write-tool pattern for CalDAV: `hermiq.createCalendarEvent` builds an iCalendar object, injects the `X-HERMIQ-AGENT-AUTHORED` property into the same serialised object, and stores it with `ICreateFromString::createFromString()` in a writable calendar of the user (`lib/Service/NcNative/CalendarWriteService.php:76-102`, `:140-185`, `:199-220` `resolveWritableCalendar()` with `ICalendarIsWritable`, `:234-247` the mark). Descriptor and honest hints in `lib/Mcp/NcNativeWriteToolDescriptors.php:53-97`.
- `AgentArtefactMarker::OBJECT_PROPERTY` `X-HERMIQ-AGENT-AUTHORED` and its value with the agent id (`lib/Service/NcNative/AgentArtefactMarker.php`), and the run-injected `agentId` for artefact-writing tools (`lib/Service/Engine/FacadeToolInvoker.php:232-235`).
- The rules from the archived `nc-native-write-tools`: create and update only, no delete verb, every write marked in the same operation, a failed mark is a failed write, the write recorded with the object's identity and never its content (`openspec/changes/archive/2026-08-15-nc-native-write-tools/specs/nc-native-tools/spec.md:117-186`).
- `hermiq.listCalendarEvents` reads the user's calendars through `ICalendar::search()` (`lib/Mcp/HermiqToolProvider.php:890-906`). Nothing reads or writes VTODO tasks; the pack's search for Tasks and VTODO found nothing.

## What this change builds

1. `hermiq.listTasks`: open, completed or all tasks from the acting user's task lists, with due date, status and list, capped.
2. `hermiq.createTask`: a VTODO with summary, due date, description and priority in a task list the user owns and can write, carrying the agent-authored property.
3. `hermiq.completeTask`: marks one of the user's tasks completed (`STATUS:COMPLETED`, `COMPLETED`, `PERCENT-COMPLETE:100`) and adds the mark.
4. Honest descriptors: create and complete are write-classified with reach `instance`, because a list the user owns may be shared with colleagues; so both are default-denied and gated when un-granted.
5. The three tools in the grant editor and the run record, with the task's uid and list, never its text.

## Out of scope

- Deleting or reopening a task. No NC-native tool deletes user data.
- Task lists shared with the user by someone else. Only the user's own lists are written.
- Deck cards. Deck is a different app with its own tool (`hermiq.listDeckBoards`).
