---
kind: code
---

## Why

An agent with no configured tools received **the entire discovered catalog**. That
has now been changed to receive none — but the change shipped with an escape hatch,
`HERMIQ_LEGACY_UNSCOPED_TOOLS=1`, that restores the old behaviour wholesale.

**From a security-by-design position that hatch is the defect, not the mitigation.**
It is an environment variable that silently re-grants every discovered tool on the
instance to every unconfigured agent. Nothing in the product surfaces that it is set.
An operator debugging one agent could set it and widen 99 others.

This change removes it, and states the resulting rule without exception:

> **No tools configured means no tools.**

## What Changes

- **Delete `HERMIQ_LEGACY_UNSCOPED_TOOLS`.** Empty or unset `Agent.tools` resolves to
  `[]`, always.
- **Delete `applyDefaultDeny()`** and its supporting path. It is called from exactly
  one place — the legacy branch — so removing the flag orphans it. Leaving an
  unreachable method that grants tools is worse than leaving none: it reads as a
  supported path to the next person.
- **Keep `isWriteOrDestructive()`.** It still classifies for the wildcard expansion
  (`app.schema.*` → read verbs only) and for the approval gate. Only the
  whole-catalog application of it goes.
- **A migration surfaces the affected agents** rather than changing them: a report
  listing every agent whose `tools` is null or empty, so an operator can grant
  deliberately instead of discovering it when an agent goes quiet.

## Why not migrate the agents automatically

Back-filling 99 agents with the tools they were implicitly receiving would preserve
behaviour and defeat the purpose — they would each carry ~101,000 tokens per turn,
now written down as an explicit grant nobody chose.

The point of the change is that an unconfigured agent is **unconfigured**, and that
this becomes visible rather than being papered over. The report tells the operator
which agents need a decision; it does not make the decision for them.

## The measurement this rests on

Development instance, 2026-08-16:

| | Tools | Bytes | Tokens |
|---|---|---|---|
| Full catalog | 122 | 433,198 | ~108,300 |
| What the legacy default yielded | 81 | 403,904 | **~101,000** |
| An agent with 10 explicit grants | 10 | 7,777 | ~1,900 |
| An agent with 3 explicit grants | 3 | 2,412 | **~600** |

**89 of 111 agents had `tools` NULL**, 10 more had `[]` — 89% took the legacy branch.

The write-verb strip barely helped: write tools are the *cheap* ones (no
`outputSchema`), so denying them removed 7% of the bytes and left the expensive read
verbs in place. "Default-deny" was doing far less than its name implies.

## Impact

- **Behaviour**: an unconfigured agent runs text-only. It does not error —
  `resolvesToNothing()` deliberately still returns false for empty grants, because
  `ToolLoop` throws on that and an unconfigured agent is not a broken one.
- **Blast radius on the development instance**: 99 agents tool-less, 12 unaffected.
- **No way back except configuring the agent**, which is the point.

## Capabilities

### Modified Capabilities
- `agent-tool-governance`: an empty grant list means no tools, with no override.
