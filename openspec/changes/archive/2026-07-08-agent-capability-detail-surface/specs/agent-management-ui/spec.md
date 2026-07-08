## ADDED Requirements

### Requirement: Agent detail manages skills in place [MVP]
The agent detail view MUST list the agent's installed skills, let the user attach an
uninstalled catalog skill, and let the user detach an installed skill, without navigating
away from the detail page.

#### Scenario: Attach a skill from the detail page
- GIVEN an agent detail view and a `Skill` in `active` state not yet installed on this agent
- WHEN the user selects it in the attach picker
- THEN the system MUST install the skill onto the agent
- AND the detail view MUST show the skill in the installed list afterward
@e2e exclude attach reuses the existing installSkill endpoint; the detail Skills section (attach picker + installed list) was live-verified rendering, and the install path is covered by SkillServiceTest. A dedicated Playwright attach flow is deferred (needs a seeded catalog skill).

#### Scenario: Detach a skill from the detail page
- GIVEN an agent detail view showing an installed skill
- WHEN the user triggers the detach action for that skill
- THEN the system MUST remove the skill from the agent's installed skills
- AND the detail view MUST no longer show the skill in the installed list afterward
@e2e exclude detach is covered by SkillServiceTest (uninstallFromAgent removes from both installedOn and skillInstalls, plus idempotency); the detail Skills section was live-verified rendering. Playwright coverage deferred.

### Requirement: Agent detail manages memory in place [MVP]
The agent detail view MUST show the agent's memory (char-budget bar, entry list, add-fact
input, Consolidate action) using the same implementation as the standalone memory view, so
memory can be reviewed and updated without leaving the detail page.

#### Scenario: Add a memory fact from the detail page
- GIVEN an agent detail view with its Memory section visible
- WHEN the user submits a new fact via the add-fact input
- THEN the system MUST persist the fact to the agent's `Memory` object
- AND the fact MUST appear in the entry list without a full page navigation
@e2e exclude the Memory section reuses the unchanged MemoryController write path via the shared AgentMemoryPanel; the section was live-verified rendering (budget bar + add-fact input + entry list) on the detail page. Playwright write coverage deferred (live write was blocked by an unrelated needsDbUpgrade state on the shared instance).

## MODIFIED Requirements

### Requirement: Create and configure an agent [MVP]
The system MUST let a user create an agent (name, model/provider, prompt, enabled tools) via
a create form, and MUST let a user edit an existing agent's configuration fields inline from
the agent detail view using a schema-driven, click-to-edit widget, persisting via
OpenRegister. Tenancy/noise fields (invited users, groups, views, private flag, quotas,
acting user, configuration, context refs, skill installs) MUST NOT be shown in the inline
config widget.

#### Scenario: Create an agent
- GIVEN the create form
- WHEN the user fills in name, selects a model, writes a prompt, and saves
- THEN the system MUST create the agent as an OpenRegister object owned by the user's organisation
@e2e exclude pre-existing behaviour (config-only create modal, unchanged by this change); live-verified during this change's verification (created an agent via the form → 201). Playwright coverage owned by the agent-management-ui base change.

#### Scenario: Edit a config field inline from the detail view
- GIVEN an agent detail view rendering the schema-driven config widget
- WHEN the user clicks a config field (e.g. name, prompt, temperature) and saves a new value
- THEN the system MUST persist the change via OpenRegister
- AND the detail view MUST reflect the saved value without opening the Edit modal
@e2e exclude live-verified: the schema-driven CnObjectDataWidget click-to-edit + whole-object PUT round-trip returned 200 and the cell reflected the saved value. Dedicated Playwright coverage deferred.

#### Scenario: Edit the tool allowlist inline from the detail view
- GIVEN an agent detail view rendering the schema-driven config widget
- WHEN the user opens the tools field
- THEN the system MUST present the current, dynamically-fetched tool catalog as selectable options
- AND selecting or deselecting tools and saving MUST persist the updated tool allowlist via OpenRegister
@e2e exclude live-verified end-to-end: opening the tools field rendered the dynamic /api/agents/tools catalog, selecting a tool and confirming issued the agent PUT (200), and the Tools cell displayed the saved tool. Dedicated Playwright coverage deferred.
