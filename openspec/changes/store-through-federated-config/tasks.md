# Tasks: store-through-federated-config

## 1. Pin the store's wire behaviour

- [x] 1.1 `tests/Unit/Service/GitHubTemplatePushServiceWireTest.php`: broker app id and acting user, refusal of an existing repository, visibility on create, commit sha, topic union, `base_tree`, empty-blob guard, bundle create, republish refusal and auxiliary files.
- [x] 1.2 `tests/Unit/Service/GitHubTemplateCatalogServiceWireTest.php`: free-text term, broker app id with anonymous fallback, auxiliary files, agent definitions from a bundle archive.
- [x] 1.3 Break each guard once and watch its test fail.

## 2. OpenRegister engine (not in this repository)

- [ ] 2.1 G1: `appId` and `actingUserId` on every brokered call.
- [ ] 2.2 G2: `discover()` returns an outcome and accepts a free-text term.
- [ ] 2.3 G3: tree or archive read with a ref and an anonymous fallback.
- [ ] 2.4 G4: multi-file Git Data commit that can update an existing repository and returns the commit sha.
- [ ] 2.5 G5: merge topics instead of replacing them.
- [ ] 2.6 G6: a switch to stop the retry under `/user/repos`.

## 3. Hermiq cutover

- [ ] 3.1 G7: read both wire formats during the transition.
- [ ] 3.2 G8: contribute a hermiq-owned agent-template type that installs through `AgentTemplateService::importPackage`.
- [ ] 3.3 Route search, install and publish through the engine one operation at a time, keeping section 1 green.
- [ ] 3.4 Delete `GitHubTemplateCatalogService` and `GitHubTemplatePushService`.
