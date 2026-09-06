# MCP tools

## What it is for

Decide what an agent may actually do. A tool is a thing an agent can call: read an
object, write one, send a message, run a command.

## How to reach it

**MCP tools** in the navigation for the instance-wide catalogue. Per-agent grants live
on the agent's own detail page, under Tool grants.

## How granting works

Default deny for anything that writes or destroys. An agent gets a tool because
somebody granted it, not because it exists.

Grants can be exact (`app.toolName`) or a read-only wildcard over a schema
(`{app}.{schema}.*`). Writing is a separate opt-in, spelled `:write`, so widening read
access never quietly widens write access.

An agent with no grants at all resolves none of the write tools. That is the safe
default and it is asserted by a test, because a default that drifts open is the kind of
thing nobody notices.

## Reach

Every tool in the catalogue declares its reach from a closed vocabulary, so you can see
what a grant actually exposes before you make it. The catalogue is filterable, because
a hundred rows is not a way to find one tool.

## When an agent wants more

It asks. An ungranted destructive call routes to [Approvals](approvals.md) rather than
failing silently or proceeding anyway. If you find yourself approving the same call
daily, grant it here instead.

## On the CLI path

When a turn runs through the `claude` CLI, the CLI owns its own tool loop. Hermiq still
serves the tools, through a governed MCP endpoint scoped to that single run, so the same
grants, guardrails, approvals, redaction and tracing apply. A turn that cannot be
governed fails loudly rather than running ungoverned.

## API

- `GET /apps/hermiq/api/agents/tools`
- `PUT /apps/hermiq/api/agents/{id}/tool-grants`

## Where to go next

Set the instance-wide rules in [Guardrail policy](guardrail-policy.md).
