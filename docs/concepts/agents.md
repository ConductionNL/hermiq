---
sidebar_position: 2
description: What an AI agent is, what it is made of, and how it differs from a chatbot.
---

# Agents

An **agent** is an AI you have configured to do a particular job.

That is the whole idea. A chatbot is general — it will talk about anything and
remembers nothing. An agent is specific: it has a job description, it knows your
material, it can take actions, and it can be scheduled to work without you
asking. If a chatbot is a stranger who is good at talking, an agent is a
colleague who has been briefed.

## What an agent is made of

When you create an agent in Hermiq, you are filling in a job description:

| You choose | What it means |
|---|---|
| **Name & description** | What this agent is for, so your colleagues know when to use it. |
| **Icon** | A visual identity, so it is recognisable in a list. |
| **Prompt** | The job description itself: who the agent is, how it should behave, what it should never do. |
| **Provider & model** | Which AI actually powers it — a cloud model like OpenAI or Anthropic, or a local one via Ollama that never leaves your building. |
| **[Skills](./skills.md)** | Reusable instruction sheets you install on it. |
| **[Context](./context.md)** | Reference material for its work. |
| **[Tools](./tools-and-mcp.md)** | What it is allowed to actually *do*. |
| **[RAG](./rag.md) settings** | Whether it looks things up in your files and records first. |

Only name, prompt and a model are really needed. Everything else you add as the
job demands.

## The prompt is the job description

The **prompt** is the most important field and the one people underuse. It is
not a question — it is standing instructions. Compare:

> "Summarise meetings."

with:

> "You summarise municipal council meetings for the clerk's office. Always lead
> with the decisions taken and who is accountable. Keep it under 300 words.
> Write in Dutch. If attendance was not recorded, say so rather than guessing.
> Never speculate about a councillor's motives."

The second one produces a usable summary every time, because it says what *good*
looks like. Write the prompt the way you would brief a new colleague on their
first day — including the things you would consider too obvious to say.

## Choosing a model

The **provider** and **model** decide which AI does the thinking. This is a real
trade-off, not a detail:

- **Local models** (via Ollama) run on your own hardware. Nothing leaves your
  building. Best when the material is sensitive, and often good enough for
  summarising, sorting and drafting.
- **Cloud models** (OpenAI, Anthropic, Fireworks, Azure) are usually more
  capable, especially at reasoning over messy input — but the text you send
  leaves your infrastructure to reach them.

An administrator can set a **model policy** for the organisation, so that agents
can only use models you have approved. If an agent asks for one outside the
policy, it is quietly moved to an allowed model rather than failing.

## Agents are records, not settings

This is the part that makes Hermiq different from most agent tools: an agent is
an ordinary [OpenRegister](https://openregister.conduction.nl) object, exactly
like any other data in your organisation.

Which means, without anyone building it specially:

- **Every change is versioned.** You can see what the prompt said last month,
  and who changed it.
- **Everything is audited.** Every run and every action is a permanent record.
- **It obeys your access rules.** Agents belong to organisations; the same
  permissions that govern your other data govern them.

That is why an agent here is something you can hand real work to and still
account for afterwards.

## What an agent does when it works

Each time an agent works, that is a **[run](./runs-and-schedules.md)**. A run can
start because you chatted with it, because a [schedule](./runs-and-schedules.md)
fired, because a flow triggered it, or because a webhook called it.

During a run the agent:

1. Assembles what it knows — its prompt, its [skills](./skills.md), its
   [context](./context.md), and its [memory](./memory.md).
2. Optionally looks things up in your files and records ([RAG](./rag.md)).
3. Thinks, and possibly calls [tools](./tools-and-mcp.md) to take action.
4. Pauses for an [approval](../approvals.md) if it is about to do something you
   have gated.
5. Delivers its answer — to the chat, or to a Nextcloud Talk conversation.

## Where to find it

**Agents** in the Hermiq navigation lists every agent you can see. Open one to
see its configuration, its runs, its skills and tools, and its memory — or use
**Chat** to talk to it directly.

## Related

- **[Skills](./skills.md)** — teach an agent a reusable way of working.
- **[Context](./context.md)** — give it this project's material.
- **[Runs & schedules](./runs-and-schedules.md)** — make it work on a rhythm.
- **[Approvals](../approvals.md)** — put a human in front of risky actions.
