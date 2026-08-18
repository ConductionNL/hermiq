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
 * lazily (`class_exists` + `Server::get`, mirroring BrokerHttpClient/
 * WebSearchClient) so a missing/older OpenRegister falls back to anonymous
 * cleanly. Search results are cached short-TTL against the tight anonymous
 * rate limit; the raw GitHub body and any token are never returned or logged.
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

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;
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
class GitHubTemplateCatalogService {
	/**
	 * The fixed GitHub API host — compile-time constant, no SSRF surface.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * The `AgentTemplate` kind — the original (and default) discovery kind.
	 *
	 * @var string
	 */
	public const KIND_AGENT_TEMPLATE = 'agent-template';

	/**
	 * The `Skill` kind (hermiq-github-store) — agentskills.io packages.
	 *
	 * @var string
	 */
	public const KIND_SKILL = 'skill';

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
	 * Outcome: transport failure / non-2xx that is not a rate limit.
	 *
	 * @var string
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * The distributed cache, or null when no cache backend is available.
	 *
	 * @var ICache|null
	 */
	private readonly ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory (anonymous calls).
	 * @param ICacheFactory $cacheFactory NC cache factory (short-TTL server cache).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics only).
	 * @param GitHubArchiveExtractor $archiveExtractor Unpacks an already-fetched repository tarball.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly GitHubArchiveExtractor $archiveExtractor,
	) {
		$cache = null;
		if ($cacheFactory->isAvailable() === true) {
			$cache = $cacheFactory->createDistributed(self::CACHE_NS);
		}

		$this->cache = $cache;
	}//end __construct()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Cache hit, rate-limit/unreachable
	 *   degradation and card filtering are the spec's own outcome branches, kept in
	 *   one linear search path.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	public function search(?string $query, ?string $actingUserId, ?string $credentialId = null, string $kind = self::KIND_AGENT_TEMPLATE): array {
		$term = trim((string)$query);
		$normalise = strtolower($term);
		$cacheKey = 'search:' . $kind . ':' . md5($normalise . '|' . ((string)$credentialId));

		$cached = $this->cacheGet(key: $cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$queryString = $this->topicFor(kind: $kind);
		if ($term !== '') {
			$queryString .= ' ' . $term;
		}

		$path = '/search/repositories?q=' . rawurlencode($queryString) . '&per_page=' . self::MAX_HITS;

		$result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
		if ($result['ok'] === false) {
			// Rate-limited/unreachable with no fresh result — surface a generic outcome,
			// never a 5xx (the caller — githubSearch() — always returns HTTP 200).
			$failure = self::OUTCOME_UNREACHABLE;
			if ($result['rateLimited'] === true) {
				$failure = self::OUTCOME_RATE_LIMITED;
			}

			return [
				'outcome' => $failure,
				'cards' => [],
				'brokerUsed' => $result['brokerUsed'],
				'rateLimited' => $result['rateLimited'],
			];
		}

		$decoded = json_decode($result['body'], true);
		$items = [];
		if (is_array($decoded) === true && is_array($decoded['items'] ?? null) === true) {
			$items = $decoded['items'];
		}

		$cards = [];
		foreach (array_slice($items, 0, self::MAX_HITS) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$card = $this->buildCard(item: $item, kind: $kind, actingUserId: $actingUserId, credentialId: $credentialId);
			if ($card !== null) {
				$cards[] = $card;
			}
		}

		$payload = [
			'outcome' => self::OUTCOME_OK,
			'cards' => $cards,
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => false,
		];
		$this->cacheSet(key: $cacheKey, value: $payload, ttl: self::SEARCH_TTL);

		return $payload;
	}//end search()

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

		return $this->fetchFileContents(
			owner: $owner,
			repo: $repo,
			path: $this->packageFileFor(kind: $kind),
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
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
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
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

		$collected = $this->collectTreeBlobs(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			accept: static function (string $path) use ($packageFile): bool {
				return ($path !== $packageFile);
			},
			maxFiles: self::MAX_AUX_FILES,
			maxBytes: self::MAX_AUX_BYTES
		);

		if ($collected['truncated'] === true) {
			$this->logger->warning(
				'Hermiq skill install: auxiliary file set truncated — the installed skill is INCOMPLETE.',
				['owner' => $owner, 'repo' => $repo, 'limit' => self::MAX_AUX_FILES]
			);
		}

		$files = [];
		foreach ($collected['files'] as $path => $contents) {
			$files[] = [
				'name' => $path,
				'content' => $contents,
			];
		}

		return $files;
	}//end fetchAuxFiles()

	/**
	 * Walk a repository tree ONCE and fetch the blobs an `accept` predicate keeps.
	 *
	 * Extracted because fetchAuxFiles() and fetchBundle() were the same tree-walk
	 * with different filters. Two copies of the bounds, the blob loop and the
	 * truncation accounting is two places for those to drift apart — and the
	 * bounds are a resource-amplification guard, so drift there matters.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 * @param callable $accept Predicate deciding whether a blob path is wanted.
	 * @param int $maxFiles Maximum blobs to fetch.
	 * @param int $maxBytes Maximum total bytes to fetch.
	 *
	 * @return array{files:array<string,string>,truncated:bool} The collected blobs.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Repo coordinates + broker identity
	 *   + the two bounds; each is a distinct input, not a logic-bearing argument list.
	 */
	private function collectTreeBlobs(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
		callable $accept,
		int $maxFiles,
		int $maxBytes,
	): array {
		$empty = ['files' => [], 'truncated' => false];

		$tree = $this->fetchTree(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($tree === null) {
			return $empty;
		}

		$files = [];
		$bytes = 0;
		$truncated = false;

		foreach ($tree as $entry) {
			$path = $this->blobPathOf(entry: $entry);
			if ($path === null || $accept($path) === false) {
				continue;
			}

			if (count($files) >= $maxFiles || $bytes >= $maxBytes) {
				$truncated = true;
				continue;
			}

			$contents = $this->fetchFileContents(
				owner: $owner,
				repo: $repo,
				path: $path,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);
			if ($contents === null) {
				continue;
			}

			$bytes += strlen($contents);
			$files[$path] = $contents;
		}//end foreach

		return ['files' => $files, 'truncated' => $truncated];
	}//end collectTreeBlobs()

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
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 * @param callable $accept Predicate deciding whether an archive entry's path (with the
	 *                         archive's own `{owner}-{repo}-{sha}/` root stripped) is wanted.
	 *
	 * @return array<string,string>|null The `path => contents` map, or null when unavailable —
	 *                                    never partial: an extraction that fails partway is
	 *                                    discarded whole, so the caller's fallback is a clean
	 *                                    do-over, not a merge of two partial sets.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Repo coordinates + broker identity + the filter predicate; each is a distinct input.
	 */
	private function fetchArchiveBlobs(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
		callable $accept,
	): ?array {
		$treeRef = ($ref ?? 'HEAD');
		$result = $this->get(
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/tarball/' . rawurlencode($treeRef),
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
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
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID, or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<int,mixed>|null The tree entries, or null when unreachable.
	 */
	private function fetchTree(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
	): ?array {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return null;
		}

		$treeRef = ($ref ?? '');
		if ($treeRef === '') {
			$treeRef = 'HEAD';
		}

		$result = $this->get(
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
				. '/git/trees/' . rawurlencode($treeRef) . '?recursive=1',
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($result['ok'] === false) {
			return null;
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false || is_array($decoded['tree'] ?? null) === false) {
			return null;
		}

		return $decoded['tree'];
	}//end fetchTree()

	/**
	 * The path of a tree entry when it is a usable blob, else null.
	 *
	 * @param mixed $entry A raw git-tree entry.
	 *
	 * @return string|null The blob path, or null when the entry is not a blob.
	 */
	private function blobPathOf(mixed $entry): ?string {
		if (is_array($entry) === false || (string)($entry['type'] ?? '') !== 'blob') {
			return null;
		}

		$path = (string)($entry['path'] ?? '');
		if ($path === '') {
			return null;
		}

		return $path;
	}//end blobPathOf()

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
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
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
		$archiveFiles = $this->fetchArchiveBlobs(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			accept: $accept
		);

		if ($archiveFiles !== null && isset($archiveFiles[self::BUNDLE_MANIFEST_FILE]) === true) {
			return ['files' => $archiveFiles, 'truncated' => false];
		}

		// Fall back to the proven per-file path — best-effort: any archive
		// failure (network, extraction, unexpected shape, or a bundle whose
		// manifest the archive filter somehow missed) degrades gracefully
		// rather than failing the install outright.
		$manifest = $this->fetchFileContents(
			owner: $owner,
			repo: $repo,
			path: self::BUNDLE_MANIFEST_FILE,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($manifest === null) {
			return null;
		}

		// The manifest's own bytes count against the bound because it is part
		// of what was fetched.
		$collected = $this->collectTreeBlobs(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			accept: static function (string $path): bool {
				return str_starts_with($path, self::BUNDLE_SKILLS_PREFIX)
					|| str_starts_with($path, self::BUNDLE_AGENTS_PREFIX);
			},
			maxFiles: self::MAX_SKILLS_BLOBS,
			maxBytes: (self::MAX_BUNDLE_BYTES - strlen($manifest))
		);

		if ($collected['truncated'] === true) {
			$this->logger->warning(
				'Hermiq bundle fetch: truncated at the bound — the installed set is INCOMPLETE.',
				['owner' => $owner, 'repo' => $repo, 'limitBytes' => self::MAX_BUNDLE_BYTES]
			);
		}

		return [
			'files' => array_merge([self::BUNDLE_MANIFEST_FILE => $manifest], $collected['files']),
			'truncated' => $collected['truncated'],
		];

	}//end fetchBundle()

	/**
	 * Build a card from a search hit's package file (non-installable when the
	 * file is missing/unparseable — the hit is surfaced, never dropped). Every
	 * card is tagged with its `kind` (hermiq-github-store).
	 *
	 * @param array<string,mixed> $item A GitHub search-result item.
	 * @param string $kind The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<string,mixed>|null The card, or null when the item lacks an owner/name.
	 */
	private function buildCard(array $item, string $kind, ?string $actingUserId, ?string $credentialId): ?array {
		$fullName = (string)($item['full_name'] ?? '');
		$nameParts = explode('/', $fullName);
		$owner = (string)($item['owner']['login'] ?? '');
		if ($owner === '') {
			$owner = (string)($nameParts[0] ?? '');
		}

		$repo = (string)($item['name'] ?? '');
		if ($repo === '') {
			$repo = (string)($nameParts[1] ?? '');
		}

		if ($owner === '' || $repo === '') {
			return null;
		}

		$stars = (int)($item['stargazers_count'] ?? 0);
		$contents = $this->fetchPackageFile(
			kind: $kind,
			owner: $owner,
			repo: $repo,
			ref: null,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);

		if ($contents === null) {
			return [
				'owner' => $owner,
				'repo' => $repo,
				'stars' => $stars,
				'kind' => $kind,
				'installable' => false,
				'unparseable' => true,
			];
		}

		if ($kind === self::KIND_SKILL) {
			// Agentskills.io packages (SkillSerializer::fromPackage()) never fail to
			// parse — a missing fence just yields an empty frontmatter/name, so the
			// only "unparseable" case for a skill is the fetch failure handled above.
			// SkillSerializer is stateless (no constructor deps) — instantiated
			// locally rather than injected, so this service's constructor stays
			// unchanged (regression-safe for every existing test that constructs it
			// positionally).
			$parsed = (new SkillSerializer())->fromPackage(package: $contents);
			$name = $parsed['name'];
			if ($name === '') {
				$name = $repo;
			}

			return [
				'owner' => $owner,
				'repo' => $repo,
				'stars' => $stars,
				'kind' => $kind,
				'installable' => true,
				'unparseable' => false,
				'name' => $name,
				'description' => $parsed['description'],
				'category' => '',
				'version' => '',
			];
		}//end if

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false) {
			return [
				'owner' => $owner,
				'repo' => $repo,
				'stars' => $stars,
				'kind' => $kind,
				'installable' => false,
				'unparseable' => true,
			];
		}

		return [
			'owner' => $owner,
			'repo' => $repo,
			'stars' => $stars,
			'kind' => $kind,
			'installable' => true,
			'unparseable' => false,
			'name' => (string)($decoded['name'] ?? $repo),
			'description' => (string)($decoded['description'] ?? ''),
			'category' => (string)($decoded['category'] ?? ''),
			'version' => (string)($decoded['version'] ?? ''),
		];
	}//end buildCard()

	/**
	 * Fetch a single file's decoded contents via the GitHub contents API.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string $path Repo-relative file path.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The decoded file contents, or null when absent/unreadable.
	 */
	private function fetchFileContents(
		string $owner,
		string $repo,
		string $path,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
	): ?string {
		$apiPath = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/'
			. implode('/', array_map('rawurlencode', explode('/', $path)));
		if ($ref !== null && $ref !== '') {
			$apiPath .= '?ref=' . rawurlencode($ref);
		}

		$result = $this->get(path: $apiPath, actingUserId: $actingUserId, credentialId: $credentialId);
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

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false) {
			return null;
		}

		$content = (string)($decoded['content'] ?? '');
		$encoding = (string)($decoded['encoding'] ?? '');
		if ($encoding === 'base64') {
			$raw = base64_decode(str_replace("\n", '', $content), true);
			if ($raw === false) {
				return null;
			}

			return $raw;
		}

		if ($content === '') {
			return null;
		}

		return $content;
	}//end fetchFileContents()

	/**
	 * Perform a GET — via the broker when a credential is supplied and the broker
	 * admits the call, else anonymously (feature-detect + fall back).
	 *
	 * @param string $path The GitHub-relative path (starts with `/`).
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function get(string $path, ?string $actingUserId, ?string $credentialId): array {
		if ($credentialId !== null && $credentialId !== '' && $this->isBrokerAvailable() === true) {
			$brokered = $this->brokerGet(path: $path, credentialId: $credentialId, actingUserId: $actingUserId);
			if ($brokered !== null) {
				return $brokered;
			}

			// Broker denied / rules missing — fall through to anonymous.
		}

		return $this->anonymousGet(path: $path);
	}//end get()

	/**
	 * Route a GET through the OpenRegister credential broker.
	 *
	 * @param string $path The GitHub-relative path.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID for the broker owner guard.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}|null Null when the broker
	 *                                                                                     denies the call (caller falls back to anonymous).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Server::get is deliberate lazy resolution
	 *   of the optional OpenRegister broker so this class stays constructible when the
	 *   broker is absent (feature-detected via class_exists).
	 */
	private function brokerGet(string $path, string $credentialId, ?string $actingUserId): ?array {
		try {
			$broker = Server::get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialId,
				self::APP_ID,
				'GET',
				$path,
				['Accept' => 'application/vnd.github+json'],
				null,
				$actingUserId
			);
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq GitHub template catalog: broker call not admitted, falling back to anonymous.');
			return null;
		}

		$status = (int)($response['status'] ?? 0);
		$body = (string)($response['body'] ?? '');

		return [
			'ok' => ($status >= 200 && $status < 300),
			'status' => $status,
			'body' => $body,
			'rateLimited' => ($status === 403 || $status === 429),
			'brokerUsed' => true,
		];
	}//end brokerGet()

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
	 * Write a value to the short-TTL cache (no-op when no cache backend).
	 *
	 * @param string $key The cache key.
	 * @param mixed $value The value to cache.
	 * @param int $ttl The TTL in seconds.
	 *
	 * @return void
	 */
	private function cacheSet(string $key, mixed $value, int $ttl): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, $ttl);
	}//end cacheSet()
}//end class
