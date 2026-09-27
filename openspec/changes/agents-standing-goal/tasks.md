# Tasks: agents-standing-goal

Kind: code. Size M. Row `hermiq:dm-standing-goal`.

## Implementation tasks

### Task 1: The Goal schema and its lifecycle
- **spec_ref**: `openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN Goal exists with its lifecycle, the cascade on agentId and the limits on interval and turns
- [ ] Implement
- [ ] Test (npm run check:register; seed of the example goal)

### Task 2: Goal turns on the dispatcher, continuing one session
- **spec_ref**: `openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/GoalService.php`
- **acceptance_criteria**:
  - GIVEN a due goal WHEN the job runs THEN the turn passes the three gates and continues the goal's session
- [ ] Implement
- [ ] Test (PHPUnit for due selection, each gate and session continuation)

### Task 3: The checks and the notifications
- **spec_ref**: `openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002`
- **files**: `lib/Service/GoalCheckService.php`, `lib/Service/EvalScoringService.php` (reuse only), `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN an objectCount check WHEN the count hits the target THEN reached and a notification; GIVEN the turn limit WHEN used THEN exhausted and a notification
- [ ] Implement
- [ ] Test (PHPUnit with a real ObjectService double for the count and a stubbed judge)

### Task 4: Set and stop a goal in the session
- **spec_ref**: `openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003`
- **files**: `src/modals/GoalFormModal.vue`, `src/views/Chat.vue`, `lib/Controller/GoalController.php`, `appinfo/routes.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a session WHEN a goal is set THEN the header shows it; GIVEN another user WHEN they stop it THEN 404
- [ ] Implement
- [ ] Test (PHPUnit on the controller guards; Playwright for set and stop)

## Verification
- [ ] `openspec validate agents-standing-goal --type change --strict` passes
- [ ] A live check: a goal with an objectCount check reaches its target on a test register
