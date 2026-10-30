---
kind: code
depends_on: []
---

# The agent edit page lets a person choose whose rights the agent works with

## Why

| row | capability | hermiq today |
|---|---|---|
| `ag-identity` | Choose whose rights an agent runs with: the person who asked, the owner, or a service account. | partial: `Agent.actingUser` (`lib/Settings/hermiq_register.json`) is resolved by `ScheduleService` and threaded through `Engine` and `ContextAssembler`; a declared identity that no longer resolves refuses the run (`specs/agent-identity`). No screen sets it: `src/modals/AgentFormModal.vue` has no field, `AgentFactsheetDialog.vue` only shows it, and `TenantOpsService::reassignAgent()` changes it only for a flagged agent. |

The board `hermiq/HmAgentBewerken` draws the choice "Namens wie werkt de agent" with two options:
"Namens de persoon die hem gebruikt" (sees only what that person may see) and "Als eigen
serviceaccount" (for schedules with nobody behind the screen). Today anyone who can write the agent
object through the OpenRegister objects API can set `actingUser` to any enabled user, which runs the
agent with that user's rights.

## What changes

- `agent-identity` gains three requirements: the edit page offers the two drawn choices and stores
  them in `actingUser` (REQ-AGID-001); naming an account is a governance action checked on the
  server for every write of the agent object (REQ-AGID-002); the edit page shows a declared account
  that no longer resolves instead of hiding it (REQ-AGID-003).
- `lib/actions.seed.json` gains `agent.set-acting-user`, admin-only by default.
- `openspec/parity/capabilities.json` points `ag-identity` at REQ-AGID-001.

## Non-goals

- No third "owner" option. The board draws two. An agent without `actingUser` already runs as the
  person who started the turn, and on a schedule as the schedule's owner (`specs/agent-identity`).
- No change to how a declared identity is resolved, refused or narrowed along a delegation.
- No account provisioning: the service account is an existing Nextcloud user.
