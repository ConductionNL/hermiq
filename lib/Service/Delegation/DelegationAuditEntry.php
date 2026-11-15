<?php

/**
 * Hermiq DelegationAuditEntry
 *
 * What one delegation audit entry records.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delegation
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

namespace OCA\Hermiq\Service\Delegation;

use DateTimeImmutable;

/**
 * What one delegation audit entry records.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DelegationAuditEntry {

	/**
	 * Constructor.
	 *
	 * @param string $targetAgentId The delegated-to agent's UUID.
	 * @param string $status `ok`|`error`.
	 * @param string $summary The raw run output/error (redacted here).
	 * @param string $runId This sub-run's own fresh run identifier.
	 * @param string|null $parentRunId The calling run's own `runId`.
	 * @param DateTimeImmutable $startedAt When the delegated turn began.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $targetAgentId,
		public readonly string $status,
		public readonly string $summary,
		public readonly string $runId,
		public readonly ?string $parentRunId,
		public readonly DateTimeImmutable $startedAt,
	) {
	}//end __construct()
}//end class
