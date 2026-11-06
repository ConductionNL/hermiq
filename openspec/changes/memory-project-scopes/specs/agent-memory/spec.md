# agent-memory Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- memory-project-scopes

## Purpose

An agent keeps a memory per project next to a shared memory, reads both in a project session and writes to the right one. Row `hermiq:dm-project-memory`.

## ADDED Requirements

### Requirement: An agent has one shared memory and a memory per project (REQ-PMEM-001)

Hermiq MUST keep for each agent exactly one memory with scope `shared` and at most one memory per project key. Existing memories MUST become the shared memory without moving their entries. A session MAY name a project key when it starts, and MUST keep that key for its life.

#### Scenario: A policy advisor starts a project session
- GIVEN the agent "Beleidsondersteuner" with the project "Omgevingsvisie 2040"
- WHEN a policy advisor starts a session on `/chat` and picks the project "Omgevingsvisie 2040"
- THEN the session header shows "Project: Omgevingsvisie 2040", and the session carries that project key
- e2e: `tests/e2e/spec-coverage/memory-project-scopes.spec.ts`

### Requirement: Recall in a project reads the project and the shared memory only (REQ-PMEM-002)

In a session with a project key, hermiq MUST search the agent's memory for that project and its shared memory, MUST label each recalled entry with its layer, and MUST NOT read another project's memory. Outside a project, hermiq MUST search the shared memory only.

#### Scenario: Facts from another project stay out
- GIVEN the agent's project memory `aanbesteding-wagenpark` holds a fact about bestelbussen
- WHEN the agent recalls "bestelbussen" in a session of the project "Omgevingsvisie 2040"
- THEN the recall result holds no entry from `aanbesteding-wagenpark`, and entries from the shared memory are labelled `shared`
- @e2e exclude a tool result, not a page; covered by PHPUnit on `recallEntries()` with three memory objects

### Requirement: Writes go to the project memory unless marked shared (REQ-PMEM-003)

In a session with a project key, hermiq MUST store a fact the agent remembers with scope `agent` in the project memory, and one with scope `shared` in the shared memory. Outside a project, both MUST go to the shared memory. Every write MUST be redacted before it is stored.

#### Scenario: A deadline lands in the project
- GIVEN a session of the project "Omgevingsvisie 2040"
- WHEN the agent remembers "De raad behandelt het ontwerp op 14 november 2026." with scope `agent`
- THEN the entry is in the project memory, and the shared memory is unchanged
- @e2e exclude the model chooses when to call the tool; covered by PHPUnit on the memory tool dispatch

### Requirement: An owner sees and moves entries between layers (REQ-PMEM-004)

The Memory page MUST show the shared memory and each project memory apart, and MUST let the agent owner move an entry from a project to the shared memory and back. A move MUST pass redaction again.

#### Scenario: A general rule moves to shared
- GIVEN the entry "Beleidsstukken gebruiken B1-taalniveau." in the project memory of "Omgevingsvisie 2040"
- WHEN the agent owner chooses "Move to shared" on it on the Memory page
- THEN the entry shows under "Shared" and no longer under the project
- e2e: `tests/e2e/spec-coverage/memory-project-scopes.spec.ts`
