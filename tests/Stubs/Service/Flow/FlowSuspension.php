<?php

/**
 * Minimal OpenRegister FlowSuspension stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/FlowSuspension.php.
 * Extends RuntimeException there, for the same reason FlowStop does: a node
 * declaring `@throws FlowSuspension` needs the class to be a Throwable or the
 * docblock does not type-check. Registered at TEST TIME only by
 * tests/bootstrap.php and scanned (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use RuntimeException;

/**
 * Minimal FlowSuspension stub: a run that paused and will come back.
 */
class FlowSuspension extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param DateTime|null $resumeAt When the run may continue.
	 * @param string $reason What it is waiting for.
	 */
	public function __construct(
		private readonly ?DateTime $resumeAt = null,
		string $reason = 'suspended',
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * When this run may resume.
	 *
	 * @return DateTime|null The resume time, or null for a pure signal wait.
	 */
	public function getResumeAt(): ?DateTime {
		return $this->resumeAt;
	}//end getResumeAt()
}//end class
