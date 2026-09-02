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

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

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
	 * Constructor, mirroring the REAL signature.
	 *
	 * The real class requires the logger and lazily collects contributions
	 * through the optional dispatcher. A zero-argument stub constructor
	 * already cost one CI round: a test built against it fataled the moment
	 * the real OpenRegister was installed (ArgumentCountError), which is the
	 * exact drift these stubs promise not to have.
	 *
	 * @param LoggerInterface $logger Unused by the stub; required by the real class.
	 * @param IEventDispatcher|null $dispatcher Unused by the stub; the real
	 *                                          class dispatches discovery on it.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fidelity is the point.
	 */
	public function __construct(
		LoggerInterface $logger,
		?IEventDispatcher $dispatcher = null,
	) {

	}//end __construct()

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
