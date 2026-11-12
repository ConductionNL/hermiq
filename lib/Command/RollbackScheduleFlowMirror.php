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
		$run = new MirrorRollbackRun(output: $output, dryRun: ((bool)$input->getOption('dry-run') === true));

		try {
			$objects = $this->readSchedules();
		} catch (Throwable $e) {
			$output->writeln('<error>Could not read schedules: ' . $e->getMessage() . '</error>');

			return 1;
		}

		foreach ($objects as $object) {
			$this->rollBackSchedule(run: $run, object: $object);
		}

		return $this->summarise(run: $run);

	}//end execute()

	/**
	 * Report the counts and answer the exit code: one when any schedule failed.
	 *
	 * @param MirrorRollbackRun $run The finished run.
	 *
	 * @return int
	 */
	private function summarise(MirrorRollbackRun $run): int {
		$run->output->writeln(
			sprintf('Rollback: %d schedules rolled back, %d failed.', $run->rolledBack, count($run->failed))
		);

		if ($run->failed !== []) {
			return 1;
		}

		return 0;
	}//end summarise()

	/**
	 * Every schedule object (system-wide read).
	 *
	 * @return array<int, mixed>
	 */
	private function readSchedules(): array {
		return $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA_SLUG)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
	}//end readSchedules()

	/**
	 * Roll back one schedule when it is delegated, or say it would in a dry run.
	 *
	 * @param MirrorRollbackRun $run The run.
	 * @param mixed $object A stored schedule object.
	 *
	 * @return void
	 */
	private function rollBackSchedule(MirrorRollbackRun $run, mixed $object): void {
		if (($object instanceof ObjectEntity) === false) {
			return;
		}

		$run->flowId = trim((string)($object->getObject()['engineFlowId'] ?? ''));
		if ($run->flowId === '') {
			return;
		}

		$run->scheduleUuid = (string)$object->getUuid();
		if ($run->dryRun === true) {
			$run->output->writeln('Would roll back schedule ' . $run->scheduleUuid . ' (flow ' . $run->flowId . ').');
			$run->rolledBack++;
			return;
		}

		$this->rollBack(run: $run, schedule: $object);
	}//end rollBackSchedule()

	/**
	 * Clear the schedule's marker, then delete its mirror flow.
	 *
	 * @param MirrorRollbackRun $run The run, on the schedule in hand.
	 * @param ObjectEntity $schedule The schedule in hand.
	 *
	 * @return void
	 */
	private function rollBack(MirrorRollbackRun $run, ObjectEntity $schedule): void {
		try {
			// Marker first, flow second: from the marker write on, the
			// local dispatcher owns the clock; a crash before the delete
			// leaves a mirror whose delegation check refuses to fire.
			$this->scheduleService->clearEngineDelegation(schedule: $schedule);
			$this->flowBridge->deleteFlow(flowId: $run->flowId);
			$run->rolledBack++;
			$run->output->writeln('Rolled back schedule ' . $run->scheduleUuid . ' (flow ' . $run->flowId . ').');
		} catch (Throwable $e) {
			$this->reportFailure(run: $run, error: $e);
		}
	}//end rollBack()

	/**
	 * Count, log and report a schedule that could not be rolled back.
	 *
	 * @param MirrorRollbackRun $run The run, on the schedule in hand.
	 * @param Throwable $error What went wrong.
	 *
	 * @return void
	 */
	private function reportFailure(MirrorRollbackRun $run, Throwable $error): void {
		$run->failed[] = $run->scheduleUuid;
		$this->logger->warning(
			'RollbackScheduleFlowMirror could not roll back schedule '
			. $run->scheduleUuid . ': ' . $error->getMessage(),
			['exception' => $error]
		);
		$run->output->writeln(
			'<error>Could not roll back schedule ' . $run->scheduleUuid
			. ': ' . $error->getMessage() . '</error>'
		);
	}//end reportFailure()
}//end class
