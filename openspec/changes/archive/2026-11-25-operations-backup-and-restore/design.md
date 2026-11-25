# Design: operations-backup-and-restore

Kind: code. Size L. A backup service and job, a restore service and queued job, an admin settings section with a restore dialog, and the use of the kill switch and OpenRegister's revert. No schema changes.

## Context at development db6b74dc

- `lib/Settings/hermiq_register.json:1-18` register version 0.32.0; `:24-53` the register's `schemas` list (28 slugs); `components.schemas` (32 schemas).
- `lib/Controller/AgentVersionController.php:103` `index()`, `:136` `diff()`, `:176` `rollback()`; `lib/Service/SkillVersionService.php:193` `rollback()`.
- `lib/Service/TenantControlService.php:84` `getForOrganisation()`, `:123` `toggle(organisation, engaged, reason, actorUid)`.
- `appinfo/info.xml:51-58` the single `<background-jobs>` block; `lib/BackgroundJob/RunRetentionCleanupJob.php` as a nightly `TimedJob` pattern.
- `lib/Controller/Settings/RunRetentionSettingsController.php` as an admin settings controller pattern; `appinfo/routes.php:463-464`.
- hermiq ADR-004 (single write path through `ObjectService`, audit on every change), hermiq ADR-023 rule 3 ("Backup / restore operations" are admin-only), hydra ADR-064 (no secret on an object), hydra ADR-069 (jobs in `lib/BackgroundJob/`, `QueuedJob` for dynamic work).
- openregister development at c53dd0685c (read only): `lib/Controller/RegistersController.php:1006-1010` export with `format` and `includeObjects`; `:1384` import; `:1750` `rollbackImport()`; `lib/Controller/RevertController.php:80-90` revert with `datetime`; `appinfo/routes.php:1453`, `:1658-1660`.

## D1. Admin only, one section

Backup and restore live in hermiq's admin settings, `#[AuthorizedAdminSetting(Application::APP_ID)]` on every route (hermiq ADR-023 rule 3). Settings, stored as `IAppConfig` `backup`:

- `enabled` (default false), `folder` (a folder in the administrator's Files, default `Hermiq backups`), `hour` (default 2), `keep` (default 14), `includeSessions` (default false).

The section says what a backup holds and who can read it: "Backups contain your agents, skills, memories and settings. With sessions included they also contain every chat of every person in the organisation. Only people who can open this folder can read them."

## D2. What a backup is

One file per organisation per run, `hermiq-backup-<organisation>-<yyyy-mm-dd-hhmm>.json.gz`, in the chosen folder:

- `manifest`: created at, hermiq app version, register version, organisation, the schemas included and the object count per schema;
- `objects`: per schema slug, every object of that organisation as `ObjectService` returns it, with uuid, owner, organisation, created and updated.

The schema set is taken from `components.schemas` in `hermiq_register.json`, not from the register's `schemas` list, because four declared schemas are missing from that list (`:24-53`). Sessions and turns (`agentsession`, `agentsessionturn`, and the retired `conversation` and `message`) are left out unless `includeSessions` is on. Nothing else is filtered: no hermiq object may hold a secret (hydra ADR-064), so none is in the file.

The nightly `BackupTask` (a `TimedJob` in `lib/BackgroundJob/`, registered in the one `<background-jobs>` block) writes a backup for each organisation with hermiq objects, then deletes backups beyond `keep`, oldest first. "Back up now" queues a `BackupNowJob`, so the request returns at once.

Rejected: OpenRegister's register export with objects. It exports the whole register; whether it keeps to one organisation on a multi-tenant instance was not verified, and a backup that leaks another organisation's agents into this one's folder is worse than no backup. `ObjectService` applies the organisation boundary hermiq already relies on everywhere.

## D3. Two restore points

- **A backup file.** The administrator picks one from the folder.
- **A moment in time.** For each object of the organisation, OpenRegister's revert to `datetime` rebuilds its state at that moment from the audit trail. This reaches back as far as the audit trail does and needs no file.

Both start with a preview (`POST /api/backup/restore/preview`), computed without writing: per schema, objects that will be put back, objects that will change, objects created after the point, and objects missing from the point. The administrator then chooses what to do with objects created after the point: keep them (default) or move them to OpenRegister's deleted objects, where they can still be recovered.

## D4. The restore stops the agents and writes through the audit trail

`POST /api/backup/restore` queues a `RestoreJob`, which:

1. engages the organisation's kill switch with the reason "Restore in progress", so no schedule, flow or chat turn runs against half-restored data;
2. writes each object back through `ObjectService::saveObject()` with the full object (a partial save drops fields), or calls OpenRegister's revert for a point in time, so every change is on the audit trail (hermiq ADR-004);
3. soft-deletes the objects created after the point when the administrator chose that;
4. releases the kill switch only if it was not engaged before the restore;
5. writes one summary record: an audit entry with action `restore` on the organisation's `TenantControl` object, with the point, the counts, the administrator and the outcome, and a Nextcloud notification to the administrator.

If any write fails, the job continues with the rest, the kill switch stays engaged, and the summary lists the failures, so a person decides before agents run again.

## D5. A restore across versions

The manifest records the register version. A file from an older register version is restored object by object; properties the current schema no longer has are dropped and listed in the preview as "not restorable", and new required properties get their schema default. A file from a newer register version than the installed one is refused.

## Declarative versus imperative

No schema changes. Backup, restore, the kill switch and the audit record are operations across all schemas at once, which hydra ADR-031 leaves imperative.

## Risks

- A backup with sessions exposes every chat to whoever can open the folder. Mitigation: off by default, the warning in D1, and the folder is the administrator's own unless they share it.
- A large organisation's backup is slow. Mitigation: objects are streamed per schema into the gzip file, and the job runs at night.
- A restore overwrites a change made after the point that someone wanted. Mitigation: the preview lists changed objects, and every overwrite is on the audit trail, so the later state can be brought back with a second point-in-time restore.
- The point-in-time restore depends on OpenRegister's revert and on audit retention. Mitigation: the preview shows objects whose history does not reach the chosen moment as "no history at that time", and leaves them untouched.
