# agent-guardrails (new capability)

Adds a per-organisation `GuardrailPolicy` that filters agent input/output content (PII, secrets,
prompt-injection patterns) and classifies each callable tool as `auto`/`confirm`/`deny`,
enforced at the same synchronous points the existing kill-switch/budget/human-approval gates
already use. A `confirm` tool reuses the existing `Approval` state machine (a new
`sourceType: "toolcall"`) instead of a second approval mechanism; a `deny` tool is refused and
recorded as a trace step, visible in run history via the existing `run-trace-observability`
step timeline. No `GuardrailPolicy` for an organisation means today's unfiltered behavior is
unchanged (fail-open, opt-in — mirroring `Budget`/`Schedule.requiresApproval`).

## ADDED Requirements

### Requirement: Per-organisation guardrail policy with a fully-open fallback
The system MUST allow an organisation admin (or an instance admin, for the organisation-less
instance default) to configure a `GuardrailPolicy` object declaring input filters, output
filters, and a per-tool risk classification list. When no policy exists for an organisation and
no organisation-less instance default exists either, the system MUST behave as if every filter
is `off` and every tool is classified `auto` — identical to the behavior before this change.

#### Scenario: An organisation with no configured policy is fully unaffected
- **GIVEN** an organisation with no `GuardrailPolicy` object and no organisation-less instance
  default
- **WHEN** any agent turn runs for that organisation
- **THEN** the system MUST NOT block or redact any input or output
- **AND** every tool call MUST be classified `auto` and invoked without any additional gate

#### Scenario: An organisation's own policy takes precedence over the instance default
- **GIVEN** an organisation-less instance-default `GuardrailPolicy` and organisation A's own
  `GuardrailPolicy`, with different `toolPolicy` entries for the same tool
- **WHEN** an agent turn runs for organisation A
- **THEN** the system MUST resolve and apply organisation A's own policy, not the instance
  default

### Requirement: Input is filtered before every LLM turn
The system MUST apply the effective policy's input filters to the user/prompt text before it is
sent to the LLM: a PII/secret match MUST be redacted (masked) when `piiAction: redact`, or the
turn MUST be refused before the LLM is ever called when `piiAction: block`; a detected
prompt-injection pattern MUST refuse the turn before the LLM is ever called when
`promptInjectionAction: block`. The redacted (not the original) text MUST be what is both sent
to the LLM and persisted, when the action is `redact`.

#### Scenario: PII in the input is redacted, not blocked
- **GIVEN** an organisation's policy sets `inputFilters.piiAction: redact`
- **WHEN** a user's message contains a detectable secret or PII pattern
- **THEN** the system MUST send the redacted (masked) text to the LLM
- **AND** the persisted copy of the message MUST also be the redacted text, never the original

#### Scenario: A detected prompt-injection pattern blocks the turn before the LLM runs
- **GIVEN** an organisation's policy sets `inputFilters.promptInjectionAction: block`
- **WHEN** an incoming prompt matches a known instruction-override pattern
- **THEN** the system MUST NOT call the LLM at all for this turn
- **AND** the block MUST be recorded as a guardrail violation (see the run-history requirement
  below)

#### Scenario: A blocked scheduled run is recorded and alerted like any other run failure
- **GIVEN** a schedule whose configured `prompt` is blocked by the effective input policy
- **WHEN** the dispatcher fires the schedule
- **THEN** the run MUST fail with a guardrail-block reason exactly like any other agent-turn
  failure, inheriting the existing retry/dead-letter/circuit-breaker handling and owner alerting
  — no new failure-handling path is introduced

### Requirement: Output is filtered before persistence and before delivery
The system MUST apply the effective policy's output filters to the LLM's response text at two
points: before it is persisted (e.g. as a chat `Message`, or written into a flow/webhook
`resultField`), and before it is handed to `DeliveryService` for Talk/notification delivery. A
PII/secret match MUST be redacted when `piiAction: redact`; when `piiAction: block`, the raw
output MUST NOT be persisted or delivered and MUST be replaced with a placeholder indicating the
response was withheld.

#### Scenario: Secrets in the output are redacted before the chat message is stored
- **GIVEN** an organisation's policy sets `outputFilters.piiAction: redact`
- **WHEN** the LLM's response contains a detectable secret pattern
- **THEN** the persisted assistant `Message` MUST contain the redacted text, never the raw
  secret
- **AND** the same redacted text MUST be what is returned to the caller

