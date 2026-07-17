---
sidebar_position: 4
description: What agent memory is — what an agent remembers between conversations, and why it has a budget.
---

# Memory

**Memory** is what an agent remembers *between* conversations.

Without it, every conversation starts from zero. You explain your team, your
preferences and your project on Monday, and on Tuesday the agent has never met
you. Memory is what turns a capable stranger into a colleague who knows how you
work.

## Memory vs context

These get confused constantly, and the difference is simple:

- **[Context](./context.md)** is what *you* hand the agent, on purpose. "Here is
  the design document."
- **Memory** is what the *agent* picked up by working with you, without being
  told to.

You curate context. Memory accumulates.

## What an agent remembers

Hermiq keeps two kinds:

**Durable facts** — things worth knowing long-term. "This team publishes on
Thursdays." "Permits over €50k always go to the committee."

**What it knows about you** — a per-person profile, so an agent working with
several colleagues does not mix you up. Your preferences are yours; your
colleague's are theirs.

Alongside these, every conversation is kept as a **session** with its individual
turns, so an agent can look back at what was actually said.

## Your sessions are yours

An agent may be shared with your whole organisation, but the conversations are
not. You only ever see **your own** sessions, and when an agent recalls past
conversations during a run, it only ever recalls the history of the person the
run is acting for.

This is enforced in the engine, not just hidden in the interface.

## The budget, and why memory gets consolidated

An AI model can only read so much at once. Everything competes for that space:
the prompt, the skills, the context, the memory, the conversation itself. Memory
grows forever; the space does not.

So memory has a **character budget**. When it grows past it, the agent is nudged
to **consolidate** — to compress what it knows into a tighter form, keeping the
durable facts and dropping the noise. This is roughly what you do yourself: you
remember that a colleague prefers short emails, not each individual email that
taught you so.

Without consolidation, a long-running agent would eventually spend its whole
budget remembering and have no room left to think.

## Where to find it

**Memory** in the Hermiq navigation shows what an agent has remembered, with the
option to consolidate it. Individual conversations live under **Sessions**.

## Related

- **[Context](./context.md)** — what you hand the agent deliberately.
- **[Agents](./agents.md)** — memory belongs to an agent.
- **[RAG](./rag.md)** — looking things up, as opposed to remembering them.
