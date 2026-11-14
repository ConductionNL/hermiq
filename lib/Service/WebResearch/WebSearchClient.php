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
 * @spec openspec/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 * @spec openspec/specs/web-research-tool/spec.md#requirement-paid-search-api-credentials-come-from-the-credential-broker
 * @spec openspec/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

use OCA\Hermiq\Kernel\Contracts\WebSearchClientInterface;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `hermiq.webSearch`'s implementation: guarded call to the configured search backend,
 * parsed via the provider's native shape or an admin-supplied field mapping.
 *
 * @spec openspec/changes/archive/2026-07-14-web-research-tool/tasks.md#task-4-websearchclient-searxng--generic-json
 */
class WebSearchClient implements WebSearchClientInterface {

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
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param WebResearchSettingsHandler $settingsHandler Reads `hermiq.webResearch`.
	 * @param WebResearchEgressGuard $guard SSRF/allowlist/denylist gate.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ContainerInterface|null $container The app container the optional cross-app
	 *                                           classes are resolved from; null when the
	 *                                           class is built by hand in a test.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly WebResearchSettingsHandler $settingsHandler,
		private readonly WebResearchEgressGuard $guard,
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * The container the optional OpenRegister credential broker is resolved from.
	 *
	 * The broker is another app's class, probed by class-string, so it can never be a
	 * constructor type here: that would hard-couple hermiq to an OpenRegister version
	 * that ships it. It used to come from the global server, which outside a booted
	 * Nextcloud autowires from scratch and can recurse through a constructor cycle
	 * until memory runs out. The container is nullable because the unit tests build
	 * this client with its four real collaborators; `fetchBody()` treats a null as it
	 * treats an absent broker and takes the direct path, so this only ever throws for
	 * a caller that reached the brokered path some other way.
	 *
	 * @return ContainerInterface The injected container.
	 *
	 * @throws RuntimeException When the client was built without one.
	 */
	private function serviceContainer(): ContainerInterface {
		if ($this->container === null) {
			throw new RuntimeException(
				'Hermiq web research: this client was constructed without a container, so the '
				. 'OpenRegister credential broker cannot be resolved.'
			);
		}

		return $this->container;
	}//end serviceContainer()

	/**
	 * Search the configured backend. Never throws.
	 *
	 * @param string $query The search query.
	 * @param string|null $actingUserId The acting user id, forwarded to the credential
	 *                                  broker's sessionless-caller path (background/
	 *                                  scheduled runs) — mirrors `BrokerHttpClient`'s
	 *                                  `$actingUserId` constructor param.
	 *
	 * @return array<string, mixed> `{query, results}` on success, or `{error: {code, message}}`.
	 *
	 * @spec openspec/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance
	 */
	public function search(string $query, ?string $actingUserId = null): array {
		$run = new WebSearchRun(query: $query, config: $this->settingsHandler->getWebResearchSettingsOnly());
		$run->actingUserId = $actingUserId;

		if ($run->provider === '' || $run->endpoint === '') {
			return $this->error(code: 'search_unavailable', message: 'No web search provider is configured.');
		}

		$run->requestUrl = $this->buildRequestUrl(run: $run);
		$refusal = $this->guardRefusal(run: $run);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$body = $this->fetchBody(run: $run);
		} catch (Throwable $e) {
			$this->logger->warning('[Hermiq] web.search request failed', ['error' => $e->getMessage()]);
			return $this->error(code: 'search_failed', message: 'The search request failed.');
		}

