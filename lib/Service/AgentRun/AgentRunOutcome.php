<?php

/**
 * Hermiq AgentRunOutcome
 *
 * How one agent run ended, as its audit entry records it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AgentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AgentRun;

use DateTimeImmutable;

/**
 * How one agent run ended, as its audit entry records it.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AgentRunOutcome {

	/**
	 * Constructor.
	 *
	 * @param string $status The run outcome (ok|error|skipped_killswitch|skipped_budget|awaiting_approval).
	 * @param string $summary The raw run output/error (redacted here).
	 * @param array<string,mixed> $payload The event payload (for agentId/flowName diagnostics).
	 * @param DateTimeImmutable|null $startedAt When the agent turn began, if it ran (UTC).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $summary,
		public readonly array $payload,
		public readonly ?DateTimeImmutable $startedAt = null,
	) {
	}//end __construct()
}//end class
