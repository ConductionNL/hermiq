---
kind: code
depends_on: []
---

# Proposal: operations-a-credential-per-agent

## Summary

The owner of an agent can pin a credential to that agent for a provider, so two agents run by the same person in the same organisation no longer share one key. A pinned credential goes first; when the broker refuses it for a run, the run stops with a clear error instead of quietly using a broader credential.

## Why

One row of hermiq's capability matrix that is `building`, decided in the build-all pass of 2026-09-28.

| row | own rating | decision |
|---|---|---|
| `hermiq:op-agent-credential` | no, building | build: three competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- Hermes Agent v2026.9.24: "agent/secret_scope.py:1-10 each profile's own .env keys resolve only inside that profile's turns (fail-closed under multiplexing)".
- Dify 1.17.1: "api/controllers/console/agent/roster.py:1032,1062 per-agent service API keys (create, list, revoke); per-agent secret environment variables injected only at runtime".
- Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/admin-use-entra-agent-identities "every new agent gets its own Microsoft Entra Agent ID".

The matrix note on the built half: "Credentials are scoped to the acting user or the organisation, never to an individual agent instance, so two agents run by the same user in the same org always share the same resolved credential."

## What hermiq already has

- `CredentialScopeResolver::resolve(provider, actingUserId, organisation)` (`lib/Service/Credential/CredentialScopeResolver.php:134`): personal, then organisation, then null for the instance credential. Every id it returns is re-validated by OpenRegister's `CredentialBrokerService::request()` guards (owner or membership, allowedApps, provider rules, host lock).
- `ProviderFactory::resolveCredentialOverride()` (`lib/Service/Llm/ProviderFactory.php:758`), called from `instantiateChatDriver()` (`:523`, `:529`) for openai and fireworks.
- The agent-credentials spec requirement "Run-time credential resolution precedence".

## What this change builds

1. `Agent.credentialIds`: an optional map from provider to a broker credential uuid, set by the agent owner.
2. The resolver takes the agent: a pinned credential for the provider goes first; personal and organisation follow only when nothing is pinned.
3. A pinned credential the broker refuses stops the turn with "The credential pinned to this agent cannot be used for this run." It does not fall back.
4. A "Credentials" section on the agent form listing the credentials the owner may pick for each provider (their own personal ones and their organisation's).

## Out of scope

- A separate identity per agent in Nextcloud. The agent still acts as its acting user; only the provider key is per agent.
- Rotating keys.
