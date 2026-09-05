<?php

/**
 * Hermiq RollbackApprovalTaskMirror.
 *
 * `occ hermiq:approvals:rollback-task-mirror`: the reverse of the
 * `MirrorPendingApprovalsToTasks` repair step and of the creation-time seam
 * (approval-task convergence, phase 1). Every still-pending Approval that
 * carries a `taskUuid` gets its mirror task terminated as moot and the
 * backlink cleared, restoring the pre-convergence world without touching any
 * decision already made. Decided approvals are left exactly as they are.
 *
 * Idempotent: a second run finds no backlinks and changes nothing, and
 * OpenRegister's `terminateAsMoot()` returns an already-terminal task
 * untouched. `--dry-run` reports without writing (the
 * `openregister:approval:rollback-to-steps` pattern).
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Command;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Terminates the mirror tasks of pending approvals and clears the backlinks.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */
class RollbackApprovalTaskMirror extends Command {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for approval objects.
	 *
	 * @var string
	 */
	private const APPROVAL_SCHEMA = 'approval';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads pending Approvals and clears the backlinks.
	 * @param ApprovalTaskBridge $taskBridge Terminates the mirror tasks (never throws).
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ApprovalTaskBridge $taskBridge,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
	 */
	protected function configure(): void {
		$this->setName(name: 'hermiq:approvals:rollback-task-mirror')
			->setDescription(
				description: 'Terminate the OpenRegister mirror tasks of pending hermiq approvals and clear the taskUuid backlinks'
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
	 * @return int Zero on success, one when any approval could not be rolled back.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ((bool)$input->getOption('dry-run') === true);

		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::APPROVAL_SCHEMA)
				->findAll(
					config: ['filters' => ['status' => 'pending']],
					_rbac: false,
					_multitenancy: false
				);
		} catch (Throwable $e) {
			$output->writeln('<error>Could not read approvals: ' . $e->getMessage() . '</error>');

			return 1;
		}

		$rolledBack = 0;
		$failed = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			$taskUuid = trim((string)($data['taskUuid'] ?? ''));
			if ((string)($data['status'] ?? '') !== 'pending' || $taskUuid === '') {
				continue;
			}

			$approvalUuid = (string)$object->getUuid();
			if ($dryRun === true) {
				$output->writeln('Would roll back approval ' . $approvalUuid . ' (task ' . $taskUuid . ').');
				$rolledBack++;
				continue;
			}

			// Terminate the mirror first: a cleared backlink with a live task
			// would leave an undecidable row in someone's inbox, while a
			// terminated task with a stale backlink is release-idempotent.
			$this->taskBridge->releaseFor(
				approval: $object,
				decision: 'rolled-back',
				deciderUid: 'occ:rollback-task-mirror'
			);

			try {
				$data['taskUuid'] = null;
				$this->objectService
					->setRegister(self::REGISTER_SLUG)
					->setSchema(self::APPROVAL_SCHEMA)
					->saveObject(
						object: $data,
						register: self::REGISTER_SLUG,
						schema: self::APPROVAL_SCHEMA,
						uuid: $approvalUuid,
						_rbac: false,
						_multitenancy: false
					);
				$rolledBack++;
				$output->writeln('Rolled back approval ' . $approvalUuid . ' (task ' . $taskUuid . ').');
			} catch (Throwable $e) {
				$failed[] = $approvalUuid;
				$this->logger->warning(
					'RollbackApprovalTaskMirror could not clear taskUuid on approval '
					. $approvalUuid . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$output->writeln(
					'<error>Could not clear the backlink on approval ' . $approvalUuid
					. ': ' . $e->getMessage() . '</error>'
				);
			}//end try
		}//end foreach

		$output->writeln(
			sprintf('Rollback: %d approvals rolled back, %d failed.', $rolledBack, count($failed))
		);

		if ($failed !== []) {
			return 1;
		}

		return 0;

	}//end execute()
}//end class