		return $this->resultsOf(run: $run, body: $body);
	}//end search()

	/**
	 * The egress guard's refusal of the admin-configured request URL, or null
	 * when it may be called.
	 *
	 * @param WebSearchRun $run The search.
	 *
	 * @return array<string, mixed>|null The error envelope, or null.
	 */
	private function guardRefusal(WebSearchRun $run): ?array {
		$safety = $this->guard->assertSafe(
			url: $run->requestUrl,
			isAdminConfiguredEndpoint: true,
			allowlist: [],
			denylist: [],
			allowInsecureHttp: (bool)$run->config['allowInsecureHttp']
		);
		if ($safety['allowed'] === false) {
			return $this->error(code: (string)$safety['code'], message: (string)$safety['message']);
		}

		return null;
	}//end guardRefusal()

	/**
	 * The search results of a response body, or the error of an unparsable one.
	 *
	 * @param WebSearchRun $run  The search.
	 * @param string       $body The raw response body.
	 *
	 * @return array<string, mixed> `{query, results}`, or an error envelope.
	 */
	private function resultsOf(WebSearchRun $run, string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return $this->error(code: 'search_failed', message: 'The search provider returned an unparsable response.');
		}

		return ['query' => $run->query, 'results' => $this->parseResults(run: $run, decoded: $decoded)];
	}//end resultsOf()

	/**
	 * Fetch the raw response body — via the credential broker when a credential is
	 * configured, direct otherwise.
	 *
	 * The broker credential UUID comes from the settings (empty = no broker).
	 *
	 * @param WebSearchRun $run The search: its request URL, settings and acting user.
	 *
	 * @return string The raw response body.
	 */
	private function fetchBody(WebSearchRun $run): string {
		$credentialId = trim((string)$run->config['searchCredentialId']);
		if ($credentialId !== '' && $this->container !== null && class_exists(self::BROKER_CLASS) === true) {
			return $this->requestViaBroker(run: $run, credentialId: $credentialId);
		}

		return $this->requestDirect(url: $run->requestUrl, timeout: (int)$run->config['timeoutSeconds']);
	}//end fetchBody()

	/**
	 * Parse the decoded JSON response per the configured provider shape.
	 *
	 * @param WebSearchRun         $run     The search: its provider and `generic-json` field mapping.
	 * @param array<string, mixed> $decoded The decoded JSON response.
	 *
	 * @return array<int, array{title: string, url: string, snippet: string}>
	 */
	private function parseResults(WebSearchRun $run, array $decoded): array {
		if ($run->provider === 'searxng') {
			return $this->parseSearxng(decoded: $decoded);
		}

		return $this->parseGenericJson(decoded: $decoded, mapping: (array)$run->config['searchFieldMapping']);
	}//end parseResults()

	/**
	 * Build the request URL for the configured provider shape.
	 *
	 * @param WebSearchRun $run The search: its endpoint, provider and query.
	 *
	 * @return string
	 */
	private function buildRequestUrl(WebSearchRun $run): string {
		$base = rtrim($run->endpoint, '/');
		if ($run->provider === 'searxng') {
			return $base . '/search?' . http_build_query(['q' => $run->query, 'format' => 'json']);
		}

		$separator = '?';
		if (str_contains($base, '?') === true) {
			$separator = '&';
		}

		return $base . $separator . http_build_query(['q' => $run->query]);
	}//end buildRequestUrl()

	/**
	 * Call the endpoint directly via `IClientService` (no credential configured).
	 *
	 * The `nextcloud.allow_local_address` opt-in mirrors `SetupController::testLlm()`'s
	 * existing precedent in this app: without it, NC's own client would refuse to
	 * connect to the private/internal address this endpoint is expected to be.
	 *
	 * @param string $url The request URL.
	 * @param int $timeout The configured timeout, in seconds.
	 *
	 * @return string The raw response body.
	 */
	private function requestDirect(string $url, int $timeout): string {
		$client = $this->clientService->newClient();
		$response = $client->get(
			$url,
			[
				'timeout' => $timeout,
				'connect_timeout' => min(5, max(1, $timeout)),
				'allow_redirects' => false,
				'nextcloud' => ['allow_local_address' => true],
			]
		);

		return (string)$response->getBody();
	}//end requestDirect()

	/**
	 * Call the endpoint through the credential broker. The broker's own resolved
	 * provider record supplies the real (host-locked) base URL — only a path (+ query
	 * string) is ever passed, exactly like `BrokerHttpClient::sendRequest()`.
	 *
	 * @param WebSearchRun $run          The search: the request URL Hermiq WOULD have called
	 *                                   directly (its path/query is what is actually sent)
	 *                                   and the acting user id (sessionless-caller path).
	 * @param string       $credentialId The broker credential UUID.
	 *
	 * @return string The raw response body.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The broker is OpenRegister's optional
	 *   cross-app service, resolved by class-name string through the injected container
	 *   and only after a `class_exists()` probe. Constructor injection would hard-couple
	 *   Hermiq to an OpenRegister version that ships it.
	 */
	private function requestViaBroker(WebSearchRun $run, string $credentialId): string {
		$parts = parse_url($run->requestUrl);
		$path = (string)($parts['path'] ?? '/');
		if (isset($parts['query']) === true && $parts['query'] !== '') {
			$path .= '?' . $parts['query'];
		}

		$broker = $this->serviceContainer()->get(self::BROKER_CLASS);
		$response = $broker->request($credentialId, self::APP_ID, 'GET', $path, [], null, $run->actingUserId);

		return (string)($response['body'] ?? '');
	}//end requestViaBroker()

	/**
	 * Parse SearXNG's native JSON response shape.
	 *
	 * @param array<string, mixed> $decoded The decoded JSON response.
	 *
	 * @return array<int, array{title: string, url: string, snippet: string}>
	 */
	private function parseSearxng(array $decoded): array {
		$results = [];
		foreach ((array)($decoded['results'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$results[] = [
				'title' => (string)($item['title'] ?? ''),
				'url' => (string)($item['url'] ?? ''),
				'snippet' => (string)($item['content'] ?? ''),
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
	private function parseGenericJson(array $decoded, array $mapping): array {
		$resultsPath = (string)($mapping['resultsPath'] ?? 'results');
		$titleField = (string)($mapping['titleField'] ?? 'title');
		$urlField = (string)($mapping['urlField'] ?? 'url');
		$snippetField = (string)($mapping['snippetField'] ?? 'content');

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
				'title' => (string)($this->dotGet(data: $item, path: $titleField) ?? ''),
				'url' => (string)($this->dotGet(data: $item, path: $urlField) ?? ''),
				'snippet' => (string)($this->dotGet(data: $item, path: $snippetField) ?? ''),
			];
		}

		return $results;
	}//end parseGenericJson()

	/**
	 * Read a dot-separated path out of a nested array (e.g. `data.items`).
	 *
	 * @param array<string, mixed> $data The array to read from.
	 * @param string $path The dot-separated path.
	 *
	 * @return mixed The value at `$path`, or null when any segment is missing.
	 */
	private function dotGet(array $data, string $path): mixed {
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
	 * @param string $code The machine error code.
	 * @param string $message The human-readable message.
	 *
	 * @return array<string, mixed> The error envelope.
	 */
	private function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class
