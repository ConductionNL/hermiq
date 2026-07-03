# Feature Specs

Feature specs define what the app should do — they are the input for OpenSpec changes when you are ready to build.

Specs are created and refined during `/opsx:app-explore` sessions.

## Feature Lifecycle

```
idea ──► planned ──► in-progress ──► done
  │          │
  │     use /opsx:ff
  │     to create a
  │     change spec
  │
still fuzzy,
needs more thinking
```

| Status | Meaning |
|--------|---------|
| `idea` | Concept noted, not yet ready to spec out — keep exploring |
| `planned` | User stories and acceptance criteria defined — **ready for `/opsx:ff`** |
| `in-progress` | One or more OpenSpec changes have been created from this feature |
| `done` | All associated OpenSpec changes have been archived |

## Spec Format

Each feature spec lives at `openspec/specs/{feature-name}/spec.md`:

```markdown
# {Feature Name} Specification

**Status**: idea | planned | in-progress | done

**OpenSpec changes:** _(links to openspec/changes/ directories when in-progress or done)_

## Purpose

What this feature does and why it matters to users.

## Requirements

### Requirement: {Requirement Name}
The system MUST/SHOULD/MAY {requirement statement}.

#### Scenario: {Scenario Name}
- GIVEN {precondition}
- WHEN {action}
- THEN the system {MUST/SHOULD} {expected outcome}

## User Stories

- As a [role], I want to [action] so that [outcome]

## Acceptance Criteria

- [ ] ...
- [ ] ...

## Notes

Open questions, constraints, dependencies, related ADRs.
```

> For `idea` status, a lightweight spec (Purpose + User Stories + Acceptance Criteria) is fine. Fill in Requirements/Scenarios when moving to `planned`.

## Important Notes

- A single feature can result in **multiple OpenSpec changes** — break large features into independently deployable slices
- Features are maintained at the concept level here; implementation details live in `openspec/changes/`
- Once a feature moves to `in-progress`, link to the OpenSpec change directories in the `OpenSpec changes` field
