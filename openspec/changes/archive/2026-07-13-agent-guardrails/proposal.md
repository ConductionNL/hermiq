# Proposal: agent-guardrails

## Summary
Hermiq's autonomous agents currently run with no content or tool-risk controls: whatever an
LLM receives as input, whatever it emits as output, and whatever tool it decides to call all
flow through unfiltered (only *secrets* are masked, and only in the audit trail, via
`RedactionService`). This change adds a per-organisation **Guardrail policy** — input filters
(block/redact PII and prompt-injection patterns before the LLM turn), output filters
(block/redact PII/secrets before delivery or persistence), and a per-tool risk classification
(`auto`/`confirm`/`deny`) enforced at the single tool-invocation chokepoint
(`FacadeToolInvoker`). A `confirm`-classified tool raises the *existing* human-approval gate
(a new `sourceType: "toolcall"` on the `Approval` schema) rather than inventing a second
approval mechanism; a `deny`-classified tool is refused outright, with the refusal recorded as
a trace step so it is visible in run history — reusing `run-trace-observability`'s existing
per-run step timeline end to end, exactly as `RedactionService` is reused (extended, not
duplicated) for the PII/secret pattern matching both filters rely on.

## Motivation
Nine rivals across every tier — OpenAI's Agents SDK ("Guardrails": input/output filters plus
human-approval interrupts), Salesforce Agentforce ("Guardrails & topic scoping"), Microsoft
Copilot Studio ("Governance & DLP"), Holistic AI ("Real-time input/output filtering" /
"Portfolio-wide policy enforcement"), Credo AI ("Risk & controls assessment" / "Role-based
governance workflows"), and Monitaur ("Policy library") — all ship a deterministic guard layer
between the model and the world. Hermiq's existing governance (kill-switch, budget caps,
human-approval-gate) all gate *whether a run happens at all*; none of it inspects *what the
agent reads or writes*, and none of it lets an operator say "this tool is safe to auto-run, that
one needs a human, that one is never allowed" at a finer grain than the existing all-or-nothing
`Agent.tools` whitelist. Without this, Hermiq cannot credibly claim the "govern the agent, not
just gate the run" position its human-approval-gate and cost-guardrails changes already
established for two other axes (oversight, cost). This is a security change: the design must be
rigorous about exactly where each check fires and what happens when it fails.

## Affected Projects
- [x] Project: `hermiq` — new `GuardrailPolicy` schema, `GuardrailPolicyService`, `Approval`
  schema `sourceType: "toolcall"` + fields, enforcement wiring in `Engine`, `ScheduleService`,
  `ToolLoop`/`FacadeToolInvoker`, `ApprovalService`; new Controller + Vue API client for policy
  CRUD; l10n strings.

## Scope

### In Scope
- A `GuardrailPolicy` OpenRegister object (per-organisation, mirroring `ModelPolicy`'s
  own-policy → instance-default resolution): `inputFilters` (PII action `off`/`redact`/`block`,
  prompt-injection action `off`/`block`), `outputFilters` (PII/secret action
  `off`/`redact`/`block`), and `toolPolicy` (a list of `{toolId, classification}` entries,
  `auto`/`confirm`/`deny`, default `auto` for any unlisted tool — zero behavior change for an
  organisation with no policy, exactly like `Budget`/`requiresApproval` are opt-in today).
- Input filter enforcement immediately before an LLM turn: inside `Engine::processMessage()`
  (covers interactive chat and the in-app-engine branch of scheduled/flow/webhook runs) and
  inside `ScheduleService::runAgentAsOwner()`'s legacy-`ChatService` branch (the one path that
  never reaches `Engine::processMessage()`), so both engine-flag states are covered exactly
  once each.
- Output filter enforcement at two boundaries: before persistence (inside
  `Engine::processMessage()`, before the assistant `Message` is stored) and before delivery
  (inside `ScheduleService::runAgentAsOwner()`'s single return point, which every caller —
  `runDue()` before `DeliveryService::deliver()`, `FlowAgentRunService` before its `resultField`
  write, `WebhookAgentRunService` before its audit-summary write — consumes).
