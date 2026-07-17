# Agent Management UI Specification

**Status**: in-progress
**Scope**: hermiq
**Standards**: WCAG 2.1 AA
**OpenSpec changes**:
- `default-companion-agent` — the agent detail page gains a declarative "Make my default" header
  action; agents rendered without an icon fall back to the Conduction AI hexagon avatar
  (kind: code)

## Purpose

Delta for `openspec/specs/agent-management-ui/spec.md`. Adds the agent-detail surface for setting
a per-user default agent, and establishes a single default avatar for agents rendered without an
icon. `AgentFormModal` already documents that clearing an agent's icon "clears back to the default
agent icon", but no single default is applied across surfaces; this delta names it.

## ADDED Requirements

### Requirement: The agent detail page offers a "Make my default" action
The system MUST render a "Make my default" action on the agent detail page (`AgentDetail`, route
`/agents/:id`) that sets the displayed agent as the calling user's default companion agent. The
action MUST be declared in the manifest page's `config.headerActions` as an `api-call` action —
the system MUST NOT introduce a bespoke Vue component, page, or button for it.

#### Scenario: A user makes an agent their default from its detail page
- GIVEN a user is viewing an agent they can access at `/agents/:id`
- WHEN the user activates the "Make my default" action
- THEN the system MUST set that agent as the user's default companion agent
- AND the system MUST confirm the result to the user

#### Scenario: The action is declared, not coded
- GIVEN `AgentDetail` is a `type: detail` page whose `config.headerActions` declares `edit-agent`, `version-history` and `view-factsheet`
- WHEN the change lands
- THEN `config.headerActions` MUST declare a fourth entry of type `api-call`
- AND no new Vue component MUST be added for this action

#### Scenario: The action actually renders
- GIVEN the manifest renderer's body-widget branch bypasses `CnDetailPage` and silently drops `config.headerActions`
- WHEN the change is verified
- THEN the action MUST be confirmed rendering and functioning in a live browser
- AND verification MUST NOT rely on grepping the built bundle

### Requirement: Agents without an icon render the AI hexagon avatar
The system MUST render the Conduction AI hexagon as the avatar for any agent shown without its
own icon. The hexagon MUST be pointy-top and point-up, MUST NOT be rotated or flat-top, and MUST
preserve the √3:2 width-to-height ratio that makes its six sides equal. The system MUST source
the hexagon from a shared component rather than duplicating its geometry into this app.

#### Scenario: An agent with no icon is displayed
- GIVEN an agent whose `icon` property is empty
- WHEN the agent is rendered on any surface that shows an agent avatar
- THEN the system MUST render the AI hexagon avatar

#### Scenario: An agent with an icon is displayed
- GIVEN an agent whose `icon` property names a Material Design Icon
- WHEN the agent is rendered
- THEN the system MUST render that icon
- AND the system MUST NOT render the hexagon fallback

#### Scenario: The brand rule is preserved
- GIVEN the hexagon carries a Conduction brand rule (pointy-top, point-up, never rotated, never flat-top; six equal sides only at a √3:2 ratio)
- WHEN the avatar is rendered at any size
- THEN the hexagon MUST remain point-up and MUST retain the √3:2 ratio
- AND its geometry MUST NOT be re-implemented locally in this app

## Non-Functional Requirements

- **Performance:** the avatar fallback MUST NOT issue any additional network request — it is a
  presentational default, resolved client-side from the agent's existing `icon` property.
- **Accessibility:** the hexagon avatar MUST NOT be the sole carrier of meaning — the agent's name
  MUST remain available to assistive technology. The "Make my default" action MUST be reachable
  and labelled through the existing header action surface (WCAG 2.1 AA).
- **Internationalization:** Dutch and English MUST be supported (ADR-005). The "Make my default"
  label MUST be present in `l10n/en.json` and `l10n/nl.json`, keyed by the English source string.

## Acceptance Criteria

- `AgentDetail`'s `config.headerActions` has four entries; the new one is `type: "api-call"`.
- The action is verified rendering and working in a live browser, not by bundle grep.
- Activating it stores the agent as the user's default; the next companion chat uses it.
- An agent with an empty `icon` renders the hexagon; an agent with an icon renders its icon.
- The hexagon's clip-path/ratio is not duplicated into hermiq's source.

## Notes

- The action is a **free-form record action**, not a state-machine transition — it belongs in
  `config.headerActions`, never `lifecycleActions`.
- `CnDetailPage` documents `api-call` as "POST/PUT + toast + refresh" with `@objectId` /
  `@object.<field>` token resolution. **Confirm the exact field names against the installed
  `CnDetailPage` before writing the manifest** — a manifest key the renderer does not read fails
  silently: the button renders and does nothing.
- The hexagon lives in `CnAiFloatingButton.vue` (`nextcloud-vue`) as CSS local to a
  `position: fixed`, 52×60px floating button — **not reusable as-is**. Design.md selects
  extracting a shared avatar component in `nextcloud-vue`; that makes this change cross-repo.
- The authorization contract for the underlying preference lives in the
  `default-companion-agent` spec: a stored UUID is a preference, never an authorization.
