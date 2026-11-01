# agent-guardrails (delta)

The output filters also cover what the chat shows of a tool call: the arguments the
model wrote and the result the tool returned.

## MODIFIED Requirements

### Requirement: Output is filtered before persistence and before delivery
The system MUST apply the effective policy's output filters to the LLM's response text at two
points: before it is persisted (e.g. as a chat `Message`, or written into a flow/webhook
`resultField`), and before it is handed to `DeliveryService` for Talk/notification delivery. A
PII/secret match MUST be redacted when `piiAction: redact`; when `piiAction: block`, the raw
output MUST NOT be persisted or delivered and MUST be replaced with a placeholder indicating the
response was withheld.

The same filters MUST apply to the tool call details shown to the user: the arguments of each
tool call and the result of each tool call. This covers the `tool_call` and `tool_result`
frames of the chat stream, the steps a `cli` turn records for the chat, their replay on the
stream, and the `toolCalls` a blocking send returns. Under `redact`, the user sees the redacted
arguments and result. Under `block`, the user sees the withheld placeholder in their place.

The filters MUST NOT change what the tool runs with or what the model reads back. The tool
receives the arguments the model wrote, and the model receives the tool's real result. Only the
copy shown to the user, and the copy stored for the chat, is filtered.

With the output filter off, the tool call details MUST be shown exactly as before.

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

#### Scenario: A secret in a tool result is redacted before the chat shows it
- **GIVEN** an organisation's policy sets `outputFilters.piiAction: redact`
- **WHEN** a tool the agent calls returns a result that contains a detectable secret pattern
- **THEN** the `tool_result` frame on the chat stream MUST carry the redacted result, never the
  raw secret
- **AND** the model MUST still receive the tool's real result
- @e2e exclude needs a live model that calls a tool and a seeded secret; covered by ToolDetailOutputFilterTest

#### Scenario: Tool call arguments are filtered for the chat but not for the tool
- **GIVEN** an organisation's policy sets `outputFilters.piiAction: redact`
- **WHEN** the model calls a tool with arguments that contain a detectable secret pattern
- **THEN** the `tool_call` frame on the chat stream MUST carry the redacted arguments
- **AND** the tool MUST run with the arguments the model wrote
- @e2e exclude needs a live model that writes a secret into a tool call; covered by ToolDetailOutputFilterTest

#### Scenario: A cli turn's recorded steps are filtered before they are stored
- **GIVEN** an organisation's policy sets `outputFilters.piiAction: redact`
- **AND** an agent that runs with `executionMode: cli`
- **WHEN** a tool call over the governed MCP transport has a secret in its arguments or result
- **THEN** the step recorded for the chat MUST hold the redacted arguments and result
- **AND** the stream replay and the `toolCalls` of a blocking send MUST show only that redacted
  step
- **AND** the CLI MUST still receive the tool's real result
- @e2e exclude needs a running CLI runner; covered by ToolDetailOutputFilterTest

#### Scenario: A blocked tool detail is shown as withheld
- **GIVEN** an organisation's policy sets `outputFilters.piiAction: block`
- **WHEN** a tool call's arguments or result contain a detectable secret pattern
- **THEN** the chat MUST show the withheld placeholder in place of that value, on the stream
  and in the recorded step
- **AND** the tool MUST still run with the arguments the model wrote
- @e2e exclude needs a live model that calls a tool and a seeded secret; covered by ToolDetailOutputFilterTest

#### Scenario: With the output filter off, tool details are shown as they are
- **GIVEN** an organisation with no policy, or a policy with `outputFilters.piiAction: off`
- **WHEN** an agent calls a tool
- **THEN** the `tool_call` and `tool_result` frames and the recorded steps MUST be exactly what
  they were before this change
- @e2e exclude a byte-for-byte frame comparison is a unit-level check; covered by ToolDetailOutputFilterTest
