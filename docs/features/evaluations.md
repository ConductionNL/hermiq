# Evaluations

## What it is for

Answer whether a skill actually helps, with evidence rather than an impression.

## How to reach it

**Evaluations** in the navigation. A dataset has its own detail page.

## How a paired run works

An evaluation runs each case twice: once with the skill attached, once without. The
difference is the skill's contribution.

You choose how the without-half is built:

- **Joint**, the default. One without-half detaches every linked skill together. Each
  case runs twice, at roughly double the token cost, and the delta is shared by the
  whole set.
- **Per-skill**. One without-half per linked skill. With N skills each case runs N+1
  times, at N+1 times the cost, and each skill gets its own true marginal delta.

Link one skill per dataset for the cleanest attribution.

## What it costs

Both halves count against the same organisation and agent budgets. An evaluation is
real LLM usage, and it is billed as such rather than being exempt for being a test.

The page tells you the multiplier before you start.

## What it produces

A recorded delta per skill, which is what [skill maturity](skills.md) is assessed
against. A skill can be held back from publication until its evidence supports the level
it claims.

## API

- `GET|POST /apps/hermiq/api/evals`
- `POST /apps/hermiq/api/evals/{id}/run`

## Where to go next

Promote what the evidence supports in [Skills](skills.md).
