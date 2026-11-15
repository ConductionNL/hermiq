<?php

/**
 * Contract of GovernedMcpEndpoint
 *
 * The public surface of GovernedMcpEndpoint that other components call, so they depend
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
 * Contract of GovernedMcpEndpoint.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GovernedMcpEndpointInterface {

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
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
	 */
	public function matches(string $host, int $port): bool;
}//end interface
