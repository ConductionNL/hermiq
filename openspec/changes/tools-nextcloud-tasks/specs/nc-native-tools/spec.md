# nc-native-tools Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-nextcloud-tasks

## Purpose

Agents read, create and complete tasks in the acting user's own CalDAV task lists, with honest hints and the agent-authored mark. Row `hermiq:tl-tasks`.

## ADDED Requirements

### Requirement: An agent can list the acting user's tasks (REQ-NCTASK-001)

Hermiq MUST expose `hermiq.listTasks`, which returns at most 50 tasks from the acting user's own task lists, filtered by status, due date and list, and MUST NOT return tasks of lists the user cannot read.

#### Scenario: A case handler asks what is still open
- GIVEN a case handler with the task list "Werkvoorraad Burgerzaken" and an agent granted `hermiq.listTasks`
- WHEN they ask "Welke taken staan nog open voor deze week?"
- THEN the answer lists the open tasks due this week, including "Terugbellen mevrouw De Vries over parkeervergunning"

### Requirement: An agent can create a task in the user's own list, marked as agent-authored (REQ-NCTASK-002)

Hermiq MUST expose `hermiq.createTask`, which writes a VTODO only into a task list the acting user owns and can write, with the agent-authored property inside the stored object. A failed mark MUST be reported as a failed write. The descriptor MUST be write-classified with reach `instance`, and its description MUST start by saying that people who share the list will see the task.

#### Scenario: A task for Friday
- GIVEN an agent granted `hermiq.createTask`
- WHEN a case handler says "Zet 'Besluit bezwaar Kerkstraat 12 versturen' op mijn takenlijst voor vrijdag"
- THEN the task appears in their Tasks app with due date Friday, and its stored object carries `X-HERMIQ-AGENT-AUTHORED`
- @e2e exclude reading the stored iCalendar bytes needs a CalDAV fetch; covered by PHPUnit on the payload handed to the store, and a Playwright check that the task shows in the Tasks app

#### Scenario: A list shared by a colleague is not written
- GIVEN a list "Team Vergunningen" that a colleague shared with the case handler
- WHEN the agent creates a task there
- THEN nothing is written, and the tool answers "That task list is shared with you by someone else and cannot be written."
- @e2e exclude needs two users and a shared list; covered by PHPUnit on list resolution

### Requirement: An agent can complete a task without losing what the user wrote (REQ-NCTASK-003)

Hermiq MUST expose `hermiq.completeTask`, which sets the task completed, keeps every other property of the task as it was, and adds the agent-authored property. When hermiq cannot replace an existing calendar object on the instance, it MUST NOT offer the tool.

#### Scenario: Done after the call
- GIVEN the open task "Terugbellen mevrouw De Vries over parkeervergunning"
- WHEN the case handler says "Die terugbeltaak is gedaan"
- THEN the task shows as completed in the Tasks app with its description unchanged

### Requirement: Task tools are default-denied, never delete, and record identity without content (REQ-NCTASK-004)

`hermiq.createTask` and `hermiq.completeTask` MUST be default-denied and MUST go through the approval gate when invoked un-granted. No task tool MUST delete a task. The run MUST record the list and the task's uid for each write, and MUST NOT record the task's summary or description.

#### Scenario: The grant editor shows the task tools honestly
- GIVEN an agent owner in the agent's Tool governance grant editor
- WHEN they look at the task tools
- THEN `hermiq.listTasks` shows as read with reach user, and `hermiq.createTask` and `hermiq.completeTask` show as write with reach instance, all ungranted by default

#### Scenario: The run record holds no task text
- GIVEN a run in which the agent created a task
- WHEN an auditor opens the run
- THEN the tool step shows the list and the task uid, and not the task's summary
- @e2e exclude reading the trace payload; covered by PHPUnit on the trace extra
