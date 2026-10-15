# Tasks: operations-backup-and-restore

Kind: code. Size L. Row `hermiq:dm-backup-restore`.

## Implementation tasks

### Task 1: Backup settings, admin only
- **spec_ref**: `openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004`
- **files**: `lib/Controller/Settings/BackupSettingsController.php`, `appinfo/routes.php`, `src/views/AdminRoot.vue`, `src/modals/BackupSettingsModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they save a folder, hour, keep and sessions choice THEN `backup` holds them
  - GIVEN a non-admin WHEN they call any backup route THEN the answer is 403
- [ ] Implement
- [ ] Test (PHPUnit on auth; Newman as admin and non-admin)

### Task 2: The backup writer and the nightly job
- **spec_ref**: `openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001`
- **files**: `lib/Service/Backup/BackupWriter.php`, `lib/BackgroundJob/BackupTask.php`, `lib/BackgroundJob/BackupNowJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN two organisations WHEN the job runs THEN each gets its own file with only its objects, and the manifest counts match
  - GIVEN sessions excluded WHEN the file is written THEN it holds no `agentsession` or `agentsessionturn` object
  - GIVEN 15 files and keep 14 WHEN the job runs THEN the oldest is deleted
  - GIVEN the four schemas missing from the register list WHEN the schema set is built THEN they are included
- [ ] Implement
- [ ] Test (PHPUnit with two tenants and a fake Files folder)

### Task 3: The restore preview
- **spec_ref**: `openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002`
- **files**: `lib/Service/Backup/RestorePlanner.php`, `lib/Controller/BackupController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a backup and a changed agent WHEN the preview runs THEN it counts one changed agent and writes nothing
  - GIVEN a moment in time WHEN the preview runs THEN objects without history at that time are listed as such
- [ ] Implement
- [ ] Test (PHPUnit on the planner, both restore points)

### Task 4: The restore job with the kill switch and the record
- **spec_ref**: `openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-stops-the-organisations-agents-and-writes-through-the-audit-trail-req-bkup-003`
- **files**: `lib/Service/Backup/RestoreRunner.php`, `lib/BackgroundJob/RestoreJob.php`, `lib/Service/TenantControlService.php`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a restore WHEN it starts THEN the kill switch is engaged before the first write, and released after a clean finish
  - GIVEN two failing saves WHEN it finishes THEN the switch stays engaged and the record and notification name both
  - GIVEN a file from a newer register version WHEN a restore is requested THEN it is refused
- [ ] Implement
- [ ] Test (PHPUnit on the runner with a failing save and a version check)

### Task 5: The restore dialog
- **spec_ref**: `openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002`
- **files**: `src/modals/RestoreDialog.vue`, `src/views/AdminRoot.vue`, `src/api/backup.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the admin section WHEN the administrator picks a backup THEN the preview shows per schema counts and a choice for objects created after the point, before any restore starts
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/backup-restore.spec.ts`)

## Verification
- [ ] `openspec validate operations-backup-and-restore --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: back up, change an agent's prompt, restore, and read the agent's version history
