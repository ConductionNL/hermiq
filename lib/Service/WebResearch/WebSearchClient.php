<?php

/**
 * Hermiq WebSearchClient.
 *
 * Backs the `hermiq.webSearch` tool: calls the admin-configured, pluggable search
 * backend (native SearXNG JSON, or a generic JSON API with an admin-supplied field
 * mapping) via `OCP\Http\Client\IClientService` — never a hardcoded call to a
 * specific commercial search provider. When a broker credential is configured, the
 * call routes through OpenRegister's `CredentialBrokerService` instead (mirrors
 * `OCA\Hermiq\Service\Llm\BrokerHttpClient`'s exact reasoning: the secret lives in the
 * vault, never in Hermiq's own config, and the broker's own provider record — not
 * this class — resolves and host-locks the real destination).
 *
 * Routes through the SAME `WebResearchEgressGuard::assertSafe()` gate `WebFetchService`
 * uses, with `$isAdminConfiguredEndpoint = true` — the private/loopback/RFC1918/ULA
 * block does not apply to the search endpoint (design.md: an admin may legitimately
 * self-host SearXNG on an internal address), but the cloud-metadata block,
 * HTTPS-or-explicit-opt-in, and the allowlist/denylist bypass do NOT change what the
 * caller must still separately enforce via request options (size cap / timeout).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-paid-search-api-credentials-come-from-the-credential-broker
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

use OCP\Http\Client\IClientService;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `hermiq.webSearch`'s implementation: guarded call to the configured search backend,
 * parsed via the provider's native shape or an admin-supplied field mapping.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-4-websearchclient-searxng--generic-json
 */
class WebSearchClient
{

    /**
     * OpenRegister's credential broker. Resolved lazily so Hermiq still boots without
     * OpenRegister installed — same guard `BrokerHttpClient::isAvailable()` performs.
     *
     * @var string
     */
    public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

    /**
     * The broker `appId` Hermiq identifies itself with.
     *
     * @var string
     */
    public const APP_ID = 'hermiq';

    /**
     * Constructor.
     *
     * @param IClientService             $clientService   Nextcloud HTTP client factory.
     * @param WebResearchSettingsHandler $settingsHandler Reads `hermiq.webResearch`.
     * @param WebResearchEgressGuard     $guard           SSRF/allowlist/denylist gate.
     * @param LoggerInterface            $logger          PSR-3 logger.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly WebResearchSettingsHandler $settingsHandler,
        private readonly WebResearchEgressGuard $guard,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Search the configured backend. Never throws.
     *
     * @param string      $query        The search query.
     * @param string|null $actingUserId The acting user id, forwarded to the credential
     *                                  broker's sessionless-caller path (background/
     *                                  scheduled runs) — mirrors `BrokerHttpClient`'s
     *                                  `$actingUserId` constructor param.
     *
     * @return array<string, mixed> `{query, results}` on success, or `{error: {code, message}}`.
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance
     */
    public function search(string $query, ?string $actingUserId=null): array
    {
        $config   = $this->settingsHandler->getWebResearchSettingsOnly();
        $provider = (string) $config['searchProvider'];
        $endpoint = trim((string) $config['searchEndpoint']);

        if ($provider === '' || $endpoint === '') {
            return $this->error(code: 'search_unavailable', message: 'No web search provider is configured.');
        }

        $requestUrl = $this->buildRequestUrl(endpoint: $endpoint, provider: $provider, query: $query);

        $safety = $this->guard->assertSafe(
            url: $requestUrl,
            isAdminConfiguredEndpoint: true,
            allowlist: [],
            denylist: [],
            allowInsecureHttp: (bool) $config['allowInsecureHttp']
        );
        if ($safety['allowed'] === false) {
            return $this->error(code: (string) $safety['code'], message: (string) $safety['message']);
        }

        $credentialId = trim((string) $config['searchCredentialId']);

        try {
            $body = $this->fetchBody(
                requestUrl: $requestUrl,
                credentialId: $credentialId,
                actingUserId: $actingUserId,
                timeout: (int) $config['timeoutSeconds']
            );
        } catch (Throwable $e) {
            $this->logger->warning('[Hermiq] web.search request failed', ['error' => $e->getMessage()]);
            return $this->error(code: 'search_failed', message: 'The search request failed.');
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return $this->error(code: 'search_failed', message: 'The search provider returned an unparsable response.');
        }

        $results = $this->parseResults(provider: $provider, decoded: $decoded, mapping: (array) $config['searchFieldMapping']);

        return ['query' => $query, 'results' => $results];

    }//end search()

    /**
     * Fetch the raw response body — via the credential broker when a credential is
     * configured, direct otherwise.
     *
     * @param string      $requestUrl   The request URL.
     * @param string      $credentialId The broker credential UUID (empty = no broker).
     * @param string|null $actingUserId The acting user id (broker sessionless-caller path).
     * @param int         $timeout      The configured timeout, in seconds.
     *
     * @return string The raw response body.
     */
    private function fetchBody(string $requestUrl, string $credentialId, ?string $actingUserId, int $timeout): string
    {
        if ($credentialId !== '' && class_exists(self::BROKER_CLASS) === true) {
            return $this->requestViaBroker(url: $requestUrl, credentialId: $credentialId, actingUserId: $actingUserId);
        }

        return $this->requestDirect(url: $requestUrl, timeout: $timeout);

    }//end fetchBody()

