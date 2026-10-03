# Proposal: store-through-federated-config

## Why

Hermiq's store searches, installs and publishes agent templates and skills on
GitHub. Two hermiq services do that work: `GitHubTemplateCatalogService` and
`GitHubTemplatePushService`. OpenRegister now owns a shared engine for the same
job, `FederatedConfigService`.

Commit `45a68371e` on `feat/retire-bespoke-github-sync` (26 July) moved the store
onto that engine. It never landed. Since then 22 commits built on the bespoke
path, and the engine does not carry what they added.

This change records the gap and pins the behaviour a later cutover must keep.
It does not move the store yet.

## What the engine lacks

Read against `openregister@development` `lib/Service/Config/FederatedConfigService.php`
on 2026-09-14.

| Gap | Engine today | Hermiq needs | Needs an OpenRegister change |
|---|---|---|---|
| G1 broker identity | Every brokered call names app `openregister` and passes no acting user. | Calls name `hermiq` and the acting user. Hermiq only offers credentials whose `allowedApps` contain `hermiq`, and the broker compares the app id exactly. | Yes: accept `appId` and `actingUserId`. |
| G2 search outcome | `discover()` turns every failure into an empty list. It takes a topic only. | A rate-limited or unreachable outcome, `brokerUsed`, a free-text term sent to GitHub, and a live status for integriq's connection registry (#875). | Yes: return status with the cards and accept a term. |
| G3 reading a package | `fetchBundle()` reads one JSON file through the contents API. No ref, no archive, and no anonymous retry when the broker refuses. | `hermiq-skill.md` (markdown), auxiliary files beside it, the bundle tree with `skills/` and `agents/` (#341) in one tarball download, a ref, and the anonymous fallback. | Yes: a tree or archive read, a ref, and the fallback. |
| G4 writing a package | `publish()` writes one file with a contents PUT and no `sha`. It returns no commit sha. | A multi-file commit on `base_tree`, an update of an existing repository (republish, bundle re-sync), the empty-blob guard (#108), the commit sha, and a repository description. | Yes: a Git Data tree commit with update support. |
| G5 topics | `setTopics()` replaces the whole list with the type's one topic. | The union of existing topics and the store topic. #108 fixed exactly this on buildiq-hydra. | Yes: merge topics. |
| G6 existing repository | `ensureRepo()` reuses an existing repository and writes into it. It also retries under the user namespace. | A single publish refuses an existing repository (spec requirement). | Hermiq can check first, but the retry under `/user/repos` needs an OpenRegister switch. |
| G7 wire format | A signed JSON envelope, for skills `hermiq-skill.json` holding `{type, version, skills: [{name, package}]}`. | The published corpus: `hermiq-skill.md`, `hermiq-agent-template.json` and bundle format 1.1. Checked live: hermiq's search shows the one engine-format repository on GitHub as unparseable. | A format decision on both sides: read both formats during a transition. |
| G8 agent template install | `hermiq.agent-template` is OpenRegister's generic schema-derived type. | Install through `AgentTemplateService::importPackage(source: 'hub')`, which quarantines and scans. | No: hermiq contributes its own agent-template type. |

Visibility is not a gap. `publish()` takes `private` and passes it to repository
creation, so hermiq can map `visibility === 'private'` onto it. The engine
defaults to public, so a cutover must always pass the flag. Neither path changes
the visibility of a repository that already exists.

## What changes now

- Two wire-level unit test files pin the behaviour above against a recording
  broker and a fake GitHub. Each test was run with its guard broken and failed.
- A spec delta names that behaviour as the bar for the cutover.
- No production code changes. The store stays on the bespoke path.

## What changes later

1. OpenRegister closes G1 to G5 and the `/user/repos` retry in G6.
2. Hermiq settles G7 and contributes its own agent-template type for G8.
3. Hermiq routes each operation through the engine only when its pinned tests
   stay green, then deletes the two services.
