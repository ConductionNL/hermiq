# The skill curator also merges duplicate skills

## Why

Ruben decided on 12 Nov (decision 390) that the curator merges duplicate skills as well as
archiving unused ones. The shipped curator (`SkillCuratorTask`) only moves skills from active
to stale to archived. Two skills that do the same thing both stay active, and agents pick
either one.

### Matrix rows (hermiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `sk-curator` | the curator proposes merging duplicate skills, and a person approves the merge |

## What changes

- The curator pass finds skills that duplicate each other and proposes one merge per pair.
- A merge needs a person's approval, keeps the survivor active, archives the other one and
  points the agents that used it at the survivor. Nothing is hard-deleted.

## Impact

- `lib/BackgroundJob/SkillCuratorTask.php`, `lib/Service/SkillMarketplaceService.php`, the
  skills page (HmSkills). Not built yet: the matrix row moves to `specified`.
