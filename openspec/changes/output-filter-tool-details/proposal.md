---
kind: code
---

# Proposal: output-filter-tool-details

## Why

An organisation can set an output filter on its guardrail policy. Today that filter covers the
model's answer text only. The chat also shows each tool call the agent makes: the arguments the
model wrote and the result the tool returned. Those details reach the user unfiltered.

So a policy that redacts keys and personal data in the answer can still show the same data in
the tool detail. That holds for the live chat stream and for the steps a `cli` turn records.

## What changes

- The output filter applies to the arguments and the result of every tool call shown to the
  user. It covers the `tool_call` and `tool_result` stream frames, the recorded `cli` steps,
  their stream replay and the `toolCalls` of a blocking send.
- The policy that decides is the conversation's, the same policy that filters the answer.
- The tool still runs with the model's arguments, and the model still reads the real result.
- With the filter off, nothing changes.

## Impact

- `GuardrailPolicyService::filterOutputValue()` applies the output filter to a tool argument
  set or result.
- `StreamYieldChannel::screenToolDetails()` lets the turn filter tool frames before any
  listener sees them. `Engine::processMessage()` sets it from the policy it resolved for the
  conversation, the one that filters the answer.
- `McpRunController` filters a `cli` step before it records it on `RunStepBus`, under the
  policy of the conversation the run token names. A JSON result is filtered as its decoded
  value.
