---
kind: code
depends_on:
  - hermiq-skill-markdown-authoring
---

# Proposal: hermiq-skill-conversational-authoring

## Summary

Let a user author a skill *by chatting*, then turn the chat's output into a reviewable
Skill. Two parts, both reusing existing machinery: (1) a new repair-step seeder
(`SeedSkillCreator`) that idempotently seeds one `skill-creator` agentskills.io Skill — a
SKILL.md whose body teaches an agent how to guide skill authoring — so any user can install
it onto an agent and chat their way to a SKILL.md; and (2) a "Save as skill" seam on the
chat surface: an action on an assistant message that takes the produced SKILL.md content and
opens the `SkillFormModal` (built in the prerequisite change `hermiq-skill-markdown-authoring`)
PRE-FILLED for review/edit, then saves. There is **no new LLM path** — the agent run that
produces the SKILL.md is the existing `ChatStream`/agent engine, and persistence is the
existing skill services. `skill-creator` is a seeded agentskills.io Skill, not a bespoke
built-in agent.

## Motivation

The prerequisite change makes *direct* markdown authoring good. But the most natural way for
a non-expert to build a skill is to describe what they want and have an agent draft the
SKILL.md conversationally. Hermiq already has everything needed to do this without inventing
anything: agents run through the existing engine, skills are installable onto agents
(`installedOn`), the chat surface streams agent turns, and the catalog can persist a Skill.
What is missing is (a) a skill that actually teaches an agent to guide skill authoring, and
(b) a one-click bridge from "the agent produced a SKILL.md in the chat" to "that SKILL.md is
now a Skill in my catalog, ready for review". This change adds exactly those two seams and
nothing more.

## Affected Projects

- [ ] Project: `hermiq` — new `lib/Repair/SeedSkillCreator.php` + its `<step>` registration
  in `appinfo/info.xml`; a "Save as skill" action on assistant messages in
  `src/views/Chat.vue` that opens the (prerequisite change's) `SkillFormModal` pre-filled;
  a small save-target extension on `SkillFormModal` so the chat seam can land its output on
  the review path. No new schema field.

## Scope

### In Scope

- `lib/Repair/SeedSkillCreator.php`: an `IRepairStep` that idempotently (matched by name)
  seeds ONE `skill-creator` Skill object via OpenRegister `ObjectService` in system context
  (`_rbac: false, _multitenancy: false`), exactly like `SeedAgentTemplates`. The seeded
  Skill carries real agentskills.io `frontmatter` + `body` teaching skill authoring, with
  `state: "active"`, `source: "local"`, `createdBy: ""`.
- Register the new step in `appinfo/info.xml` under both `<pre-migration>`/`<post-migration>`
  (mirroring how `SeedAgentTemplates` is listed).
- A "Save as skill" action on each assistant message in `src/views/Chat.vue` that opens
  `SkillFormModal` pre-filled with the message's SKILL.md content as the `body` (name /
  frontmatter left for the user to complete or already present in the content), for
  review/edit.
- A minimal save-target on `SkillFormModal` so the chat-seam save lands on the review path
  (quarantine → Approve), reusing the existing `SkillMarketplaceService::installFromSource`
  quarantine gate; the catalog-authoring entry point keeps its existing default.

### Out of Scope

- No new LLM/agent path — the SKILL.md is produced by the existing `ChatStream`/agent
  engine running the `skill-creator` skill; this change adds no model call.
- No new schema or schema field — `Skill` already has `frontmatter`, `body`, `state`,
  `source`, `createdBy`, `quarantineReason`, `scanReport` (verified).
- No bespoke built-in "skill creator agent" — `skill-creator` is a seeded agentskills.io
  Skill a user installs onto any agent of their choice.
- No change to the direct-authoring modal's markdown editing built in the prerequisite
  change — this change only adds an alternate open (pre-filled) and save-target to it.
- No new content scanner — reuses OpenRegister's existing `ContentScanService` via
  `installFromSource` unchanged.

## Approach

