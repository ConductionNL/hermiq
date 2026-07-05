# Skills Marketplace Specification

**Status**: active (Hermiq surface live-verified; content-scan + a live hub are OpenRegister/OpenConnector seams)

**Feature tier**: V2

**OpenSpec changes:** `skills-marketplace` — DONE: `Skill` schema gains `quarantined` state + `source`/`quarantineReason`/`lastActivityAt`/`staleSince`/`archivedAt`; `SkillMarketplaceService` (installFromSource → quarantine; approveQuarantined review gate → active; age-based `curate()` active→stale→archived that NEVER hard-deletes; publishToHub via SkillSerializer + OpenConnector CallService, structured error when unavailable); `SkillCuratorTask` (daily TimedJob); `SkillMarketplaceController` + routes; Skills UI gains a quarantine badge + Approve + Publish + "Install from hub (quarantine)". The content **security scan** (OR has no scanner — SecurityService is auth rate-limiting) is a documented seam realised as the review gate; a live external **hub** needs an OpenConnector connector; usage-based staleness needs OR run-loop last-used stamping.

## Purpose

Extends the skills catalog (V1) to let organisations share skills across their own tenants and
publish to/consume from external hubs (ClawHub, skills.sh) in the agentskills.io format. Every
inbound skill passes through quarantine and a security scan before activation, and a background
Curator job manages the skill lifecycle without ever hard-deleting a skill.

## Requirements

### Requirement: Quarantine + security scan on install
The system MUST place any skill installed from another organisation or an external hub into a
quarantine state and MUST run OR's `SecurityService` scan on it before the skill can transition to
`active`.

#### Scenario: An org installs a skill published by another tenant
- GIVEN organisation B publishes a skill to the shared marketplace
- WHEN organisation A installs that skill
- THEN the system MUST place the installed skill in a quarantine state
- AND the skill MUST NOT become usable by an agent until OR's `SecurityService` scan completes and passes

### Requirement: Curator manages lifecycle without hard-delete
The system MUST run a background Curator job that transitions skills through active → stale →
archived based on usage/age, and MUST NOT hard-delete a skill at any point in that lifecycle.

#### Scenario: A skill goes unused past the staleness threshold
- GIVEN a `Skill` object has not been used by any agent run for longer than the configured staleness threshold
- WHEN the Curator background job runs
- THEN the system MUST transition the skill's state from `active` to `stale`
- AND the system MUST NOT delete the skill or its underlying object/files

### Requirement: Publish to and consume from external hubs
The system MUST support publishing a locally-authored skill to an external hub (ClawHub, skills.sh)
and importing a skill from those hubs, both in agentskills.io format, reusing the `SkillSerializer`
from the skills-catalog spec.

#### Scenario: A user publishes a local skill to an external hub
- GIVEN a locally-authored `Skill` object in `active` state
- WHEN the user chooses to publish it to an external hub
- THEN the system MUST serialize it to agentskills.io format via `SkillSerializer`
- AND the system MUST submit it to the selected hub's publish endpoint

## User Stories

- As an org admin, I want to share skills across tenants within my organisation so that teams don't duplicate work.
- As a skill author, I want to publish my skill to ClawHub or skills.sh so that the wider community can use it.
- As a security reviewer, I want every externally-sourced skill quarantined and scanned before it can run so that malicious skills can't execute unchecked.
- As a platform operator, I want stale skills archived rather than deleted so that historical agent configurations remain reconstructable.

## Acceptance Criteria

- [ ] Skills installed from another org or external hub start in a quarantine state
- [ ] OR `SecurityService` scan must pass before a quarantined skill can become `active`
- [ ] Curator background job transitions active→stale→archived on a schedule
- [ ] No code path hard-deletes a `Skill` object
- [ ] Publish/import to at least one external hub (ClawHub or skills.sh) works via `SkillSerializer`

## Notes

Depends on `skills-catalog` (V1) for the base `Skill`/`SkillSource` schemas and `SkillSerializer`, and
on OpenRegister's `SecurityService`. Related: ADR-003 (memory & skills as OR objects). External hub
API contracts (ClawHub, skills.sh) are unconfirmed and need research before moving to `planned`.
