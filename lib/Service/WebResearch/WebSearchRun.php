<?php

/**
 * Hermiq WebSearchRun.
 *
 * One `web.search` call: the query, the web-research settings it runs under,
 * the acting user the credential broker is told about, and the request URL
 * built for the configured provider. Built and read only inside WebSearchClient.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

/**
 * One web search.
 *
 * @spec openspec/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance
 */
final class WebSearchRun {

	/**
	 * The configured provider shape (`searxng` or `generic-json`), or ''.
	 *
	 * @var string
	 */
	public readonly string $provider;

	/**
	 * The admin-configured base endpoint, trimmed, or ''.
	 *
	 * @var string
	 */
	public readonly string $endpoint;

	/**
	 * The acting user id, forwarded to the broker's sessionless-caller path.
	 *
	 * @var string|null
	 */
	public ?string $actingUserId = null;

	/**
	 * The request URL for the provider.
	 *
	 * @var string
	 */
	public string $requestUrl = '';

	/**
	 * Constructor.
	 *
	 * @param string               $query  The search query.
	 * @param array<string, mixed> $config The web-research settings.
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $config,
	) {
		$this->provider = (string)$config['searchProvider'];
		$this->endpoint = trim((string)$config['searchEndpoint']);
	}//end __construct()
}//end class
