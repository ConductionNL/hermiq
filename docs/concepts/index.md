---
sidebar_position: 1
description: A plain-language introduction to agents, skills, memory, context, tools, MCP and RAG — no prior AI knowledge needed.
---

# Agentic concepts, explained

Hermiq is built out of a handful of ideas that come from the world of AI agents.
If you have never met these words before — *agent*, *skill*, *memory*, *context*,
*tool*, *MCP*, *RAG* — this page explains all of them in plain language, in the
order they make sense. Nothing here assumes you have used an AI system before.

Read this page once, and the rest of the documentation will make sense.

## Start with the problem

A chatbot answers one question at a time. You ask, it replies, and it forgets.
That is fine for a quick question, but it cannot do a job for you.

What most people actually want is something closer to a **colleague**: someone
you brief once, who knows your organisation's material, who can look things up
and take action, who remembers what happened last week, and who does the job
every Monday morning without being asked.

That is what an agent is for. Everything below is one piece of turning a
forgetful chatbot into something that can hold a job.

## The pieces, in one table

| Concept | In one sentence | Everyday analogy |
|---|---|---|
| **[Agent](./agents.md)** | The worker: a configured AI with a job description. | The colleague you hired. |
| **[Skill](./skills.md)** | A reusable instruction sheet teaching a way of working. | A how-to guide in their desk drawer. |
| **[Memory](./memory.md)** | What the agent remembers between conversations. | What they learned about you over months. |
| **[Context](./context.md)** | Reference material you hand the agent for this job. | The project binder on their desk. |
| **[Tools & MCP](./tools-and-mcp.md)** | The actions the agent can actually take. | Their hands, phone, and keyboard. |
| **[RAG](./rag.md)** | Looking things up before answering. | Checking the filing cabinet before speaking. |
| **[Runs & schedules](./runs-and-schedules.md)** | The agent doing its job, once or on a rhythm. | A shift, or a standing Monday task. |

Two more pieces exist to keep all of this safe, and they matter as much as the
rest: **[approvals](../approvals.md)** (a human says yes before something risky
happens) and **[incidents](../incidents.md)** (a written record when something
goes wrong).

## How they fit together

Think of hiring someone:

1. **You hire them** → that is the **agent**. You give it a job description (a
   prompt) and decide which AI model powers it.
2. **You give them the house rules** → those are **skills**. "This is how we
   write a permit decision." Reusable, and shared across agents.
3. **You hand them the project binder** → that is **context**. "Here is the
   design document for this project, our standards, who the stakeholders are."
4. **You show them where the filing cabinet is** → that is **RAG**. They look
   things up in your files and records instead of guessing.
5. **You give them a phone and a keyboard** → those are **tools**, offered
   through **MCP**. Now they can actually *do* things, not just talk.
6. **They remember you** → that is **memory**. Next month they still know your
   preferences.
7. **They work Monday mornings** → that is a **schedule**, and each time they
   work is a **run**.
8. **They ask before anything drastic** → that is an **approval**.

Every one of these is optional. An agent with none of them is just a chatbot.
Add them as the job requires.

## The one distinction people trip over

**Skill vs context** sounds like the same thing — both are text you give the
agent. The difference is *reusability*:

- A **skill** is a *way of working* that travels. "How to summarise a meeting"
  is useful to any agent, in any project, at any organisation. Skills can be
  shared and published.
- A **context** is *this situation's material*. Your project's `design.md`, your
  team's naming conventions, this quarter's targets. It is specific to you and
  it does not travel.

Rule of thumb: if you would happily give it to another organisation, it is a
skill. If it only makes sense inside your project, it is context.

**Memory vs context** is the other one:

- **Context** is what *you* hand the agent, deliberately.
- **Memory** is what the *agent* picked up by working, without being told.

## What makes Hermiq's version different

Hermiq runs all of this **inside your own Nextcloud**. That matters for reasons
that are not about AI at all:

- **Your data stays yours.** The agent reads your files and records where they
  already live. Nothing is shipped to someone else's platform to be useful.
- **Everything is a record.** Agents, their skills, memory and context are all
  stored as ordinary [OpenRegister](https://openregister.conduction.nl) objects
  — which means every change is versioned and audited like any other data in
  your organisation.
- **It is governed by default.** Because every run and every tool call is
  written to an audit trail, and risky actions can be gated behind a human
  approval, an agent doing real work is something you can actually account for
  — including under the EU AI Act.

That is the whole idea: an agent you can hand real work to, that behaves like
part of your organisation rather than a service outside it.

## Where to go next

- New to all of this? Read **[Agents](./agents.md)** next — everything else
  hangs off it.
- Want the agent to know your material? **[Context](./context.md)** and
  **[RAG](./rag.md)**.
- Want the agent to *do* things? **[Tools & MCP](./tools-and-mcp.md)**.
- Responsible for governance? **[Approvals](../approvals.md)** and
  **[Incidents](../incidents.md)**.
