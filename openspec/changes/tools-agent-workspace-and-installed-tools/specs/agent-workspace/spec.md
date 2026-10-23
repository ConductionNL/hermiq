# agent-workspace Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- tools-agent-workspace-and-installed-tools

## Purpose

An agent keeps a writable workspace for a conversation, runs code and installed command line tools on it inside the sandbox, and the user can see and keep its files. Rows `hermiq:dm-agent-workspace` and `hermiq:dm-install-cli-tool`.

## ADDED Requirements

### Requirement: An agent can keep a workspace for the whole conversation (REQ-WKSP-001)

When an agent's `workspaceMode` is `session`, hermiq MUST key its governed workspace by agent, conversation and user, so every run in that conversation reaches the same workspace and no run of another conversation, user or agent reaches it. A session workspace MUST end when its conversation is deleted or 14 days after its last activity, and MUST stay within its size and file budget.

#### Scenario: A file from the first question is there for the second
- GIVEN an agent with `workspaceMode: session` and the workspace tools granted
- WHEN a policy officer asks it to write "kwartaal-q3.csv" and, three messages later, to add a total row to it
- THEN the second turn reads and changes the same file, and the Files panel shows one "kwartaal-q3.csv"

#### Scenario: Another user's conversation cannot reach it
- GIVEN two users each in a conversation with the same agent
- WHEN a run of the second user names the first user's workspace id
- THEN the call is refused, and the workspace served is the second user's own
- @e2e exclude needs two users and a crafted tool call; covered by PHPUnit on workspace resolution

### Requirement: Sandbox runs work on the workspace and write back what they change (REQ-WKSP-002)

Hermiq MUST copy the workspace, or the named paths, into a sandbox run and MUST write back only the files the run created or changed, confined to the workspace. A workspace over the sandbox's input cap MUST be refused with a message that names the limit.

#### Scenario: Code adds a column to a file in the workspace
- GIVEN a session workspace with "aanvragen.csv"
- WHEN the agent runs Python that adds a column "doorlooptijd" and saves the file
- THEN the workspace holds the changed file, and no file outside the workspace was written

### Requirement: The user sees the workspace and can keep a file (REQ-WKSP-003)

Hermiq MUST show a conversation's workspace files in the chat, and MUST let the session user download a file or save it to their own Files. A saved file MUST carry the agent-authored mark from the same operation, and a failed mark MUST fail the save.

#### Scenario: A policy officer keeps the report
- GIVEN a conversation whose workspace holds "rapportage-q3.pdf"
- WHEN the policy officer chooses "Save to my Files" on it
- THEN the file appears in their Files under "Hermiq" with the tag "Agent authored"

### Requirement: An agent installs command line tools only from an allowlisted registry, without install scripts (REQ-WKSP-004)

Hermiq MUST expose `hermiq.installTool` as a default-denied tool with reach `external` and `destructiveHint: true`. The install MUST run in the sandbox's installer, whose only egress is to the registries the admin allowlisted, and MUST NOT run a package's install scripts. A package outside the organisation's package allowlist, when one is set, MUST be refused before the installer is called. Code runs MUST NOT have the installer's network.

#### Scenario: An agent installs csvkit
- GIVEN an agent granted `hermiq.installTool` and PyPI allowlisted
- WHEN the model installs `csvkit` version `2.1.0`
- THEN the tool answers with the installed binaries and their versions, and the run records the package, the version and the layer's hash
- @e2e exclude needs registry access from the installer; covered by the ExApp tests and PHPUnit on the tool

#### Scenario: A registry that is not allowlisted
- GIVEN an admin allowlist of PyPI only
- WHEN the model installs an npm package
- THEN the installer is not called, and the tool answers "npm is not an allowed registry on this instance."
- @e2e exclude needs a model that calls the tool; covered by PHPUnit

### Requirement: An installed tool runs with an argument list and no shell (REQ-WKSP-005)

Hermiq MUST run an installed binary through `hermiq.runInstalledTool` with an argument list, without a shell, inside the sandbox with the workspace as working directory and the sandbox's limits and network isolation.

#### Scenario: Shell syntax is not interpreted
- GIVEN `csvkit` installed
- WHEN the model runs `csvstat` with the argument `aanvragen.csv; rm -rf .`
- THEN `csvstat` receives that text as one file name and fails to find it, and no other command runs
- @e2e exclude needs a model that crafts the argument; covered by the ExApp tests
