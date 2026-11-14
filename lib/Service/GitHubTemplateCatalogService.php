<?php

/**
 * Hermiq GitHubTemplateCatalogService.
 *
 * Server-side GitHub source for the unified "Store" page's discovery surface
 * (agent-template-github-store, generalised by hermiq-github-store). Searches
 * GitHub for repositories tagged either `topic:hermiq-agent-template` or
 * `topic:hermiq-skill` (a `kind` seam — one call per kind, never both topics in
 * one round trip), and fetches a hit's portable package file for install: the
 * JSON shape `AgentTemplateSerializer::toPackage()`/`AgentTemplateService::
 * importPackage()` produces/consumes for `KIND_AGENT_TEMPLATE`, the
 * agentskills.io fenced shape `SkillSerializer::toPackage()`/`fromPackage()`
 * produces/consumes for `KIND_SKILL`. Every returned card carries its `kind`.
 * Every existing caller that omits the `$kind` parameter gets the exact
 * agent-template-only behaviour this service had before hermiq-github-store.
 *
 * SSRF-safe by construction (mirrors OpenBuild's GitHubCatalogService, verified
 * at HEAD in `openbuild/lib/Service/GitHubCatalogService.php`): every outbound
 * host is the compile-time constant `api.github.com` — there is NO admin-
 * configurable URL — and `owner`/`repo`/`ref` are pattern-validated before path
 * interpolation. Browsing is anonymous-first (usable with no credential); when
 * the acting user supplies an allowed broker `github` credential the call is
 * transparently upgraded through OpenRegister's CredentialBrokerService so the
 * token stays broker-side and NEVER enters Hermiq. The broker is resolved
 * lazily (`class_exists` + a lookup on the injected container, mirroring
 * BrokerHttpClient/WebSearchClient) so a missing/older OpenRegister falls back
 * to anonymous cleanly. Search results are cached short-TTL against the tight
 * anonymous rate limit; the raw GitHub body and any token are never returned or
 * logged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fixed-host, SSRF-safe GitHub catalogue source with optional broker upgrade.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same rationale as the complexity
 *   suppression below: the SSRF-safe fixed-host invariant is only meaningful if
 *   every outbound call lives behind it, so splitting the read-path across classes
 *   would weaken the guarantee it exists to hold.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class owns the whole
 *   catalogue read-path (search, card build, package fetch, brokered vs anonymous
 *   GET, caching) so the SSRF-safe fixed-host invariant stays in a single place.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
class GitHubTemplateCatalogService implements GitHubTemplateCatalogServiceInterface {
	/**
	 * The fixed GitHub API host — compile-time constant, no SSRF surface.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * Per-kind discovery topic (hermiq-github-store: generalised from the single
	 * agent-template-only `DISCOVERY_TOPIC`). Every conforming repo of a kind
	 * carries the matching topic.
	 *
	 * @var array<string,string>
	 */
	private const DISCOVERY_TOPICS = [
		self::KIND_AGENT_TEMPLATE => 'topic:hermiq-agent-template',
		self::KIND_SKILL => 'topic:hermiq-skill',
	];

	/**
	 * Per-kind repo-root package file name (hermiq-github-store: generalised from
	 * the single agent-template-only `PACKAGE_FILE`). The agent-template file is
	 * the JSON shape `AgentTemplateSerializer::toPackage()`/`::fromPackage()`
	 * produces/consumes; the skill file is the agentskills.io fenced-frontmatter
	 * shape `SkillSerializer::toPackage()`/`::fromPackage()` produces/consumes.
	 *
	 * @var array<string,string>
	 */
	public const PACKAGE_FILES = [
		self::KIND_AGENT_TEMPLATE => 'hermiq-agent-template.json',
		self::KIND_SKILL => 'hermiq-skill.md',
	];

	/**
	 * Maximum auxiliary blobs fetched for one install (skill-package-multifile).
	 *
	 * Sized above the largest real skill observed in the hydra set (`create-pr`,
	 * 63 files) with headroom, while still bounding a hostile repository's ability
	 * to turn one install into an unbounded fan-out of contents-API calls.
	 *
	 * @var int
	 */
	private const MAX_AUX_FILES = 128;

	/**
	 * Maximum total auxiliary bytes fetched for one install.
	 *
	 * @var int
	 */
	private const MAX_AUX_BYTES = 4194304;

	/**
	 * The bundle manifest at a bundle repository's root (skill-bundle-publish).
	 * Its presence is what makes a repository a bundle.
	 *
	 * @var string
	 */
	public const BUNDLE_MANIFEST_FILE = 'hermiq-skills.json';

	/**
	 * The directory bundled skills live under.
	 *
	 * @var string
	 */
	public const BUNDLE_SKILLS_PREFIX = 'skills/';

	/**
	 * The directory bundled agents live under (skill-bundle §agents extension) —
	 * mirrors {@see \OCA\Hermiq\Service\SkillBundleSerializer::AGENTS_PREFIX}.
	 * Without this, a bundle's `agents/*.json` files are never fetched from a
	 * remote repo at all — only `agentsFromBundle()`'s PARSING was added
	 * (skill-bundle-publish agents extension); the fetch-side accept filter
	 * was never taught about this prefix, so it silently collected zero agent
	 * files on every remote install regardless of how many the bundle declared.
	 *
	 * @var string
	 */
	public const BUNDLE_AGENTS_PREFIX = 'agents/';

	/**
	 * Maximum total bytes fetched for one bundle install (design.md §Security 3).
	 *
	 * @var int
	 */
	private const MAX_BUNDLE_BYTES = 16777216;

	/**
	 * Maximum blobs fetched under `skills/` for one bundle install — 64 skills
	 * (SkillBundleSerializer::MAX_SKILLS) times a generous per-skill file count.
	 *
	 * @var int
	 */
	private const MAX_SKILLS_BLOBS = 4096;

	/**
	 * The credential-broker service FQCN (resolved lazily; may be absent).
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The broker `appId` Hermiq identifies itself with.
	 *
	 * @var string
	 */
	private const APP_ID = 'hermiq';

	/**
	 * Cache namespace for search results.
	 *
	 * @var string
	 */
	private const CACHE_NS = 'hermiq_github_template_catalog';

	/**
	 * Search-result cache TTL (seconds).
	 *
	 * @var int
	 */
	private const SEARCH_TTL = 60;

	/**
	 * Connect + request timeout (seconds) for every anonymous call.
	 *
	 * @var int
	 */
	private const TIMEOUT = 10;

	/**
	 * Maximum number of search hits turned into cards (per-hit fetch cost).
	 *
	 * @var int
	 */
	private const MAX_HITS = 30;

	/**
	 * Safe owner/repo pattern (GitHub allows alnum, `-`, `_`, `.`).
	 *
	 * @var string
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Safe ref pattern (branch/tag/sha; no path traversal / spaces).
	 *
	 * @var string
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Outcome: the request succeeded.
	 *
	 * @var string
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: GitHub rate-limited and no cached result was available.
	 *
	 * @var string
	 */
	public const OUTCOME_RATE_LIMITED = 'github_rate_limited';

	/**
	 * The distributed cache, or null when no cache backend is available.
	 *
	 * @var ICache|null
	 */
	private readonly ?ICache $cache;

	/**
	 * Reader over the GitHub response bodies (no outbound calls).
	 *
	 * @var GitHubCatalogPayload
	 */
	private readonly GitHubCatalogPayload $payload;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory (anonymous calls).
	 * @param ICacheFactory $cacheFactory NC cache factory (short-TTL server cache).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics only).
	 * @param GitHubArchiveExtractor $archiveExtractor Unpacks an already-fetched repository tarball.
	 * @param ContainerInterface|null $container The app container the optional cross-app
	 *                                           classes are resolved from; null when the
	 *                                           class is built by hand in a test.
	 * @param ConnectionReporter|null $connectionReporter Tells integriq's connection registry what a
	 *                                                    live store search met; null when built by
	 *                                                    hand in a test.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly GitHubArchiveExtractor $archiveExtractor,
		private readonly ?ContainerInterface $container = null,
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
		$cache = null;
		if ($cacheFactory->isAvailable() === true) {
			$cache = $cacheFactory->createDistributed(self::CACHE_NS);
		}

		$this->cache = $cache;
		$this->payload = new GitHubCatalogPayload();
	}//end __construct()

	/**
	 * The container the optional OpenRegister credential broker is resolved from.
	 *
	 * The broker is another app's class, feature-detected by class-string, so it can
	 * never be a constructor type here. It used to come from `\OCP\Server::get()`,
	 * which outside a booted Nextcloud autowires from scratch and can recurse through
	 * a constructor cycle until memory runs out. The container is nullable because the
	 * unit tests build this service with its four real collaborators and never reach
	 * a brokered call; a null is the same dead end as the broker being absent, and the
	 * caller already falls back to the anonymous GET.
	 *
	 * @return ContainerInterface The injected container.
	 *
	 * @throws RuntimeException When the service was built without one.
	 */
	private function serviceContainer(): ContainerInterface {
		if ($this->container === null) {
			throw new RuntimeException(
				'Hermiq GitHub template catalog: this service was constructed without a container, '
				. 'so the OpenRegister credential broker cannot be resolved.'
			);
		}

		return $this->container;
	}//end serviceContainer()

	/**
	 * Whether the OpenRegister credential broker is present on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Search GitHub for a kind's discovery-topic repos and build cards.
	 *
	 * Hermiq-github-store: generalised with a `$kind` seam (default
	 * `KIND_AGENT_TEMPLATE`, unchanged from the original agent-template-only
	 * behaviour — every existing caller that omits `$kind` gets EXACTLY the prior
	 * result). `SkillController::githubSearch()` calls this with `KIND_SKILL`. The
	 * unified "Store" page issues one call per active kind filter and merges the
	 * kind-tagged cards client-side rather than this method searching both topics
	 * in one round trip — keeping each kind's search independently regression-safe.
	 *
	 * @param string|null $query Optional free-text term appended to the topic query.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
	 * @param string $kind The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function search(?string $query, ?string $actingUserId, ?string $credentialId = null, string $kind = self::KIND_AGENT_TEMPLATE): array {
		$search = new CatalogSearch(kind: $kind, caller: new CatalogCaller(actingUserId: $actingUserId, credentialId: $credentialId));

		return $this->searchCatalog(term: trim((string)$query), search: $search);
	}//end search()

	/**
	 * Answer a search from the cache, or from GitHub and cache the answer.
	 *
	 * @param string        $term   The trimmed free-text term, '' for none.
	 * @param CatalogSearch $search The search.
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 */
	private function searchCatalog(string $term, CatalogSearch $search): array {
		$cacheKey = $this->searchCacheKey(term: $term, search: $search);
		$cached = $this->cacheGet(key: $cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$result = $this->get(path: $this->searchPath(kind: $search->kind, term: $term), caller: $search->caller);
		// A live answer is the one moment hermiq meets GitHub; a cached one met nothing.
		$this->reportSearchOutcome(result: $result);
		if ($result['ok'] === false) {
			// Rate-limited/unreachable with no fresh result — surface a generic outcome,
			// never a 5xx (the caller — githubSearch() — always returns HTTP 200).
			return $this->payload->searchFailure(result: $result);
		}

		$payload = $this->searchAnswer(cards: $this->searchCards(result: $result, search: $search), result: $result);
		$this->cacheSet(key: $cacheKey, value: $payload);

		return $payload;
	}//end searchCatalog()

	/**
	 * The cache key of a search: its kind, its lower-cased term and its credential.
	 *
	 * @param string        $term   The trimmed free-text term.
	 * @param CatalogSearch $search The search.
	 *
	 * @return string The key.
	 */
	private function searchCacheKey(string $term, CatalogSearch $search): string {
		return 'search:' . $search->kind . ':' . md5(strtolower($term) . '|' . ((string)$search->caller->credentialId));
	}//end searchCacheKey()

	/**
	 * The cards built from a search answer's hits (a hit that is not a list entry is skipped).
	 *
	 * @param array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool} $result The GET result.
	 * @param CatalogSearch $search The search.
	 *
	 * @return array<int,array<string,mixed>> The cards.
	 */
	private function searchCards(array $result, CatalogSearch $search): array {
		$cards = [];
		foreach ($this->payload->searchItems(body: $result['body'], max: self::MAX_HITS) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$card = $this->buildCard(item: $item, search: $search);
			if ($card !== null) {
				$cards[] = $card;
			}
		}

		return $cards;
	}//end searchCards()

	/**
	 * The repository-search API path for a kind's topic, with the term appended when given.
	 *
	 * @param string $kind The discovery kind.
	 * @param string $term The trimmed free-text term, '' for none.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	private function searchPath(string $kind, string $term): string {
		$queryString = $this->topicFor(kind: $kind);
		if ($term !== '') {
			$queryString .= ' ' . $term;
		}

		return '/search/repositories?q=' . rawurlencode($queryString) . '&per_page=' . self::MAX_HITS;
	}//end searchPath()

	/**
	 * The answer of a search GitHub served: its cards and whether the broker carried it.
	 *
	 * @param array<int,array<string,mixed>> $cards The cards built from the hits.
	 * @param array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool} $result The GET result.
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	private function searchAnswer(array $cards, array $result): array {
		return [
			'outcome' => self::OUTCOME_OK,
			'cards' => $cards,
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => false,
		];
	}//end searchAnswer()

	/**
	 * Fetch a repo's portable template package file (raw JSON string, unparsed) —
	 * the caller (AgentTemplateController::githubInstall()) hands this verbatim to
	 * `AgentTemplateService::importPackage(source: 'hub')`, so no parsing happens
	 * here: the existing serializer/quarantine/scan path owns that entirely.
	 *
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The raw package JSON string, or null when missing/unreadable/invalid.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function fetchTemplateFile(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?string {
		return $this->fetchPackageFile(
			kind: self::KIND_AGENT_TEMPLATE,
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
	}//end fetchTemplateFile()

	/**
	 * Fetch a repo's portable package file for the given kind (raw string,
	 * unparsed) — the generalised form of `fetchTemplateFile()`
	 * (hermiq-github-store). The caller hands this verbatim to the matching
	 * quarantine/scan import path: `AgentTemplateService::importPackage(source:
	 * 'hub')` for `KIND_AGENT_TEMPLATE`, `SkillMarketplaceService::
	 * installFromSource(source: 'hub')` for `KIND_SKILL`. No parsing happens here
	 * beyond that existing serializer/quarantine/scan path.
	 *
	 * @param string $kind The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The raw package string, or null when missing/unreadable/invalid.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function fetchPackageFile(
		string $kind,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?string {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return null;
		}

		$fetch = new CatalogFetch(owner: $owner, repo: $repo);
		$fetch->ref = $ref;
		$fetch->actingUserId = $actingUserId;
		$fetch->credentialId = $credentialId;

		return $this->fetchFileContents(fetch: $fetch, path: $this->packageFileFor(kind: $kind));
	}//end fetchPackageFile()

	/**
	 * Fetch a published skill repo's AUXILIARY files — every blob in the repo tree
	 * except the package file itself (skill-package-multifile).
	 *
	 * `publish()` commits auxiliary files as sibling blobs at their own (possibly
	 * nested) paths, but `fetchPackageFile()` only ever retrieved the single package
	 * file. Installing from GitHub therefore reconstructed a bare SKILL.md and
	 * silently dropped every `references/`, `examples/` and `learnings.md` entry — a
	 * lossy round trip through the very path the app-repo store depends on.
	 *
	 * Bounded on purpose: at most MAX_AUX_FILES blobs and MAX_AUX_BYTES total, so a
	 * hostile or accidentally enormous repository cannot turn one install into an
	 * unbounded fan-out of API calls. Truncation is logged rather than silent.
	 *
	 * @param string $kind The publish kind (KIND_SKILL|KIND_AGENT_TEMPLATE).
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<int, array{name: string, content: string}> The auxiliary files.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function fetchAuxFiles(
		string $kind,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): array {
		$packageFile = $this->packageFileFor(kind: $kind);
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return [];
		}

		$fetch = new CatalogFetch(owner: $owner, repo: $repo);
		$fetch->ref = $ref;
		$fetch->actingUserId = $actingUserId;
		$fetch->credentialId = $credentialId;
		$fetch->maxFiles = self::MAX_AUX_FILES;
		$fetch->maxBytes = self::MAX_AUX_BYTES;
		$this->collectTreeBlobs(
			fetch: $fetch,
			accept: static function (string $path) use ($packageFile): bool {
				return ($path !== $packageFile);
			}
		);

		return $this->auxFilesOf(fetch: $fetch);
	}//end fetchAuxFiles()

	/**
	 * The collected auxiliary blobs as `{name, content}` entries, logging when the
	 * collection stopped at a bound.
	 *
	 * @param CatalogFetch $fetch The read, collected.
	 *
	 * @return array<int, array{name: string, content: string}> The auxiliary files.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	private function auxFilesOf(CatalogFetch $fetch): array {
		if ($fetch->truncated === true) {
			$this->logger->warning(
				'Hermiq skill install: auxiliary file set truncated — the installed skill is INCOMPLETE.',
				['owner' => $fetch->owner, 'repo' => $fetch->repo, 'limit' => self::MAX_AUX_FILES]
			);
		}

		return array_map(
			static fn (string $path, string $contents): array => ['name' => $path, 'content' => $contents],
			array_keys($fetch->files),
			$fetch->files
		);
	}//end auxFilesOf()

	/**
	 * Walk a repository tree ONCE and fetch the blobs an `accept` predicate keeps.
	 *
	 * Extracted because fetchAuxFiles() and fetchBundle() were the same tree-walk
	 * with different filters. Two copies of the bounds, the blob loop and the
	 * truncation accounting is two places for those to drift apart — and the
	 * bounds are a resource-amplification guard, so drift there matters.
	 *
	 * The bounds are the read's `maxFiles`/`maxBytes`; the blobs read, their bytes
	 * and the truncation are recorded on the read itself.
	 *
	 * @param CatalogFetch $fetch The read, with its bounds set.
	 * @param callable $accept Predicate deciding whether a blob path is wanted.
	 *
	 * @return void
	 */
	private function collectTreeBlobs(CatalogFetch $fetch, callable $accept): void {
		$tree = $this->fetchTree(fetch: $fetch);
		if ($tree === null) {
			return;
		}

		foreach ($this->payload->wantedBlobPaths(tree: $tree, accept: $accept) as $path) {
			$this->collectBlob(fetch: $fetch, path: $path);
		}
	}//end collectTreeBlobs()

	/**
	 * Read one wanted blob into the read, or mark the read truncated once a bound is reached.
	 *
	 * A blob that cannot be read is skipped and counts against neither bound.
	 *
	 * @param CatalogFetch $fetch The read in progress.
	 * @param string $path The blob path.
	 *
	 * @return void
	 */
	private function collectBlob(CatalogFetch $fetch, string $path): void {
		if ($fetch->isFull() === true) {
			$fetch->truncated = true;
			return;
		}

		$contents = $this->fetchFileContents(fetch: $fetch, path: $path);
		if ($contents === null) {
			return;
		}

		$fetch->bytes += strlen($contents);
		$fetch->files[$path] = $contents;
	}//end collectBlob()

	/**
	 * Collect blobs under a prefix via ONE archive download instead of one HTTP
	 * call per file.
	 *
	 * `collectTreeBlobs()` fetches the tree in one call but then GitHub's
	 * Contents API one file at a time — for a 94-skill bundle that is ~750
	 * individual broker-mediated round trips. Live-verified: this burns through
	 * a PAT's rate limit fast enough that a single large bundle install can
	 * fail partway with a 403, and the caller (which treats a failed fetch as
	 * "file absent" rather than "fetch failed") reports a clean but wrong
	 * `0 installed` rather than surfacing the real cause. GitHub's tarball
	 * endpoint (`GET /repos/{owner}/{repo}/tarball/{ref}`) returns the ENTIRE
	 * repository content in one request regardless of file count — this is the
	 * `/repos/*` GET rule already granted to the `github` provider (no new
	 * broker allow-rule needed), and the 303 it returns carries a
	 * pre-authorised `codeload.github.com` URL in its own query string, which
	 * Guzzle follows by default.
	 *
	 * Best-effort: returns null on ANY failure (fetch, non-2xx, empty body,
	 * extraction) so the caller can fall back to {@see collectTreeBlobs()}
	 * rather than fail the whole install over an optimisation.
	 *
	 * This method owns only the FETCH — the one part that is an outbound call and
	 * therefore subject to this class's fixed-host invariant. Unpacking the bytes
	 * makes no outbound call, so it lives in {@see GitHubArchiveExtractor}.
	 *
	 * @param CatalogFetch $fetch The read.
	 * @param callable $accept Predicate deciding whether an archive entry's path (with the
	 *                         archive's own `{owner}-{repo}-{sha}/` root stripped) is wanted.
	 *
	 * @return array<string,string>|null The `path => contents` map, or null when unavailable —
	 *                                    never partial: an extraction that fails partway is
	 *                                    discarded whole, so the caller's fallback is a clean
	 *                                    do-over, not a merge of two partial sets.
	 */
	private function fetchArchiveBlobs(CatalogFetch $fetch, callable $accept): ?array {
		$treeRef = ($fetch->ref ?? 'HEAD');
		$result = $this->fetchGet(fetch: $fetch, path: '/tarball/' . rawurlencode($treeRef));
		if ($result['ok'] === false) {
			return null;
		}

		return $this->archiveExtractor->extract(
			body: $result['body'],
			accept: $accept,
			maxBytes: self::MAX_BUNDLE_BYTES
		);
	}//end fetchArchiveBlobs()

	/**
	 * Fetch a repository's recursive git tree.
	 *
	 * Every caller has validated the read's coordinates before it got here.
	 *
	 * @param CatalogFetch $fetch The read.
	 *
	 * @return array<int,mixed>|null The tree entries, or null when unreachable.
	 */
	private function fetchTree(CatalogFetch $fetch): ?array {
		$treeRef = ($fetch->ref ?? '');
		if ($treeRef === '') {
			$treeRef = 'HEAD';
		}

		$result = $this->fetchGet(fetch: $fetch, path: '/git/trees/' . rawurlencode($treeRef) . '?recursive=1');
		if ($result['ok'] === false) {
			return null;
		}

		return $this->treeEntries(body: $result['body']);
	}//end fetchTree()

	/**
	 * The entries of a git-tree answer, or null when it holds no tree list.
	 *
	 * @param string $body The answer body.
	 *
	 * @return array<int,mixed>|null The tree entries.
	 */
	private function treeEntries(string $body): ?array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || is_array($decoded['tree'] ?? null) === false) {
			return null;
		}

		return $decoded['tree'];
	}//end treeEntries()

	/**
	 * Fetch a BUNDLE repository as a `path => contents` map (skill-bundle-publish).
	 *
	 * A bundle is identified by `hermiq-skills.json` at the repo root. Its ABSENCE
	 * is a definitive "not a bundle" (null), never a best-effort parse — a repo
	 * that half-reads as a bundle is worse than one that refuses, because the
	 * caller would install a partial skill set believing it complete.
	 *
	 * Only the manifest and blobs under `skills/` are fetched; anything else in
	 * the repository is ignored, so a bundle can live alongside other content.
	 * Bounded by MAX_BUNDLE_BYTES with truncation reported rather than silent.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array{files:array<string,string>,truncated:bool}|null The bundle tree,
	 *                                                               or null when the repo is not a bundle / unreachable.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function fetchBundle(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?array {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return null;
		}

		$fetch = new CatalogFetch(owner: $owner, repo: $repo);
		$fetch->ref = $ref;
		$fetch->actingUserId = $actingUserId;
		$fetch->credentialId = $credentialId;

		// Only the manifest, plus blobs under `skills/` or `agents/`, are
		// wanted; anything else in the repository is ignored, so a bundle can
		// live alongside unrelated content.
		$accept = static function (string $path): bool {
			return $path === self::BUNDLE_MANIFEST_FILE
				|| str_starts_with($path, self::BUNDLE_SKILLS_PREFIX)
				|| str_starts_with($path, self::BUNDLE_AGENTS_PREFIX);
		};

		// ONE archive download beats one HTTP call per file — for a 94-skill
		// bundle that is ~750 fewer broker round trips (previously ~1 for the
		// manifest, ~750 more for skills, now 1 total). Live-verified to
		// matter: per-file fetching burns through a PAT's request budget fast
		// enough that a single large bundle install can 403 partway through
		// (GitHub's secondary rate limit / abuse detection on the Contents API
		// specifically — triggered independently of the primary 5000/hour
		// quota by request PATTERN, not just count). Tried first, covering the
		// manifest fetch too, so a bundle install never has to touch the
		// per-file endpoint at all in the common case.
		$archiveFiles = $this->fetchArchiveBlobs(fetch: $fetch, accept: $accept);

		if ($archiveFiles !== null && isset($archiveFiles[self::BUNDLE_MANIFEST_FILE]) === true) {
			return ['files' => $archiveFiles, 'truncated' => false];
		}

		// Fall back to the proven per-file path — best-effort: any archive
		// failure (network, extraction, unexpected shape, or a bundle whose
		// manifest the archive filter somehow missed) degrades gracefully
		// rather than failing the install outright.
		return $this->fetchBundlePerFile(fetch: $fetch);
	}//end fetchBundle()

	/**
	 * Fetch a bundle file by file: the manifest first, then every blob under
	 * `skills/` or `agents/`, bounded by MAX_BUNDLE_BYTES with truncation reported.
	 *
	 * @param CatalogFetch $fetch The read: the validated repository and the caller's broker identity.
	 *
	 * @return array{files:array<string,string>,truncated:bool}|null The bundle tree,
	 *                                                               or null when the repo has no readable manifest.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function fetchBundlePerFile(CatalogFetch $fetch): ?array {
		$manifest = $this->fetchFileContents(fetch: $fetch, path: self::BUNDLE_MANIFEST_FILE);
		if ($manifest === null) {
			return null;
		}

		// The manifest's own bytes count against the bound because it is part
		// of what was fetched.
		$fetch->maxFiles = self::MAX_SKILLS_BLOBS;
		$fetch->maxBytes = (self::MAX_BUNDLE_BYTES - strlen($manifest));
		$this->collectTreeBlobs(
			fetch: $fetch,
			accept: static function (string $path): bool {
				return str_starts_with($path, self::BUNDLE_SKILLS_PREFIX)
					|| str_starts_with($path, self::BUNDLE_AGENTS_PREFIX);
			}
		);

		if ($fetch->truncated === true) {
			$this->logger->warning(
				'Hermiq bundle fetch: truncated at the bound — the installed set is INCOMPLETE.',
				['owner' => $fetch->owner, 'repo' => $fetch->repo, 'limitBytes' => self::MAX_BUNDLE_BYTES]
			);
		}

		return [
			'files' => array_merge([self::BUNDLE_MANIFEST_FILE => $manifest], $fetch->files),
			'truncated' => $fetch->truncated,
		];
	}//end fetchBundlePerFile()

	/**
	 * Build a card from a search hit's package file (non-installable when the
	 * file is missing/unparseable — the hit is surfaced, never dropped). Every
	 * card is tagged with its `kind` (hermiq-github-store).
	 *
	 * @param array<string,mixed> $item   A GitHub search-result item.
	 * @param CatalogSearch       $search The search: its kind and caller.
	 *
	 * @return array<string,mixed>|null The card, or null when the item lacks an owner/name.
	 */
	private function buildCard(array $item, CatalogSearch $search): ?array {
		$coordinates = $this->payload->hitCoordinates(item: $item);
		if ($coordinates === null) {
			return null;
		}

		$contents = $this->hitPackageFile(coordinates: $coordinates, search: $search);

		$base = $coordinates + [
			'stars' => (int)($item['stargazers_count'] ?? 0),
			'kind' => $search->kind,
		];

		return $this->payload->card(base: $base, contents: $contents);
	}//end buildCard()

	/**
	 * A search hit's package file, read for the search's caller at the default branch.
	 *
	 * @param array{owner:string,repo:string} $coordinates The hit's repository.
	 * @param CatalogSearch                   $search      The search.
	 *
	 * @return string|null The raw package string, or null when missing/unreadable/invalid.
	 */
	private function hitPackageFile(array $coordinates, CatalogSearch $search): ?string {
		return $this->fetchPackageFile(
			kind: $search->kind,
			owner: $coordinates['owner'],
			repo: $coordinates['repo'],
			ref: null,
			actingUserId: $search->caller->actingUserId,
			credentialId: $search->caller->credentialId
		);
	}//end hitPackageFile()

	/**
	 * Fetch a single file's decoded contents via the GitHub contents API.
	 *
	 * @param CatalogFetch $fetch The read: repository, ref and caller.
	 * @param string $path Repo-relative file path.
	 *
	 * @return string|null The decoded file contents, or null when absent/unreadable.
	 */
	private function fetchFileContents(CatalogFetch $fetch, string $path): ?string {
		$apiPath = $this->contentsPath(fetch: $fetch, path: $path);

		$result = $this->get(path: $apiPath, caller: $this->callerOf(fetch: $fetch));
		if ($result['ok'] === false) {
			// Rate-limit exhaustion specifically flagged: a failed per-file fetch
			// silently reads to a caller as "file absent", not "fetch failed" —
			// live-verified this is how a large bundle install came back a clean
			// but wrong `0 installed` instead of surfacing the real 403. Logged
			// here, at the one place that knows the real HTTP status, since
			// nothing downstream can tell the difference once it sees null.
			// `rateLimited` and `status` are declared members of get()'s return
			// shape and are always present — a `??` fallback here reads as a
			// guard against a key that cannot be absent, which is why phpstan
			// rejects it.
			if ($result['rateLimited'] === true) {
				$this->logger->warning(
					'Hermiq GitHub template catalog: rate-limited fetching ' . $apiPath . ' — result will read as "file absent", not "fetch failed".',
					['status' => $result['status']]
				);
			}

			return null;
		}

		return $this->payload->fileContents(body: $result['body']);
	}//end fetchFileContents()

	/**
	 * The contents-API path of a repo-relative file at the read's ref.
	 *
	 * @param CatalogFetch $fetch The read: repository and ref.
	 * @param string       $path  Repo-relative file path.
	 *
	 * @return string The API path.
	 */
	private function contentsPath(CatalogFetch $fetch, string $path): string {
		$apiPath = $fetch->apiPath() . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)));
		if ($fetch->ref !== null && $fetch->ref !== '') {
			$apiPath .= '?ref=' . rawurlencode($fetch->ref);
		}

		return $apiPath;
	}//end contentsPath()

	/**
	 * Tell integriq what a live store search met, at most once an hour per status.
	 *
	 * Throttled, because a search runs whenever someone opens the store
	 * (adopt-connection-registry design D4). A rate limit reads Limited: the
	 * store still works from cache and with a credential.
	 *
	 * @param array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool} $result The GET result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	private function reportSearchOutcome(array $result): void {
		if ($this->connectionReporter === null) {
			return;
		}

		$status  = 'error';
		$message = 'GitHub could not be reached on the last store search.';
		if ($result['ok'] === true) {
			$status  = 'configured';
			$message = 'GitHub answered the last store search without a credential. Anonymous calls get a low rate limit.';
			if ($result['brokerUsed'] === true) {
				$message = 'GitHub answered the last store search with a credential.';
			}
		} else if ($result['rateLimited'] === true) {
			$status  = 'limited';
			$message = 'GitHub rate-limited the last store search. Add a GitHub credential under Organisation credentials to raise the limit.';
		} else if ($result['status'] > 0) {
			$message = 'GitHub answered HTTP ' . $result['status'] . ' on the last store search.';
		}

		$this->connectionReporter->reportThrottled(key: 'github-templates', status: $status, message: $message);
	}//end reportSearchOutcome()

	/**
	 * Perform a GET — via the broker when a credential is supplied and the broker
	 * admits the call, else anonymously (feature-detect + fall back).
	 *
	 * @param string        $path   The GitHub-relative path (starts with `/`).
	 * @param CatalogCaller $caller The session UID (broker owner-guard identity) and
	 *                              the optional allowed `github` credential.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function get(string $path, CatalogCaller $caller): array {
		if ($caller->hasCredential() === true && $this->isBrokerAvailable() === true) {
			$brokered = $this->brokerGet(path: $path, caller: $caller);
			if ($brokered !== null) {
				return $brokered;
			}

			// Broker denied / rules missing — fall through to anonymous.
		}

		return $this->anonymousGet(path: $path);
	}//end get()

	/**
	 * A GET of a path under the read's repository, for the read's caller.
	 *
	 * @param CatalogFetch $fetch The read.
	 * @param string       $path  The path below `/repos/<owner>/<repo>`.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function fetchGet(CatalogFetch $fetch, string $path): array {
		return $this->get(path: $fetch->apiPath() . $path, caller: $this->callerOf(fetch: $fetch));
	}//end fetchGet()

	/**
	 * The caller a read was assigned.
	 *
	 * @param CatalogFetch $fetch The read.
	 *
	 * @return CatalogCaller
	 */
	private function callerOf(CatalogFetch $fetch): CatalogCaller {
		return new CatalogCaller(actingUserId: $fetch->actingUserId, credentialId: $fetch->credentialId);
	}//end callerOf()

	/**
	 * Route a GET through the OpenRegister credential broker.
	 *
	 * @param string        $path   The GitHub-relative path.
	 * @param CatalogCaller $caller The `github` credential and the session UID for the
	 *                              broker owner guard.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}|null Null when the broker
	 *                                                                                     denies the call (caller falls back to anonymous).
	 */
	private function brokerGet(string $path, CatalogCaller $caller): ?array {
		try {
			$response = $this->brokerRequest(path: $path, caller: $caller);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq GitHub template catalog: broker call not admitted, falling back to anonymous.');
			return null;
		}

		return $this->brokerResult(response: $response);
	}//end brokerGet()

	/**
	 * The GET result of a broker answer.
	 *
	 * @param mixed $response The broker's answer (`status`, `body`).
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function brokerResult(mixed $response): array {
		$status = (int)($response['status'] ?? 0);

		return [
			'ok' => ($status >= 200 && $status < 300),
			'status' => $status,
			'body' => (string)($response['body'] ?? ''),
			'rateLimited' => ($status === 403 || $status === 429),
			'brokerUsed' => true,
		];
	}//end brokerResult()

	/**
	 * Ask the broker to make the GET with the caller's credential, as hermiq.
	 *
	 * @param string        $path   The GitHub-relative path.
	 * @param CatalogCaller $caller The credential and the acting user.
	 *
	 * @return mixed The broker's answer (`status`, `body`).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The optional OpenRegister broker is named by
	 *   class-string and resolved through the injected container, so this class stays
	 *   constructible when the broker is absent (feature-detected via class_exists).
	 */
	private function brokerRequest(string $path, CatalogCaller $caller): mixed {
		$broker = $this->serviceContainer()->get(self::BROKER_CLASS);

		return $broker->request(
			(string)$caller->credentialId,
			self::APP_ID,
			'GET',
			$path,
			['Accept' => 'application/vnd.github+json'],
			null,
			$caller->actingUserId
		);
	}//end brokerRequest()

	/**
	 * Perform an anonymous GET against the fixed GitHub host.
	 *
	 * @param string $path The GitHub-relative path.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function anonymousGet(string $path): array {
		try {
			$response = $this->clientService->newClient()->get(
				self::API_BASE . $path,
				[
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'headers' => [
						'Accept' => 'application/vnd.github+json',
						'User-Agent' => 'Hermiq-Agent-Template-Store',
					],
				]
			);
		} catch (Throwable $e) {
			$rateLimited = (str_contains($e->getMessage(), '403') === true || str_contains($e->getMessage(), '429') === true);
			return ['ok' => false, 'status' => 0, 'body' => '', 'rateLimited' => $rateLimited, 'brokerUsed' => false];
		}

		$status = $response->getStatusCode();

		return [
			'ok' => ($status >= 200 && $status < 300),
			'status' => $status,
			'body' => (string)$response->getBody(),
			'rateLimited' => ($status === 403 || $status === 429),
			'brokerUsed' => false,
		];
	}//end anonymousGet()

	/**
	 * Validate owner/repo/ref against safe patterns before path interpolation.
	 *
	 * @param string $owner The repo owner.
	 * @param string $repo The repo name.
	 * @param string|null $ref Optional git ref.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function validRepo(string $owner, string $repo, ?string $ref): bool {
		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return false;
		}

		if ($ref !== null && $ref !== '' && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return false;
		}

		return true;
	}//end validRepo()

	/**
	 * Resolve a kind's discovery topic (hermiq-github-store), falling back to
	 * `KIND_AGENT_TEMPLATE`'s topic for an unrecognised kind.
	 *
	 * @param string $kind The discovery kind.
	 *
	 * @return string The `topic:…` search-qualifier string.
	 */
	private function topicFor(string $kind): string {
		return self::DISCOVERY_TOPICS[$kind] ?? self::DISCOVERY_TOPICS[self::KIND_AGENT_TEMPLATE];
	}//end topicFor()

	/**
	 * Resolve a kind's repo-root package file name (hermiq-github-store),
	 * falling back to `KIND_AGENT_TEMPLATE`'s file name for an unrecognised kind.
	 *
	 * @param string $kind The discovery kind.
	 *
	 * @return string The repo-relative package file name.
	 */
	private function packageFileFor(string $kind): string {
		return self::PACKAGE_FILES[$kind] ?? self::PACKAGE_FILES[self::KIND_AGENT_TEMPLATE];
	}//end packageFileFor()

	/**
	 * Read a value from the short-TTL cache (no-op when no cache backend).
	 *
	 * @param string $key The cache key.
	 *
	 * @return mixed The cached value, or null on a miss / no cache.
	 */
	private function cacheGet(string $key): mixed {
		if ($this->cache === null) {
			return null;
		}

		return $this->cache->get($key);
	}//end cacheGet()

	/**
	 * Write a search answer to the short-TTL cache for SEARCH_TTL seconds (no-op
	 * when no cache backend).
	 *
	 * @param string $key The cache key.
	 * @param mixed $value The value to cache.
	 *
	 * @return void
	 */
	private function cacheSet(string $key, mixed $value): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, self::SEARCH_TTL);
	}//end cacheSet()
}//end class
