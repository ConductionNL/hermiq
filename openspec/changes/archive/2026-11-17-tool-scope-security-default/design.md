## Context

An agent with no configured tools received the whole discovered catalog. That was
changed to receive none, but shipped with `HERMIQ_LEGACY_UNSCOPED_TOOLS=1` restoring
the old behaviour. This change removes the override.

## Goals / Non-Goals

**Goals:**
- One rule, no exceptions: no tools configured means no tools.
- No unreachable code that grants tools.
- Make the affected agents visible without changing them.

**Non-Goals:**
- Migrating agents. See D3.
- Tool classification itself — `isWriteOrDestructive()` / `requiresGrant()` stay,
  because wildcard expansion and the approval gate use them.

## Decisions

### D1 — Remove the override rather than make it louder

The first instinct was to keep the hatch and log loudly when it is set. That fails
on the same two properties that make it a defect: a log line is not visible where the
capability is read (the agent), and it does not make the widening scoped.

An operator who needs an agent to have tools can give it tools. That is one action,
in the place the capability is defined.

### D2 — Remove `applyDefaultDeny()` with it

It is called from exactly one place. Left behind it would be an unreachable method
whose signature says "return the granted tool ids", which reads as a supported path
and is one call site from becoming one again.

### D3 — Report the affected agents; do not migrate them

Back-filling 99 agents with the tools they implicitly held would preserve behaviour
and defeat the change: each would carry ~101,000 tokens per turn, now recorded as an
explicit grant nobody chose.

The report converts an invisible default into a visible decision. Making the decision
automatically would just re-hide it.

### D4 — Tool-less is not broken

`resolvesToNothing()` continues to return false for empty grants. `ToolLoop` throws
on that predicate, so routing unconfigured agents through it would have turned a
scoping change into 99 failing agents. An agent with no tools is a legitimate
conversational agent; an agent whose configured grants resolve to nothing is a
defect, and only the second raises.

## Seed Data (ADR-001)

**None.** No OpenRegister schemas are introduced or modified.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Grant resolution | **Imperative** | The grant grammar is code; its inputs (`Agent.tools`, the catalog) are the declarative part. |
| The agent's tool list | **Declarative** | `Agent.tools` on the agent object, which is the whole point of this change. |

## Risks / Trade-offs

**99 agents lose tools on the development instance.** That is the change working, not
a side effect — but it will look like a regression to whoever hits it first, which is
why the report exists and why the behaviour is documented rather than only committed.

**No escape hatch means no fast rollback.** Deliberate. A rollback switch for "grant
every discovered tool to every unconfigured agent" is the thing being removed; adding
it back under a different name would be the same defect with better manners.
