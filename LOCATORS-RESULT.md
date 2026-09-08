# hermiq: global-container lookups retired

Branch `refactor/injected-container-lookups`, PR https://github.com/ConductionNL/hermiq/pull/826 against `development`.
Commit `1044398`. Not merged by this run: the merge is the main session's.

## Sites

Fleet no-service-locator sniff on `lib`: **10 before, 0 after**. No `phpcs:ignore` annotations, so the 0 is a true 0 rather than 0 after exemptions.

| file | sites | resolves |
|---|---|---|
| `lib/Service/StageDispatchService.php` | 3 | AppAPI `PublicFunctions`, broker (twice) |
| `lib/Service/Llm/ProviderFactory.php` | 2 | broker, AppAPI `PublicFunctions` |
| `lib/Service/AsyncStageDispatchService.php` | 1 | AppAPI `PublicFunctions` |
| `lib/Service/GitHubTemplateCatalogService.php` | 1 | broker |
| `lib/Service/GitHubTemplatePushService.php` | 1 | broker |
| `lib/Service/Llm/BrokerHttpClient.php` | 1 | broker |
| `lib/Service/WebResearch/WebSearchClient.php` | 1 | broker |

All ten resolve another app's class by class-string: AppAPI's `PublicFunctions` and OpenRegister's `CredentialBrokerService`. Both are optional sibling apps hermiq must stay bootable without, so neither can become a constructor type. They stay dynamic with the same `class_exists` probe and try/catch; only the source moved, from the global server to an injected container.

The container is a trailing nullable parameter on all seven classes: six are built positionally by unit tests, and `ProviderFactory` already ends in six nullable defaulted parameters, so a required parameter is not available there at all. A null container takes each class's existing "sibling app absent" branch. `serviceContainer()` is `protected` on `StageDispatchService` because `AsyncStageDispatchService` calls it; PHPStan caught that.

## Left alone

Nothing. `lib/AppInfo`, `lib/AppHost`, `lib/Migration`, `lib/Repair` and `lib/Resources` are excluded from the sniff by path, and hermiq has no lookups in them anyway.

## Docblocks

Six `@SuppressWarnings(PHPMD.StaticAccess)` explanations named `OCP\Server::get` as the deliberate mechanism and now name the injected container. Four docblocks in `tests/` said the same and were corrected. No test assertion changed.

## Verification, by exit code

| check | scope | before | after |
|---|---|---|---|
| unit suite `phpunit.xml --no-coverage` | full | 1904 tests, exit 0, RSS 92 MB | 1904 tests, exit 0, RSS 92 MB |
| fleet sniff | `lib` | 10 | 0 |
| phpcs `phpcs.xml` | 7 touched lib files | | exit 0 |
| phpstan `--memory-limit=1G` | 7 touched lib files | | no errors, exit 0 |
| psalm `--no-cache --memory-limit=2G` | 7 touched lib files | | no errors, exit 0 |
| phpmd, both rulesets, one at a time | 7 touched lib files | | exit 0 each |

Two notes.

`phpunit-unit.xml` is red on its own, 91 errors before and after: that standalone config does not map OpenRegister's `Service\Capability` classes and errors loudly rather than answering from a stub, exactly as its bootstrap documents. `phpunit.xml` is what CI runs and is green. It needs an `openregister` checkout beside the clone; this run symlinked `/home/rubenlinde/memcap-work/fleet/openregister` to `or-clone` to satisfy that.

The assertion count moves from 6078 to 6081. `GitHubTemplatePushServiceTest` has two reflective tests that loop over the constructor's parameters, and there is now one more parameter. A per-test assertion diff of the two runs confirms those two are the only tests that changed.

Whole-tree `composer check:strict` and whole-tree psalm/phpstan/phpmd were not run, per the memory discipline for the day.

One pre-existing phpcs warning stands on `AsyncStageDispatchService`: the class is missing an `@spec` tag pointing at a spec rather than a change dir. It is present on `development`, it is a warning rather than an error, and choosing a spec path for it is a separate decision.

## Left to do

Merge PR #826, then watch the `development` push run of `code-quality.yml`.
