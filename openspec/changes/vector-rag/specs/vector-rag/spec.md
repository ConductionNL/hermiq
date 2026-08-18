# vector-rag (delta)

This change makes semantic and hybrid RAG real: a public OpenRegister vector-search
facade (dependency requirement), `ContextRetrievalHandler` mode wiring, local-first
embedding backends, and honest degradation when no vector backend is configured.

## ADDED Requirements

### Requirement: A public vector-search facade is the only vector dependency
The system MUST consume vector search exclusively through a public OpenRegister
facade offering `searchSemantic(query, limit, views)`, `searchHybrid(query, limit,
views)`, `embedTexts(texts)`, and `isAvailable()`, and MUST NOT reference
`OCA\OpenRegister\Service\Vectorization\*` internals. The facade MUST be resolved
lazily behind a `class_exists()` guard so Hermiq boots and answers on an OpenRegister
release that predates it. Facade rows MUST use the retrieval superset shape
(`entity_id`, `entity_type` of `object` or `file`, `chunk_text`, `similarity`,
`metadata`), and the facade MUST only return chunks whose parent object or file the
acting user is authorized to read.

#### Scenario: The facade is absent from the installed OpenRegister
- **GIVEN** an OpenRegister release without the vector facade class
- **WHEN** Hermiq boots and an agent turn runs
- **THEN** the app MUST function fully, with retrieval on the keyword path
- **AND** no error MUST surface to the user

#### Scenario: No internal vectorization class is referenced
- **WHEN** Hermiq's code is inspected
- **THEN** it MUST contain no reference to OpenRegister's internal vectorization
  classes, only to the public facade

### Requirement: Semantic and hybrid retrieval run through the facade
The system MUST route `ragSearchMode: semantic` to `searchSemantic()` and
`ragSearchMode: hybrid` to `searchHybrid()` when the facade is present and
`isAvailable()` is true, passing the resolved view filters through unchanged. The
empty-views retrieval gate MUST apply to semantic and hybrid retrieval exactly as it
applies to keyword retrieval: no resolved views means retrieval is skipped and
logged. The `retrieveContext()` never-throws contract MUST be preserved.

#### Scenario: A semantic turn retrieves by similarity
- **GIVEN** an agent with `ragSearchMode: semantic`, resolved views, and a live
  vector backend
- **WHEN** context is retrieved for a query
- **THEN** the retrieved sources MUST come from the facade's semantic search scoped
  to the resolved views
- **AND** each source MUST carry the facade-reported `similarity`

#### Scenario: File chunks become retrievable sources
- **GIVEN** a live vector backend with indexed file content
- **WHEN** a semantic or hybrid retrieval returns a row with `entity_type: file`
- **THEN** the system MUST format it as a file source (respecting `searchFiles` and
  `numSourcesFiles` exactly as the existing loop does)

#### Scenario: An agent with no resolved views retrieves nothing, in every mode
- **GIVEN** an agent whose views resolve to an empty set
- **WHEN** a semantic or hybrid turn runs
- **THEN** retrieval MUST be skipped with the existing logged explanation
- **AND** the facade MUST NOT be called

### Requirement: Retrieval degrades honestly when no vector backend is configured
When the facade is absent, reports `isAvailable() === false`, or a facade call
throws, the system MUST fall back to the existing keyword retrieval path for
`semantic` and `hybrid` modes, MUST log which condition caused the fallback
(distinguishing "facade not present" from "no backend configured" from "backend
error"), and MUST NOT fail the turn. The system MUST expose the current tier
(vector-live or keyword-degraded) on the admin settings surface so a degraded
instance is inspectable rather than silent.

#### Scenario: No vector backend is configured
- **GIVEN** the facade is present but no embedding backend is configured in
  OpenRegister
- **WHEN** an agent with `ragSearchMode: semantic` runs a turn
- **THEN** the system MUST answer using keyword retrieval
- **AND** MUST log that semantic mode degraded because no backend is configured
- **AND** the settings surface MUST report vector search as unavailable

#### Scenario: A backend error mid-turn does not lose the answer
- **GIVEN** a live backend that throws during a facade search call
- **WHEN** the turn's context is retrieved
- **THEN** the system MUST fall back to keyword retrieval for that turn
- **AND** MUST log the error at warning level
- **AND** the turn MUST complete

### Requirement: Embedding backends are OpenRegister-configured and local-first
Embedding generation for objects and files MUST live in OpenRegister (per the
ADR-001 delegation of the RAG substrate), configurable with a local Ollama embedding
model as the default backend class; Hermiq MUST NOT introduce a second embedding
configuration surface and MUST NOT generate or store object/file embeddings itself.
All texts compared in one search MUST be embedded by the same model; the facade owns
model identity and `isAvailable()` MUST be false when no backend is configured.

#### Scenario: Hermiq adds no embedding configuration
- **WHEN** Hermiq's settings surfaces are inspected
- **THEN** they MUST offer no embedding backend or model selection, only a read-only
  report of the facade's availability
