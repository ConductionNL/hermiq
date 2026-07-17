---
sidebar_position: 3
description: What a skill is — a reusable, shareable instruction sheet that teaches an agent a way of working.
---

# Skills

A **skill** is a reusable instruction sheet that teaches an agent *how to do
something*.

If the agent's prompt is its job description, a skill is a how-to guide in its
desk drawer: "this is how we write a permit decision", "this is how we triage an
inbox". Write it once, install it on any agent that needs it, and share it with
other teams — or with the world.

## Why not just put it in the prompt?

You could. But a prompt belongs to *one* agent, and it gets unwieldy fast. A
skill is separate because it is:

- **Reusable** — install the same skill on five agents.
- **Shareable** — publish it to GitHub, or install one someone else wrote.
- **Reviewable** — it has a lifecycle, so a skill from outside gets checked
  before an agent is allowed to use it.
- **Improvable** — refine the skill and every agent using it improves at once.

Rule of thumb: if it describes *how to do a task well*, it is a skill. If it
describes *who this agent is*, it belongs in the prompt.

## What a skill looks like

Skills use the open [agentskills.io](https://agentskills.io) format, so they are
not locked to Hermiq. A skill is a Markdown document — a `SKILL.md` — with two
parts:

- **Frontmatter** — a short YAML block at the top: the skill's name, a
  description, and metadata.
- **Body** — the actual instructions, in plain Markdown.

A skill can also carry **auxiliary files** — extra documents alongside the main
one, for templates or examples.

Because it is just Markdown, you can read a skill before you trust it. There is
no hidden code.

## Writing a skill

Hermiq gives you two ways, and they suit different people:

**Write or paste it directly.** Open **Skills → Add Skill** and write the
`SKILL.md` in the built-in Markdown editor, or paste a whole agentskills.io
package and let Hermiq split it into frontmatter and body. Good when you already
know what you want to say.

**Talk it through with an agent.** Hermiq ships a **skill-creator** skill.
Install it on any agent, then simply chat: describe the task, and the agent
interviews you and drafts the skill. When you are happy, **Save as skill** on its
reply opens the editor pre-filled, so you review and adjust before saving. Good
when you know the job but not how to write it down.

Skills created from a chat land **quarantined** — see below.

## The review gate

A skill is instructions an AI will follow, so where it came from matters.

- Skills you write yourself are **active** immediately.
- Skills that come from somewhere else — installed from GitHub, or produced by
  an agent in a conversation — land **quarantined**. An agent will not use them
  until a person clicks **Approve**.

This is deliberate. A quarantined skill is one nobody has vouched for yet, and
"an AI wrote it" is not vouching. The review is a human reading the Markdown and
deciding it says what it should.

## Sharing skills

Skills travel through the **Store**:

- **Find** — browse skills published on GitHub and install them (quarantined,
  pending your review).
- **Publish** — push a skill you wrote to a GitHub repository so other teams can
  install it.

Because the format is the open agentskills.io standard, a skill you write here
is not trapped here.

## Where to find it

**Skills** in the Hermiq navigation lists every skill, with actions to export,
publish, approve and install onto an agent. **Store** is where you find skills
published by others.

## Related

- **[Agents](./agents.md)** — skills are installed onto an agent.
- **[Context](./context.md)** — the other kind of text you give an agent, and
  the one skills get confused with.
- **[Concepts overview](./index.md)** — the skill-vs-context distinction, in one
  rule of thumb.
