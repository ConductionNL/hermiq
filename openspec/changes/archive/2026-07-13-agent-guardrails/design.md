# Design: agent-guardrails

## Architecture Overview
Hermiq already has three governance gates that decide *whether a run happens*: the kill-switch
(`TenantControl`), the budget hard cap (`Budget`), and the human-approval gate (`Approval`,
`sourceType: schedule|flow|webhook`). All three are read at the same synchronous point —
`ScheduleService::dispatch()` for a scheduled tick, and the mirrored gate sequence inside
`FlowAgentRunService::dispatch()`/`WebhookAgentRunService::dispatch()` — immediately before the
agent turn (`ScheduleService::runAgentAsOwner()`) ever fires.

This change adds a fourth, orthogonal axis: *what the agent reads and writes, and which tools
it may call without asking*. It does not touch the existing three gates. It introduces:

1. A new per-organisation `GuardrailPolicy` object (mirrors `ModelPolicy`'s resolution shape).
2. A new `GuardrailPolicyService` that resolves the effective policy and exposes three pure
   enforcement helpers: `filterInput()`, `filterOutput()`, `classifyTool()`.
3. Two new enforcement seams in the agent-turn call chain (input before the LLM call, output
   before persistence/delivery) and one new enforcement seam in the tool-invocation chokepoint
   (`FacadeToolInvoker`).
4. A fourth `Approval.sourceType` (`toolcall`), generalising `ApprovalService` exactly as it was
   already generalised three times before (schedule → flow → webhook).

```
                    ┌─────────────────────────────────────────────┐
                    │              GuardrailPolicyService          │
                    │  effectivePolicyFor(org) → {input,output,    │
                    │                              toolPolicy}      │
                    └───────────────┬───────────────────────────────┘
                                    │ resolved once per turn
        ┌───────────────────────────┼───────────────────────────────┐
        │                           │                                │
        ▼                           ▼                                ▼
 Engine::processMessage()   ScheduleService::               ToolLoop::buildFunctionInfos()
  - filterInput($userMessage)  runAgentAsOwner()               → FacadeToolInvoker
    before storeMessage(user)  - filterInput($prompt) on         - classifyTool() before
    + before the LLM call        the LEGACY ChatService            facade->invokeTool()
  - filterOutput($aiResponse)   branch only (the in-app           - auto:   invoke, unchanged
    before storeMessage         Engine branch is covered by       - confirm: ApprovalService
    (assistant) + before        Engine::processMessage() when       sourceType=toolcall
    returning to the caller     runAgentViaEngine() calls it)     - deny:   refuse, trace step
                               - filterOutput() on the RETURN       outcome='denied', never
                                 value of BOTH branches — the        calls invokeTool()
                                 one seam every caller (runDue(),
                                 FlowAgentRunService,
                                 WebhookAgentRunService) reads
                                 before delivery/persistence
```

## API Design

### `GET /apps/hermiq/api/guardrail-policies`
List every `GuardrailPolicy` the caller may administer (instance admin sees all; an
organisation owner sees their own + the instance default), mirroring
`TenantModelPolicyController::index()`.

### `GET /apps/hermiq/api/guardrail-policies/effective?organisation=<id>`
**Response:**
```json
{
  "source": "organisation",
  "inputFilters": { "piiAction": "redact", "promptInjectionAction": "block" },
  "outputFilters": { "piiAction": "redact" },
  "toolPolicy": [
    { "toolId": "openregister.files.delete", "classification": "confirm" },
    { "toolId": "openregister.files.read", "classification": "auto" }
  ]
}
```

### `PUT /apps/hermiq/api/guardrail-policies?organisation=<id>`
Upserts the policy for `organisation` (empty string = the instance-wide default), mirroring
`TenantModelPolicyController::upsert()`. Request body is the same shape as the effective-policy
response minus `source`.

### `POST /apps/hermiq/api/approvals/{approvalId}/approve` / `.../deny`
Unchanged endpoints (`ApprovalController`, existing) — now also accept an Approval whose
`sourceType` is `toolcall`. `approve()` on a `toolcall` Approval does **not** dispatch a run (see
Decision 4); `deny()` behaves identically to the other three sourceTypes.

## Database Changes
No Nextcloud DB migration — Hermiq owns no database tables (ADR-004, thin-app). This is an
OpenRegister register-schema change: a new `GuardrailPolicy` schema added to
`lib/Settings/hermiq_register.json`, plus new fields on the existing `Approval` schema. The
register re-import is version-gated on `appinfo/info.xml`, so the patch version is bumped by
one (0.1.52 → 0.1.53) to trigger the re-import on next `occ` upgrade/repair, exactly as every
prior schema-adding change in this app has done.

## Nextcloud Integration
- Controllers: new `GuardrailPolicyController` (mirrors `TenantModelPolicyController`); existing
  `ApprovalController` unchanged (already sourceType-agnostic — it just loads/decides an
  `Approval` by UUID).
- Services: new `GuardrailPolicyService`; modified `Engine`, `ScheduleService`, `ToolLoop`,
  `FacadeToolInvoker`, `ApprovalService`, `DeliveryService`.
- Mappers/Entities: none new — everything is an OpenRegister `ObjectEntity` via `ObjectService`,
  exactly like every other Hermiq schema.
- Events/Hooks: none new.

## Security Considerations
This is the whole point of the change, so the enforcement seams are stated precisely rather
than left to the Decisions section:

- **Fail-open by design, not by accident.** No `GuardrailPolicy` for an organisation (the
  default, day-one state) means every filter is a no-op and every tool is `auto` — identical to
  today's behavior. This mirrors `Budget`/`requiresApproval`'s existing opt-in security model in
  this app (see design Decision 1 for why fail-open, not fail-closed, is correct here).
- **The tool-classification check happens *before* `ToolRegistryFacade::invokeTool()` is ever
  called**, not after — a `deny`/pending-`confirm` tool never reaches OpenRegister's registry at
  all. There is no code path where a denied tool's side effect can occur and then be
  discovered after the fact.
- **No new approval mechanism.** A `confirm` tool reuses `ApprovalService`'s existing pending →
  reviewer-notified → approved/denied state machine and the existing `Approval` schema/RBAC
  (IDOR guard via `ApprovalService::isReviewer()`, unchanged). This avoids a second,
  independently-reviewable authorization surface.
- **Redaction reuse, not duplication.** Both filters detect PII/secrets via
  `RedactionService::redact()` — the one place Hermiq's ~40 credential patterns + PII patterns
  live. No new pattern set is introduced for PII; only prompt-injection detection (a genuinely
  new concern — instruction-override phrasing, not a credential/PII shape) is new regex, owned
  by `GuardrailPolicyService`.
- **Guardrail violations are never silent.** Every block/redact/deny is recorded as a trace step
  (`RunTraceCollector`), which — on the schedule/flow/webhook paths — is already folded into the
  per-run `AuditTrail` entry `run-trace-observability` wired up; nothing new needs to be built to
  make a guardrail action "visible in run history."
- **IDOR/RBAC**: the new `GuardrailPolicyController` mirrors
  `TenantModelPolicyController`'s existing authorization shape exactly (instance admin can
  administer any organisation's policy; an organisation owner only their own) — no new
  authorization pattern is introduced.

## File Structure
```
lib/
  Settings/hermiq_register.json        (MODIFIED: + GuardrailPolicy schema, + Approval fields)
  Service/
    GuardrailPolicyService.php          (NEW)
    ApprovalService.php                 (MODIFIED: sourceType=toolcall)
    DeliveryService.php                 (MODIFIED: deliverApprovalRequestForToolCall())
    ScheduleService.php                 (MODIFIED: filterInput/filterOutput seams)
    Engine/
      Engine.php                        (MODIFIED: filterInput/filterOutput seams)
      ToolLoop.php                      (MODIFIED: threads policy + organisation)
      FacadeToolInvoker.php             (MODIFIED: classifyTool() enforcement)
  Controller/
    GuardrailPolicyController.php       (NEW)
  AppInfo/Application.php               (MODIFIED: DI registration if needed)
appinfo/
  routes.php                            (MODIFIED: + guardrail-policies routes)
  info.xml                              (MODIFIED: version bump)
src/
  api/guardrailPolicy.js                (NEW, mirrors modelPolicy.js)
l10n/
  en.json, nl.json                      (MODIFIED: new strings)
```

## Seed Data

### Schema: `guardrailpolicy`
| Field | Object 1 (instance default) | Object 2 (Gemeente Voorbeeld) | Object 3 (agent-scoped-strict tenant) |
|-------|---|---|---|
| `@self.organisation` | `""` (instance default) | `gemeente-voorbeeld` | `strict-tenant` |
| `inputFilters.piiAction` | `redact` | `redact` | `block` |
| `inputFilters.promptInjectionAction` | `block` | `block` | `block` |
| `outputFilters.piiAction` | `redact` | `redact` | `block` |
| `toolPolicy` | `[]` (all auto) | `[{files.delete, confirm}, {mail.send, confirm}]` | `[{files.delete, deny}, {mail.send, confirm}, {files.read, auto}]` |
| `enabled` | `true` | `true` | `true` |

**Related items per object:** none (a `GuardrailPolicy` is a standalone governance object, like
`ModelPolicy`/`Budget` — no file/note/task/contact relations).

## Decisions

### Decision 1: Fail-open (opt-in), not fail-closed, when no policy exists
**Choice:** An organisation with no `GuardrailPolicy` (and no instance default) behaves exactly
as Hermiq does today — no filtering, every tool `auto`.
**Alternative considered:** Mirror `TenantModelPolicyService`'s fail-*closed* fallback (which
restricts to the single currently-configured LLM provider when no policy exists anywhere).
**Rationale:** `TenantModelPolicyService`'s fail-closed default protects against an unbounded
cost/vendor surface (any provider) that must never be silently "everything allowed." Guardrails
protect a *content/behavior* surface where the existing precedent in this exact app
(`Schedule.requiresApproval`, `Budget`) is opt-in-per-organisation, defaulting to off. Making
guardrails fail-closed by default would mean every existing installation's tools silently
switch from `auto` to some undefined "deny everything," which is both a breaking change for
current users and inconsistent with how this app treats every other opt-in governance control.

