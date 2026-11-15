---
kind: code
depends_on: []
---

# Sub-agents that work on parts of one task run at the same time

## Why

| row | capability | hermiq today |
|---|---|---|
| `fl-parallel` | Run several sub-agents at the same time on parts of one task. | partial: one turn may call `hermiq.delegateAgent` several times, bounded by `delegation.maxFanOut` (`lib/Service/DelegationService.php`), but each call runs the sub-agent inline through `ScheduleService::runAgentAsOwner()` and returns before the next starts. Three parts of two minutes each take six minutes. |

## What changes

- `sub-agent-delegation` gains two requirements: a turn hands several parts to sub-agents in one
  call and they run at the same time, each gated and audited as a single delegation is
  (REQ-DLGPAR-001); a part no worker picks up in time runs inline, so the turn never waits on a
  worker that is not there (REQ-DLGPAR-002).
- A new agent tool `hermiq.delegateAgents` takes a list of parts; `hermiq.delegateAgent` stays.
- `openspec/parity/capabilities.json` points `fl-parallel` at REQ-DLGPAR-001.

## Non-goals

- No change to the existing gates (allowlist, self and cycle, depth, fan-out, organisation,
  kill switch, budget, approval) or to the acting user a sub-agent inherits.
- No sub-agent sees another part's conversation; each part keeps its own isolated conversation.
- Parallel scheduled runs are `operations-parallel-run-workers`, not this change.
