<?php

/**
 * Hermiq FailedAttempt
 *
 * A failed agent turn and the retry settings it is judged against.
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
use Throwable;

/**
 * A failed agent turn and the retry settings it is judged against.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class FailedAttempt {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The advanced schedule payload.
	 * @param bool $retryEnabled Whether this schedule opted into retry.
	 * @param int $attemptNumber The attempt number that just failed.
	 * @param bool $deferDisable Whether the once/finite-repeat disable is pending.
	 * @param DateTimeImmutable $now The current UTC moment.
	 * @param Throwable $error The captured agent-turn failure.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $data,
		public readonly bool $retryEnabled,
		public readonly int $attemptNumber,
		public readonly bool $deferDisable,
		public readonly DateTimeImmutable $now,
		public readonly Throwable $error,
	) {
	}//end __construct()
}//end class