#### Scenario: A blocked output is never delivered to Talk or a notification
- **GIVEN** a schedule whose policy sets `outputFilters.piiAction: block`
- **WHEN** the scheduled agent turn's output contains a detectable secret pattern
- **THEN** `DeliveryService` MUST receive and deliver only the withheld-response placeholder,
  never the raw output

#### Scenario: The output filter still applies on the legacy (non-in-app-engine) execution path
- **GIVEN** an installation with the in-app engine feature flag off (the default)
- **WHEN** a scheduled run's output would otherwise be delivered or persisted
- **THEN** the output filter MUST still be applied at the point every execution path returns its
  result, regardless of which underlying execution path produced it

### Requirement: Per-tool risk classification enforced before invocation
The system MUST classify every callable tool as `auto`, `confirm`, or `deny` per the effective
policy (default `auto` for any tool absent from the policy's list) and MUST apply that
classification BEFORE the tool registry is ever invoked: an `auto` tool MUST be invoked
unchanged; a `deny` tool MUST NOT be invoked under any circumstance; a `confirm` tool MUST NOT be
invoked until a matching human approval exists.

#### Scenario: An auto-classified tool is invoked with no additional gate
- **GIVEN** a tool with no explicit entry in the effective policy's `toolPolicy`
- **WHEN** the agent calls that tool
- **THEN** the system MUST invoke it exactly as it would with no guardrail policy configured

#### Scenario: A deny-classified tool is never invoked
- **GIVEN** a policy classifying `files.delete` as `deny`
- **WHEN** the agent calls `files.delete`
- **THEN** the system MUST NOT invoke the underlying tool
- **AND** MUST return a refusal result to the LLM so the agent turn can continue

### Requirement: A confirm-classified tool call reuses the existing human-approval gate
The system MUST NOT introduce a second approval mechanism for a `confirm`-classified tool. The
first attempt to call a `confirm` tool MUST be refused and MUST create a pending `Approval`
object (`sourceType: "toolcall"`) carrying the tool id and arguments, routed to a reviewer and
notified exactly as the existing schedule/flow/webhook approval flows are. Approving that
`Approval` MUST NOT itself re-execute anything; it MUST authorize exactly one subsequent,
argument-matching retry of the same tool call within a bounded time window, after which the
authorization expires.

#### Scenario: A confirm tool's first attempt is refused and creates a pending approval
- **GIVEN** a policy classifying `mail.send` as `confirm`
- **WHEN** the agent calls `mail.send` for the first time with a given set of arguments
- **THEN** the system MUST NOT invoke the underlying tool
- **AND** MUST create exactly one pending `Approval` (`sourceType: "toolcall"`) for that
  agent/tool/arguments combination and notify the resolved reviewer

#### Scenario: A repeated attempt while the approval is still pending creates no duplicate
- **GIVEN** a pending `toolcall` Approval already exists for a given agent/tool/arguments
  combination
- **WHEN** the agent (or a retried turn) calls the same tool with the same arguments again
- **THEN** the system MUST NOT create a second pending `Approval`
- **AND** MUST NOT invoke the underlying tool

#### Scenario: An approved retry within the validity window succeeds exactly once
- **GIVEN** a reviewer has approved a pending `toolcall` Approval for a given agent/tool/
  arguments combination
- **WHEN** a subsequent turn calls the identical tool with the identical arguments within the
  approval's validity window
- **THEN** the system MUST invoke the underlying tool exactly this one time
- **AND** a further identical retry after this one MUST be treated as a new, unapproved attempt

### Requirement: Every guardrail action is visible in run history
The system MUST record every input block, output block/redaction, tool denial, and tool
confirm-request as a trace step, using the existing per-run step timeline
(`run-trace-observability`) — no new logging or audit mechanism is introduced for this purpose.

#### Scenario: A denied tool call appears in the run's step timeline
- **GIVEN** an agent run in which a `deny`-classified tool is called
- **WHEN** the run completes and its step timeline is persisted with the run's audit entry
- **THEN** the timeline MUST include a step for that tool call with an outcome indicating it was
  denied
- **AND** this step MUST be visible wherever the run's history is displayed

### Requirement: Guardrail policy administration is authorization-guarded
The system MUST let an instance admin administer any organisation's `GuardrailPolicy` (or the
organisation-less instance default) and MUST let an organisation owner administer only their own
organisation's policy, mirroring the existing `tenant-model-policy` authorization model.

#### Scenario: An organisation owner cannot read or modify another organisation's policy
- **GIVEN** organisation A's owner and organisation B's `GuardrailPolicy`
- **WHEN** organisation A's owner requests organisation B's policy
- **THEN** the system MUST reject the request
