---
kind: code
depends_on: []
---

# An administrator edits the action matrix on the tenant management page

## Why

| row | capability | hermiq today |
|---|---|---|
| `op-rbac` | Give people different rights: admin, builder or user. | partial: the action matrix of ADR-023 is enforced (`lib/Service/ActionAuthService.php`, 23 actions in `lib/actions.seed.json`), but no screen edits it. The seed's comment points at an admin settings page that does not exist; an administrator can only change the app config key `hermiq` / `actions` by hand. `ActionAuthService::setMatrix()` exists and has no caller besides the repair step. |

A right that only `occ config:app:set` can change is not a right an organisation manages, so the
row moves from yes to partial until the screen exists.

## What changes

- `action-authorization` gains two requirements: the tenant management page shows the matrix to
  Nextcloud administrators, with each action in plain words and the groups that may run it, and
  saves changes (REQ-ACTMX-001); the matrix endpoints are administrator-only and refuse unknown
  actions and groups (REQ-ACTMX-002).
- Each seeded action gets a plain-language label and description.
- `openspec/parity/capabilities.json` sets `op-rbac` to partial and points it at REQ-ACTMX-001.

## Non-goals

- No per-organisation matrix: the matrix is instance-wide, as ADR-023 decides. The section says so.
- No delegation of matrix editing to a group: editing who may do what stays with administrators,
  or a group could grant itself any action.
- No change to agent ownership or sharing, or to OpenRegister's data RBAC.
