---
sidebar_position: 8
description: The skills catalog — agentskills.io-format skills you can browse, author, install onto agents, and share through the marketplace.
keywords:
  - Hermiq
  - Skills
  - agentskills.io
  - Catalog
  - Marketplace
---

# Skills

A **skill** is a reusable package of instructions an agent loads into its
context: a markdown body (SKILL.md), YAML frontmatter (`name`, `description`),
and optional auxiliary files (references, examples). Hermiq stores skills as
OpenRegister objects in the **agentskills.io** format, so a skill authored in
Hermiq can be exported, published, and consumed anywhere that format is spoken
— and skills from external hubs can be imported without conversion.

Find them under the **Skills** item in the Hermiq navigation (`/skills`). Each
skill has a detail page (`/skills/:id`) with its content, maturity scorecard,
eval evidence, and learnings — see [Skill maturity levels](./skill-levels.md).

## The agentskills.io format

Every skill carries:

| Part | What it is |
|---|---|
| **Frontmatter** | YAML with at least `name` and `description`. The description doubles as the *trigger* — it tells an agent when to reach for the skill. |
| **Body** | The SKILL.md content: the actual instructions, procedures, and rules. |
| **Files** | Auxiliary entries such as `references/*` and `examples/*`, plus (once a skill starts learning) `learnings.md` and `learning-candidates.md`. |

Import and export are **byte-for-byte**: exporting a skill and re-importing
the package reproduces the original frontmatter and body exactly. Hermiq-only
metadata (maturity level, evidence, GitHub provenance) never enters the
exported package.

## Installing skills onto agents

Browse the catalog and install any `active` skill onto one of your agents.
From the skill's next run onward, its content is injected into the agent's run
context. Detaching is the symmetric undo — the agent's next run no longer sees
the skill.

Only skills in `active` state are ever exposed to an agent. Quarantined,
stale, and archived skills are never injected, so the review gate below cannot
be bypassed by a stale install reference.

## Authoring a skill

There are two ways to write a skill:

- **The authoring form.** The catalog's Add/Edit action opens a dedicated
  markdown form: a markdown editor for the body, plain fields for name,
  description, and frontmatter, and a files editor for auxiliary entries
  (add, rename, edit, remove). You can also paste a complete agentskills.io
  package — the leading `---` fenced frontmatter is split from the body
  automatically. Skills you author here are yours (`createdBy`), start
  `active`, and persist through the ordinary catalog path.
- **Conversationally, from chat.** Hermiq seeds a `skill-creator` skill that
  teaches an agent to interview you and emit a well-formed agentskills.io
  package. Every assistant message in chat offers **Save as skill**: it opens
  the same authoring form pre-filled with the message content so you review
  and edit before saving. Chat-authored skills deliberately land
  **quarantined** — an agent cannot use them until a reviewer approves them.

## Lifecycle: active → stale → archived (and quarantine)

A background **Curator** job manages skill freshness. Skills unused past the
staleness threshold move `active → stale`, and eventually to `archived`.
Nothing is ever hard-deleted, so historical agent configurations remain
reconstructable. Lifecycle state is independent of a skill's maturity level —
see [maturity vs lifecycle](./skill-levels.md#maturity-vs-lifecycle-state).

**Quarantine** is the security gate for inbound content. Any skill installed
from another organisation, from an external hub, or saved from chat lands in
`quarantined` state and is content-scanned before it can become `active`:

- Approving a quarantined skill requires the `skill.approve-quarantined`
  action (granted per group in Settings).
- Forcing a skill past a **dangerous** scan verdict requires the stricter
  `skill.override-scan-verdict` action on top — approval rights alone are not
  enough.

## Sharing: marketplace and GitHub publishing

The primary publishing path is **GitHub**: publish a skill to a new repository
tagged `topic:hermiq-skill`, committed in agentskills.io format. The skill is
stamped with its provenance (`githubOwner`/`githubRepo`/`publishedAt`).
Publishing requires the publish action authorization, never holds or logs your
GitHub token (broker-mediated, fail-closed), and refuses to overwrite an
existing repository — with one carve-out: **republishing** an updated skill to
its *own* provenance repo is allowed as an explicit, user-triggered update
(see [self-improvement](./skill-learnings-and-self-improvement.md#republish-the-published-copy-is-behind-signal)).

Two file-selection rules apply on every publish and republish:

- `learnings.md` (vetted, promoted experience) **ships** with the package.
- `learning-candidates.md` (unvetted raw observations) is **always stripped**
  — unreviewed content never leaves your instance.

An OpenConnector-based hub route (ClawHub, skills.sh) exists as the secondary
publish path, and installing *from* a hub goes through the quarantine gate
described above.

## Related pages

- [Skill maturity levels](./skill-levels.md) — the L1–L7 ladder and the
  Qualify scorecard.
- [Skill evals](./skill-evals.md) — proving a skill helps with paired
  with/without runs.
- [Learnings and self-improvement](./skill-learnings-and-self-improvement.md)
  — how skills accumulate experience and propose their own improvements.
