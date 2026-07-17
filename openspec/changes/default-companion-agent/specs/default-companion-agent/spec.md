# default-companion-agent Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `default-companion-agent` — a per-user default agent preference, its precedence chain over the
  instance-wide default and the first-accessible fallback, and its access-checking contract
  (kind: code)

## Purpose

Lets a user name the agent their AI companion talks to, instead of receiving whichever agent
`ChatStreamController::pickFallbackAgentForUser()` (line 558) happens to return first from a
20-row scan. Establishes a three-tier precedence — per-user, then the instance-wide app-config
default (`companion_agent_uuid`, hermiq#116), then first-accessible — and fixes the contract that
makes it safe: a stored agent UUID is a **preference, never an authorization**. See ADR-023
(action authorization — identity, never stale authority).

## ADDED Requirements

### Requirement: A user can set and clear a default companion agent
The system MUST let an authenticated user store a default agent UUID as a per-user preference,
and MUST let them clear it. The preference MUST be stored in Nextcloud's user configuration for
the `hermiq` app. The system MUST NOT store it as an OpenRegister object or schema property.

#### Scenario: A user sets a default agent
- GIVEN an authenticated user who can access an agent
- WHEN the user sets that agent as their default
- THEN the system MUST persist the agent's UUID as that user's preference
- AND subsequent companion chats MUST use that agent

#### Scenario: A user clears their default agent
- GIVEN a user with a stored default agent
- WHEN the user clears it
- THEN the system MUST remove the stored preference
- AND companion agent resolution MUST fall through to the next precedence tier

#### Scenario: A user tries to set an agent they cannot access
- GIVEN an authenticated user and an agent UUID the user cannot access
- WHEN the user attempts to set that agent as their default
- THEN the system MUST reject the request with `403`
- AND the system MUST NOT persist the UUID

### Requirement: The companion agent resolves by per-user, then app-config, then first-accessible precedence
The system MUST resolve the companion agent for a user in this order: (1) the user's stored
default agent, (2) the instance-wide `companion_agent_uuid` app-config value, (3) the first agent
the user can access. The system MUST return the first tier that yields an agent the user can
access, and MUST return no agent only when every tier is exhausted.

#### Scenario: A user with a stored default starts a chat
- GIVEN a user has stored a default agent they can access
- AND an instance-wide `companion_agent_uuid` names a different agent
- WHEN the user starts a companion chat without naming an agent
- THEN the system MUST use the user's stored default
- AND the system MUST NOT use the instance-wide default

#### Scenario: A user without a stored default starts a chat
- GIVEN a user has no stored default agent
- AND an instance-wide `companion_agent_uuid` names an agent the user can access
- WHEN the user starts a companion chat without naming an agent
- THEN the system MUST use the instance-wide default

#### Scenario: Neither a per-user nor an instance-wide default exists
- GIVEN a user has no stored default agent
- AND no instance-wide `companion_agent_uuid` is configured
- WHEN the user starts a companion chat without naming an agent
- THEN the system MUST use the first agent the user can access
- AND the system MUST return no agent when the user can access none

### Requirement: A stored agent UUID is a preference, never an authorization
The system MUST verify that the calling user can access the agent named by a stored preference on
**every** resolution, at **every** tier, before returning it. When the access check fails, the
system MUST fall through to the next tier and MUST NOT raise an error. The system MUST NOT treat
the presence of a stored UUID as evidence of access.

#### Scenario: A user's stored default names an agent they cannot access
- GIVEN a user has a stored default agent UUID
- AND the user cannot access that agent
- WHEN the companion agent is resolved
- THEN the system MUST NOT return that agent
- AND the system MUST fall through to the next precedence tier
- AND the system MUST NOT return an error to the user

#### Scenario: A user loses access to their stored default agent
- GIVEN a user stored a default agent they could access
- AND the agent's sharing has since been revoked for that user
- WHEN the companion agent is resolved
- THEN the system MUST NOT return that agent
- AND the user's chat MUST continue to work using the next available tier

#### Scenario: The instance-wide default names an agent this user cannot access
- GIVEN the instance-wide `companion_agent_uuid` names an agent
- AND the calling user cannot access that agent
- WHEN the companion agent is resolved for that user
- THEN the system MUST fall through to the first-accessible tier

#### Scenario: A stored default names a deleted agent
- GIVEN a user's stored default agent has been deleted
- WHEN the companion agent is resolved
- THEN the system MUST fall through to the next precedence tier
- AND correctness MUST NOT depend on the stale preference having been cleaned up

### Requirement: The app-config precedence tier is optional
The system MUST tolerate the absence of the `companion_agent_uuid` app-config key. When the key
is absent or empty, the system MUST fall through from the per-user tier directly to the
first-accessible tier without error.

#### Scenario: The app-config key has not been introduced yet
- GIVEN the `companion_agent_uuid` app-config key does not exist on the instance
- WHEN the companion agent is resolved for a user with no stored default
- THEN the system MUST use the first agent the user can access
- AND the system MUST NOT raise an error or log a failure

## Non-Functional Requirements

- **Performance:** resolution MUST NOT add more than one agent lookup to the existing chat path
  when a per-user or app-config default is set. The first-accessible tier MUST retain its
  existing `findAll(config: ['limit' => 20])` cap and MUST NOT be reached when an earlier tier
  answers.
- **Accessibility:** the settings picker MUST be a WCAG 2.1 AA compliant control. Any `NcSelect`
  MUST carry an `inputLabel` — a manual `<label>` breaks the component's internal accessibility
  wiring (SC 1.3.1, 4.1.2).
- **Internationalization:** Dutch and English MUST be supported (ADR-005). All new user-facing
  strings MUST be present in `l10n/en.json` and `l10n/nl.json`, keyed by the English source string.

## Acceptance Criteria

- A user with no default gets the same companion agent they get today (no behaviour change).
- A user who sets a default gets that agent on the next companion chat, deterministically.
- Setting an inaccessible agent returns `403` and stores nothing.
- Resolution with an inaccessible stored default falls through silently and chat still works.
- Resolution works with the `companion_agent_uuid` key absent (hermiq#116 not merged).
- `pickFallbackAgentForUser()` is unchanged and is reached only when tiers 1 and 2 yield nothing.

## Notes

- **hermiq#116 is not merged into this worktree.** `grep -rn "companion_agent_uuid" lib/ src/`
  returns nothing at HEAD; `pickFallbackAgentForUser()` (line 558) is first-accessible only, with
  no app-config read. Tier 2 is specified here but MUST NOT be implemented here — read the key
  defensively so both merge orders work.
- **The write endpoint validates; the read path falls through.** The postures differ deliberately:
  a `403` at write time gives immediate feedback, while falling through at read time keeps chat
  working when a once-valid preference goes stale.
- **This change does not fix model/provider mismatch.** `ProviderFactory` (lines ~1880-1882) lets
  an agent's `model` override `anthropicConfig.chatModel`, which is how a `qwen2.5` agent produced
  `claude --model qwen2.5` → exit 1, empty stderr, infinite spin. A per-user default lets a user
  *avoid* the broken agent; it does not validate compatibility. Deferred as a follow-up.
- Related ADRs: ADR-023 (action authorization), ADR-022 (apps consume OpenRegister abstractions),
  ADR-064 (credential custody — this change stores no secret, only an agent UUID).
