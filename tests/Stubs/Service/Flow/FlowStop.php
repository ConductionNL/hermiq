<?php

/**
 * Minimal OpenRegister FlowStop stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/FlowStop.php.
 * It extends RuntimeException there, and that is the load-bearing part rather
 * than a detail: a node declaring `@throws FlowStop` is only a valid docblock if
 * the class is a Throwable, and phpstan says so out loud when the stub is
 * missing. Registered at TEST TIME only by tests/bootstrap.php and scanned
 * (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * Minimal FlowStop stub: a run that ended on purpose.
 */
class FlowStop extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $reason Why the run stopped.
	 * @param bool $isError Whether the stop is a fault rather than a decision.
	 * @param string|null $checkId The check that stopped it, when one did.
	 */
	public function __construct(
		string $reason = '',
		private readonly bool $isError = false,
		private readonly ?string $checkId = null,
	) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * The check that stopped the run, when one did.
	 *
	 * @return string|null The check id.
	 */
	public function checkId(): ?string {
		return $this->checkId;
	}//end checkId()

	/**
	 * Whether this stop is a fault rather than a decision.
	 *
	 * @return bool
	 */
	public function isError(): bool {
		return $this->isError;
	}//end isError()
}//end class
