<?php

/**
 * Hermiq GovernedMcpEndpoint — the one place that says WHERE the governed MCP
 * endpoint lives from inside the runner container.
 *
 * Two collaborators need that answer and they must never disagree:
 *
 *   - `ProviderFactory` writes the URL into the CLI's `--mcp-config`, so the CLI
 *     knows where to send its tool calls;
 *   - `EgressAuthorizeController` (the egress PDP) needs to recognise that same
 *     origin when the egress proxy asks whether a run may open a connection to
 *     it — because the runner's route to its OWN governance runs through the
 *     proxy like everything else.
 *
 * Two copies of that rule would be two policies, and the second would drift. So
 * the rule lives here once, as {@see applyContainerOrigin()}, and both callers
 * read it.
 *
 * **Why the PDP has to admit this origin at all.** `WebResearchEgressGuard` is
 * an SSRF guard: it blocks private/RFC1918 destinations, because the question it
 * answers is "may the model fetch this URL off the internet?". The governed MCP
 * endpoint is not an internet destination — it is Hermiq's own control plane,
 * and on a container deployment it is deliberately a private address
 * (`http://nextcloud`). Widening the web-research allowlist to cover it would
 * make the model's `webFetch` tool able to reach internal HTTP hosts, which is
 * the exact hole that guard exists to close. Admitting this ONE exact authority,
 * and only at the PDP, leaves `webFetch` untouched.
 *
 * The admission is by exact `host:port`. Not a suffix, not a wildcard, not a
 * list — a neighbouring host, a different port, or a subdomain of the same name
 * is a stranger and is judged by the ordinary policy.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Resolves, and recognises, the container-facing origin of Hermiq's governed MCP
 * endpoint.
 */
class GovernedMcpEndpoint {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Yields the URL Nextcloud publishes to browsers.
	 * @param IAppConfig $appConfig Reads the `mcp_run_base_url` container-facing override.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Rewrite a published endpoint URL onto the container-facing origin.
	 *
	 * `IURLGenerator` yields the URL Nextcloud publishes to BROWSERS (the trusted
	 * domain / `overwrite.cli.url`). From inside the runner container that host
	 * frequently does not resolve to Nextcloud at all — a stock dev instance
	 * publishes `http://localhost`, which inside the container is the container.
	 * AppAPI already records the container-facing origin (its daemon's
	 * `nextcloud_url`, e.g. `http://nextcloud`); `mcp_run_base_url` pins the same
	 * value here.
	 *
	 * Pure on purpose: it takes both inputs rather than reading config, so the one
	 * caller that resolves them differently (ProviderFactory, whose collaborators
	 * are nullable for its test call sites) still shares this single copy of the
	 * rule.
	 *
	 * @param string $publishedUrl The absolute URL Nextcloud publishes.
	 * @param string $baseOverride The `mcp_run_base_url` value ('' when unset).
	 *
	 * @return string The container-facing URL, or `$publishedUrl` unchanged.
	 */
	public static function applyContainerOrigin(string $publishedUrl, string $baseOverride): string {
		$baseOverride = trim($baseOverride);
		if ($baseOverride === '' || $publishedUrl === '') {
			return $publishedUrl;
		}

		$path = (string)parse_url($publishedUrl, PHP_URL_PATH);
		if ($path === '') {
			return $publishedUrl;
		}

		return rtrim($baseOverride, '/') . $path;

	}//end applyContainerOrigin()

	/**
	 * The container-facing URL of the governed MCP endpoint.
	 *
	 * @return string The URL, or '' when it cannot be resolved.
	 */
	public function url(): string {
		try {
			$published = $this->urlGenerator->linkToRouteAbsolute('hermiq.mcpRun.handle');
		} catch (\Throwable $unresolvable) {
			// An unresolvable route yields NO authority, which admits nothing. The
			// failure direction is deny, so it is safe to swallow here.
			unset($unresolvable);
			return '';
		}

		return self::applyContainerOrigin(
			publishedUrl: $published,
			baseOverride: (string)$this->appConfig->getValueString('hermiq', 'mcp_run_base_url', '')
		);

	}//end url()

	/**
	 * The `host:port` of the governed MCP endpoint, lowercased.
	 *
	 * @return string The authority, or '' when it cannot be resolved.
	 */
	public function authority(): string {
		$url = $this->url();
		if ($url === '') {
			return '';
		}

		$parts = parse_url($url);
		if (is_array($parts) === false || empty($parts['host']) === true) {
			return '';
		}

		$port = (int)($parts['port'] ?? 0);
		if ($port === 0) {
			$port = (strtolower((string)($parts['scheme'] ?? 'http')) === 'https') ? 443 : 80;
		}

		return strtolower((string)$parts['host']) . ':' . $port;

	}//end authority()

	/**
	 * Whether `host:port` IS the governed MCP endpoint.
	 *
	 * Exact match on both halves. An unresolvable endpoint matches nothing, so the
	 * caller falls through to the ordinary policy rather than admitting anything.
	 *
	 * @param string $host The requested host.
	 * @param int $port The requested port.
	 *
	 * @return bool True when this is the governance origin itself.
	 */
	public function matches(string $host, int $port): bool {
		$authority = $this->authority();
		if ($authority === '') {
			return false;
		}

		return $authority === strtolower(trim($host)) . ':' . $port;

	}//end matches()
}//end class
