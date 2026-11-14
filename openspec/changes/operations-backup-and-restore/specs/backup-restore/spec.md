# backup-restore Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- operations-backup-and-restore

## Purpose

An administrator backs up an organisation's hermiq data on a schedule and restores it to an earlier point, audited and with agents stopped during the restore. Row `hermiq:dm-backup-restore`.

## ADDED Requirements

### Requirement: An administrator can back up an organisation's hermiq data to Files on a schedule (REQ-BKUP-001)

Hermiq MUST let only a Nextcloud administrator switch on scheduled backups and set their folder, time, number to keep, and whether chat sessions are included. Each backup MUST contain every object of every declared hermiq schema for one organisation, read within that organisation's boundary, with a manifest of versions and counts. Chat sessions and turns MUST be left out unless the administrator included them. Hermiq MUST delete backups beyond the number to keep, oldest first.

#### Scenario: The nightly backup runs
- GIVEN an administrator at Gemeente Tilburg who switched on backups at 02:00 into `Hermiq backups`, keeping 14
- WHEN the night passes
- THEN `Hermiq backups` holds a new `hermiq-backup-tilburg-2026-09-28-0200.json.gz`, its manifest counts match the organisation's objects per schema, it holds no session, and the oldest backup beyond the 14 kept is gone
- @e2e exclude waits for a background job; covered by PHPUnit on the job and a live check

#### Scenario: Another organisation's agents stay out
- GIVEN an instance with two organisations
- WHEN the backup for Tilburg is written
- THEN it holds no object of the other organisation
- @e2e exclude needs two organisations; covered by PHPUnit with two tenants

### Requirement: A restore starts with a preview of what will change (REQ-BKUP-002)

Hermiq MUST let an administrator choose a backup file or a moment in time and MUST show, before anything is written, per schema how many objects will be put back, changed, created after the point, and missing at the point.

#### Scenario: An administrator checks a restore before running it
- GIVEN a bad bulk edit to 12 agents this morning
- WHEN the administrator chooses "Restore" and picks yesterday's 02:00 backup
- THEN the preview shows "Agents: 12 will be changed back, 1 was created after this backup", and nothing has been written yet
- e2e: `tests/e2e/spec-coverage/backup-restore.spec.ts`

### Requirement: A restore stops the organisation's agents and writes through the audit trail (REQ-BKUP-003)

Hermiq MUST engage the organisation's kill switch before a restore writes anything, MUST write every restored object through OpenRegister's object service, and MUST release the kill switch afterwards only when the restore succeeded and the switch was not engaged before. Hermiq MUST record each restore with its point, counts, administrator and outcome.

#### Scenario: The agents wait while data is put back
- GIVEN a restore of yesterday's backup in progress
- WHEN a schedule of the organisation becomes due
- THEN it is skipped with the kill switch reason "Restore in progress", and after the restore the schedules run again
- @e2e exclude timing across a background job; covered by PHPUnit on the restore job

#### Scenario: A partial failure keeps the agents stopped
- GIVEN a restore in which two objects fail to save
- WHEN the job finishes
- THEN the kill switch stays engaged, the administrator gets a notification naming the two failures, and the restore record says "completed with 2 failures"
- @e2e exclude covered by PHPUnit with a failing save

### Requirement: Backup and restore are for administrators only (REQ-BKUP-004)

Every backup and restore route MUST require a Nextcloud administrator.

#### Scenario: A regular user cannot restore
- GIVEN a user who is not an administrator
- WHEN they send `POST /api/backup/restore`
- THEN the answer is 403 and no job is queued
- @e2e exclude a crafted request; covered by PHPUnit and Newman
