# agent-context-system (delta)

This change implements the Context entity and its run-start assembly, described in
`SPECTR-NEXTCLOUD-PLAN.md` §6.4.

## ADDED Requirements

### Requirement: Context objects with char-budget consolidation
The system MUST persist a named bundle of files, object-queries, and view refs as a
`Context` OpenRegister object, and MUST enforce a character budget on its assembled text,
setting `needsConsolidation` when the budget is exceeded rather than silently truncating.

#### Scenario: A Context's assembled content exceeds its char budget
- **GIVEN** a `Context` object whose `objectQueries`/`files` resolve to more characters
  than its configured `charBudget`
- **WHEN** the context is assembled at run start
- **THEN** the system MUST include the FULL assembled text (no truncation)
- **AND** the system MUST persist `needsConsolidation=true` on the Context object

#### Scenario: A previously over-budget Context returns under budget
- **GIVEN** a `Context` object with `needsConsolidation=true` stored
- **WHEN** its queries/files now resolve to content under `charBudget`
- **THEN** the system MUST persist `needsConsolidation=false`

### Requirement: Context assembly resolves object queries and files
The system MUST resolve each `objectQueries` entry via OpenRegister's `ObjectService` and
each `files` entry from the acting user's Nextcloud folder, concatenating the results into
the assembled text; a single unresolvable query or missing file MUST be skipped (logged),
not fail the whole assembly.

#### Scenario: One object query targets a nonexistent register/schema
- **GIVEN** a Context with two `objectQueries` entries, one targeting a valid
  register/schema and one targeting an invalid one
- **WHEN** the context is assembled
- **THEN** the system MUST include the valid query's results in the assembled text
- **AND** the system MUST NOT fail the assembly because of the invalid entry

### Requirement: Agent context attachment
The system MUST let an `Agent` declare `contextRefs` (Context object uuids); at run start,
the system MUST assemble every referenced Context and prepend the combined result to the
system prompt, ahead of the per-turn RAG/CnAiContext content.

#### Scenario: An agent with an attached Context runs a turn
- **GIVEN** an Agent with one `contextRefs` entry pointing at a Context with assembled text T
- **WHEN** the agent processes a chat turn
- **THEN** the system prompt MUST contain T
- **AND** T MUST appear before the turn's RAG context block
