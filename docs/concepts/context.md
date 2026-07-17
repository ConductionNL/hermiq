---
sidebar_position: 5
description: What context is — the reference material you hand an agent for a job, including documents like a design.md.
---

# Context

**Context** is the reference material you hand an agent so it understands *your*
situation.

An agent arrives knowing how to write, reason and summarise — but it does not
know your project. It has never read your design document, does not know your
naming conventions, and has no idea who your stakeholders are. Context is the
project binder you put on its desk.

## Context vs skills

Both are text you give an agent, so the line matters:

- A **[skill](./skills.md)** is a *way of working* that travels. "How to
  summarise a meeting" helps any agent, anywhere.
- A **context** is *this situation's material*. Your `design.md`, your team's
  conventions, this quarter's targets.

If you would happily hand it to another organisation, it is a skill. If it only
makes sense inside your project, it is context.

## What you can put in a context bundle

A **context bundle** is a named collection with a budget. It can hold three
different kinds of material, and the difference is where the content lives:

| Kind | What it is | Use it when |
|---|---|---|
| **Documents** | Text you write or paste directly onto the bundle — a `design.md`, a persona brief, a standards doc. | The material *is* the context, and you want it versioned and self-contained. |
| **Files** | References to Nextcloud files, read at run time from your folder. | The document lives elsewhere and someone else maintains it. |
| **Object queries** | Live queries against your OpenRegister data. | The agent needs *current* records, not a snapshot. |

**Documents vs files** is a real choice. A document is written on the bundle
itself: self-contained, versioned like any other record, and it cannot drift or
break because someone moved a file. A file reference always reads the live
document — better when a team maintains that file as the source of truth, at the
cost of depending on it staying put.

Object queries are different in kind: they pull *live data*, so the agent sees
today's records rather than what was true when you wrote the bundle.

## The budget

Everything in a bundle is assembled into a single block of text and placed in
front of the agent before it starts work.

That block has a **character budget**. As with [memory](./memory.md), the space
an AI can read at once is finite, and context competes with everything else. If
an assembled bundle runs past its budget, it is flagged as needing
consolidation — a signal to tighten it up. Ten documents "just in case" crowd out
the one that mattered.

Keep bundles focused. A context bundle per project beats one enormous bundle
holding everything.

## Attaching context to an agent

A bundle is not attached to a job — it is attached to an **[agent](./agents.md)**,
and an agent can carry several. So a "permit team" agent might hold the permit
process design document, the house style, and a live query of open cases.

## A note on trust

Context is *material*, never instructions the agent must obey. It is data placed
in front of the model, and it is subject to the same guardrails as any other
input — an organisation's guardrail policy filters it exactly as it filters
anything else. A document cannot smuggle an instruction past your policy just by
being called context.

## Where to find it

**Contexts** in the Hermiq navigation lists your context bundles, with an editor
for documents, files and object queries.

## Related

- **[Memory](./memory.md)** — what the agent picks up itself, as opposed to what
  you hand it.
- **[RAG](./rag.md)** — looking material up on demand, rather than handing it
  over up front.
- **[Skills](./skills.md)** — the reusable counterpart.
