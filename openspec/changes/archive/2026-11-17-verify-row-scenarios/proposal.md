---
kind: spec
depends_on: []
---

# Say in a scenario what five capability rows promise

## Why

The 12 November verification read every capability row against the scenarios of the requirement it records. For five rows the requirement is right but no WHEN/THEN says what the row says. This change adds the missing scenarios to the main specs. Two of them describe behaviour the code does not have yet, so those rows lose `built` (decision 389) and this change carries the build tasks.

| row | missing scenario | built? |
|---|---|---|
| `tl-contacts` | an agent looks a contact up | yes, `hermiq.searchContacts` |
| `tl-mail` | an agent sends an email | yes, `hermiq.sendMail` |
| `co-disable-dept` | an admin reads who engaged the switch and when | yes, `OrganisationControlController::show()` |
| `ov-tool-oversight` | an operator marks a tool call as misuse | no: the oversight view lists and exports, nothing marks |
| `op-health` | one page shows chat health with each dependency | no: the endpoint exists, no page shows it (scenario added to `spec-coverage-built-rows`) |

## What changes

- `nc-native-tools`: two scenarios under "NC-native capabilities registered as IMcpToolProvider tools".
- `agent-tool-governance`: one scenario under "Per-agent tool-invocation oversight surface (AI Act art.12/14)", drawn on `hermiq/HmAgent` ("Markeren", "Misbruik").
- `human-approval-gate`: one scenario under "Org-level kill-switch halts all runs".

## Out of scope

The board `hermiq/HmGoedkeuringen` draws the switch per department ("Per afdeling"); the spec and the code switch a whole organisation. That is a decision for Ruben and is not changed here.
