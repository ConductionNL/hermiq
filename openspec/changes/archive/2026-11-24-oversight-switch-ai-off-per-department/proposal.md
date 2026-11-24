# Switch AI off for one department, with evidence to download

## Why

Ruben decided on 13 Nov (decision 406) that the drawn board is canon. HmGoedkeuringen draws the
emergency stop for the whole organisation and, next to it, a switch per department. A department
that is off shows who switched it off, when, and how many runs were refused, with a "Bewijs
downloaden" button. The shipped kill-switch (`OrganisationControlController`) only switches a
whole OpenRegister organisation, and nothing can be downloaded.

### Matrix rows (hermiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `co-disable-dept` | an admin switches AI off for one department and downloads evidence that it was off |

## What changes

- A department is a Nextcloud group that the admin marks as a department. Each agent runs for one
  department. No department names are hard-coded: the list is configuration.
- An admin switches AI off for one department. Runs of that department's agents are refused and
  recorded, and other departments carry on.
- The admin downloads an evidence file per department: who switched it off, when, and every run
  refused while it was off, read from the audit trail.

## Impact

- `lib/Controller/OrganisationControlController.php`, the run dispatch check, the approvals page
  (HmGoedkeuringen). Not built yet: the matrix row moves to `specified`.
