---
sidebar_position: 6
description: What tools are, what MCP means, and how an agent is allowed to actually do things.
---

# Tools & MCP

A **tool** is something an agent can *do*, as opposed to something it can say.

An AI model on its own can only produce text. It cannot read your case file,
send a message, or create a record — it can only *describe* doing those things.
Tools are what close that gap. Give an agent a tool and it stops being a writer
and starts being a worker.

## What MCP means

**MCP** stands for **Model Context Protocol**. It is an open standard for how an
AI system is offered tools — think of it as a universal plug socket.

Before a standard existed, every AI product invented its own way of wiring up
"let the model do a thing", and none of them fit together. MCP means a tool
written once can be offered to any AI system that speaks the protocol.

You almost never need to think about the protocol itself. What matters is what
it buys you: **the tools in Hermiq are not a fixed list someone hard-coded.**
Other apps in your Nextcloud publish their capabilities as MCP tools, and your
agents can use them. A tool published by your case-management app is available to
an agent without anyone writing Hermiq-specific glue.

## What an agent can do with tools

Concretely, tools let an agent:

- **Look things up** — search your records, fetch a case, read a file.
- **Take action** — create a record, update a status, send a message.
- **Reach outside** — do web research, call an external service.

The exact catalogue depends on which apps you have installed and what they
publish.

## Tools are granted, not assumed

This is the important part. An agent does **not** get every tool that exists. Each
agent carries an explicit **grant list** — the tools it is allowed to call — and
that list is enforced when its turn is assembled, not merely suggested to the
model.

An agent with no tools granted can call nothing. That is the default, and it is
the safe one: an agent gets exactly the tools its job needs, and nothing else.
The same principle as not giving every new hire a master key.

## Classifying tools by risk

Not every tool deserves the same trust. Reading a record is not the same as
sending an email to a citizen.

In **Settings → Guardrail policy**, an administrator classifies tools per
organisation:

| Classification | What happens |
|---|---|
| **auto** | The agent may call it freely. |
| **confirm** | The call pauses for a human **[approval](../approvals.md)** first. |
| **deny** | The agent may not call it at all. |

Set once, inherited by every agent. So "any agent may read cases, but sending
anything to a citizen always needs a person" becomes a rule of the organisation
rather than something each agent's author has to remember.

Every tool call an agent makes is written to the audit trail, so you can answer
"what did it actually do?" afterwards.

## Where to find it

**MCP tools** in the Hermiq navigation lists the live tool catalogue — what is
available to grant. An agent's own grants are on its detail page, and risk
classification is in **Settings → Guardrail policy**.

## Related

- **[Agents](./agents.md)** — tools are granted per agent.
- **[Approvals](../approvals.md)** — the gate a `confirm` tool passes through.
- **[RAG](./rag.md)** — the other way an agent gets at your material.
