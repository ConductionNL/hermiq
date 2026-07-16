# ADR-024: Agent context concepts — how design.md and document-shaped context reach an agent

**Status:** accepted
**Date:** 2026-07-16

## Context

Hermiq already assembles per-agent context at run start. Today a `Context`
OpenRegister object bundles three source kinds and is attached to an agent via
`Agent.contextRefs`:

- **`files`** — Nextcloud files (a `{path, description}` per entry) read from the
  acting user's folder.
- **`objectQueries`** — OpenRegister queries (`{register, schema, …}`) run through
  `ObjectService` to pull live data.
- **`viewRefs`** — view UUIDs to narrow which data is included (resolution
  currently deferred; collected + logged only).

`ContextAssembler` (`lib/Service/Engine/ContextAssembler.php`) resolves every
referenced `Context` into a single **budgeted text preamble** (`charBudget`,
`needsConsolidation` nudge — mirroring `MemoryService`) prepended to the system
prompt at run start. A bad reference is non-fatal (one broken file must not blank
the preamble).

Separately, this session added **skill authoring**: skills are agentskills.io
`SKILL.md` documents (frontmatter + markdown body + auxiliary files) installed
onto an agent and available during a turn.

The open question (this ADR): **how should "new context concepts" — a project
`design.md`, a coding-standards doc, an API contract, a persona brief, other
document-shaped material — be modeled and delivered to an agent?** They are
document-shaped and mostly static; distinct from *data* (`objectQueries`) and
from *capabilities* (skills). Right now the only home for a `design.md` is a
`files` entry pointing at a Nextcloud file — which works but conflates "a file
the user happens to store in NC" with "a curated, versioned piece of context
that is part of the agent's definition", and gives no first-class handle for
authoring, versioning, or sharing that context.

## Decision

### Rule 1 — Three distinct concepts, one assembly seam

Keep a clean separation of *what feeds a run*, because each has a different
lifecycle, authoring surface, and trust posture:

| Concept | Object | Shape | Purpose | Authoring |
|---|---|---|---|---|
| **Skill** | `Skill` (`agentskill`) | SKILL.md (frontmatter + body + files) | A reusable *capability / instruction* the agent can apply anywhere | Skill authoring modal + conversational skill-creator (this session) |
| **Context** | `Context` (`context`) | files + objectQueries + viewRefs + **documents** (new) | *Situation/project-specific reference material* for this agent | Context editor (proposed below) |
| **Memory** | `Memory`/`UserProfile` | consolidated entries | *Learned state* accumulated across runs | Written by the run loop |

All three continue to converge in **one** budgeted preamble assembled at run
start, in a fixed layering order (highest-priority last, closest to the user
turn): `system prompt → context preamble → installed skills → memory recall →
conversation`. `ContextAssembler` stays the single seam; no second assembly path.

### Rule 2 — `design.md`-style context is a first-class Context *document* source, not a bare file ref

Add a fourth source kind to the `Context` schema — **`documents`**: an array of
`{ name, body, format, description }` where `body` is inline Markdown authored/
pasted directly on the Context object (default `format: "markdown"`).

Rationale:
- A `design.md` is *content*, not *a pointer to a user's file*. Storing it inline
  makes the Context object self-contained, versionable (OpenRegister
  AuditTrail/versioning, ADR-004), and shareable without depending on a
  particular user's Files tree.
- It reuses the exact markdown-authoring surface built this session
  (`CnMarkdownEditor` + the SKILL.md editor pattern) — a Context document is
  edited the same way a skill body is.
- `files` remains for genuine "include this NC file" cases (a spreadsheet, a PDF,
  a doc the user maintains elsewhere). `documents` is for curated context that is
  part of the agent's definition.

`ContextAssembler` renders each `documents[]` entry into the preamble under a
titled section (its `name`), inside the same `charBudget` accounting as `files`
and `objectQueries` — no new budget contract.

### Rule 3 — No new trust surface; context is untrusted-by-default input

Context documents are **data prepended to a prompt**, never executable and never
a way to escalate. They inherit the run's identity (acting user, ADR-023) and
carry no authorization of their own. Guardrail input filters (the guardrail
policy this session made configurable) apply to the assembled preamble exactly
as they do to any other model input — a `design.md` cannot smuggle a
prompt-injection past the org's guardrail policy.

### Rule 4 — Reuse, don't reinvent, for authoring + sharing

- **Authoring**: a Context editor mirrors the SkillFormModal — name/description +
  a `documents` list (markdown editor per entry) + the existing files/objectQueries
  pickers. No bespoke editor.
- **Sharing**: a Context bundle *may later* travel through the same GitHub store
  seam as skills/templates (`topic:hermiq-context`), reusing the kind-parameterised
  catalog/push services — but that is a follow-on, out of this ADR's scope.
- **Conversational authoring**: the skill-creator pattern generalises to a
  future `context-creator`; not decided here.

## Consequences

### Positive
- A `design.md` (and friends) gets a first-class, versioned, self-contained home
  distinct from both skills and raw NC files, with a familiar authoring surface.
- One assembly seam and one budget contract are preserved — no second context path.
- The concept table gives contributors a clear rule for "is this a skill, context,
  or memory?" — reducing the orphaned-capability risk of overlapping features.

### Negative
- Adds a `documents` field to the `Context` schema (a version-gated register
  re-import) and a Context editor surface — real build work.
- Inline `documents` can drift from a source-of-truth `design.md` the team edits
  elsewhere; teams that want a live file should use `files`, not `documents`.

### Neutral
- `viewRefs` resolution stays deferred (unchanged by this ADR).
- The GitHub-shareable-context and conversational context-creator ideas are
  explicitly parked as follow-ons.

## Implementation note

This ADR is the *decision*. If accepted, the build is a natural config→code chain
mirroring the skill-authoring work: (1) `Context` schema gains `documents`
[config]; (2) `ContextAssembler` renders documents + a Context editor modal + wire
`contextRefs` management on the agent form [code]. No code is written until this
ADR is accepted.
