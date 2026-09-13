# Memory

## What it is for

An agent that forgets everything between runs starts from nothing every morning.
Memory is the durable half of what it knows, and this page is where you read and
correct it.

## How to reach it

**Memory** in the navigation, then pick an agent. The same panel appears in place on
the agent's own detail page, so you can curate memory without leaving the agent.

## What is stored

Two things, both as OpenRegister objects:

- **Agent memory**, durable facts the agent recorded about its own work
- **User profile**, what it learned about the person it works for

Each has a character budget. When an entry pushes it over, the entry is still written
and the object is flagged for consolidation. Nothing is silently dropped, because an
agent that quietly forgets is worse than one that asks to be tidied.

## What an agent may write

An agent can add a fact itself during a run, through the `hermiq.rememberMemory` tool,
choosing whether it belongs to the agent or to the user. Redaction runs before the
write, so a secret that passed through a turn does not become a permanent record.

## Tenancy

Memory is scoped to the owning organisation. Another tenant cannot read it, and cannot
learn of its existence through a failed request.

## API

- `GET|POST /apps/hermiq/api/agents/{agentId}/memory`
- `GET /apps/hermiq/api/agents/{agentId}/user-profiles`
- `GET /apps/hermiq/api/agents/{agentId}/recall`

## Where to go next

Memory is what the agent worked out for itself. For material you hand it deliberately,
attach a context object on the [agent page](agents.md).
