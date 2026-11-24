# session-fork Specification

## Purpose
A person can fork a session at one of its turns into a new session of their own, carrying the conversation up to that point, so they can try another direction without changing the original. Only the session's owner forks it; the source session stays untouched. Created by archiving change `chat-fork-and-share-link` (2026-11-24).
## Requirements
### Requirement: The owner can fork a session at an answer (REQ-SFORK-001)

Hermiq MUST let the owner of a session create a new session from any assistant turn in it. The new session MUST have the same agent, MUST contain copies of every turn up to and including that turn, and MUST record the source session and turn in `forkedFrom`. Hermiq MUST NOT change the source session or its turns.

#### Scenario: A policy advisor tries a different line of questioning
- GIVEN a policy advisor with a ten-turn session about the "Omgevingsvisie 2040", whose fourth answer they want to take another way
- WHEN they choose "Fork from here" on that answer
- THEN a new session opens titled "Omgevingsvisie 2040 (fork)" with the first four turns, ready for a question, and the original still shows all ten turns
- e2e: `tests/e2e/spec-coverage/session-fork-and-share.spec.ts`

#### Scenario: Only an answer can be a fork point
- GIVEN a session owner
- WHEN they post a fork request naming one of their own questions
- THEN the answer is 400 and no session is created
- @e2e exclude a crafted request body; covered by PHPUnit and Newman

#### Scenario: Someone else's session cannot be forked
- GIVEN a colleague's session the caller can read through a share
- WHEN the caller posts a fork request for it
- THEN the answer is 404 and no session is created
- @e2e exclude a crafted request body; covered by PHPUnit and Newman

