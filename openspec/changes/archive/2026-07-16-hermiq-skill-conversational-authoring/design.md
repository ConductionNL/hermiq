# Design: hermiq-skill-conversational-authoring

## Architecture Overview

Two reused seams, no new engine and no new schema.

```
(1) Seed  — install/upgrade
    appinfo/info.xml  <step>OCA\Hermiq\Repair\SeedSkillCreator</step>   ← NEW (pre + post)
        └─ SeedSkillCreator (IRepairStep)  ── ObjectService (system ctx) ──▶
             one `agentskill` Skill: name "skill-creator", state active, source local
             (real agentskills.io frontmatter + body teaching skill authoring)

(2) Author-by-chat — runtime
    User installs `skill-creator` onto ANY agent (existing installOnAgent), then chats:
      Chat.vue  ── existing ChatStreamController::stream() / agent engine ──▶ assistant SKILL.md
        └─ "Save as skill" action (assistant message)   ← NEW
              └─ opens SkillFormModal (from hermiq-skill-markdown-authoring) PRE-FILLED
                    body = message.content
                    save-target = review path
                      └─ SkillMarketplaceService::installFromSource(source:"local")
                           → Skill lands `quarantined` (+ ContentScanService verdict)
                           → Approve via existing skill-row-actions (action-gated) → active
```

The seed mirrors `lib/Repair/SeedAgentTemplates.php` (container-lazy `ObjectService`,
idempotent-by-name, `_rbac:false, _multitenancy:false`). The chat action attaches to the
existing assistant-message action row in `src/views/Chat.vue` (which already renders
feedback buttons on assistant bubbles). The modal is the one built in the prerequisite
change; this change only adds an alternate pre-filled open and a save-target.

### ADR-031 (declarative-vs-imperative) note

Nothing here adds declarative OR behaviour or state-machine semantics for an OR-owned object.

- The **seed** is a repair-step **data seed** — an imperative seeder, the established
  fleet pattern (`SeedAgentTemplates`, `SeedComplianceControls`, `SeedModelPolicies`). It
  writes one object through `ObjectService` and advances no lifecycle. ADR-016 (seed data)
  sanctions exactly this; ADR-031 does not apply because there is no net-new service class
  implementing `transitionTo`/`setStatus`/`advancePhase` for a Skill.
- The **chat→skill seam** reuses existing services end-to-end: the SKILL.md is produced by
  the existing `ChatStreamController`/agent engine (no new model call), and persistence is
  the existing `SkillMarketplaceService::installFromSource` quarantine gate + the existing
  action-gated `approve()`. The only new imperative code is a Vue action handler and a
  repair step — no new declarative dialect, no new aggregation/notification, no new
  lifecycle machine. State transitions (quarantined → active) remain owned by the existing
  marketplace review gate, unchanged.

## API Design

No new endpoint is introduced. Reused, unchanged:

- Assistant turn production: existing `POST /apps/hermiq/api/chat` stream
  (`ChatStreamController::stream()`).
- Save-as-skill (review path): existing `SkillMarketplaceController::installFromSource`
  (`POST` with `{ package, source }`), which already produces a `quarantined` skill and
  runs `ContentScanService`.

**One contained modification (not a new endpoint):** `installFromSource()` currently coerces
`source` to `org`/`hub` (`in_array($source, ['org','hub']) === false → 'hub'`). To carry
honest provenance for a chat-authored skill, relax that whitelist to also accept the
**already-valid** `local` enum value:

```
// before: if (in_array($source, ['org', 'hub'], true) === false) { $source = 'hub'; }
// after:  if (in_array($source, ['local', 'org', 'hub'], true) === false) { $source = 'hub'; }
```

This changes no schema (`source`'s enum is already `['local','org','hub']`) and no
quarantine behaviour — `installFromSource` lands `quarantined` regardless of source.

## Database Changes

None. No new table, column, or migration. `Skill` (slug `agentskill`) already declares every
field the seed and seam use: `name`, `description`, `frontmatter`, `body`, `state`, `source`,
`createdBy`, `quarantineReason`, `scanReport` (verified in
`lib/Settings/hermiq_register.json`; `required` is `["name"]`).

## Nextcloud Integration

- Controllers: none new. Reuses `ChatStreamController` and `SkillMarketplaceController`
  (the latter with the one-line source-whitelist relaxation above).
- Services: none new. Reuses `SkillMarketplaceService`, `SkillService`, agent engine,
  OpenRegister `ObjectService`/`ContentScanService`.
- Mappers/Entities: none — `Skill` is an OpenRegister object.
- Repair steps: `OCA\Hermiq\Repair\SeedSkillCreator` implements `OCP\Migration\IRepairStep`,
  registered in `appinfo/info.xml` under `<pre-migration>` and `<post-migration>` alongside
  `SeedAgentTemplates`.
- Events/Hooks: none.

## Security Considerations

- The seed writes in system context (`_rbac:false, _multitenancy:false`) exactly like the
  other `Seed*` steps — it creates one shared, inert catalog object; it never elevates a
  user request.
- The chat-seam save routes machine-generated content through the existing quarantine gate,
  so an agent cannot use a chat-authored skill until it is Approved. Approve is
  action-gated (`skill.approve-quarantined`, admin-seeded) in the existing
  `SkillMarketplaceController::approve()` — this change does not weaken that.
- `ContentScanService` scans the composed package on `installFromSource` (unchanged), so a
  `dangerous` verdict blocks one-click approval as today.
- No new endpoint, no new parameter beyond the already-valid `source: "local"`; the chat
  action uses the existing CSRF-token-bearing axios path.

## NL Design System

- The "Save as skill" action uses a standard NC action control (e.g. `NcButton`/
  `NcActionButton`) in the existing assistant-message action row, with an `aria-label` and a
  `t('hermiq', …)` label (English source key), matching the neighbouring feedback buttons.
- The pre-filled review UI is the prerequisite change's `SkillFormModal` (`NcModal` +
  `CnMarkdownEditor`), so no new visual surface is introduced.

