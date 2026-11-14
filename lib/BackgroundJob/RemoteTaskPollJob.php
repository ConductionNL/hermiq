<?php

/**
 * Hermiq RemoteTaskPollJob.
 *
 * Every minute: expire the handoffs past their deadline and ask each waiting
 * remote agent task once (flows-hand-off-and-wait). All logic lives in
 * HandoffSweep; this class stays a pure wrapper (ADR-002).
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Handoff\HandoffSweep;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Sweeps the waiting handoffs once a minute.
 *
 * @psalm-api
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */
class RemoteTaskPollJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time  Time factory for the base job.
	 * @param HandoffSweep $sweep Expires and polls the waiting handoffs.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly HandoffSweep $sweep,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
	}//end __construct()

	/**
	 * Run one sweep.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	protected function run($argument): void {
		$this->sweep->run();
	}//end run()
}//end class