    /**
     * Parse the decoded JSON response per the configured provider shape.
     *
     * @param string               $provider `searxng` or `generic-json`.
     * @param array<string, mixed> $decoded  The decoded JSON response.
     * @param array<string, mixed> $mapping  The `generic-json` field mapping.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function parseResults(string $provider, array $decoded, array $mapping): array
    {
        if ($provider === 'searxng') {
            return $this->parseSearxng(decoded: $decoded);
        }

        return $this->parseGenericJson(decoded: $decoded, mapping: $mapping);

    }//end parseResults()

    /**
     * Build the request URL for the configured provider shape.
     *
     * @param string $endpoint The admin-configured base endpoint.
     * @param string $provider `searxng` or `generic-json`.
     * @param string $query    The search query.
     *
     * @return string
     */
    private function buildRequestUrl(string $endpoint, string $provider, string $query): string
    {
        $base = rtrim($endpoint, '/');
        if ($provider === 'searxng') {
            return $base.'/search?'.http_build_query(['q' => $query, 'format' => 'json']);
        }

        $separator = '?';
        if (str_contains($base, '?') === true) {
            $separator = '&';
        }

        return $base.$separator.http_build_query(['q' => $query]);

    }//end buildRequestUrl()

    /**
     * Call the endpoint directly via `IClientService` (no credential configured).
     *
     * The `nextcloud.allow_local_address` opt-in mirrors `SetupController::testLlm()`'s
     * existing precedent in this app: without it, NC's own client would refuse to
     * connect to the private/internal address this endpoint is expected to be.
     *
     * @param string $url     The request URL.
     * @param int    $timeout The configured timeout, in seconds.
     *
     * @return string The raw response body.
     */
    private function requestDirect(string $url, int $timeout): string
    {
        $client   = $this->clientService->newClient();
        $response = $client->get(
            $url,
            [
                'timeout'         => $timeout,
                'connect_timeout' => min(5, max(1, $timeout)),
                'allow_redirects' => false,
                'nextcloud'       => ['allow_local_address' => true],
            ]
        );

        return (string) $response->getBody();

    }//end requestDirect()

    /**
     * Call the endpoint through the credential broker. The broker's own resolved
     * provider record supplies the real (host-locked) base URL — only a path (+ query
     * string) is ever passed, exactly like `BrokerHttpClient::sendRequest()`.
     *
     * @param string      $url          The request URL Hermiq WOULD have called
     *                                  directly (its path/query is what is actually sent).
     * @param string      $credentialId The broker credential UUID.
     * @param string|null $actingUserId The acting user id (sessionless-caller path).
     *
     * @return string The raw response body.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) The broker is OpenRegister's optional
     *   cross-app service, resolved by class-name string via `Server::get()` only
     *   after a `class_exists()` probe — constructor injection would hard-couple
     *   Hermiq to an OpenRegister version that ships it.
     */
    private function requestViaBroker(string $url, string $credentialId, ?string $actingUserId): string
    {
        $parts = parse_url($url);
        $path  = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) === true && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        $broker   = Server::get(self::BROKER_CLASS);
        $response = $broker->request($credentialId, self::APP_ID, 'GET', $path, [], null, $actingUserId);

        return (string) ($response['body'] ?? '');

    }//end requestViaBroker()

    /**
     * Parse SearXNG's native JSON response shape.
     *
     * @param array<string, mixed> $decoded The decoded JSON response.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function parseSearxng(array $decoded): array
    {
        $results = [];
        foreach ((array) ($decoded['results'] ?? []) as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $results[] = [
                'title'   => (string) ($item['title'] ?? ''),
                'url'     => (string) ($item['url'] ?? ''),
                'snippet' => (string) ($item['content'] ?? ''),
            ];
        }

        return $results;

    }//end parseSearxng()

    /**
     * Parse a generic JSON search API response using an admin-supplied field mapping
     * — the mechanism that makes the backend pluggable without a new code deployment.
     *
     * @param array<string, mixed> $decoded The decoded JSON response.
     * @param array<string, mixed> $mapping `{resultsPath, titleField, urlField, snippetField}`.
     *
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function parseGenericJson(array $decoded, array $mapping): array
    {
        $resultsPath  = (string) ($mapping['resultsPath'] ?? 'results');
        $titleField   = (string) ($mapping['titleField'] ?? 'title');
        $urlField     = (string) ($mapping['urlField'] ?? 'url');
        $snippetField = (string) ($mapping['snippetField'] ?? 'content');

        $items = $this->dotGet(data: $decoded, path: $resultsPath);
        if (is_array($items) === false) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $results[] = [
                'title'   => (string) ($this->dotGet(data: $item, path: $titleField) ?? ''),
                'url'     => (string) ($this->dotGet(data: $item, path: $urlField) ?? ''),
                'snippet' => (string) ($this->dotGet(data: $item, path: $snippetField) ?? ''),
            ];
        }

        return $results;

    }//end parseGenericJson()

    /**
     * Read a dot-separated path out of a nested array (e.g. `data.items`).
     *
     * @param array<string, mixed> $data The array to read from.
     * @param string               $path The dot-separated path.
     *
     * @return mixed The value at `$path`, or null when any segment is missing.
     */
    private function dotGet(array $data, string $path): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) === false || array_key_exists($segment, $current) === false) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;

    }//end dotGet()

    /**
     * Build a structured error envelope.
     *
     * @param string $code    The machine error code.
     * @param string $message The human-readable message.
     *
     * @return array<string, mixed> The error envelope.
     */
    private function error(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];

    }//end error()
}//end class
