# Tasks: hermiq-domain-mcp-tools

## 1. Domain tool descriptors

- [ ] 1.1 Add five entries to `HermiqToolProvider::TOOL_DESCRIPTORS`
      (`lib/Mcp/HermiqToolProvider.php:84-150`): `hermiq.listAgents`, `hermiq.getAgentStatus`,
      `hermiq.listPendingApprovals`, `hermiq.runAgentNow`, `hermiq.searchSkills` — each with
      `id`, `name`, `description`, `inputSchema` (JSON Schema).
- [ ] 1.2 Inject the collaborating services (`ApprovalService`, the schedule/run read+trigger
      path already used by `RunNowController`/`RunHistoryController`, `SkillService`) into
      `HermiqToolProvider::__construct()` — thin delegation only, no new business logic
      duplicated from the controllers/services.

## 2. invokeTool branches

- [ ] 2.1 `hermiq.listAgents` — delegate to the same tenant-scoped agent read path as
      `AgentCatalog`'s index (no admin bypass; scoped to the caller's session).
- [ ] 2.2 `hermiq.getAgentStatus` — read the agent's last-run + next-scheduled-run fields.
- [ ] 2.3 `hermiq.listPendingApprovals` — delegate to
      `ApprovalService::listPendingForReviewer(uid: $user->getUID())`, mirroring
      `ApprovalController::index()` exactly (same reviewer scoping — no broader access
      than the HTTP endpoint grants).
- [ ] 2.4 `hermiq.runAgentNow` — delegate to the SAME owner-scoped guard
      `RunNowController::loadOwnedSchedule()` uses before triggering a run; the per-object
      authorization MUST NOT be weakened or bypassed inside `invokeTool()`.
- [ ] 2.5 `hermiq.searchSkills` — delegate to `SkillService::listSkills()` with an optional
      query filter.
- [ ] 2.6 Every branch returns a structured `['error' => ['code' => ..., 'message' => ...]]`
      on failure; `invokeTool()` MUST NOT throw (matches the existing class contract).

## 3. Docblock correction

- [ ] 3.1 Update `lib/Mcp/HermiqToolProvider.php:22-24` — the "OR#269 blocks LLM tool-calling"
      note is stale (OR#269 closed per the hermiq/hermes port); correct or remove it once
      verified against the current OpenRegister integration.

## 4. Tests

- [ ] 4.1 Unit-test the extended `TOOL_DESCRIPTORS` catalogue includes the five new ids with
      valid `inputSchema` objects.
- [ ] 4.2 Unit-test each new `invokeTool` branch: happy path + the same guard-denial path its
      HTTP-controller counterpart exercises (e.g. `hermiq.runAgentNow` on a schedule the
      caller doesn't own returns a structured error, not a successful run).

## 5. Verify

- [ ] 5.1 Verify live on NC + OR (agent turn against the in-app companion): ask the
      companion "what agents do I have" / "do I have any pending approvals" on the
      Approval Inbox page and confirm a domain-specific answer (not "I can list registers").
- [ ] 5.2 `composer phpcs` (lib scope) + PHPStan; PHPUnit the CI way.

## Acceptance criteria

- `HermiqToolProvider` exposes at least one MCP tool per core Hermiq domain object (Agent,
  Schedule/Run, Approval, Skill).
- Every new tool enforces the identical per-object/tenant guard its HTTP-controller
  counterpart already enforces — no new privilege surface.
- The stale OR#269 docblock note is corrected.

## Quality reminders

- SPDX in each PHP docblock; `@spec` tags referencing this change.
- Per-object authorization BEFORE business logic in every `invokeTool()` branch (class
  contract already documented).
- No sed/awk/scripts on code — Edit tool only; i18n keys in English if any UI surfaces text.
