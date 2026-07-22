# Agent Engine Port Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `claude-cli-session-reuse` — a resumed turn sends only the new user message instead of the whole
  flattened history, so a conversation's per-turn cost stops growing with its length (kind: code)

## Purpose

Delta for `openspec/specs/agent-engine-port/spec.md`. The runner flattens every message of a
conversation into one prompt string on every turn, so the input a conversation pays for grows
linearly with its own length and nothing can be prompt-cached. Once a conversation maps to a
resumable CLI session (`claude-cli-session-reuse`), the history is already held by the session and
re-sending it is both redundant and the reason long conversations get slower.

This delta adds the requirement that a resumed turn carries only what is new.

## ADDED Requirements

### Requirement: A resumed turn sends only the new user message

When a turn resumes an existing session, the runner MUST send only the new user message. It MUST
NOT re-send the messages the session already holds.

When a turn cannot resume a session — no session home, evicted, or unreadable — the runner MUST
send the full history, preserving today's behaviour exactly.

#### Scenario: The second turn in a conversation

- **WHEN** a turn resumes an existing session
- **THEN** exactly one user message is passed to the CLI
- **AND** the reply accounts for the earlier turns, because the session holds them

#### Scenario: A cold start still sends everything

- **WHEN** a turn cannot resume a session
- **THEN** the full flattened history is sent, as it is today
- **AND** the reply is indistinguishable from the current behaviour

#### Scenario: The per-turn payload stops growing with conversation length

- **WHEN** a conversation reaches its tenth resumed turn
- **THEN** the payload for that turn is the same size as for its second resumed turn
- **AND** it does not scale with the number of prior turns

## Non-Functional Requirements

### Performance

Baselines measured on the reference instance before this change, so the effect is checkable rather
than asserted:

- A turn's `llm` time was ~9s for a two-character answer (process spawn plus round trip) and ~17s
  for a normal reply.
- One user message spawned two CLI processes (reply plus conversation title).
- Every turn re-sent the entire history.

The process spawn is not removed by this change and remains in every turn. The saving comes from
the input a resumed turn no longer carries, and from a session prefix that can be cached.

**The effect MUST be measured during implementation, not assumed.** The acceptance signal is
structural rather than a promised number: a resumed turn's payload does not grow with the
conversation's length, and `llm` time for turn N does not trend upward with N. If measurement
shows no improvement, that result MUST be reported rather than the change being justified by its
premise.
