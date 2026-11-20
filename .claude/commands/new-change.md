---
description: Start a new OpenSpec change in openspec/changes/
---
Start an OpenSpec change named `$ARGUMENTS` (kebab-case).

1. Read `openspec/README.md` and `openspec/config.yaml` for the layout and project rules, and look at an existing change in `openspec/changes/`.
2. Create `openspec/changes/$ARGUMENTS/` with:
   - `proposal.md`: why, what changes, and impact;
   - `design.md`: the decisions and their trade-offs;
   - `tasks.md`: a checklist of implementation tasks;
   - `specs/<capability>/spec.md`: the requirement deltas, each with scenarios.
3. Check `openspec/specs/` first, so the change extends an existing capability where one fits.

Do not build anything yet. When the change is done, its spec deltas are merged into `openspec/specs/` and the change moves to `openspec/changes/archive/`.