Two reuses. (1) Seed: copy the `SeedAgentTemplates` shape — a container-lazy `ObjectService`,
idempotent-by-name guard, system-context write — but seed a single `agentskill` object whose
`frontmatter`/`body` is a real SKILL.md that instructs an agent to interview the user and
emit a well-formed agentskills.io package. (2) Seam: the chat view already renders assistant
messages with a small action row (feedback buttons). Add a "Save as skill" action there;
clicking it opens `SkillFormModal` with the message content as the initial `body`. Because
chat output is machine-generated, the seam saves through the existing quarantine gate
(`SkillMarketplaceService::installFromSource`) so the skill lands `quarantined` and must be
Approved (existing `skill-row-actions` Approve, action-gated) before an agent can use it. The
human's edit in the modal is the first review; the Approve gate is the second. Details, and
the active-vs-quarantine decision, in design.md.

## New Dependencies

None. Reuses the existing repair-step framework, `SeedAgentTemplates` pattern,
`SkillFormModal` (prerequisite change), `ChatStream`/engine, `SkillMarketplaceService`, and
OpenRegister `ObjectService`/`ContentScanService`.

## Impact

- Backend: one new repair step + two `info.xml` lines. If the chat-seam save routes through
  `installFromSource` with `source: "local"`, a one-line relaxation of that controller's
  `org`/`hub` source whitelist to also accept the already-valid `local` enum value (no schema
  change — `source`'s enum already lists `local`).
- Frontend: a "Save as skill" action added to assistant messages in `src/views/Chat.vue`,
  and a small save-target prop on `SkillFormModal` (from the prerequisite change).
- Data: on install/upgrade, one new `skill-creator` Skill object appears in the catalog
  (idempotent — never duplicated, never overwrites an admin edit).

## Cross-Project Dependencies

Depends on the prerequisite change `hermiq-skill-markdown-authoring` for `SkillFormModal`
(the pre-fillable authoring modal). Consumes the same already-pinned `@conduction/nextcloud-vue`
(no version bump). Reuses OpenRegister `ObjectService`/`ContentScanService` at runtime — no
new cross-project API.

## Risks

### Risk 1: The chat-authored skill's landing state (active vs quarantined) is a product decision

**Severity:** Medium — **Mitigation:** The prerequisite change's catalog save lands
skills `active`; the existing quarantine gate (`installFromSource`) is designed for
`org`/`hub` sources and its controller currently coerces `source` to those two. The
provisional decision here is to land chat-authored skills `quarantined` with `source:
"local"` (an already-valid enum value), relaxing that one whitelist so LLM-generated content
is Approved before use. This is called out as a deferred question for the user to confirm;
either resolution is a small, contained edit.

### Risk 2: A seeded SKILL.md that itself contains skill-authoring guidance could trip the content scanner

**Severity:** Low — **Mitigation:** The seed is written through `SeedSkillCreator` directly
as an `active`/`local` object and never passes through `installFromSource`, so it is not
scanned. The chat-seam path (which does scan) operates on user-produced content, not the
seed. Keep the seeded body free of shell/exfiltration example patterns.

### Risk 3: The seed step ordering must not assume OpenRegister is already installed

**Severity:** Low — **Mitigation:** Mirror `SeedAgentTemplates` exactly — resolve
`ObjectService` lazily from the container and warn-and-return if OpenRegister is absent, so
the repair step is safe on a fresh install.

## Rollback Strategy

Remove the `<step>OCA\Hermiq\Repair\SeedSkillCreator</step>` lines from `appinfo/info.xml`
and delete `lib/Repair/SeedSkillCreator.php`; revert the "Save as skill" action in
`src/views/Chat.vue` and the save-target prop on `SkillFormModal`; if the source-whitelist
relaxation was applied, revert it. The already-seeded `skill-creator` Skill object can be
left in place (it is a valid, inert catalog entry) or deleted via the catalog. No data
migration is involved; rollback is a code revert with at most one inert seeded object left
behind.

## Open Questions

- Should chat-authored skills land `quarantined` (review gate + Approve, the provisional
  decision) or `active` (the prerequisite change's catalog default, with the modal edit as
  the only review)? See Risk 1 — surfaced as a deferred question.