### Decision 2: Detect PII by diffing `RedactionService::redact()`, not a new detector method
**Choice:** `GuardrailPolicyService::filterInput()`/`filterOutput()` call
`$this->redactionService->redact($text)` and compare the result to the input; a difference means
PII/secrets were found. The (already masked) result IS the "redact" action's output directly; for
the "block" action, the *presence* of a difference is enough — the original text is discarded
and replaced with a placeholder/refusal.
**Alternative considered:** Add a new `RedactionService::containsSensitiveData(): bool` method.
**Rationale:** The diff-based check needs zero changes to a class many other services already
depend on (`ApprovalService`, `ScheduleService`, `FlowAgentRunService`, `WebhookAgentRunService`)
and duplicates no pattern. A named method would be marginally more explicit but is a pure
refactor available later with no behavior change — not worth touching a security-load-bearing
class for.

### Decision 3: Prompt-injection detection is new, deterministic regex — not part of
`RedactionService`
**Choice:** A small, fixed list of known instruction-override phrasings ("ignore previous
instructions", "disregard the system prompt", "you are now in developer mode", "reveal your
system prompt", etc. — case-insensitive substring/regex match) lives in
`GuardrailPolicyService`, checked only when `inputFilters.promptInjectionAction !== 'off'`.
**Alternative considered:** Extend `RedactionService` with a `redactPromptInjection()` pass,
keeping "all input-side text transforms" in one class.
**Rationale:** `RedactionService`'s docblock is explicit about its single responsibility —
"masks secrets/PII... before an audit write" — and every one of its ~13 private pass methods is
a credential/PII shape. Prompt injection is a different threat model (instruction confusion, not
data leakage) with a different owner (a guardrail policy an org admin tunes) and a different
action space (`block`, never `redact` — you cannot "mask" an injection attempt into something
safe, you can only refuse it). Keeping it in the new, purpose-built service is a cleaner
separation of concerns than growing `RedactionService` into "the input-text filter."
**Deferred (explicitly out of scope, per proposal):** an ML/learned classifier for prompt
injection or for "safe commands" (Hermes' Smart Approvals) — this MVP is a fixed, deterministic
pattern list only.

### Decision 4: A `confirm` tool call is refused-then-retried, never paused-and-resumed
**Choice:** `FacadeToolInvoker::__call()`, on a `confirm`-classified tool:
1. Computes `correlationId = hash('sha256', json_encode([agentId, toolId, ksort($arguments)]))`.
2. If an **approved, unconsumed** `toolcall` Approval with this `correlationId` exists and its
   `decidedAt` is within a fixed TTL (1 hour, a class constant — no new schema field): marks it
   consumed (`consumedAt` set) and calls `$this->facade->invokeTool()` normally — the tool runs.
3. Else if a **pending** `toolcall` Approval with this `correlationId` already exists: no new
   Approval is created (idempotent, mirrors `ensurePendingApproval()`'s existing idempotency);
   returns a synthetic tool-result telling the LLM the action is still awaiting approval.
4. Else (first time): calls `ApprovalService::ensurePendingApprovalForToolCall()` (new method,
   mirrors `ensurePendingApprovalForFlowRun()`/`ensurePendingApprovalForWebhookRun()` exactly),
   notifies the reviewer, and returns the same "awaiting approval" synthetic tool-result.
5. In cases 2/3/4, the invocation is recorded as a `tool` trace step with outcome
   `'invoked_after_approval'` / `'awaiting_approval'` / `'awaiting_approval'` respectively —
   never a fabricated `'ok'`.
**Alternative considered:** Durable-execution-style pause: persist the LLM loop's partial state
(message history + pending tool call) when a `confirm` tool is hit, and truly resume the SAME
turn from that exact point once approved.
**Rationale:** Hermiq's agent turn is a synchronous PHP call stack
(`Engine::processMessage()` → `ResponseGenerationHandler::generateResponse()` →
LLPhant's `generateChat()` tool-calling loop) with no checkpointing/durable-execution layer —
building one is a project-sized effort orthogonal to guardrails and explicitly out of scope
(proposal Out of Scope). "Refuse, then let a matching retry through" is the same shape
`Schedule.requiresApproval`/`AgentWebhook.requiresApproval` already use at the *run* level (the
run does not happen; a later approved occurrence is a fresh dispatch, not a resumed one) —
applying the identical shape one level down, at the *tool call* level, is consistent rather than
inventing a new paradigm. The trade-off (Risk 2 in the proposal) is explicit: the LLM (and, in
interactive chat, the human) must notice the refusal and retry — this is visible/auditable
degradation, not silent.
**Consumption is single-use** (`consumedAt` set on first successful match) so an approved
destructive tool call cannot be replayed indefinitely within the TTL window — it authorizes
exactly one subsequent identical attempt.

### Decision 5: `ApprovalService::resumeGatedRun()`'s new `toolcall` branch is a no-op
**Choice:** Unlike the `schedule`/`flow`/`webhook` branches (which each dispatch a full run),
approving a `toolcall` Approval returns `ran: false` and dispatches nothing — see Decision 4,
approval only authorizes a future matching retry, it does not itself re-execute anything (there
is no paused turn to resume).
**Rationale:** Consistent with Decision 4; stated separately because it is the one place
`ApprovalService`'s existing "approve dispatches the gated run" invariant is deliberately broken
for this fourth sourceType, and that deserves its own explicit call-out for a reviewer.

### Decision 6: Tool classification is resolved once per turn and threaded like
`RunTraceCollector`/`StreamYieldChannel`, not re-resolved per tool call
**Choice:** `ResponseGenerationHandler::generateResponse()` resolves `$organisation` (already
does, for tenant-model-policy) and passes it to `ToolLoop::buildFunctionInfos()`, which resolves
the effective `GuardrailPolicy` ONCE and passes the resolved `toolPolicy` map into the single
`FacadeToolInvoker` instance shared by every `FunctionInfo` built for that turn.
**Rationale:** Mirrors the exact existing pattern (`$channel`/`$trace` are resolved once per
turn and threaded through the same call chain) rather than introducing a second threading
convention; avoids N policy reads for N tool calls in one turn.

### Decision 7: Output filter fires at two boundaries, input filter at effectively one
**Choice:** Input: `Engine::processMessage()` (covers interactive chat + the in-app-Engine
branch reached via `runAgentViaEngine()`) plus the legacy-`ChatService` branch of
`ScheduleService::runAgentAsOwner()` (the one path `Engine::processMessage()` never sees) — two
call sites, but each actual run is filtered exactly once. Output: `Engine::processMessage()`
before persistence (the assistant `Message` object) AND `ScheduleService::runAgentAsOwner()`'s
single return point before the string reaches ANY caller (`runDue()` before
`DeliveryService::deliver()`; `FlowAgentRunService`/`WebhookAgentRunService` before their own
persistence writes) — this second output check is a deliberate defense-in-depth re-check at a
different trust boundary (about to leave the governed audit trail and reach a human via Talk/
notification, or be written into an arbitrary object field), not redundant busywork: on the
legacy `ChatService` path it is the ONLY output check, since `Engine::processMessage()` is never
invoked there.
**Rationale:** Placing both checks at the two real trust-boundary crossings (LLM input; and
each of "stored for later reading" vs. "pushed out/written externally") gives complete coverage
across all four entry paths (chat, schedule, flow, webhook) and both engine-flag states with the
minimum number of new call sites, matching how `RedactionService`'s own
redaction-before-persist boundary was reasoned about.

## Risks / Trade-offs
- [Risk] A misconfigured `promptInjectionAction: block` policy could false-positive on
  legitimate prompts containing benign phrases that coincidentally match a pattern (e.g. a user
  quoting "ignore the previous email" in a support-ticket triage agent) → **Mitigation:** the
  pattern list is intentionally narrow (fixed override/jailbreak phrasings, not generic words
  like "ignore"), it is opt-in per organisation, and a blocked input is fully visible (trace
  step + `lastError` for scheduled runs, an explicit chat error for interactive chat) so a false
  positive is immediately diagnosable and the org admin can turn the action to `off`/`redact`.
- [Risk] `consumedAt`/TTL-based tool-confirm consumption is checked/written without a
  transaction — a race between two near-simultaneous retries of the SAME tool call could both
  read "approved, unconsumed" before either writes `consumedAt` → **Mitigation:** OpenRegister's
  `ObjectService::saveObject()` is the single write path and Hermiq has no cross-object lock
  primitive available to it (same constraint every other Hermiq idempotency check —
  `findPendingApprovalForCorrelation()` etc. — already accepts); the blast radius of a double
  invocation is bounded to exactly one extra execution of an ALREADY-HUMAN-APPROVED action
  within the TTL window, not an unapproved one, so this is judged acceptable for the MVP (same
  risk profile the existing idempotency checks elsewhere in `ApprovalService` already carry).
- [Risk] Adding a policy-resolution read to `ToolLoop::buildFunctionInfos()` (called once per
  agent turn) adds one extra `ObjectService::findAll()` query per turn → **Mitigation:** this
  is the same cost `TenantModelPolicyService::effectivePolicyFor()` already introduced for the
  model-policy check on every turn; it is a per-organisation, cache-free read exactly like that
  precedent, not a new class of cost.

## Migration Plan
No `lib/Migration/` class — see migration.md skip note. Deploy order: (1) merge the schema
change + service/enforcement code together (the schema addition is additive, so it is safe to
ship even before any organisation configures a policy); (2) bump `appinfo/info.xml` patch
version so the register re-import picks up `GuardrailPolicy` + the extended `Approval` fields;
(3) no data backfill needed — every existing organisation reads as "no policy" (fail-open,
Decision 1) until an admin opts in.

## Open Questions
None — see proposal.md's Open Questions for the same conclusion; every enforcement seam and
each schema field in this document was checked against the HEAD implementation of the files
listed in File Structure before writing it.

## Trade-offs
See Decisions 1–7 above; each states the alternative considered and why it was rejected. The
overarching trade-off of this whole design is breadth-of-coverage (four entry paths, two engine
flags, one tool chokepoint) versus a single unified enforcement point — a single point was not
available because `ScheduleService::runAgentAsOwner()` genuinely branches into two
structurally-different call targets (OpenRegister's `ChatService` vs. Hermiq's own `Engine`),
a constraint this change did not introduce and must work within until `or-chat-proxy-deprecation`
removes the legacy branch entirely.