- Per-tool `auto`/`confirm`/`deny` classification enforced inside `FacadeToolInvoker::__call()`
  (the single chokepoint every LLM tool call passes through, regardless of provider or
  streaming mode) via a policy resolved once per turn and threaded through `ToolLoop` exactly
  as `RunTraceCollector`/`StreamYieldChannel` already are.
- A `confirm`-classified tool call is refused on first attempt and raises a pending `Approval`
  (`sourceType: "toolcall"`, a fourth kind alongside `schedule`/`flow`/`webhook`) instead of
  running; once a reviewer approves it, a *matching retry* of the identical tool call
  (same agent + tool + arguments, within a bounded window) is let through — see design.md for
  why this "approve, then retry" shape is used instead of resuming a paused LLM turn.
- A `deny`-classified tool call is refused unconditionally (no Approval is ever created) and
  recorded as a `tool` trace step with `outcome: 'denied'` — visible in run history via the
  same `steps`/`AuditTrail` path `run-trace-observability` already wired up.
- Extending `RedactionService` is done by *reuse*, not new patterns: both filters detect
  PII/secrets by diffing `RedactionService::redact()`'s input against its output (a difference
  means something was masked) — no second pattern set. Prompt-injection detection is new,
  deterministic regex-based heuristics (a fixed list of known override/jailbreak phrasings),
  owned by the new `GuardrailPolicyService`, not folded into `RedactionService` (a different
  concern: PII/secrets vs. instruction-override attempts).
- CRUD API + a minimal settings surface for `GuardrailPolicy`, mirroring
  `TenantModelPolicyController`/`TenantModelPolicyService`'s existing organisation-scoped
  read/upsert pattern.

### Out of Scope
- Bias/toxicity model-scoring and hallucination detection — that is a model-monitoring
  concern that belongs to the separate `compliance-control-packs` line of work, not a
  deterministic pre/post filter.
- The "learns which commands are safe" ML variant of Hermes' Smart Approvals. This change
  specs a deterministic, admin-configured policy only; an adaptive/learned classifier is a
  clearly separable follow-up, not part of this MVP.
- A new "topics" abstraction (à la Salesforce Agentforce). Hermiq already has a coarse
  allow-list at `Agent.tools` (agent-management-ui); this change adds a finer per-tool risk
  tier *on top of* that existing whitelist, for governance purposes, rather than introducing a
  second, overlapping scoping concept.
- Filtering RAG/context content (retrieved documents, tool results fed back to the LLM) — only
  the user/prompt input and the final LLM output are filtered in this MVP; a compromised
  document smuggling an injection through RAG context is a related but distinct problem
  (context-poisoning) left for a future change.
- Resuming a paused mid-turn LLM loop exactly at the point a `confirm` tool call was refused —
  Hermiq's synchronous request/response architecture has no durable-execution/checkpointing
  layer to make this possible; see design.md's "approve, then retry" decision.

## Approach
Add one new OpenRegister schema (`GuardrailPolicy`) and one new service
(`GuardrailPolicyService`) that mirrors `TenantModelPolicyService`'s per-organisation
resolution shape exactly (own policy → organisation-less instance default → a fully-open
fallback, since guardrails — like `Budget`/`requiresApproval` — are opt-in, not fail-closed).
Thread the resolved policy through the three existing chokepoints every agent-turn call
already funnels through: `Engine::processMessage()` for input/output text, and
`FacadeToolInvoker`/`ToolLoop` for tool calls — the same pattern `RunTraceCollector` and
`StreamYieldChannel` already established for "one optional collaborator threaded through the
whole call chain." Generalise `ApprovalService` a fourth time (it already has
`schedule`/`flow`/`webhook`) to a `sourceType: "toolcall"`, following the exact
`ensurePendingApprovalFor*()`/`resumeGatedRun()` shape the other three already use.

## New Dependencies
None.

## Impact
- New schema: `GuardrailPolicy` in `lib/Settings/hermiq_register.json` (register re-import is
  version-gated — `appinfo/info.xml` patch bump required).
