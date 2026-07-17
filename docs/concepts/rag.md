---
sidebar_position: 7
description: What RAG (Retrieval-Augmented Generation) is — letting an agent look things up in your files and records before answering.
---

# RAG

**RAG** stands for **Retrieval-Augmented Generation**. Behind the acronym is a
simple idea: **look it up before you answer.**

## The problem it solves

An AI model knows what it was trained on — a huge amount of general knowledge,
frozen at some point in the past. It knows nothing about your organisation. It
has never seen your case files, your policies, or last week's decision.

Ask it something specific to you and it does the worst possible thing: it answers
confidently and wrongly. It has no way to know it does not know.

RAG fixes this by changing the order of operations. Instead of *ask → answer*, it
becomes:

1. **Retrieve** — search your files and records for material relevant to the
   question.
2. **Augment** — put what it found in front of the model along with the question.
3. **Generate** — answer *from that material*.

The answer is grounded in your actual documents rather than the model's
recollection. Which is exactly what you would want from a colleague: check the
file, then speak.

## RAG vs context vs memory

All three put material in front of the model. The difference is *when* and *who
chooses*:

| | Who chooses | When |
|---|---|---|
| **[Context](./context.md)** | You, in advance | Attached to the agent, always present |
| **RAG** | The system, per question | Looked up fresh, based on what was asked |
| **[Memory](./memory.md)** | The agent, from experience | Accumulated over time |

Context is the binder you hand over. RAG is the filing cabinet they walk to.
Memory is what they picked up along the way.

Use **context** when the agent *always* needs the material. Use **RAG** when the
relevant material depends on the question and there is far too much to hand over
up front.

## Configuring RAG on an agent

RAG is off by default, and enabling it on an [agent](./agents.md) gives you a few
choices:

| Setting | What it decides |
|---|---|
| **Enable RAG** | Whether the agent looks things up at all. |
| **Search mode** | *Keyword* matches words literally; *semantic* matches meaning; *hybrid* does both. |
| **Number of sources** | How many results to pull in. More is not better — each one spends budget. |
| **Include files** | Search your Nextcloud files. |
| **Include objects** | Search your OpenRegister records. |

**Search mode** is the one worth understanding. Keyword search finds "permit" when
you write "permit". Semantic search finds a document about permits when you ask
about "planning applications", because it matches on meaning. Hybrid does both,
which is usually the right default when you are unsure.

**Number of sources** is a budget decision, not a quality dial. Every retrieved
source competes for the same finite space as the prompt, skills, context and
memory. Ten mediocre sources can crowd out the one good one.

## What it can see

RAG searches as the person the run is acting for. It cannot surface a file or a
record that person could not open themselves — your existing permissions apply
unchanged. Retrieval does not become a way around access control.

## Related

- **[Context](./context.md)** — material handed over in advance.
- **[Memory](./memory.md)** — material the agent accumulated itself.
- **[Tools & MCP](./tools-and-mcp.md)** — the other way an agent reaches your
  data, by taking action rather than searching.
