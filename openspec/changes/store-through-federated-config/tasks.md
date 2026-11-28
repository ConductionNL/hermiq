# Tasks: store-through-federated-config

## 1. Pin the store's wire behaviour

- [x] 1.1 `tests/Unit/Service/GitHubTemplatePushServiceWireTest.php`: broker app id and acting user, refusal of an existing repository, visibility on create, commit sha, topic union, `base_tree`, empty-blob guard, bundle create, republish refusal and auxiliary files.
- [x] 1.2 `tests/Unit/Service/GitHubTemplateCatalogServiceWireTest.php`: free-text term, broker app id with anonymous fallback, auxiliary files, agent definitions from a bundle archive.
- [x] 1.3 Break each guard once and watch its test fail.

## 2. OpenRegister engine (not in this repository)

Built in openregister on branch `build/store-through-federated-config` (open PR to main); not yet on main.

- [x] 2.1 G1: `appId` and `actingUserId` on every brokered call.
- [x] 2.2 G2: `discover()` returns an outcome and accepts a free-text term.
- [x] 2.3 G3: tree or archive read with a ref and an anonymous fallback.
- [x] 2.4 G4: multi-file Git Data commit that can update an existing repository and returns the commit sha.
- [x] 2.5 G5: merge topics instead of replacing them.
- [x] 2.6 G6: a switch to stop the retry under `/user/repos`.

## 3. Hermiq cutover

- [x] 3.1 G7: read both wire formats during the transition.
- [x] 3.2 G8: contribute a hermiq-owned agent-template type that installs through `AgentTemplateService::importPackage`.
- [x] 3.3 Route search, install and publish through the engine one operation at a time, keeping section 1 green.
  - 2026-11-24: search routed (`FederatedCatalogSearch` over `FederatedConfigService::discoverOutcome()`, OpenRegister main; the direct GitHub search stays as the fallback without the engine); tests `GitHubTemplateCatalogEngineSearchTest`, section 1 green. Install and publish next.
  - 2026-11-24: install reads routed (`FederatedCatalogRead` over `FederatedConfigService::fetchPackage()`): the bundle (manifest, `skills/`, `agents/`) and a skill's auxiliary files, within the store's own 128-file bound; tests `GitHubTemplateCatalogEngineReadTest`. The per-hit package read during search stays on the contents API (one small read per card, not one archive). Publish next.
  - Publish, read against openregister main on 2026-11-24 (`publishFiles(RepoFilesPublish)`, `GitHubTreeCommitter`): the engine commits on `ConfigPublishTarget::branch` (default `main`) while hermiq commits on the repository's own `default_branch`; and without `onlyIntoANewRepository()` the engine's `ensureRepo()` creates a missing repository, where a republish must refuse one. So `push()` passes the branch the created repository reports (or creates through hermiq and commits through the engine), and `pushUpdate()` keeps hermiq's existence check and passes that repository's `default_branch`. Topics already merge (G5) and the empty-blob guard is in the engine.
  - 2026-11-24: publish routed (`FederatedPackagePublish` over `publishFiles()`): hermiq keeps creating the repository, the existing-repository refusal, the republish existence check and the topics; the engine commits on the branch the repository reports, so both gaps above are closed. A failing engine falls back to `GitHubCommitWriter`'s own commit. Tests `GitHubTemplatePushEngineTest`; section 1 (`GitHubTemplatePushServiceWireTest`) green. The direct GitHub paths stay as the fallback for an OpenRegister without the engine; 3.4 deletes them once hermiq's minimum OpenRegister carries it.
- [ ] 3.4 Delete `GitHubTemplateCatalogService` and `GitHubTemplatePushService`.
