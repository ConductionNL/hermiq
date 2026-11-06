# provider-prompt-caching Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-prompt-and-answer-cache

## Purpose

Hermiq marks the stable part of each model request for the provider's prompt cache and reports what came from it. Row `hermiq:dm-prompt-cache`.

## ADDED Requirements

### Requirement: Anthropic requests carry cache breakpoints by default (REQ-PCACHE-001)

On the `http` transport hermiq MUST send `cache_control` breakpoints on the last tool definition, on the stable system block and on the last message before the newest user message, unless an admin switched `promptCaching` off. The breakpoint on the conversation MUST move to the last tool result in each tool round.

#### Scenario: A long agent prompt is cached across turns
- GIVEN an agent with a 3,000 token prompt on Anthropic and prompt caching left on
- WHEN a case handler sends a second message in the same session
- THEN the request carries the three breakpoints, and the run's usage shows cache read tokens greater than zero
- @e2e exclude needs a live Anthropic account; covered by PHPUnit on the payload and one live check

#### Scenario: An admin switches caching off
- GIVEN an admin in Admin settings, LLM provider, Anthropic
- WHEN they switch off "Use the provider's prompt cache" and save
- THEN the next request carries no `cache_control` field

### Requirement: The stable part of the system prompt comes first and stays the same (REQ-PCACHE-002)

Hermiq MUST assemble the system prompt as a stable block (the agent's prompt and context preamble) followed by a per-turn block (app context and retrieved context), so the cached prefix does not change between turns of the same agent version.

#### Scenario: Retrieved context does not break the cache
- GIVEN an agent with retrieval on
- WHEN two turns retrieve different documents
- THEN the stable block is byte-identical in both requests and only the per-turn block differs
- @e2e exclude request payload inspection; covered by PHPUnit on the prompt assembly

### Requirement: The run reports cache tokens (REQ-PCACHE-003)

Hermiq MUST record `cacheReadTokens` and `cacheWriteTokens` on the run's usage when the provider reports them, and `promptTokens` MUST include cached input tokens.

#### Scenario: An organisation admin sees the saving
- GIVEN a run whose provider read 1,840 of 2,300 input tokens from its cache
- WHEN an organisation admin opens the run on the Runs page
- THEN the run shows "1,840 of 2,300 input tokens from the provider's cache", and the budget counts 2,300 input tokens
