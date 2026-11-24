---
kind: code
---

# Proposal: hermiq-domain-mcp-tools

# Why

`lib/Mcp/HermiqToolProvider.php` (registered in `lib/AppInfo/Application.php:95-96` under
the `OCA\OpenRegister\Mcp\IMcpToolProvider::hermiq` alias) is Hermiq's only `IMcpToolProvider`
implementation. Its `TOOL_DESCRIPTORS` (`lib/Mcp/HermiqToolProvider.php:84-150`) expose
exactly six tools: `listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`,
`sendMail`, `listDeckBoards` — all Nextcloud-native leaf capabilities (nc-native-tools
spec). Not one tool touches Hermiq's own domain objects: `Agent`, `Schedule`, `Run`,
`Approval`, `Skill`, `AiFeature` (all defined in `lib/Settings/hermiq_register.json` and
served by `lib/Controller/{RunHistory,RunNow,Approval,Skill,AiFeature,Memory,Analytics}Controller.php`).

ADR-035 (`hydra/openspec/architecture/adr-035-mcp-per-app-coverage.md`) exists precisely
because of this failure mode: "the AI never knows about ... the things the user is looking
at ... the answer would have been 'I can list registers' — useless." ADR-035 cites
decidesk's `DecideskToolProvider` (five domain tools: `listOpenActionItems`,
`listRecentMeetings`, `getMeetingDetails`, `startMeeting`, `addActionItem`) as the proof this
matters. Hermiq is the one app in the fleet whose entire product is "autonomous AI agents"
— and its own AI chat companion cannot list the user's agents, check a schedule's last run,
see pending approvals, or search the skills catalog. A user chatting with the in-app
companion on the Approval Inbox or Agent Catalog page gets the same generic "I can list
registers" answer ADR-035 was written to eliminate.

Separately, the class docblock (`lib/Mcp/HermiqToolProvider.php:22-24`) still says tool
invocation is "blocked on OR#269" — per project memory OR#269 was closed as part of the
hermiq/hermes port (3 OR seams closed, PR#282/283/284). The stale comment should be
corrected in the same change since it directly affects whether these new tools are
reachable end-to-end.

# What Changes

- Add domain tools to `HermiqToolProvider` (or a new sibling provider if the class grows
  past a reasonable size — decision left to the implementer, but the DI alias stays
  singular per ADR-034/035):
  - `hermiq.listAgents` — list the caller's tenant-scoped agents (delegates to the same
    read path `AgentCatalog` uses).
  - `hermiq.getAgentStatus` — an agent's last run outcome + next scheduled run.
  - `hermiq.listPendingApprovals` — the caller's reviewer-scoped pending approvals
    (delegates to `ApprovalService::listPendingForReviewer`, same as `ApprovalController::index`).
  - `hermiq.runAgentNow` — trigger an owner-scoped immediate run (delegates to the same
    guarded path as `RunNowController::run` — per-object owner check MUST be preserved
    inside `invokeTool()`, not bypassed).
  - `hermiq.searchSkills` — search the tenant's skill catalog (delegates to `SkillService::listSkills`).
  - Each tool follows the existing per-object-authorization-before-business-logic rule the
    class docblock already documents (`lib/Mcp/HermiqToolProvider.php:14-17`) and never
    throws (structured error envelope on failure).
- Correct the stale OR#269 docblock comment (`lib/Mcp/HermiqToolProvider.php:22-24`) once
  the tools are wired to reflect the current (closed) status.
- Add unit tests asserting the tool catalogue includes the new ids and that each `invokeTool`
  branch enforces the same guard as its HTTP-controller counterpart.
- Record the MCP-coverage decision per ADR-035 Decision 2 in this change's proposal (done,
  above) — no `project.md` opt-out needed since Hermiq already has a provider; this closes
  the domain-coverage gap in that provider.

# Impact

- Affected code: `lib/Mcp/HermiqToolProvider.php`, `lib/AppInfo/Application.php` (constructor
  DI additions for the services the new tools delegate to — likely `ApprovalService`,
  `RunNowController`'s underlying schedule-run path, `SkillService`), new/updated
  `tests/Unit/Mcp/HermiqToolProviderTest.php`.
- Affected specs: `nc-native-tools` (this change extends its scope with domain tools; a new
  capability heading is added rather than overloading nc-native-tools' own delta).
