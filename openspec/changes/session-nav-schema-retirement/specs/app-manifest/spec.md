# app-manifest Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `session-nav-schema-retirement` — the `AgentSessions` page and its duplicate `Sessions` menu
  entry are removed; the surviving `Chat` page's menu entry is relabelled "Sessions" (kind: config)

## Purpose

Delta for `openspec/specs/app-manifest/spec.md`. Hermiq's UI is manifest-driven: `src/manifest.json`
declares `pages[]` and `menu[]`. The manifest currently declares an `AgentSessions` page whose
component `src/views/AgentSessions.vue` is deleted by this change's dependency
(`session-store-consolidation`), and two menu entries with the same `icon-comment` icon for one
user-facing concept. This delta requires the manifest to declare exactly one conversation surface.

## ADDED Requirements

### Requirement: The manifest declares no page without a component
The system's `src/manifest.json` MUST NOT declare a page, route, or main-navigation entry whose
component file does not exist in `src/views/`. Specifically, the `AgentSessions` page MUST NOT be
declared, because `src/views/AgentSessions.vue` is removed by `session-store-consolidation`.

#### Scenario: A user opens the app's main navigation
- GIVEN the Hermiq app is loaded for any authenticated user
- WHEN the user views the main navigation
- THEN the navigation MUST NOT contain an entry whose page is `AgentSessions`
- AND no route under `/sessions` MUST be registered

#### Scenario: The app is built
- GIVEN `src/views/AgentSessions.vue` no longer exists
- WHEN the frontend is built
- THEN the build MUST NOT emit an unresolved-module error or warning for a manifest-declared page
- AND the manifest MUST declare 17 pages

### Requirement: Exactly one conversation surface is exposed in the main navigation
The system MUST expose exactly one main-navigation entry for the conversation capability. That
entry MUST target the page id `Chat` and MUST be labelled "Sessions". The page id (`Chat`), its
route (`/chat`), and its icon (`icon-comment`) MUST be unchanged — the rename is a user-facing
label only.

#### Scenario: A user looks for their conversations in the navigation
- GIVEN the Hermiq app is loaded for any authenticated user
- WHEN the user views the main navigation
- THEN exactly one entry MUST be labelled "Sessions"
- AND that entry MUST target the page id `Chat`
- AND clicking it MUST open the route `/chat`

#### Scenario: The duplicate icon-comment entry is gone
- GIVEN the manifest previously declared two `menu[]` entries with the icon `icon-comment` (page `Chat` labelled "Chat", and page `AgentSessions` labelled "Sessions")
- WHEN the change lands
- THEN the manifest MUST declare exactly one `menu[]` entry with the icon `icon-comment`
- AND the manifest MUST declare 16 menu entries

#### Scenario: The correct entry is retained
- GIVEN the retained entry is identified by its page id and not by its label
- WHEN the menu is inspected after the change
- THEN the entry whose page is `AgentSessions` MUST be absent
- AND the entry whose page is `Chat` MUST be present

## Non-Functional Requirements

- **Performance:** no runtime impact — the manifest is a static declaration.
- **Accessibility:** the navigation MUST NOT present two entries with the same icon and
  overlapping meaning (WCAG 2.1 AA; ambiguous adjacent navigation targets).
- **Internationalization:** Dutch and English MUST be supported (ADR-005). The "Sessions" menu
  label MUST be present in `l10n/en.json` and `l10n/nl.json`, keyed by the English source string.

## Acceptance Criteria

- `src/manifest.json` parses as valid JSON after the edit.
- No entry in `pages[]` has the id `AgentSessions`; `pages[]` has 17 entries.
- No entry in `menu[]` has the page `AgentSessions`; `menu[]` has 16 entries.
- Exactly one `menu[]` entry has the icon `icon-comment`, its page is `Chat`, its label is "Sessions".
- The `Chat` page entry's route is still `/chat`.

## Notes

- The retained entry MUST be selected by `page === "Chat"`, never by label: after the relabel the
  surviving entry is *labelled* "Sessions" while the deleted entry's *page id* is
  `AgentSessions`. Matching on the string "Sessions" deletes the wrong one.
- Depends on `session-store-consolidation`, which deletes the component. This change MUST land
  after it, and MUST be rolled back before it.
