<?php

/**
 * Hermiq GateSkip
 *
 * An occurrence a gate held back, and why.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;

/**
 * An occurrence a gate held back, and why.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class GateSkip {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The schedule payload (advanced + finalised here).
	 * @param string $owner The owner UID (for timezone-anchored next-run).
	 * @param DateTimeImmutable $now The current UTC moment.
	 * @param string $status The gate status (skipped_killswitch|skipped_budget|awaiting_approval).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $owner,
		public readonly DateTimeImmutable $now,
		public readonly string $status,
	) {
	}//end __construct()
}//end class
