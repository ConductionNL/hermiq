<?php

/**
 * Hermiq EgressRequest.
 *
 * The inputs of one egress check: the URL, whether it is the admin-configured
 * search endpoint, the fetch allow/deny lists and the insecure-HTTP opt-in.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

/**
 * One egress check's inputs.
 *
 * @spec openspec/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
 */
final class EgressRequest {

	/**
	 * Exact-hostname allowlist (`web.fetch` targets only).
	 *
	 * @var string[]
	 */
	public array $allowlist = [];

	/**
	 * Exact-hostname denylist (`web.fetch` targets only).
	 *
	 * @var string[]
	 */
	public array $denylist = [];

	/**
	 * Admin opt-in for a non-HTTPS URL.
	 *
	 * @var bool
	 */
	public bool $allowInsecureHttp = false;

	/**
	 * Build the request.
	 *
	 * @param string $url           The URL to check.
	 * @param bool   $adminEndpoint Whether this is the admin-configured search endpoint.
	 */
	public function __construct(
		public readonly string $url,
		public readonly bool $adminEndpoint,
	) {
	}//end __construct()
}//end class
