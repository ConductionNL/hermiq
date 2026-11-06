---
kind: code
depends_on: []
---

# Proposal: agents-export-import-and-git-sync

## Summary

An agent owner exports an agent to a file from its page and imports that file into another Nextcloud, where it goes through the same review as any outside template. The owner can also save an agent as a template colleagues reuse. And the owner can keep an agent in a git repository: publish it there, edit its files in a code editor, and pull the changes back into the same agent as a new version, with a diff to check first and a rollback afterwards.

## Why

Three rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `hermiq:ag-import-export` | partial, built | build: four competitors rate yes for the missing half, exporting a live agent |
| `hermiq:dm-agent-as-code` | partial, built | build: changelog demand and three competitors rate yes for the missing half, git edits that sync back |
| `hermiq:re-template-from-agent` | no (built.state built) | build: the archived `agent-template-gallery` built the endpoint and nothing calls it |

Demand, quoted from the matrix:

- `dm-agent-as-code`: changelog https://learn.microsoft.com/en-us/microsoft-copilot-studio/whats-new

Competitor cells rated yes, quoted from the matrix evidence:

- `ag-import-export`, Hermes Agent v2026.9.24: `hermes_cli/subcommands/profile.py:115` "`profile export` to .tar.gz and :122 `profile import`". Dify 1.17.1: `web/features/agent-v2/agent-detail/sidebar-actions.tsx:33,52` "Export (app DSL YAML)". n8n 2.40.7: `en.json:7613-7619` "agent builder 'Export JSON' and 'Import agent JSON' modal". Open WebUI v0.11.4: `backend/open_webui/routers/models.py:381` "GET /models/export, :441 POST /models/import".
- `dm-agent-as-code`, Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/visual-studio-code-extension-clone-agent "the Visual Studio Code extension (GA January 2026) clones an agent to a local folder, supports git and applies changes back". Dify 1.17.1: `cli/src/commands/import/studio-app/index.ts:9-17,31-33` "import --from-file with --app-id overwrites the existing app". Hermes Agent: `hermes_cli/profiles.py:35,151,306` "an agent (profile) is a directory of plain files ... that any editor and git can hold".
- `re-template-from-agent`, Open WebUI v0.11.4: `src/lib/components/workspace/Models.svelte:221` "shareModelHandler posts the full model to openwebui.com, :214 Clone, per-model Export JSON".

## What hermiq already has

- `AgentTemplateService::exportFromAgent()` (`lib/Service/AgentTemplateService.php:241-273`) turns an agent into a secret-free package; `GET /api/agent-templates/from-agent/{agentId}/export` (`appinfo/routes.php:405-410`, `AgentTemplateController::export()` read-guarded at `lib/Controller/AgentTemplateController.php:283-297`); `exportAgentToTemplate()` in `src/api/agentTemplates.js:88-90`. Nothing in `src/` calls it.
- Template import from a pasted package with source `local` or `org` (`src/modals/TemplateImportModal.vue:104-110`), quarantine and scan for non-local sources (`AgentTemplateService::importPackage()`, `:305`), "Use this template" to instantiate (`:431`).
- GitHub: search and install (`lib/Service/GitHubTemplateCatalogService.php:361,443,482`), publish a template to a new repository (`lib/Service/GitHubTemplatePushService.php:222`), and an update push to the repository stamped on a skill's provenance (`pushUpdate()`, `:310`), used for skills only.
- Agent versions with list, diff and rollback (`appinfo/routes.php:172-173`, `lib/Controller/AgentVersionController.php`).

## What this change builds

1. "Export" on the agent page: download the agent's package as a `.json` file.
2. "Import agent" on the catalog: upload a package file; it lands in the Store as a quarantined template, and after approval "Use this template" creates the agent.
3. "Save as template" on the agent page: an active local template in the Store, visible to the organisation.
4. "Keep in git": publish the agent's package to a new repository, stamp owner, repository and path on the agent, push later edits to that same repository, and "Pull from git" to apply the repository's package to the same agent as a new version after a diff.

## Out of scope

- A command line tool or an editor extension. The repository holds a plain JSON package any editor opens.
- Moving the store onto OpenRegister's `FederatedConfigService`. The open change `store-through-federated-config` owns that cutover; this change uses the same GitHub services the store uses today and names that change in its tasks.
- Schedules, memory and run history in the package. The package stays secret-free and tenant-free, as the archived `agent-template-gallery` requires.
