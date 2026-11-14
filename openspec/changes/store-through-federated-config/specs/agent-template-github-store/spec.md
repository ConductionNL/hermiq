# agent-template-github-store Specification Delta

## ADDED Requirements

### Requirement: The store MUST keep its GitHub wire behaviour until the shared engine carries it

The store's search, install and publish MAY move onto OpenRegister's
`FederatedConfigService` one operation at a time. An operation SHALL move only
when the engine keeps every behaviour below for that operation. Until then the
operation SHALL stay on hermiq's own GitHub services.

#### Scenario: A brokered call names hermiq and the acting user
@e2e exclude pinned by GitHubTemplatePushServiceWireTest::testEveryBrokerCallNamesHermiqAndTheActingUser and GitHubTemplateCatalogServiceWireTest::testABrokeredReadNamesHermiqAndFallsBackToAnonymousWhenRefused; a browser run cannot see which app id reached the broker.

- GIVEN a user picks a GitHub credential whose `allowedApps` contain `hermiq`
- WHEN the store makes a brokered GitHub call with it
- THEN the call names `hermiq` as the app and carries the user as the acting user
- AND a read the broker refuses is retried anonymously

#### Scenario: Publishing a bundle keeps the repository's other topics
@e2e exclude pinned by GitHubTemplatePushServiceWireTest::testBundlePublishKeepsTheRepositorysOtherTopics; an end-to-end run would write topics on a real GitHub repository.

- GIVEN a repository already carries the topic `openbuild-app`
- WHEN a skill bundle is published into it
- THEN the repository carries both `openbuild-app` and `hermiq-skill-bundle`

#### Scenario: A blob without a sha stops the publish on its own path
@e2e exclude pinned by GitHubTemplatePushServiceWireTest::testABlobWithoutAShaFailsNamingThePathBeforeAnyTreeIsBuilt; GitHub cannot be made to drop a sha on demand.

- GIVEN GitHub answers one blob upload without a sha
- WHEN a bundle is published
- THEN the publish fails with an error naming that file
- AND no tree is built

#### Scenario: A publish keeps the rest of the repository and returns its commit
@e2e exclude pinned by GitHubTemplatePushServiceWireTest::testBundlePublishRidesTheBaseTree and testPushReturnsTheCommitShaTheBranchWasMovedTo; an end-to-end run would commit to a real GitHub repository.

- GIVEN a repository holds files outside the bundle
- WHEN a bundle, a skill or a template is published
- THEN the commit is built on the existing tree, so those files survive
- AND the branch moves forward to the new commit, whose sha the publish returns

#### Scenario: The chosen visibility reaches repository creation
@e2e exclude pinned by GitHubTemplatePushServiceWireTest::testPushCreatesTheRepositoryWithTheChosenVisibility and testBundlePublishCreatesAnAbsentRepositoryWithTheChosenVisibility; an end-to-end run would create a real GitHub repository.

- GIVEN a user publishes with visibility `private`
- WHEN the store creates the repository
- THEN the repository is created private
- AND a store publish never creates a public repository the user did not ask for

#### Scenario: Install reads the whole package, not one file
@e2e exclude pinned by GitHubTemplateCatalogServiceWireTest::testAuxiliaryFilesAreEveryBlobExceptThePackage and testABundleArchiveKeepsItsAgentDefinitions; the fake GitHub controls the tree, which a live repository cannot.

- GIVEN a skill repository holds `hermiq-skill.md` and `references/steps.md`
- AND a bundle repository holds `hermiq-skills.json`, `skills/` and `agents/`
- WHEN either is installed
- THEN the skill arrives with its auxiliary files
- AND the bundle arrives with its skills and its agent definitions, from one archive download

#### Scenario: Search sends the free-text term to GitHub
@e2e exclude pinned by GitHubTemplateCatalogServiceWireTest::testSearchSendsTheFreeTextTermToGitHub; a browser run cannot see the query string hermiq sends to GitHub.

- GIVEN a user searches the store for `meeting notes`
- WHEN the store queries GitHub
- THEN the query holds the kind's topic and the term together
