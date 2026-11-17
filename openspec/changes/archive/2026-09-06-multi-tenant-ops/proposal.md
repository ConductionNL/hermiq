---
kind: code
---

# Proposal: multi-tenant-ops

# Why

The `multi-tenant-ops` capability spec (V2, status: idea) adds MSP/org-level operational
controls on Hermiq's multi-tenant foundation: per-org quotas, strict per-tenant isolation,
and sovereignty (local inference + per-tenant EU AI Act audit export), built on OR's
`organisation`/`owner`/`groups` model — no parallel tenancy layer. This change delivers the
Hermiq-ownable surface of that spec.

# What Changes

- Add `lib/Service/TenantOpsService.php`:
  - `quotaStatus()` — the caller's org usage vs. configured limits: **schedules**
    (count/limit/atLimit — Hermiq-owned, accurate) and **agents-in-use** (distinct agentIds
    across the caller's schedules; the authoritative agent-creation quota lives in OR, a
    seam). Limits come from app config (`agentQuota`, `scheduleQuota`) with sane defaults.
  - `exportAuditTrail()` — a per-tenant **EU AI Act audit export**: the governance records
    (runs, gate decisions, kill-switch skips) for the caller's own Hermiq objects only,
    read from OR's hash-chained `AuditTrail` (already redacted at write time). Scoped by
    loading the caller's tenant objects first, so no cross-tenant record leaks.
- Add `lib/Controller/TenantOpsController.php` (`@NoAdminRequired`, `@NoCSRFRequired`):
  `GET /api/tenant-ops/quota` and `GET /api/tenant-ops/audit-export` (downloadable JSON).
- Add a **Tenant ops** nav page (`src/views/TenantOps.vue`, `src/api/tenantOps.js`): quota
  usage cards + an "Export AI Act audit trail" download button; shown to org owners /
  instance admins via the existing `can_manage_killswitch` capability.

**Isolation** is already native: every Hermiq object (schedule, memory, skill, approval,
session) carries OR `organisation`/`owner`/`groups`, and all reads run RBAC-on — this change
verifies + documents it (the export is the proof point). **Local inference** is a config
capability (agents already target the Ollama provider), documented. **Hard agent/schedule
quota reject at creation time** is an OpenRegister seam — object creation flows through OR's
object API, not a Hermiq endpoint — so Hermiq surfaces + advises on the quota; OR owns the
create-time hard reject.

# Impact

- Affected specs: `multi-tenant-ops` (idea → active, with documented OR seams).
- Affected code: `lib/Service/TenantOpsService.php`, `lib/Controller/TenantOpsController.php`,
  `appinfo/routes.php`, `src/manifest.json`, `src/registry.js`, `src/customComponents.js`,
  `src/views/TenantOps.vue`, `src/api/tenantOps.js`, `tests/Unit/Service/TenantOpsServiceTest.php`.
- OR seams (documented, not implemented): create-time hard quota reject (OR object API);
  authoritative org agent inventory.
