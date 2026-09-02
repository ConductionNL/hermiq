<?php

/**
 * Minimal OpenRegister RegisterFlowOversightEvent stub for standalone runs.
 *
 * Signatures mirrored verbatim from openregister
 * lib/Service/Flow/RegisterFlowOversightEvent.php. Registered at TEST TIME
 * only by tests/bootstrap.php.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Minimal RegisterFlowOversightEvent stub.
 */
class RegisterFlowOversightEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param FlowOversightRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly FlowOversightRegistry $registry,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Contribute an oversight check.
	 *
	 * @param IFlowOversightCheck $check The check.
	 *
	 * @return void
	 */
	public function registerCheck(IFlowOversightCheck $check): void {
		$this->registry->register(check: $check);

	}//end registerCheck()
}//end class
