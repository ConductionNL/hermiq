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

## As built (part A, 1 Oct 2026)

Tasks 1 and 2 ship first; the git tasks (D3, tasks 3 to 5) follow in their own PR.

- A header action opens a modal with static props, so "Export" and "Save as template" are one modal (`AgentExportModal`) with a `mode` prop. Export downloads the package from the existing route as `<agent-name>.hermiq-agent.json` (`src/utils/agentExport.js`).
- "Save as template" answers 404 when the caller cannot read the agent and 403 when they can but do not own it (`AgentAccessService::canUserModifyAgent`). The template is `local` and `active`, with `derivedFrom` set to the agent, a relation (`$ref: agent`).
- "Import agent" reuses `TemplateImportModal` with `mode: agent`: a file input beside the paste box, one button, source `org`.
- Found while testing against the real fragment: a `local` import and `create()` wrote `null` into `quarantineReason`, `scanReport` and (for a package without one) `suggestedSchedule`. The fragment types them as string and object and refuses null, so these keys are now left out instead.

## As built (part B, 1 Oct 2026)

Tasks 3 to 5. Where they differ from D3:

- No `gitPath`. The package file is the one `GitHubTemplatePushService` already writes for an agent template, `hermiq-agent-template.json`, and `GitHubTemplateCatalogService::fetchPackageFile()` reads the same name, so a configurable path would have nothing to configure.
- `gitLastPulledHash` instead of `gitLastPulledSha`. `fetchPackageFile()` returns the file's content and not the commit, so the pull records the sha256 of the package it wrote. A commit sha needs a second GitHub call this change does not make.
- The four git properties carry an `authorization.update` rule for the `admin` group, like `appAssistantFor`: an owner cannot point the stamp at another repository through the object API. `AgentGitService` writes them for the checked owner with RBAC off.
- A confirmed pull fetches and scans the package again instead of trusting the preview, because a commit may land between the two.
- The modal publishes private repositories; the publish route accepts `visibility: public` for a later form field.
- `store-through-federated-config` has not landed, so the existing GitHub push and catalog services are the engine.

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
