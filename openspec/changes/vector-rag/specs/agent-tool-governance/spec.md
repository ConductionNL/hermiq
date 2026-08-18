# agent-tool-governance (delta)

The `hermiq.searchTools` ranking upgrades from substring matching to embedding
similarity through the vector facade, preserving the resolved-set-only invariant and
keeping substring matching as the no-backend fallback.

## MODIFIED Requirements

### Requirement: Progressive tool disclosure for large catalogs
The system MUST NOT place every tool descriptor into the model context when an agent's resolved tool
catalog exceeds a configurable threshold (`IAppConfig('hermiq', 'tools.disclosureThreshold')`,
default **30**); it MUST instead expose a single `hermiq.searchTools` meta-tool and load full
descriptors only for the tools the model selects via that meta-tool (deferred loading). Below the
threshold, all resolved descriptors MAY be placed in context as today.

When embedding generation is available through the OpenRegister vector facade, the
system MUST rank `hermiq.searchTools` matches by embedding similarity between the
query and each resolved descriptor (id + name + description), applying a configurable
similarity floor and the existing result cap; descriptor embeddings MUST be cached
per resolved set and invalidated when the descriptor set or the embedding model
changes. When embedding generation is unavailable, the system MUST rank by the
existing substring match. On both paths the system MUST NOT return, or make
invocable, any tool outside the agent's already-resolved (grant-filtered,
default-denied) set.

#### Scenario: A resolved catalog exceeds the disclosure threshold

- **GIVEN** an agent whose resolved (grant-filtered) tool catalog contains more tools than the
  configured disclosure threshold
- **WHEN** the engine assembles the agent's turn
- **THEN** the system MUST place only the `hermiq.searchTools` meta-tool (plus any always-on tools)
  into the model context
- **AND** the system MUST NOT place the full set of tool descriptors into the context

#### Scenario: The model searches for and then invokes a deferred tool

- **GIVEN** progressive disclosure is active for an agent turn
- **WHEN** the model calls `hermiq.searchTools` with a query
- **THEN** the system MUST return only descriptors from that agent's already-resolved
  (grant-filtered, default-denied) set that match the query
- **AND** the system MUST make the matched tools invocable on a subsequent turn
- **AND** the system MUST NOT return, or make invocable, any tool outside the agent's resolved set

#### Scenario: A paraphrased query finds a granted tool by meaning

- **GIVEN** embedding generation is available and a granted tool whose descriptor
  shares no substring with the model's query but matches its meaning
- **WHEN** the model calls `hermiq.searchTools` with that query
- **THEN** the system MUST return that tool's descriptor when its similarity clears
  the configured floor

#### Scenario: Tool search without an embedding backend uses substring matching

- **GIVEN** no embedding backend is available through the vector facade
- **WHEN** the model calls `hermiq.searchTools`
- **THEN** the system MUST rank by the existing substring match
- **AND** the resolved-set-only restriction MUST hold unchanged

#### Scenario: A small catalog does not trigger disclosure

- **GIVEN** an agent whose resolved catalog does not exceed the threshold
- **WHEN** the engine assembles the turn
- **THEN** the system MAY place all resolved descriptors directly into context
- **AND** the `hermiq.searchTools` meta-tool need not be present
