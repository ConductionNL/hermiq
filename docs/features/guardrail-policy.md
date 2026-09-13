# Guardrail policy

## What it is for

Say, once for the whole organisation, which kinds of action an agent may take on its own
and which need a person first.

## How to reach it

**Settings > Guardrail policy**.

## How it relates to tool grants

Two different questions, deliberately separated.

- [MCP tools](mcp-tools.md) answers "may this agent call this tool at all"
- Guardrail policy answers "and if it does, does a human see it first"

An agent can hold a grant and still be gated, which is the normal arrangement for
anything that writes to a system of record.

## Effective policy

Policy resolves per caller. The effective policy endpoint tells you what actually
applies, rather than what was configured somewhere up the chain. When a run is blocked,
that is the answer you want.

## Model policy

The same surface constrains which providers and models an organisation may use. An
agent pointed at a model outside the policy is refused at the chokepoint, before the
call is made, not after the tokens are spent.

## API

- `GET|POST /apps/hermiq/api/guardrail-policies`
- `GET /apps/hermiq/api/guardrail-policies/effective`

## Where to go next

Decide who reviews what lands in [Approvals](approvals.md).