- Extended schema: `Approval.sourceType` gains `"toolcall"`; new `toolId`/`toolArguments`/
  `consumedAt` fields (all optional, present only for `sourceType: "toolcall"`, mirroring how
  `flowContext`/`webhookContext` are already sourceType-specific).
- New service: `lib/Service/GuardrailPolicyService.php`.
- Modified: `lib/Service/Engine/Engine.php`, `lib/Service/ScheduleService.php`,
  `lib/Service/Engine/ToolLoop.php`, `lib/Service/Engine/FacadeToolInvoker.php`,
  `lib/Service/ApprovalService.php`, `lib/Service/DeliveryService.php` (new notification kind
  for a pending toolcall approval, mirroring the existing three).
- New controller: `lib/Controller/GuardrailPolicyController.php` + route entries.
- New frontend: `src/api/guardrailPolicy.js` (mirrors `src/api/modelPolicy.js`); minor additions
  to the existing approvals list rendering for the `toolcall` sourceType.
- `l10n/en.json` + `l10n/nl.json`: new strings for the policy admin surface, the tool-confirm
  approval notification, and the blocked-content chat error.

## Cross-Project Dependencies
None. Hermiq owns no LLM/tool engine of its own beyond the ported `Engine`/`ToolLoop` — all
persistence continues through OpenRegister's `ObjectService` single write-path, and tool
invocation continues through OpenRegister's public `ToolRegistryFacade` (ADR-022/gate-27);
this change adds a policy check *before* that facade call, it does not touch the facade or
OpenRegister itself.

## Risks

### Risk 1: A "block" input filter turns a persistently-misconfigured schedule into a
permanent dead-letter loop
**Severity:** Medium — **Mitigation:** This is deliberate reuse, not a bug: a blocked input
surfaces as a normal agent-turn exception inside `ScheduleService::runDue()`'s existing
try/catch, so it inherits `run-reliability`'s retry/dead-letter/circuit-breaker handling and
the owner is alerted via the existing `deliverFailureAlert()`/`deliverCircuitBreakerAlert()`
path — exactly the same outcome as any other persistently-failing schedule, with zero new
alerting code.

### Risk 2: The tool-confirm "approve, then retry" shape is a weaker guarantee than a true
paused-and-resumed tool call
**Severity:** Medium — **Mitigation:** Documented explicitly as a scope boundary (see Out of
Scope). The refused tool call's synthetic result explicitly tells the LLM (and, in the
interactive-chat case, therefore the user) that the action requires approval and must be
retried — this is a visible, auditable degradation, not a silent one. A future change can add
durable execution if a true pause/resume becomes necessary.

### Risk 3: Diffing `RedactionService::redact()` output to detect PII, rather than adding a
dedicated `containsSensitiveData()` method, is a slightly indirect reuse
**Severity:** Low — **Mitigation:** It is still zero pattern duplication (the actual
regex/detection logic lives in exactly one place), it needs no change to a security-sensitive
class that many other services already depend on, and the diff check is O(string comparison) —
negligible cost. If a future caller needs the same detection more directly, promoting the diff
into a named `RedactionService` method is a pure refactor with no behavior change.

## Rollback Strategy
The register schema addition (`GuardrailPolicy`) and the `Approval.sourceType` extension are
additive (existing objects unaffected). All enforcement points fail open when no
`GuardrailPolicy` exists for an organisation (`allow` action / `auto` classification for every
tool — today's exact behavior), so disabling the feature is: delete any `GuardrailPolicy`
objects an organisation created (or set `enabled: false` on them, mirroring `Budget.enabled`),
which immediately restores today's unfiltered behavior with no code rollback required. A full
code rollback (reverting the PRs) is safe because no existing behavior is removed, only a new
opt-in check is added ahead of it.

## Open Questions
None — the enforcement seams, the reuse of `RedactionService`, and the "approve, then retry"
tool-confirm shape were all verified against the HEAD implementation of `Engine`,
`ScheduleService`, `FacadeToolInvoker`, and `ApprovalService` before writing this proposal.
