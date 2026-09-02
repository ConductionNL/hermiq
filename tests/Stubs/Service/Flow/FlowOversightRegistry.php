<?php

/**
 * Minimal OpenRegister FlowOversightRegistry stub for standalone runs.
 *
 * Only what the RegisterFlowOversightEvent stub needs: `register()`, with the
 * real signature, storing the checks so a test can read back what a listener
 * contributed. Registered at TEST TIME only by tests/bootstrap.php.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal FlowOversightRegistry stub.
 */
class FlowOversightRegistry {

	/**
	 * The registered checks, keyed by id.
	 *
	 * @var array<string, IFlowOversightCheck>
	 */
	private array $checks = [];

	/**
	 * Register an oversight check.
	 *
	 * @param IFlowOversightCheck $check The check to add.
	 *
	 * @return void
	 */
	public function register(IFlowOversightCheck $check): void {
		$this->checks[$check->getId()] = $check;
	}//end register()

	/**
	 * The registered checks, keyed by id.
	 *
	 * @return array<string, IFlowOversightCheck> The checks.
	 */
	public function all(): array {
		return $this->checks;
	}//end all()
}//end class
