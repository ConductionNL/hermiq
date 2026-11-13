# Tasks: output-filter-tool-details

## Implementation tasks

### Task 1: Filter a tool argument set or result with the output policy
- **spec_ref**: `openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery`
- **files**: `lib/Service/GuardrailPolicyService.php`
- **acceptance_criteria**:
  - With the filter off, the value comes back as given
  - Under `redact`, a secret in a string or in any field is masked
  - Under `block`, the value becomes the withheld placeholder

- [x] Add `filterOutputValue()`

### Task 2: Filter the tool frames of the chat stream
- **spec_ref**: `openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery`
- **files**: `lib/Service/Engine/StreamYieldChannel.php`, `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - `tool_call` and `tool_result` frames carry the filtered arguments and result
  - The tool runs with the model's arguments, and the model reads the real result
  - With the filter off, the frames are unchanged
  - The conversation's policy decides, not the agent's organisation

- [x] Add `StreamYieldChannel::screenToolDetails()` and set it in `Engine::processMessage()` from the conversation's policy

### Task 3: Filter the steps a cli turn records
- **spec_ref**: `openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery`
- **files**: `lib/Controller/McpRunController.php`, `lib/Service/Engine/RunStepBus.php`
- **acceptance_criteria**:
  - The recorded step holds the filtered arguments and result
  - The stream replay and the `toolCalls` of a blocking send show only the recorded step
  - The CLI still receives the real result
  - The conversation's policy decides; when the conversation cannot be read, the step shows the withheld placeholder
  - A JSON result is filtered as its decoded value, so escaped slashes do not hide a match

- [x] Filter before `RunStepBus::record()`

### Task 4: Tests
- **files**: `tests/Unit/Service/Engine/ToolDetailOutputFilterTest.php`, `tests/Unit/Controller/ToolDetailDriveTest.php`
- **acceptance_criteria**:
  - Each redact and block scenario fails without the change
  - The filter-off scenario pins the frames and the recorded step byte for byte

- [x] Add `ToolDetailOutputFilterTest`
- [x] Add `ToolDetailDriveTest` (blocking send, SSE stream and cli replay)
