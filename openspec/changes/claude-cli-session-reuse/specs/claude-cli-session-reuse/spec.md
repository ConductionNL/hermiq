# Claude CLI Session Reuse Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `claude-cli-session-reuse` — a conversation maps to one Claude CLI session; the runner keeps a
  per-conversation session home instead of a per-turn throwaway, and a resumed turn sends only the
  new message (kind: code)

## Purpose

A running conversation should be a running Claude session. Today every message starts a brand-new
one: the runner gives each turn a throwaway `HOME` and `cwd` and `rm -rf`s them on exit, so the
CLI's session store is created and destroyed per message, and the whole history is re-flattened
into a fresh prompt each turn.

This capability defines the conversation→session mapping, the addressing contract that makes a
session findable again, the isolation and eviction rules for the session home, and the cold-start
fallback that keeps correctness independent of any of it.

## ADDED Requirements

### Requirement: A conversation maps to exactly one Claude CLI session

The runner MUST address a session by the conversation's UUID, passing it as the CLI's
`--session-id`. A second turn in the same conversation MUST resume that session rather than start
a new one.

The conversation UUID is the only session identifier. The runner MUST NOT mint its own.

#### Scenario: The first turn in a conversation creates the session

- **WHEN** a turn is dispatched for a conversation that has no session home
- **THEN** the runner invokes the CLI with `--session-id <conversationUuid>`
- **AND** the session transcript is written inside that conversation's session home
- **AND** the reply is returned exactly as it is today (no behavioural change on turn 1)

#### Scenario: A later turn resumes the same session

- **WHEN** a turn is dispatched for a conversation whose session home exists
- **THEN** the runner resumes that session rather than creating a second one
- **AND** the CLI is given only the new user message, not the flattened history

#### Scenario: A different conversation never resumes another conversation's session

- **WHEN** two conversations are active for the same user
- **THEN** each turn resolves to its own conversation's session home
- **AND** neither turn's process can reach the other's transcript

### Requirement: A session is addressed by HOME, CWD and session id together

The runner MUST use a stable, per-conversation directory as **both** `HOME` and `cwd` for every
turn of that conversation, because both are part of a session's address.

The Claude CLI stores a session at `$HOME/.claude/projects/<escaped-cwd>/<session-id>.jsonl`, and
`--continue` is defined as "continue the most recent conversation **in the current directory**".
Stabilising only one of the two MUST be treated as a defect: it produces a silent cold start on
every turn — the exact failure this capability removes — while appearing to work.

#### Scenario: The session home is stable across turns

- **WHEN** two turns are dispatched for the same conversation
- **THEN** both child processes receive the same `HOME` and the same `cwd`
- **AND** the second turn finds the first turn's transcript

#### Scenario: Only HOME is stabilised

- **WHEN** a turn runs with a stable `HOME` but a fresh `cwd`
- **THEN** the CLI cannot resolve the prior session
- **AND** the turn MUST be reported as a cold start rather than silently continuing

### Requirement: The session home is isolated per conversation and per tenant

The session home MUST be keyed by conversation **and** by tenant/user. A shared home MUST NOT be
used: `--continue` resolves "the most recent conversation in this directory", so a shared home
would place one tenant's transcript within another tenant's reach.

Both path segments MUST be validated as UUIDs before use, so a caller-supplied value cannot
traverse the path. The session home MUST NOT be world-readable.

#### Scenario: One tenant cannot reach another tenant's session

- **WHEN** two tenants each hold a conversation
- **THEN** their session homes are distinct directories
- **AND** neither tenant's turn is ever given the other's directory as `HOME` or `cwd`

#### Scenario: A non-UUID identifier is refused

- **WHEN** a turn is dispatched with a conversation or tenant identifier that is not a valid UUID
- **THEN** the runner refuses the turn rather than constructing a path from it

### Requirement: The session home is a cache and never the source of truth

The runner MUST treat the session home as a performance cache belonging to a replaceable
container; hermiq's `conversation` and `message` objects remain authoritative.

When the session home is missing, evicted, or unreadable, the runner MUST fall back to a
full-history cold start and produce the same answer it produces today. It MUST NOT continue from a
truncated conversation, and MUST NOT fail the turn.

#### Scenario: The session home was evicted between turns

- **WHEN** a turn is dispatched for a conversation whose session home no longer exists
- **THEN** the runner sends the full history, as it does today
- **AND** the reply is correct and complete
- **AND** the cold start is observable (logged), because a silent one is the defect this
  capability exists to remove

#### Scenario: The runner container is replaced

- **WHEN** the runner container is recreated and every session home is gone
- **THEN** existing conversations continue to work by cold start
- **AND** no conversation history is lost, because it lives in hermiq's objects

### Requirement: Session homes are evicted on a bounded policy

The runner MUST evict session homes on a TTL and a disk budget: eviction is what bounds how long
transcripts persist inside the container, and what bounds disk growth.

Eviction MUST be safe at any moment — correctness never depends on a cache hit — so the policy may
evict aggressively.

#### Scenario: A session home outlives its TTL

- **WHEN** a conversation's session home is older than the configured TTL
- **THEN** it is removed
- **AND** the next turn for that conversation cold-starts and still answers correctly

### Requirement: The per-run token never becomes persistent

The governed MCP config carries a per-run bearer token. Making the session home persistent MUST
NOT make that token persistent.

The MCP config MUST continue to be written per turn with `0600` permissions and removed when the
turn ends, exactly as `cli-runner-governed-mcp-and-egress` requires. It MUST NOT be written into
the persistent session home in a way that outlives the run whose token it carries.

#### Scenario: A turn ends and its token file is gone

- **WHEN** a governed turn completes, errors, or times out
- **THEN** the MCP config file for that run no longer exists on disk
- **AND** the session home survives for the next turn
