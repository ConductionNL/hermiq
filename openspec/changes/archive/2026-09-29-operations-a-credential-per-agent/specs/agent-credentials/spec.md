# agent-credentials Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- operations-a-credential-per-agent

## Purpose

Give each agent its own provider credential. Row `hermiq:op-agent-credential`.

## ADDED Requirements

### Requirement: An agent can carry its own credential per provider (REQ-AGCRED-001)

The Agent MUST accept an optional `credentialIds` map from provider to a broker credential uuid. The agent form MUST let the owner pick, per provider, one of the credentials they may use for hermiq. Only the owner and administrators MUST be able to change it.

#### Scenario: An owner gives an agent its own key
- GIVEN the owner of "Supplier digest" and "Permit helper", both in the same organisation
- WHEN they open "Supplier digest" in the agent form, pick the credential "Digest OpenAI key" for openai and save
- THEN the next turn of "Supplier digest" uses that key and "Permit helper" keeps using the organisation's key

### Requirement: A pinned credential goes first and is never bypassed (REQ-AGCRED-002)

When an agent has a credential pinned for the provider of a turn, the system MUST use it before the personal and organisation credentials. When the broker refuses the pinned credential, the turn MUST stop with "The credential pinned to this agent cannot be used for this run." and MUST NOT use another credential.

#### Scenario: The pinned key is no longer allowed
- GIVEN an agent pinned to a credential whose allowed apps no longer include hermiq
- WHEN a user sends it a message
- THEN the chat shows "The credential pinned to this agent cannot be used for this run." and no model is called
- @e2e exclude broker refusal needs a second credential store state, covered by PHPUnit on the resolver and ProviderFactory
