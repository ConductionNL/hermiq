# Tasks: multi-tenant-ops

## 1. TenantOpsService

- [x] 1.1 Create `lib/Service/TenantOpsService.php` (SPDX) with `quotaStatus()`: load the caller's schedules via `ObjectService` (RBAC on), report `schedules` (count, limit, atLimit) and `agents` (distinct agentIds in use, limit, atLimit); limits from app config (`scheduleQuota` default 100, `agentQuota` default 50).
- [x] 1.2 `exportAuditTrail()`: load the caller's Hermiq objects (schedules + approvals, tenant-scoped), read each object's `AuditTrail` entries via `AuditTrailMapper`, and assemble an EU AI Act export `{generatedAt, recordCount, records[]}` — each record `{objectType, objectUuid, action, status, user, created}` — scoped strictly to the caller's own objects (no cross-tenant leak; already redacted at write time).

## 2. Controller + routes

- [x] 2.1 Create `lib/Controller/TenantOpsController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): `quota()` → `quotaStatus()`; `auditExport()` → `exportAuditTrail()` with a `Content-Disposition` download filename; unauthenticated ⇒ 401.
- [x] 2.2 Register `GET /api/tenant-ops/quota` + `GET /api/tenant-ops/audit-export` in `appinfo/routes.php`.

## 3. UI

- [x] 3.1 Add `src/api/tenantOps.js` wrapping the quota + audit-export endpoints.
- [x] 3.2 Add `src/views/TenantOps.vue`: quota usage cards (schedules X/limit, agents Y/limit, with an at-limit warning) + an "Export AI Act audit trail" button that downloads the export; shown only when the `can_manage_killswitch` capability (org owner / instance admin) is set (`loadState`); `NcEmptyContent`/loading states.
- [x] 3.3 Register the Tenant ops page in `src/manifest.json` (`route: /tenant-ops`, nav) + `src/registry.js` + `src/customComponents.js`.

## 4. Verify

- [x] 4.1 Unit-test `TenantOpsService` the CI way: quota math (count/limit/atLimit, distinct agents); the audit export includes only the caller's objects' records (a foreign object's audit is excluded).
- [x] 4.2 Verify live on NC + OR: `quota` reflects the real schedule/agent counts vs. limits; `audit-export` downloads a JSON of the caller's governance records (runs/decisions) scoped to the org; Playwright-test the Tenant ops page (quota cards + export button) with 0 console errors.

## Acceptance criteria

- Per-org quota usage (schedules + agents) is reported against configurable limits; the hard create-time reject is documented as an OpenRegister seam (creation flows through OR's object API).
- Every Hermiq object type carries `organisation`/`owner`/`groups`; no API response includes another tenant's objects (verified — the audit export is org-scoped).
- A per-tenant EU AI Act audit export is produced, scoped strictly to the caller's organisation, from OR's hash-chained `AuditTrail` (redacted at write).
- Local-only inference is a documented config capability (agents target the Ollama provider); no data must leave the instance when configured.

## Quality reminders

- SPDX in each PHP docblock; pass `composer phpcs` (lib scope) + PHPStan; run PHPUnit the CI way.
- Read/aggregate surface (ADR-031) — introduce no new schema or write path; reuse OR `ObjectService`/`AuditTrailMapper`.
- No sed/awk/scripts on code — Edit tool only; `@spec` docblock tags; i18n keys in English.
- Redaction is inherited (entries are redacted at write time); the export MUST stay org-scoped.
