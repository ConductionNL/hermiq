# Tasks: ai-oversight-subject-access

- [x] 1.1 Approval schema read rule: advisory rows to `ai-oversight` and admins only.
- [x] 1.2 `AiOversightAccess`: role check and subject check through OpenRegister with RBAC.
- [x] 1.3 `GET /api/ai-oversight/subject`, access checked before the read.
- [x] 2.1 `ignored` maps to `unused`; `unusedBecause` validated and stored.
- [x] 3.1 PHPUnit for allowed, denied and role cases, and for `unused`.
- [x] 1.4 `ProvisionAiOversightGroup` repair step creates the group when missing (install and upgrade), idempotent.
- [x] 1.5 The subject route searches every id the origin app has carried (`OriginAppAliases`), so records stored under `procest` show for `dossiq`. Stored data is not rewritten.
