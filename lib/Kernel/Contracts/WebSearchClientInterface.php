<?php

/**
 * Contract of WebSearchClient
 *
 * The public surface of WebSearchClient that other components call, so they depend
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
 * Contract of WebSearchClient.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebSearchClientInterface {

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
	public function search(string $query, ?string $actingUserId = null): array;
}//end interface
