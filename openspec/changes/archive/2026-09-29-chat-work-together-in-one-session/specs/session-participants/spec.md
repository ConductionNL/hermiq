# session-participants Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-work-together-in-one-session

## Purpose

Several colleagues work with the same agent in one session on `/chat`, and each turn says who asked. Row `hermiq:ch-shared-session`.

## ADDED Requirements

### Requirement: The owner invites colleagues into a session (REQ-SPART-001)

Hermiq MUST let only the owner of a session add or remove participants through `/api/sessions/{uuid}/participants`. It MUST answer 404 to anyone else, MUST refuse a session bound to a Talk room with 409, and MUST notify each person added.

#### Scenario: A policy advisor invites a colleague
- GIVEN Anne owns the session "Omgevingsvisie 2040" on `/chat`
- WHEN she chooses "Invite colleagues" and adds Bram
- THEN Bram is on the session's participant list and gets the notification "Anne de Vries invited you to the session Omgevingsvisie 2040"
- @e2e exclude needs two logged-in users in one run; covered by PHPUnit on SessionParticipantService and the controller

#### Scenario: Someone else cannot change the list
- GIVEN Bram is a participant in Anne's session
- WHEN Bram posts to `/api/sessions/{uuid}/participants`
- THEN the answer is 404 and the list is unchanged
- @e2e exclude a crafted request; covered by PHPUnit

#### Scenario: A Talk session keeps its room roster
- GIVEN a session bound to a Talk room
- WHEN the owner posts a participant
- THEN the answer is 409 with "This session belongs to a Talk room. Invite the person to the room instead."
- @e2e exclude needs a Talk room; covered by PHPUnit

### Requirement: An invited colleague reads and takes turns (REQ-SPART-002)

Hermiq MUST show a participant the session under "Shared with me" on `/chat`, MUST let them read it and ask the agent in it, and MUST keep rename, archive, restore and delete for the owner. A person who is neither owner nor participant MUST get 403.

#### Scenario: Bram asks a question in Anne's session
- GIVEN Bram is a participant in "Omgevingsvisie 2040"
- WHEN he opens `/chat`, picks the session under "Shared with me" and asks a question
- THEN the agent answers in the same session, and Anne sees Bram's name above his turn
- @e2e exclude needs two logged-in users in one run; covered by PHPUnit on SessionController index, show and messages

#### Scenario: A participant cannot delete the session
- GIVEN Bram is a participant
- WHEN he sends `DELETE /api/sessions/{uuid}`
- THEN the answer is 403 and the session stays
- @e2e exclude a crafted request; covered by PHPUnit
