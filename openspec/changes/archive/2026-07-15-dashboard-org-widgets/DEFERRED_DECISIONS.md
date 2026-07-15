# Deferred Decisions: dashboard-org-widgets

Judgment calls made while writing this change's artifacts that the shared realignment
brief did not explicitly specify. Flagged for the user/apply-agent to confirm or override.

## 1. Preserved the org-owner/admin-only visibility gate on the relocated widget
The brief said the quota cards "move to the main Dashboard" but did not say whether to keep
or drop the existing org-owner/instance-admin-only visibility rule that gates them on
`TenantOps.vue` today. **Decision made:** keep the gate exactly as-is (`loadState('hermiq',
'can_manage_killswitch', false)`), because `TenantOpsController`'s docblock is explicit that
this is a deliberate product decision (quota-vs-limit management data, not just a
data-leak concern), not an accident of where the display happened to live. Relocating the
surface should not silently widen who sees it. If the intent was actually to show quota
usage to every organisation member on the Dashboard (arguably more useful — a member notices
"we're near the schedule limit" before an admin does), that is a one-line change (drop the
`can_manage_killswitch` check in `QuotaUsageWidget.vue`) but is a real behavior/visibility
change worth an explicit decision.

## 2. Non-manager render = nothing, not an explanatory empty state
For a Dashboard visitor without the capability, the widget renders nothing (blank grid
cell) rather than an `NcEmptyContent` explaining "organisation admins only" (which
`TenantOps.vue` shows on its own page today). Chosen to avoid visual noise for the majority
of everyday Dashboard users who will never have this capability. If product wants
discoverability instead (so users understand quota management exists and who to ask), swap
to a small `NcEmptyContent`/`NcNoteCard` in the same grid cell.

## 3. Widget grid placement/size
Placed at `gridX:0, gridY:1, gridWidth:12, gridHeight:1` — full-width, directly below the
existing three `stats-block` cards (which occupy `gridY:0, gridHeight:1`). This was not
specified by the brief; `AnalyticsKpiWidget` (which renders a similar multi-card row) also
uses `gridHeight:1` at full width, so this mirrors that established sizing. If the two
quota cards need more vertical room once built (e.g. wrapping at narrow viewport widths),
`gridHeight` can bump to `2` without any spec change.

## 4. `TenantOps.vue`'s top-level `loading`/`error`/`v-if`/`v-else` wrapper is removed, not just the quota section
The brief said "you only touch the quota cards," but the page's outer `loading`/`error`
state and `v-if="loading" / v-else` template wrapper were tied 1:1 to the quota fetch
(`load()`) — nothing else on the page used them. Leaving them in place after deleting the
quota section would either (a) leave dead state/methods (lint failure, orphaned code) or
(b) require inventing a new reason for a page-level loading spinner that never existed
before. **Decision made:** remove the now-dead `loading`/`error`/`load()`/`getQuota` import
together with the quota markup, and render the remaining sections directly under the
existing `canManage` gate — every remaining section already manages its own
loading/error state independently, so this changes no other section's behaviour. Treated
as "touching the quota cards" broadly enough to keep the file coherent, not as scope creep
into the sibling `inapp-settings-section` change's territory (which handles the actual
guardrail/MCP/compliance moves).

## 5. Artifacts skipped
`contract.md`, `discovery.md`, and `migration.md` were not created:
- **contract.md** — no new or modified API endpoint; the widget consumes the existing,
  unmodified `GET /api/tenant-ops/quota` and no other project in apps-extra consumes it.
- **discovery.md** — no Nextcloud API/framework uncertainty; the exact widget-grid
  mechanism and registry pattern were already verified at HEAD via `RunAnalytics`'s
  `analytics-kpis`/`analytics-breakdown` entries.
- **migration.md** — no database/schema change; no OpenRegister register/schema version
  bump is needed (nav/UI relocation only, per the shared brief's rule 6).

`openspec validate dashboard-org-widgets --type change --strict` passes with these three
omitted.
