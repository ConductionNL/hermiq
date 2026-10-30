# agent-template-github-store Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-export-import-and-git-sync

## Purpose

Keep an agent in a git repository and bring edits made there back into the same agent. Row `hermiq:dm-agent-as-code`.

## ADDED Requirements

### Requirement: An agent owner can keep an agent in a git repository (REQ-AGEXP-004)

The system MUST let an agent owner publish the agent's package to a new GitHub repository, stamp the repository on the agent, and push later edits only to that stamped repository. Coordinates MUST be read from the agent, never from the request. Publishing to an existing repository MUST be refused.

#### Scenario: An owner publishes an agent to GitHub
- GIVEN the owner of an agent with a GitHub credential in the credential broker
- WHEN they choose "Keep in git", then "Publish to GitHub", with the repository name "complaint-router-agent"
- THEN the repository holds `agent.json` and the agent page shows the repository link

#### Scenario: A push cannot be redirected to another repository
- GIVEN an agent stamped with one repository
- WHEN a request to push names a different owner and repository
- THEN the push goes to the stamped repository or is refused, never to the named one
- @e2e exclude request tampering, covered by PHPUnit

### Requirement: Edits made in git come back into the same agent after a diff (REQ-AGEXP-005)

The system MUST let the owner pull the package from the stamped repository and ref, content-scan it, show a diff of the fields it carries against the current agent, and on confirm save it as a new version of the same agent. A dangerous scan verdict MUST block the pull. Sharing, schedules, credentials and memory MUST NOT change.

#### Scenario: A developer edits the prompt in git and the owner pulls it
- GIVEN an agent kept in git and a commit on `main` that changes its prompt
- WHEN the owner chooses "Pull from git"
- THEN a diff shows the old and new prompt, and after confirming the agent has the new prompt and version history lists the pull as a new version that can be rolled back

#### Scenario: A pulled prompt with an injection is blocked
- GIVEN a commit whose prompt the content scan rates dangerous
- WHEN the owner chooses "Pull from git"
- THEN the pull is refused with the scan reason and the agent is unchanged
