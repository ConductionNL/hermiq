# Architectural Decision Records

This folder contains app-specific Architectural Decision Records (ADRs) for this app.

ADRs document significant design decisions, their context, the reasoning behind them, and the alternatives that were considered. They provide a historical record of why the app is built the way it is.

> **Note:** Organisation-wide ADRs (ADR-001 through ADR-015) live in `apps-extra/.claude/openspec/architecture/` and apply to all Conduction apps. Only create an app-specific ADR here when the decision is **unique to this app** and not already covered by an org-wide ADR.

ADRs are created and refined during `/opsx:app-explore` sessions.

## Naming Convention

Files are named `adr-{NNN}-{slug}.md` with sequential numbering:

- `adr-001-example-decision.md`
- `adr-002-another-decision.md`

## File Format

```markdown
# ADR-{NNN}: {Title}

**Status**: proposed | accepted | deprecated | superseded by [ADR-XXX]

**Date**: YYYY-MM-DD

## Context

What situation or problem prompted this decision? What constraints exist?

## Decision

What was decided?

## Consequences

**Positive:**
- ...

**Negative / trade-offs:**
- ...

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| ...    | ...              |
```

## Status Values

| Status | Meaning |
|--------|---------|
| `proposed` | Being discussed — not yet in effect |
| `accepted` | Agreed and in effect |
| `deprecated` | No longer applies (but kept for history) |
| `superseded` | Replaced by a newer ADR (reference the new one) |

## When to Write an ADR

Write an ADR whenever you make a significant decision that:
- Is hard to reverse
- Affects multiple parts of the codebase
- Would surprise future developers if they didn't know the reasoning
- Involves a meaningful trade-off
- Is specific to this app (not already covered by an org-wide ADR)
