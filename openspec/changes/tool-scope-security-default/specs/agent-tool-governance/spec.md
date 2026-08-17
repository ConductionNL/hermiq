## MODIFIED Requirements

### Requirement: An empty grant list means no tools, with no override

`ToolGrantResolver::resolve()` MUST return an empty whitelist when `Agent.tools` is
empty, unset, or sanitises to nothing. There MUST be no configuration, environment
variable or flag that restores whole-catalog access.

An unconfigured agent previously received every discovered tool minus the write
verbs. Measured 2026-08-16 that was 81 tools and ~101,000 tokens per turn — over half
a 200K context window — taken by 89% of agents on the instance because nobody had
filled the field in.

Two properties make the override unacceptable rather than merely untidy:

1. **It is invisible.** Nothing in the product shows that the variable is set, so an
   agent's true capability cannot be read from the agent.
2. **It is not scoped.** Setting it to unblock one agent widens every unconfigured
   agent on the instance.

"Unconfigured" MUST NOT mean "may use everything discovered here". That is a grant
nobody made, and it widens by itself as apps are installed.

#### Scenario: Empty grants yield nothing

- **GIVEN** an agent whose `tools` is `[]`, null, or all-whitespace entries
- **WHEN** its grants are resolved against a catalog of any size
- **THEN** the resolved whitelist MUST be empty

#### Scenario: No environment variable can widen it

- **GIVEN** any environment configuration
- **WHEN** an agent with empty grants is resolved
- **THEN** the result MUST still be empty
- **AND** no code path MUST exist that returns the catalog for an empty grant list

#### Scenario: An unconfigured agent is tool-less, not broken

- **GIVEN** an agent with no configured tools
- **WHEN** the tool loop resolves its functions
- **THEN** it MUST run text-only without raising
- **AND** `resolvesToNothing()` MUST return false, because `ToolLoop` throws on that and an unconfigured agent is a legitimate conversational agent

#### Scenario: Configured grants that resolve to nothing are still an error

- **GIVEN** an agent granting `pipelinq.typo.get`, which no catalog id matches
- **WHEN** its grants are resolved
- **THEN** `resolvesToNothing()` MUST return true, because grants were made and none took effect

### Requirement: No unreachable code may grant tools

When the whole-catalog path is removed, the helper that applied it MUST be removed
with it rather than left unreachable.

`applyDefaultDeny()` is called from exactly one place. An orphaned method that
returns a set of granted tool ids reads to the next maintainer as a supported path,
and is one call site away from becoming one again.

#### Scenario: The whole-catalog helper does not survive as dead code

- **GIVEN** the legacy branch is removed
- **WHEN** the resolver is inspected
- **THEN** no method MUST remain whose purpose is to return the catalog under default-deny
- **AND** the classification helper `isWriteOrDestructive()` MUST remain, because wildcard expansion and the approval gate still use it

### Requirement: Affected agents MUST be reportable

An operator MUST be able to list every agent whose `tools` is empty or unset, so the
change surfaces as a decision to make rather than as agents quietly going silent.

The report MUST NOT modify the agents. Back-filling them with the tools they were
implicitly receiving would preserve the behaviour and defeat the change — each would
carry ~101,000 tokens per turn, now recorded as an explicit grant nobody chose.

#### Scenario: The report names unconfigured agents without changing them

- **GIVEN** an instance where some agents have empty or unset `tools`
- **WHEN** the report is run
- **THEN** it MUST list each such agent by name and id
- **AND** every agent's `tools` value MUST be unchanged afterwards
