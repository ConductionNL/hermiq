<?php

/**
 * Hermiq LlmEndpointProbe.
 *
 * The first-time-setup LLM probe's reach: which endpoints it may ask (the
 * allow-list that keeps it from becoming an SSRF primitive) and what an
 * allow-listed host advertises at `/api/tags`. Built by SetupController over
 * its HTTP client service.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\Http\Client\IClientService;

/**
 * Allow-list check and model listing for an LLM endpoint.
 *
 * @spec exclude First-time-setup LLM probe; no behavioural spec.
 */
final class LlmEndpointProbe {

	/**
	 * Hosts an LLM endpoint may point at. Keeps the probe from becoming an SSRF
	 * primitive — the realistic Ollama locations in a Nextcloud deployment are
	 * the loopback interface or the Docker host gateway.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal'];

	/**
	 * Build the probe.
	 *
	 * @param IClientService $clientService The HTTP client factory.
	 */
	public function __construct(
		private readonly IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Whether an LLM endpoint URL is a well-formed http(s) URL pointing at an allow-listed host.
	 *
	 * @param string $endpoint The endpoint URL.
	 *
	 * @return bool True when the endpoint may be probed.
	 *
	 * @spec exclude First-time-setup LLM probe; no behavioural spec.
	 */
	public function isAllowedEndpoint(string $endpoint): bool {
		$parts = parse_url($endpoint);
		if ($parts === false || isset($parts['scheme']) === false || isset($parts['host']) === false) {
			return false;
		}

		if (in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false) {
			return false;
		}

		return in_array(strtolower($parts['host']), self::ALLOWED_HOSTS, true);
	}//end isAllowedEndpoint()

	/**
	 * The non-empty model names an allow-listed host advertises at `$url`, in order.
	 *
	 * @param string $url The `/api/tags` URL of an allow-listed endpoint.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec exclude First-time-setup LLM probe; no behavioural spec.
	 */
	public function advertisedModels(string $url): array {
		return $this->modelNames(tags: $this->fetchTags(url: $url));
	}//end advertisedModels()

	/**
	 * GET the host's `/api/tags` and decode it.
	 *
	 * @param string $url The `/api/tags` URL of an allow-listed endpoint.
	 *
	 * @return mixed The decoded answer.
	 */
	private function fetchTags(string $url): mixed {
		$client = $this->clientService->newClient();
		// Opt this single request past Nextcloud's local-address guard: the LLM host is a
		// loopback / Docker-gateway service (the realistic Ollama location), and the host is
		// already constrained to the ALLOWED_HOSTS allow-list, so this is not an
		// open SSRF. The global guard stays on for every other request.
		$response = $client->get(
			$url,
			[
				'timeout' => 5,
				'connect_timeout' => 3,
				'nextcloud' => ['allow_local_address' => true],
			]
		);

		return json_decode((string)$response->getBody(), true);
	}//end fetchTags()

	/**
	 * The non-empty model names an `/api/tags` answer lists, in order.
	 *
	 * @param mixed $tags The decoded answer.
	 *
	 * @return array<int, string> The names.
	 */
	private function modelNames(mixed $tags): array {
		$models = [];
		foreach (($tags['models'] ?? []) as $model) {
			$name = (string)($model['name'] ?? '');
			if ($name !== '') {
				$models[] = $name;
			}
		}

		return $models;
	}//end modelNames()
}//end class
