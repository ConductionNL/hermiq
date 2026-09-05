<?php

/**
 * occ command that returns every delegated schedule clock to hermiq.
 *
 * The rollback half of schedules-onto-engine-triggers: for every schedule
 * carrying an `engineFlowId`, clear the marker (the local dispatcher owns
 * the clock again from that write on) and then delete the mirror flow. The
 * order is the safety property — a crash in between leaves a mirror whose
 * dispatch node refuses to fire and disables it, never a double clock.
 *
 * ⚠️ The per-tick sync re-mirrors eligible schedules on the next tick unless
 * hermiq is upgraded past the seam or the schedules are edited. This command
 * exists for the upgrade window and for incident response, mirroring
 * `RollbackApprovalTaskMirror`.
 *
 * @category Command
 * @package  OCA\Hermiq\Command
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

namespace OCA\Hermiq\Command;

use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Clears delegation markers and deletes the mirror flows.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
class RollbackScheduleFlowMirror extends Command {

	/**
	 * OpenRegister register slug that holds hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for schedule objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'schedule';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads delegated schedules.
	 * @param ScheduleService $scheduleService Clears the markers through the sanitised persist.
	 * @param ScheduleFlowBridge $flowBridge Deletes the mirror flows.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ScheduleService $scheduleService,
		private readonly ScheduleFlowBridge $flowBridge,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	protected function configure(): void {
		$this->setName(name: 'hermiq:schedules:rollback-flow-mirror')
			->setDescription(
				description: 'Return every delegated schedule clock to hermiq: clear engineFlowId markers and delete the mirror flows'
			)
			->addOption(
				name: 'dry-run',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be rolled back without changing anything'
			);
	}//end configure()

	/**
	 * Execute the rollback.
	 *
	 * @param InputInterface $input Command input.
	 * @param OutputInterface $output Command output.
	 *
	 * @return int Zero on success, one when any schedule could not be rolled back.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ((bool)$input->getOption('dry-run') === true);

		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->writeln('<error>Could not read schedules: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$rolledBack = 0;
		$failed = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$flowId = trim((string)($object->getObject()['engineFlowId'] ?? ''));
			if ($flowId === '') {
				continue;
			}

			$scheduleUuid = (string)$object->getUuid();
			if ($dryRun === true) {
				$output->writeln('Would roll back schedule ' . $scheduleUuid . ' (flow ' . $flowId . ').');
				$rolledBack++;
				continue;
			}

			try {
				// Marker first, flow second: from the marker write on, the
				// local dispatcher owns the clock; a crash before the delete
				// leaves a mirror whose delegation check refuses to fire.
				$this->scheduleService->clearEngineDelegation(schedule: $object);
				$this->flowBridge->deleteFlow(flowId: $flowId);
				$rolledBack++;
				$output->writeln('Rolled back schedule ' . $scheduleUuid . ' (flow ' . $flowId . ').');
			} catch (Throwable $e) {
				$failed[] = $scheduleUuid;
				$this->logger->warning(
					'RollbackScheduleFlowMirror could not roll back schedule '
					. $scheduleUuid . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$output->writeln(
					'<error>Could not roll back schedule ' . $scheduleUuid
					. ': ' . $e->getMessage() . '</error>'
				);
			}//end try
		}//end foreach

		$output->writeln(
			sprintf('Rollback: %d schedules rolled back, %d failed.', $rolledBack, count($failed))
		);

		if ($failed !== []) {
			return 1;
		}

		return 0;

	}//end execute()
}//end class
