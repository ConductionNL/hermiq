<?php

/**
 * Repair step that delegates in-flight schedules to the engine's clock.
 *
 * New and edited schedules are armed by `ScheduleTask`'s per-tick sync; this
 * step is the data migration for the schedules that ALREADY exist when
 * schedules-onto-engine-triggers arrives, so an upgraded instance hands its
 * eligible clocks to OpenRegister's schedule trigger at once instead of one
 * poll interval later.
 *
 * Idempotent by construction: the whole pass is `ScheduleFlowBridge::syncAll()`,
 * whose `engineFlowId` guard makes a second run a no-op (the
 * `MirrorPendingApprovalsToTasks` pattern). Never raises — a repair step that
 * aborts the upgrade over one unmappable schedule would trade a local clock
 * for an instance that will not start; failures are counted and logged. The
 * rollback is `occ hermiq:schedules:rollback-flow-mirror`.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors eligible schedules that predate the engine delegation seam.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
class MirrorSchedulesToEngineFlows implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ScheduleFlowBridge $flowBridge The sync pass (mirror/refresh/retire).
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ScheduleFlowBridge $flowBridge,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's display name.
	 *
	 * @return string The name shown during upgrade.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function getName(): string {
		return 'Delegate eligible hermiq schedules to OpenRegister\'s schedule trigger';
	}//end getName()

	/**
	 * Run one sync pass over every schedule.
	 *
	 * @param IOutput $output Upgrade progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function run(IOutput $output): void {
		try {
			$stats = $this->flowBridge->syncAll();
		} catch (Throwable $e) {
			// A fresh install has no register yet; an OpenRegister without the
			// flow store answers the same way. The per-tick sync arms every
			// schedule from here on, so nothing is lost.
			$output->info('Hermiq schedule delegation skipped: ' . $e->getMessage());
			$this->logger->info(
				'MirrorSchedulesToEngineFlows skipped: ' . $e->getMessage(),
				['exception' => $e]
			);
			return;
		}

		$output->info(
			sprintf(
				'Hermiq schedule delegation: %d mirrored, %d refreshed, %d retired, %d stayed local, %d failed.',
				$stats['mirrored'],
				$stats['refreshed'],
				$stats['retired'],
				$stats['skipped'],
				$stats['failed']
			)
		);

	}//end run()
}//end class
