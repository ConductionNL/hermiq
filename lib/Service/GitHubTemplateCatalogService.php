<?php

/**
 * Hermiq GitHubTemplateCatalogService.
 *
 * Server-side GitHub source for the agent-template gallery's "GitHub store"
 * (agent-template-github-store). Searches GitHub for repositories tagged
 * `topic:hermiq-agent-template`, and fetches a hit's portable template package
 * file for install — the SAME JSON shape `AgentTemplateSerializer::toPackage()`
 * produces and `AgentTemplateService::importPackage()` consumes.
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
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
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
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
class GitHubTemplateCatalogService
{
    /**
     * The fixed GitHub API host — compile-time constant, no SSRF surface.
     *
     * @var string
     */
    private const API_BASE = 'https://api.github.com';

    /**
     * The discovery topic every conforming hermiq template repo carries.
     *
     * @var string
     */
    private const DISCOVERY_TOPIC = 'topic:hermiq-agent-template';

    /**
     * The repo-root file name a template repo carries its portable package under
     * (the same JSON `AgentTemplateSerializer::toPackage()`/`::fromPackage()` shape).
     *
     * @var string
     */
    public const PACKAGE_FILE = 'hermiq-agent-template.json';

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
     * @param IClientService  $clientService NC HTTP client factory (anonymous calls).
     * @param ICacheFactory   $cacheFactory  NC cache factory (short-TTL server cache).
     * @param LoggerInterface $logger        PSR logger (secret-free diagnostics only).
     */
    public function __construct(
        private readonly IClientService $clientService,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
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
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
     */
    public function isBrokerAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS) === true;
    }//end isBrokerAvailable()

    /**
     * Search GitHub for `topic:hermiq-agent-template` repos and build cards.
     *
     * @param string|null $query        Optional free-text term appended to the topic query.
     * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
     *
     * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
     */
    public function search(?string $query, ?string $actingUserId, ?string $credentialId=null): array
    {
        $term      = trim((string) $query);
        $normalise = strtolower($term);
        $cacheKey  = 'search:'.md5($normalise.'|'.((string) $credentialId));

        $cached = $this->cacheGet(key: $cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $queryString = self::DISCOVERY_TOPIC;
        if ($term !== '') {
            $queryString .= ' '.$term;
        }

        $path = '/search/repositories?q='.rawurlencode($queryString).'&per_page='.self::MAX_HITS;

        $result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            // Rate-limited/unreachable with no fresh result — surface a generic outcome,
            // never a 5xx (the caller — githubSearch() — always returns HTTP 200).
            $failure = self::OUTCOME_UNREACHABLE;
            if ($result['rateLimited'] === true) {
                $failure = self::OUTCOME_RATE_LIMITED;
            }

            return [
                'outcome'     => $failure,
                'cards'       => [],
                'brokerUsed'  => $result['brokerUsed'],
                'rateLimited' => $result['rateLimited'],
            ];
        }

        $decoded = json_decode($result['body'], true);
        $items   = [];
        if (is_array($decoded) === true && is_array($decoded['items'] ?? null) === true) {
            $items = $decoded['items'];
        }

        $cards = [];
        foreach (array_slice($items, 0, self::MAX_HITS) as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $card = $this->buildCard(item: $item, actingUserId: $actingUserId, credentialId: $credentialId);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        $payload = [
            'outcome'     => self::OUTCOME_OK,
            'cards'       => $cards,
            'brokerUsed'  => $result['brokerUsed'],
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
     * @param string      $owner        Repo owner (pattern-validated).
     * @param string      $repo         Repo name (pattern-validated).
     * @param string|null $ref          Optional git ref (pattern-validated).
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return string|null The raw package JSON string, or null when missing/unreadable/invalid.
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
     */
    public function fetchTemplateFile(
        string $owner,
        string $repo,
        ?string $ref,
        ?string $actingUserId,
        ?string $credentialId=null
    ): ?string {
        if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
            return null;
        }

        return $this->fetchFileContents(
            owner: $owner,
            repo: $repo,
            path: self::PACKAGE_FILE,
            ref: $ref,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );
    }//end fetchTemplateFile()

    /**
     * Build a card from a search hit's package file (non-installable when the
     * file is missing/unparseable — the hit is surfaced, never dropped).
     *
     * @param array<string,mixed> $item         A GitHub search-result item.
     * @param string|null         $actingUserId The session UID (broker identity), or null.
     * @param string|null         $credentialId Optional allowed `github` credential.
     *
     * @return array<string,mixed>|null The card, or null when the item lacks an owner/name.
     */
    private function buildCard(array $item, ?string $actingUserId, ?string $credentialId): ?array
    {
        $fullName  = (string) ($item['full_name'] ?? '');
        $nameParts = explode('/', $fullName);
        $owner     = (string) ($item['owner']['login'] ?? '');
        if ($owner === '') {
            $owner = (string) ($nameParts[0] ?? '');
        }

        $repo = (string) ($item['name'] ?? '');
        if ($repo === '') {
            $repo = (string) ($nameParts[1] ?? '');
        }

        if ($owner === '' || $repo === '') {
            return null;
        }

        $stars    = (int) ($item['stargazers_count'] ?? 0);
        $contents = $this->fetchTemplateFile(
            owner: $owner,
            repo: $repo,
            ref: null,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );

        if ($contents === null) {
            return [
                'owner'       => $owner,
                'repo'        => $repo,
                'stars'       => $stars,
                'installable' => false,
                'unparseable' => true,
            ];
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded) === false) {
            return [
                'owner'       => $owner,
                'repo'        => $repo,
                'stars'       => $stars,
                'installable' => false,
                'unparseable' => true,
            ];
        }

        return [
            'owner'       => $owner,
            'repo'        => $repo,
            'stars'       => $stars,
            'installable' => true,
            'unparseable' => false,
            'name'        => (string) ($decoded['name'] ?? $repo),
            'description' => (string) ($decoded['description'] ?? ''),
            'category'    => (string) ($decoded['category'] ?? ''),
            'version'     => (string) ($decoded['version'] ?? ''),
        ];
    }//end buildCard()

    /**
     * Fetch a single file's decoded contents via the GitHub contents API.
     *
     * @param string      $owner        Repo owner.
     * @param string      $repo         Repo name.
     * @param string      $path         Repo-relative file path.
     * @param string|null $ref          Optional git ref.
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
        ?string $credentialId
    ): ?string {
        $apiPath = '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/contents/'
            .implode('/', array_map('rawurlencode', explode('/', $path)));
        if ($ref !== null && $ref !== '') {
            $apiPath .= '?ref='.rawurlencode($ref);
        }

        $result = $this->get(path: $apiPath, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) === false) {
            return null;
        }

        $content  = (string) ($decoded['content'] ?? '');
        $encoding = (string) ($decoded['encoding'] ?? '');
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
     * @param string      $path         The GitHub-relative path (starts with `/`).
     * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
     */
    private function get(string $path, ?string $actingUserId, ?string $credentialId): array
    {
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
     * @param string      $path         The GitHub-relative path.
     * @param string      $credentialId The `github` credential UUID.
     * @param string|null $actingUserId The session UID for the broker owner guard.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}|null Null when the broker
     *         denies the call (caller falls back to anonymous).
     */
    private function brokerGet(string $path, string $credentialId, ?string $actingUserId): ?array
    {
        try {
            $broker   = Server::get(self::BROKER_CLASS);
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

        $status = (int) ($response['status'] ?? 0);
        $body   = (string) ($response['body'] ?? '');

        return [
            'ok'          => ($status >= 200 && $status < 300),
            'status'      => $status,
            'body'        => $body,
            'rateLimited' => ($status === 403 || $status === 429),
            'brokerUsed'  => true,
        ];
    }//end brokerGet()

    /**
     * Perform an anonymous GET against the fixed GitHub host.
     *
     * @param string $path The GitHub-relative path.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
     */
    private function anonymousGet(string $path): array
    {
        try {
            $response = $this->clientService->newClient()->get(
                self::API_BASE.$path,
                [
                    'timeout'         => self::TIMEOUT,
                    'connect_timeout' => self::TIMEOUT,
                    'headers'         => [
                        'Accept'     => 'application/vnd.github+json',
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
            'ok'          => ($status >= 200 && $status < 300),
            'status'      => $status,
            'body'        => (string) $response->getBody(),
            'rateLimited' => ($status === 403 || $status === 429),
            'brokerUsed'  => false,
        ];
    }//end anonymousGet()

    /**
     * Validate owner/repo/ref against safe patterns before path interpolation.
     *
     * @param string      $owner The repo owner.
     * @param string      $repo  The repo name.
     * @param string|null $ref   Optional git ref.
     *
     * @return bool
     *
     * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
     */
    public function validRepo(string $owner, string $repo, ?string $ref): bool
    {
        if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
            return false;
        }

        if ($ref !== null && $ref !== '' && preg_match(self::REF_PATTERN, $ref) !== 1) {
            return false;
        }

        return true;
    }//end validRepo()

    /**
     * Read a value from the short-TTL cache (no-op when no cache backend).
     *
     * @param string $key The cache key.
     *
     * @return mixed The cached value, or null on a miss / no cache.
     */
    private function cacheGet(string $key): mixed
    {
        if ($this->cache === null) {
            return null;
        }

        return $this->cache->get($key);
    }//end cacheGet()

    /**
     * Write a value to the short-TTL cache (no-op when no cache backend).
     *
     * @param string $key   The cache key.
     * @param mixed  $value The value to cache.
     * @param int    $ttl   The TTL in seconds.
     *
     * @return void
     */
    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->set($key, $value, $ttl);
    }//end cacheSet()
}//end class