## File Structure

```
lib/
  Repair/
    SeedSkillCreator.php        (NEW — IRepairStep, mirrors SeedAgentTemplates)
lib/
  Controller/
    SkillMarketplaceController.php  (MODIFIED — accept source: "local" in installFromSource)
src/
  views/
    Chat.vue                    (MODIFIED — "Save as skill" action on assistant messages)
  modals/
    SkillFormModal.vue          (MODIFIED — optional save-target so the chat seam lands on the review path)
appinfo/
  info.xml                      (MODIFIED — register SeedSkillCreator step, pre + post)
```

## Seed Data

Per ADR-016, this change seeds realistic, install-time data. It introduces **no new schema**
— it seeds ONE object of the existing `Skill` schema (slug `agentskill`), idempotently by
name, so the app is testable immediately: a fresh install already has a `skill-creator`
skill a user can install onto an agent and chat with.

### Schema: `agentskill`

| Field | skill-creator |
|-------|---------------|
| slug (@self) | register `hermiq`, schema `agentskill` |
| name | `skill-creator` |
| description | `Guides you through authoring a new agent skill in the agentskills.io format — interviews you about the capability, then drafts a clean SKILL.md (frontmatter + body) you can save to your catalog.` |
| state | `active` |
| source | `local` |
| createdBy | `` (empty — system seed, like SeedAgentTemplates) |
| installedOn | `[]` |
| files | `[]` |
| frontmatter | (raw YAML block, verbatim — see below) |
| body | (SKILL.md markdown — see below) |

Seeded `frontmatter` (stored verbatim; the `SkillSerializer` round-trip preserves it
byte-for-byte):

```yaml
name: skill-creator
description: Guides you through authoring a new agent skill in the agentskills.io format — interviews you about the capability, then drafts a clean SKILL.md you can save to your catalog.
version: 0.1.0
```

Seeded `body` (SKILL.md — a real, sensible skill-authoring instruction; safe placeholders
only, no shell/exfiltration example patterns so it never trips the content scanner):

```markdown
# Skill Creator

You help the user author a new **agent skill** in the agentskills.io format. A skill is a
Markdown document (SKILL.md) with a YAML frontmatter header (`name`, `description`, optional
`version`) followed by a body that tells an agent HOW to perform one capability well.

## How to work with the user

1. Ask what single capability the new skill should give an agent. Keep it to ONE clear job.
2. Ask for the trigger: when should an agent reach for this skill? Capture a one-line
   description — it becomes the frontmatter `description` and drives discovery.
3. Draft the body: a short title, a "How to work" section with numbered steps, any rules or
   guardrails ("never fabricate a figure"), and one worked example using safe placeholders
   (e.g. `YOUR_VALUE_HERE`) — never real secrets, tokens, or personal data.
4. Show the user the full SKILL.md (frontmatter fence + body) and ask them to confirm or
   refine. Iterate until they are happy.

## Output format

Emit the finished skill as a fenced agentskills.io package:

```
---
name: <kebab-case-name>
description: <one line — what the skill does and when to use it>
version: 0.1.0
---
# <Title>

<body: how-to steps, rules, one safe example>
```

## Rules

- One capability per skill. If the user describes two jobs, propose two skills.
- The `description` MUST be specific enough that an agent knows when to trigger the skill.
- Never include a real credential, token, or personal datum in the body — use placeholders.
- Keep the body focused and skimmable; an agent reads it as instructions, not prose.
```

**Related items per object:**
- Files: none (the `files` array stays empty; the seed is a single-document skill).
- Notes: none.
- Tasks: none.
- Contacts: none.

## Trade-offs

- **Land chat-authored skills `quarantined` (chosen, provisional) vs `active`.** Chat output
  is machine-generated, so routing it through the existing quarantine + Approve gate is the
  safer default and reuses the whole marketplace review flow with zero new logic. The
  alternative (land `active` via the prerequisite change's catalog path, treating the modal
  edit as the only review) is simpler but lets an unreviewed, model-drafted skill be
  installed onto an agent immediately. This is surfaced as a **deferred question** because
  the user's brief both says "reuses SkillService" (active) and "landing quarantined per
  skills-marketplace" — those two paths differ, and a human should confirm.
- **`source: "local"` (chosen) vs reusing `source: "org"`/`"hub"`.** `local` is the honest
  provenance for a skill authored inside this instance and is already a valid `source` enum
  value; it costs one line relaxing the controller whitelist and no schema change. Reusing
  `hub`/`org` would mislabel provenance.
- **Seed a `skill-creator` agentskills.io Skill (chosen) vs a bespoke built-in "skill
  creator" agent.** A seeded Skill is portable, installable onto any agent the user already
  trusts, exportable, and consistent with ADR-003 (skills as OR objects). A bespoke agent
  would be a second, special-cased engine surface — more code, less reuse.
- **Seed direct via `ObjectService` (chosen) vs via `installFromSource`.** The seed is
  first-party trusted content authored by us; it should land `active` immediately and must
  not be scanned/quarantined, so it writes directly like `SeedAgentTemplates` rather than
  going through the external-install quarantine gate.
