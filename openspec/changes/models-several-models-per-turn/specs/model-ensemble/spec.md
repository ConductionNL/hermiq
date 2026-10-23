# model-ensemble Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-several-models-per-turn

## Purpose

An agent answers one prompt with several models and merges their answers into one reply, within the model policy and the budget. Row `hermiq:dm-model-ensemble`.

## ADDED Requirements

### Requirement: An agent owner can switch on ensemble answers (REQ-MENS-001)

An `Agent` MAY carry `ensemble` with two or three reference models, each a provider and model pair. Hermiq MUST refuse to save a reference model that the organisation's effective model policy does not allow.

#### Scenario: An agent owner sets up an ensemble
- GIVEN an agent owner editing the agent "Beleidsadviseur" in the agent form
- WHEN they switch on "Answer with several models", pick `anthropic` `claude-opus-4-8` and `ollama` `qwen2.5` as reference models and save
- THEN the agent shows "Ensemble: 2 reference models" and the form states "Each answer costs up to four model calls."

#### Scenario: A reference model outside the policy is refused
- GIVEN an organisation whose policy allows only `ollama`
- WHEN the agent owner picks `openai` as a reference model and saves
- THEN the save is refused with "This model is not allowed by your organisation's model policy." and the agent is unchanged

### Requirement: Reference models answer without tools and the agent's own model merges (REQ-MENS-002)

In an ensemble turn hermiq MUST send the assembled prompt to each reference model without offering any tool. It MUST then run the agent's own turn with the reference answers added as labelled context and with the agent's granted tools, so a tool runs at most as often as in a single-model turn. The merged answer MUST be the only reply the user sees, and the reference answers MUST be kept on the run.

#### Scenario: One question, one merged answer
- GIVEN an ensemble agent with two reference models
- WHEN a policy officer asks "Welke regels gelden voor een terrasvergunning?" in the chat
- THEN the chat shows one reply, the run's steps show "Asked 2 models" before the answer, and the run holds both reference answers

#### Scenario: A reference model that fails is left out
- GIVEN an ensemble agent whose second reference model answers HTTP 503
- WHEN a turn runs
- THEN the merge uses the answer that arrived, the run records the failed reference with its reason, and the turn does not fail
- @e2e exclude needs a reference model that fails on demand; covered by PHPUnit with stubbed drivers

### Requirement: Each reference model passes the same checks as the agent's model (REQ-MENS-003)

Before calling a reference model hermiq MUST apply the model policy and, when the agent names an AI feature, the feature's residency and redaction checks. A reference model that fails a check MUST be skipped and recorded, and MUST NOT be called.

#### Scenario: A reference model outside the required residency is skipped
- GIVEN an ensemble agent whose AI feature requires residency `on-premise`, with reference models `ollama` (declared `on-premise`) and `anthropic` (declared `outside-eu`)
- WHEN a turn runs
- THEN only `ollama` is called, and the run records `anthropic` as skipped with step `residency`
- @e2e exclude residency declarations for two live providers; covered by PHPUnit on the ensemble turn

### Requirement: An ensemble counts every call against the budget (REQ-MENS-004)

Hermiq MUST check the budget hard cap before each reference call and MUST record each call's token usage per model on the run. The run's `usage` MUST be the sum of all calls. When the cap is reached, hermiq MUST stop calling reference models and MUST merge what arrived, saying so in the run's steps.

#### Scenario: The budget runs out during an ensemble
- GIVEN an ensemble agent whose organisation budget reaches its hard cap after the first reference call
- WHEN the turn continues
- THEN no second reference model is called, the steps say "Budget reached: answered from 1 model", and the run's usage counts both calls that ran
- @e2e exclude needs a budget at its cap mid-turn; covered by PHPUnit with BudgetService stubbed
