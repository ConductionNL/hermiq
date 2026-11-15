<?php

/**
 * Contract of WebResearchEgressGuard
 *
 * The public surface of WebResearchEgressGuard that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of WebResearchEgressGuard.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebResearchEgressGuardInterface {

	/**
	 * Validate a destination URL before any request is issued.
	 *
	 * @param string $url The destination URL.
	 * @param bool $isAdminConfiguredEndpoint Whether `$url` is the admin-entered
	 *                                        search endpoint (exempt from the
	 *                                        private-address block and the
	 *                                        allowlist/denylist) rather than an
	 *                                        agent-chosen `web.fetch` target.
	 * @param string[] $allowlist Exact-hostname allowlist (`web.fetch`
	 *                            targets only; ignored for the search
	 *                            endpoint).
	 * @param string[] $denylist Exact-hostname denylist (`web.fetch`
	 *                           targets only; ignored for the search
	 *                           endpoint).
	 * @param bool $allowInsecureHttp Admin opt-in for a non-HTTPS URL.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string} The verdict.
	 *
	 * @spec openspec/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
	 * @spec openspec/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Not ad-hoc mode switches:
	 *   `$isAdminConfiguredEndpoint` is the spec's binding trust-tier input and
	 *   `$allowInsecureHttp` the admin's persisted non-HTTPS opt-in from config.
	 */
	public function assertSafe(
		string $url,
		bool $isAdminConfiguredEndpoint,
		array $allowlist = [],
		array $denylist = [],
		bool $allowInsecureHttp = false,
	): array;
}//end interface
