# agent-template-gallery Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-export-import-and-git-sync

## Purpose

Export a live agent to a file, import it elsewhere through review, and save an agent as a template. Rows `hermiq:ag-import-export` and `hermiq:re-template-from-agent`.

## ADDED Requirements

### Requirement: An agent can be exported to a file from its page (REQ-AGEXP-001)

The system MUST offer "Export" on the agent page to users who may read the agent. It MUST download the agent's secret-free package as `<agent-name>.hermiq-agent.json`, holding no invited users, groups, quotas, views, acting user or credentials.

#### Scenario: A functional admin exports an agent to move it to the test environment
- GIVEN a functional admin on the page of the agent "Complaint router"
- WHEN they choose "Export"
- THEN a file `complaint-router.hermiq-agent.json` downloads, and it contains the prompt and tools but no user or group ids

### Requirement: An exported agent is imported through review (REQ-AGEXP-002)

The system MUST offer "Import agent" on the agent catalog. A file import MUST land in the Store as a quarantined, content-scanned template. An agent MUST only be created from it after an organisation admin approves it, through "Use this template".

#### Scenario: The agent arrives on the test environment
- GIVEN the exported file and a functional admin on another Nextcloud
- WHEN they choose "Import agent" on the catalog and upload the file
- THEN the Store lists "Complaint router" as quarantined with its scan report, and after approval "Use this template" creates the agent

### Requirement: An agent can be saved as a reusable template (REQ-AGEXP-003)

The system MUST offer "Save as template" on the agent page to the agent's owner. It MUST create an active template in the Store from the agent's package, recording the agent it came from.

#### Scenario: A team lead shares a well-tuned agent as a template
- GIVEN the owner of the agent "Meeting minutes drafter"
- WHEN they choose "Save as template"
- THEN the Store shows the template "Meeting minutes drafter" as active, and a colleague creates their own agent from it with "Use this template"
