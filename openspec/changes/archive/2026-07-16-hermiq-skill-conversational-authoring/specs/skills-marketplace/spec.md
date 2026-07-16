# Skills Marketplace Specification (delta: hermiq-skill-conversational-authoring)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-skill-conversational-authoring

## Purpose

Extends the quarantine gate so a locally-authored, machine-generated skill (produced via the
chat "Save as skill" seam) can be installed through the SAME review path used for external
skills — landing `quarantined` and content-scanned before it can become usable. This is a
provenance-and-routing extension only; it reuses the existing `installFromSource` quarantine
+ `ContentScanService` + action-gated Approve, adds no new endpoint, and changes no schema.
Related: skills-catalog delta (the chat seam), ADR-023 (action-gated approve).

## ADDED Requirements

### Requirement: A locally-authored skill can be installed through the quarantine gate

The system MUST accept `source: "local"` on the install-from-source quarantine path
(`SkillMarketplaceController::installFromSource` → `SkillMarketplaceService::installFromSource`)
so a skill authored inside this instance (e.g. via the chat "Save as skill" seam) is landed
`quarantined`, content-scanned via OpenRegister `ContentScanService`, and recorded with honest
`source` `local` provenance. `local` is already a valid value of the `Skill` schema's `source`
enum (`["local","org","hub"]`), so this MUST require no schema change — only the controller's
existing `org`/`hub` source whitelist is relaxed to also accept `local`. The quarantine
invariant MUST hold unchanged: a skill installed through this path MUST NOT be `active`, and
MUST require the existing action-gated Approve (`skill.approve-quarantined`) before an agent
can use it.

#### Scenario: A chat-authored skill lands quarantined with local provenance

- GIVEN a user has reviewed a chat-produced SKILL.md in the authoring modal
- WHEN the seam installs it through `installFromSource` with `source: "local"`
- THEN the resulting `Skill` MUST have `state` `quarantined` and `source` `local`
- AND `ContentScanService` MUST have run over its body + frontmatter, recording a `scanReport`
- AND the skill MUST NOT be usable by an agent until it is Approved

#### Scenario: An unknown source value still defaults safely

- GIVEN a caller passes a `source` value that is not one of `local`, `org`, or `hub`
- WHEN `installFromSource` runs
- THEN the source MUST default to `hub` (the existing safe fallback), and the skill MUST
  still land `quarantined`

## Non-Functional Requirements

- **Performance:** The change is a whitelist relaxation plus the existing scan; it adds no
  new per-install work beyond what external installs already incur.
- **Accessibility:** No new UI surface (the review UI is the existing skill-row-actions
  Approve and the authoring modal).
- **Internationalization:** No new user-facing strings beyond those already covered by the
  skills-catalog delta; Dutch and English MUST be supported (ADR-005).

## Acceptance Criteria

- [ ] `installFromSource` accepts `source: "local"` and lands the skill `quarantined` with `source` `local`
- [ ] `ContentScanService` runs and a `scanReport` is recorded, exactly as for `org`/`hub` installs
- [ ] The quarantine invariant holds: the skill is not `active` until Approved (action-gated)
- [ ] No schema change is made (`local` is already in the `source` enum)

## Notes

- Provisional design decision (see the change's design.md and proposal Open Questions):
  chat-authored skills land `quarantined`. The alternative — landing them `active` via the
  prerequisite change's catalog path — is a surfaced deferred question for the user to
  confirm. If the user chooses `active`, this delta is dropped and the seam saves via the
  skills-catalog active path instead.
