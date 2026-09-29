# ai-feature-governance Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- models-bind-a-provider-per-feature

## Purpose

Give the administrator a control to choose the provider and model of each AI feature. Row `hermiq:mo-per-feature`.

## ADDED Requirements

### Requirement: An administrator chooses the provider of one AI feature (REQ-AIBIND-001)

The AI-feature register MUST offer "Change provider" on every feature. The dialog MUST offer only the providers and models the organisation's model policy allows, and a residency. Saving MUST call `PUT /api/ai-features/{id}/binding`; the register MUST then show the provider, model and residency the server stored. A refusal from the server MUST be shown in the dialog in the server's words and MUST leave the row unchanged. "Use the organisation default" MUST clear the binding.

#### Scenario: An administrator puts summaries on a local model
- GIVEN an administrator on the AI-feature register, and a model policy that allows ollama and openai
- WHEN they choose "Change provider" on "Summarise a case", pick ollama with the model llama3 and save
- THEN the register shows "Summarise a case" running on ollama, llama3, on the instance

#### Scenario: A binding the policy does not allow
- GIVEN the policy changed after the dialog opened and no longer allows openai
- WHEN the administrator saves openai for "Translate a message"
- THEN the dialog shows the server's refusal naming the policy and the register still shows the old provider

#### Scenario: Back to the default
- GIVEN a feature bound to ollama
- WHEN the administrator chooses "Use the organisation default" and saves
- THEN the register shows the feature running on the policy default
