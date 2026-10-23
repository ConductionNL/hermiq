---
kind: code
depends_on: []
---

# Proposal: agents-standing-goal

## Summary

A person gives an agent a standing goal, such as "get every overdue permit application a reminder until none are left", with a check that says when it is reached. The agent keeps working on the goal in one session, a turn at a time, until the check passes, the person stops it, or a turn limit runs out. Every turn goes through the same kill switch, budget and approval gates as a scheduled run, and the person sees the progress in the session.

## Why

One row of hermiq's capability matrix, agents area (core), decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:dm-standing-goal` | no | build: core area (agents), changelog demand and one competitor rates yes |

Demand, quoted from the matrix:

- changelog https://github.com/NousResearch/hermes-agent/releases/tag/v2026.5.7

Competitor cell rated yes, quoted from the matrix evidence:

- Hermes Agent v2026.9.24: `hermes_cli/commands.py:117` "/goal 'Set a standing goal Hermes works on across turns until achieved'"; `hermes_cli/goals.py:397` "GoalState, :344 GoalGate shell checks, :860 goal_judge model call".

Partial cells (quoted): Dify 1.17.1 "Loop node with ... 'Loop Termination Condition' and ... maximum loop count"; n8n "an agent schedule re-runs the same objective on cron ... but has no completion check"; Open WebUI "timer lets the agent schedule a follow-up prompt back into the same chat".

## What hermiq already has

- Schedules with once, interval and cron, dispatched by one background job through three gates, kill switch, budget and approval (`lib/Service/ScheduleService.php:937-1000`).
- Each scheduled run starts a fresh session: `runAgentViaEngine()` saves a new conversation titled "Hermiq scheduled run" (`lib/Service/ScheduleService.php:2644-2675`).
- An LLM-as-judge through the `ProviderFactory` chokepoint, used for evals: `EvalScoringService` (`lib/Service/EvalScoringService.php:8-15,65-87`), which returns `{passed, score, judgeRationale}` and never throws on a bad case.
- Object queries in Context bundles, resolved by `ContextAssembler` (`lib/Service/Engine/ContextAssembler.php:354,394`), a way to read OpenRegister objects as the acting user.

## What this change builds

1. A `Goal` object: agent, session, goal statement, check, cadence, turn limit, status.
2. "Set a goal" in a session: the person writes the goal, picks the check and the cadence.
3. Turns that continue the same session until the check passes, each through the scheduled-run gates.
4. Two kinds of check: an object query that must return a count (deterministic, preferred), or a judge question answered by a model.
5. The goal's progress in the session header, with "Stop goal".

## Out of scope

- Goals that spawn other agents. Delegation keeps its own rules (archived `sub-agent-delegation`).
- Shell or code checks, as Hermes runs them. hermiq has no sandbox yet; `tools-code-sandbox` in this pass specifies one.
