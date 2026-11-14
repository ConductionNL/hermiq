<?php

/**
 * Hermiq RunTokenRequest.
 *
 * What one run token is minted for: the run, its agent and acting user, the
 * conversation it belongs to, and an optional lifetime override.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * The binding of one run token.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
 */
final class RunTokenRequest {

	/**
	 * The acting user's UID (resolves RBAC on tool dispatch).
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The conversation UUID, when one exists (audit binding only).
	 *
	 * @var string
	 */
	public string $conversationId = '';

	/**
	 * A lifetime override in seconds (the workload plane's own ceiling), or null for the turn default.
	 *
	 * @var int|null
	 */
	public ?int $ttlSeconds = null;

	/**
	 * Build the request.
	 *
	 * @param string $runId   The run UUID.
	 * @param string $agentId The acting agent's UUID (resolves the granted tool set).
	 */
	public function __construct(
		public readonly string $runId,
		public readonly string $agentId,
	) {
	}//end __construct()
}//end class
