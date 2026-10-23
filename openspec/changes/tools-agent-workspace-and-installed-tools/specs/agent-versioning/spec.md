# agent-versioning Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-agent-workspace-and-installed-tools

## Purpose

A published build freezes an agent's workspace files and installed tools with its version, so every run of the published agent starts from the same build. Row `hermiq:dm-build-snapshot`.

## ADDED Requirements

### Requirement: An agent owner can publish a build that pins files and installed tools (REQ-BUILD-001)

Hermiq MUST let an agent owner publish a build that records the agent version, an archive of the current workspace and the installed tools with their versions and hashes. Publishing MUST pin that build on the agent.

#### Scenario: An agent owner publishes build 3
- GIVEN an agent owner with a test conversation whose workspace holds two templates and `csvkit` installed
- WHEN they choose "Publish build" and enter the note "Sjablonen kwartaalrapportage"
- THEN the agent page shows "Build 3, published by" their name with the date, two files and one tool

### Requirement: Runs of a published agent start from its pinned build (REQ-BUILD-002)

When an agent has a pinned build, hermiq MUST start every new session workspace from the build's archive and tool layers, MUST NOT write run changes back into the build, and MUST record the build id on every run next to the agent version.

#### Scenario: Two users get the same starting point
- GIVEN an agent with build 3 pinned
- WHEN two case handlers each start a new conversation
- THEN both workspaces start with the same two templates and `csvkit` 2.1.0, and each run record names build 3

#### Scenario: Edits after publishing are visible to the owner
- GIVEN an agent with build 3 pinned whose prompt was edited afterwards
- WHEN the agent owner opens the agent page
- THEN it says "Unpublished changes since build 3", and runs use the build's files and tools with the live prompt

### Requirement: Rolling back re-pins the matching build (REQ-BUILD-003)

When an agent is rolled back to a version that has a published build, hermiq MUST pin that build. When the version has none, hermiq MUST keep the current pin and MUST say so.

#### Scenario: A rollback to the version of build 2
- GIVEN an agent with builds 2 and 3, and build 3 pinned
- WHEN the owner rolls the agent back to the version of build 2
- THEN build 2 is pinned, and the next new conversation starts from build 2's files and tools
