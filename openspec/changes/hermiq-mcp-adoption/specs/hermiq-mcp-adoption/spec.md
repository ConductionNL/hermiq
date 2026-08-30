# hermiq-mcp-adoption Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `hermiq-mcp-adoption` — declares the ADR-063 dialect on a read-only subset of Hermiq's registers and refuses the agent-governance surface outright (kind: code)

## Purpose

Defines Hermiq's agent-facing tool surface over its **own** registers under ADR-063. Hermiq is the
fleet's agent platform, so its registers double as its governance control plane: an agent that can
write them can widen its own grants. This capability therefore specifies both what is exposed (a
small, read-only subset) and — normatively — what MUST NOT be, so a later dialect edit cannot quietly
re-open the escalation path. Supersedes the unimplemented `hermiq-domain-mcp-tools` change, which
proposed hand-written `listPendingApprovals` / `runAgentNow` tools under the older ADR-035.

## ADDED Requirements

### Requirement: Declarative read-only tool surface on a curated schema subset
The system MUST derive Hermiq's register tools from `x-openregister-mcp` declarations on exactly three schemas — `Agent`, `Schedule` and `Session` — and MUST NOT hand-write any `IMcpToolProvider` CRUD tool over Hermiq's registers.

Each declaration MUST enable the `search` and `get` verbs only, MUST carry agent-facing `description`
prose per verb, and MUST declare `scope: 'read'` with `readOnlyHint: true`. Every `search.filters`
entry MUST name a real property of that schema.

#### Scenario: The derived catalog exposes reads for the three curated schemas
- GIVEN `hermiq_register.json` declares `x-openregister-mcp` on `Agent`, `Schedule` and `Session`
- WHEN `SchemaDerivedToolProvider` builds the catalog for app id `hermiq`
- THEN it MUST emit `hermiq.agent.search`, `hermiq.agent.get`, `hermiq.schedule.search`, `hermiq.schedule.get`, `hermiq.session.search` and `hermiq.session.get`
- AND every emitted descriptor MUST carry `readOnlyHint: true` and `scope: 'read'`

#### Scenario: Declared search filters are real schema properties
- GIVEN the `Schedule` declaration lists `filters: [enabled, agentId, lastStatus, kind]`
- WHEN OpenRegister's `McpAnnotationValidator` validates the schema at import
- THEN validation MUST pass because each filter names a declared property of `Schedule`
- AND a filter naming a property the schema does not declare MUST be rejected at import

### Requirement: No write verb on any Hermiq schema
The system MUST NOT declare the `create`, `update` or `delete` verb in the `x-openregister-mcp` block of any schema in `hermiq_register.json`.

An agent able to write Hermiq's own registers can escalate its own privileges in a single tool call:
`Agent.tools` and `Agent.prompt` grant capability and rewrite instructions; `Schedule.requiresApproval`
disables the agent's own approval gate and `Schedule.prompt` establishes unattended persistence;
`Memory.entries` is a persistent-injection sink. Refusing at declaration time means the tool is never
emitted, which is a stronger guarantee than one enforced at invoke time.

#### Scenario: No write tool is derivable for Hermiq
- GIVEN every `x-openregister-mcp` block in `hermiq_register.json`
- WHEN the derived catalog for app id `hermiq` is built
- THEN it MUST contain no tool id ending in `.create`, `.update` or `.delete`

#### Scenario: An agent cannot grant itself a tool
- GIVEN an agent whose grants list resolves to Hermiq's derived read tools
- WHEN the agent attempts to modify its own `Agent` object's `tools` array via an MCP tool
- THEN no such tool MUST exist in the catalog
- AND the attempt MUST fail as an unknown tool rather than being gated at invoke time

### Requirement: The agent-governance objects are off the tool surface entirely
The system MUST NOT declare `x-openregister-mcp` on `Approval`, `AgentWebhook`, `GuardrailPolicy`, `ModelPolicy`, `TenantControl`, `Budget`, `Skill`, `SkillSource`, `Memory`, `UserProfile`, `Incident` or `AiFeature` — for read verbs as well as write verbs.

These are the control plane, not the domain. `Approval` is the human gate an agent could otherwise
enumerate and (given any future write) self-approve. `AgentWebhook` carries `secretHash` and
`secretPrefix` — credential material. `GuardrailPolicy.inputFilters` / `outputFilters` **are** the
blocklist, so reading them tells an agent what to evade, and `TenantControl.engaged` is the tenant
kill-switch. Because the dialect has no field projection, a `get` returns the entire object, so
"expose the safe fields only" is not expressible and the schema MUST stay off.

#### Scenario: Approvals are not reachable as a tool
- GIVEN the derived catalog for app id `hermiq`
- WHEN an agent enumerates available tools
- THEN no `hermiq.approval.*` tool MUST appear
- AND no `hermiq.guardrailpolicy.*`, `hermiq.agentwebhook.*` or `hermiq.tenantcontrol.*` tool MUST appear

#### Scenario: Transcript bodies stay off while headers stay on
- GIVEN `Session` is declared and `SessionTurn`, `Conversation` and `Message` are not
- WHEN an agent enumerates available tools
- THEN `hermiq.session.search` MUST be present
- AND no tool returning message `content` MUST be present, so a transcript cannot be read and forwarded through `hermiq.sendMail`

## Non-Functional Requirements

- **Performance:** The curated surface MUST stay at or below roughly a dozen `hermiq.*` tools, so tool-selection accuracy and prompt token cost do not degrade (ADR-063 rule 3).
- **Internationalization:** No new user-facing strings; tool `description` prose is agent-facing English. N/A for `nl_NL`/`en_US`.

## Acceptance Criteria

- [ ] `hermiq_register.json` validates at import with the dialect on exactly `Agent`, `Schedule`, `Session`
- [ ] The derived catalog for `hermiq` contains six tools, all read-only
- [ ] No `create`/`update`/`delete` tool id exists for `hermiq`
- [ ] No governance schema appears in the catalog under any verb
- [ ] The superseded `hermiq-domain-mcp-tools` change is withdrawn

## Notes

- ADR-063 (hydra#102). Verified against OpenRegister `origin/development`:
  `Service/Mcp/McpAnnotationValidator.php` (`VERBS`, `SCOPES`, filter cross-check),
  `Mcp/BuiltIn/SchemaDerivedToolProvider.php` (id shape, `suppressedIds`).
- Open: whether `Agent.configuration` can still hold provider credentials post-`hermiq#43`. If it can,
  `Agent` MUST drop to OFF — the dialect cannot project fields away.
