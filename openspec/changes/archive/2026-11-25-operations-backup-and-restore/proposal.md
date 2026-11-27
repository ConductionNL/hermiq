---
kind: code
---

# Proposal: operations-backup-and-restore

## Summary

A Nextcloud administrator can have hermiq back up an organisation's agent data every night into a folder in Files: agents, skills, contexts, memories, schedules, knowledge bases, policies and the other hermiq objects, optionally including chat sessions. When a bad change hits, the administrator can restore the organisation to a chosen backup, or to a chosen moment within the audit history, after a preview that lists what will change. During a restore the organisation's agents are stopped with the kill switch, every restored object is written through OpenRegister so it is on the audit trail, and the restore itself is recorded.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-backup-restore` | partial, built | build: the featureRequest demand row (hermes-agent#12238, automatic backup of agent data) asks for the missing half, a whole-data backup and a restore point |

Demand row:

- `dm-backup-restore`: featureRequest https://github.com/NousResearch/hermes-agent/issues/12238

Competitor cell rated yes, quoted from the matrix:

- Copilot Studio: "https://learn.microsoft.com/en-us/power-platform/admin/backup-restore-environments https://learn.microsoft.com/en-us/microsoft-copilot-studio/authoring-solutions-import-export Power Platform environments (holding agents in Dataverse) get automatic system backups and restore to an earlier point; solutions export gives manual backups."

## What hermiq already has

- Agent version history and owner-only rollback, read from and replayed onto the agent's OpenRegister audit trail (`appinfo/routes.php:168-179`, `lib/Controller/AgentVersionController.php:103`, `:136`, `:176`), and the same for skills (`lib/Service/SkillVersionService.php:118`, `:193`).
- The per-organisation kill switch (`lib/Service/TenantControlService.php:84`, `:123`), enforced in the dispatch loop.
- The register declares 32 schemas (`lib/Settings/hermiq_register.json`, `components.schemas`). Its `schemas` list at `:24-53` names 28 of them; `agentoutsideregistration`, `agentassistantprompt`, `agentreportgroup` and `agentintakeconversation` are declared but not listed.
- Background jobs are registered once in `appinfo/info.xml:52-58`.
- OpenRegister offers a register export with objects (`GET /api/registers/{id}/export?format=configuration&includeObjects=true`), a register import with an import rollback, and a per-object revert to a date and time from the audit trail (`POST /api/objects/{register}/{schema}/{id}/revert` with `datetime`) (openregister `appinfo/routes.php:1453`, `:1658-1660`, `lib/Controller/RevertController.php:80-90`, `lib/Controller/RegistersController.php:1006-1010`, development at c53dd0685c).
- Nothing in hermiq backs up all of an organisation's data or restores it to a point.

## What this change builds

1. A backup settings section for administrators: on or off, the Files folder, the time of day, how many backups to keep, and whether chat sessions are included.
2. A nightly job that writes one backup file per organisation: a manifest (time, hermiq version, register version, counts per schema) and every hermiq object of that organisation, read through `ObjectService` so the organisation boundary holds.
3. "Back up now" for an administrator, with the same result.
4. A restore preview: pick a backup or a moment in time, and see per schema how many objects will be put back, changed, and created after that point.
5. The restore: engage the organisation's kill switch, write each object back through `ObjectService`, optionally move objects created after the point to OpenRegister's deleted objects, release the kill switch, and record the restore.

## Out of scope

- Backing up OpenRegister's audit trail or other apps' registers. The audit trail is OpenRegister's, and a whole-instance backup is the server administrator's.
- Secrets. No hermiq object holds one (hydra ADR-064); credentials live in the broker and are not in the backup.
- Restoring one agent to an earlier version. That already exists as version rollback and stays.
- Moving data between instances. The file can be read by another instance's restore, but migration tooling is not specified here.
