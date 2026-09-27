# Design: agents-export-import-and-git-sync

Kind: code. Size M. Rows `hermiq:ag-import-export`, `dm-agent-as-code`, `re-template-from-agent`.

## Context at development db6b74dc

- Export: `AgentTemplateService::exportFromAgent()` (`lib/Service/AgentTemplateService.php:241-273`) copies name, description, type, prompt, provider, model, tools and skill references, and never `invitedUsers`, `groups`, quotas, `views` or `actingUser`. The route `agentTemplate#export` (`appinfo/routes.php:405-410`) is read-guarded (`lib/Controller/AgentTemplateController.php:283-297`). `src/api/agentTemplates.js:88-90` wraps it; `grep -rn exportAgentToTemplate src/` finds only the definition.
- Import: `TemplateImportModal.vue:104-110` posts a pasted package with source `local` or `org` to `agentTemplate#import`; `importPackage()` quarantines and scans every non-local source (`:305-351`). Approval: `approveQuarantined()` (`:372`), action-authorised.
- GitHub: `GitHubTemplateCatalogService::fetchPackageFile()` (`lib/Service/GitHubTemplateCatalogService.php:482`) reads a package at a repository and ref, `validRepo()` (`:1216`) validates coordinates; `GitHubTemplatePushService::push()` (`lib/Service/GitHubTemplatePushService.php:222`) creates a new repository and refuses an existing one; `pushUpdate()` (`:310`) updates only the repository stamped on the object's own provenance, and the caller derives the coordinates, never the client. The GitHub token comes from the credential broker and is never held.
- Versions: `AgentVersionController` lists, diffs and rolls back (`appinfo/routes.php:172-173`); versions live on OpenRegister's AuditTrail (archived `agent-versioning`).
- Agent page header actions: `src/manifest.json` page `AgentDetail`, `headerActions` edit, version history, factsheet.

## D1. Export and save as template from the agent page

Two header actions on `AgentDetail`: "Export" (downloads `<agent-name>.hermiq-agent.json` from the existing route) and "Save as template" (new `POST /api/agent-templates/from-agent/{agentId}`, owner-only, which calls `exportFromAgent()` then `importPackage(source: 'local')`, so the template lands active and records `derivedFrom: <agentId>`). No new package format.

## D2. Import an agent from a file

A catalog header action "Import agent" opens `TemplateImportModal.vue` with a file input next to the paste box. A file import uses source `org`, so it lands quarantined and scanned like any outside package. The Store row then offers approval to an organisation admin and "Use this template" afterwards. The modal says so: "Imported agents are reviewed before anyone can use them."

Rejected: importing straight into a live agent. It would skip the scan the archived `agent-template-gallery` requires for every outside package.

## D3. Keep an agent in git

New Agent properties `gitOwner`, `gitRepo`, `gitPath` (default `agent.json`), `gitRef` (default `main`), `gitLastPulledSha`. Three owner-only actions under "Keep in git":

1. "Publish to GitHub": `GitHubTemplatePushService::push()` with the agent's package into a new repository, then stamp the coordinates on the agent. An existing repository is refused, as today.
2. "Push changes": `pushUpdate()` to the stamped repository only, the same carve-out skills use, with coordinates read from the agent and never from the request.
3. "Pull from git": `fetchPackageFile()` at `gitRef`, parse with `AgentTemplateSerializer`, content-scan the prompt with the same scan `importPackage()` uses, show a diff of the fields the package carries against the current agent, and on confirm write them as an ordinary save, which OpenRegister records as a new agent version. `gitLastPulledSha` records the commit. A dangerous scan verdict blocks the pull with the scan reason.

Only the package's fields change. Sharing, schedules, credentials and memory are never taken from git.

Rejected: two-way automatic sync on every commit. It would need a webhook from GitHub into hermiq and a merge rule; a pull the owner confirms is enough for "edit in git, sync back".

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| git coordinates and `derivedFrom` | declarative, schema properties | plain data |
| export, save, publish, push and pull | imperative, existing services | external integration (GitHub) and document generation, ADR-031 exceptions |

## Seed data

None required. The seeded starter templates are unchanged.

## Risks

- A pull that replaces a carefully tuned prompt. Mitigation: the diff before confirm, and rollback through version history.
- Pushing to a repository the owner does not control. Mitigation: coordinates only from the agent's own stamp, set by a publish that created the repository.
- A package from git carrying an injected prompt. Mitigation: the same content scan as an outside import, blocking on a dangerous verdict.
