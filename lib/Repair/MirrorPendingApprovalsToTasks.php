<?php

/**
 * Repair step that mirrors every in-flight pending Approval as an
 * OpenRegister task (approval-task convergence, phase 1).
 *
 * New pending Approvals are mirrored at creation time by the seam in
 * `ApprovalService::persistApproval()`; this step is the data migration for
 * the approvals that were ALREADY pending when the seam arrived, so an
 * upgraded instance starts with its whole approval backlog visible in
 * OpenRegister's shared task inbox.
 *
 * Idempotent, guarded on the stored backlink: an Approval already carrying a
 * `taskUuid` is skipped, so a second run changes nothing (the
 * `MigrateApprovalChainsToTasks` reconciliation pattern). Decided approvals
 * are history, not work, and get no task. Never raises — a repair step that
 * aborts the upgrade over an unmirrorable approval would trade a missing
 * inbox row for an instance that will not start; failures are counted and
 * named in the output instead. The rollback is
 * `occ hermiq:approvals:rollback-task-mirror`.
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors pending Approvals that predate the convergence seam.
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
 */
class MirrorPendingApprovalsToTasks implements IRepairStep {

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
	 * @param ObjectService $objectService Reads pending Approvals and writes the backlink.
	 * @param ApprovalTaskBridge $taskBridge Creates the mirror tasks (never throws).
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ApprovalTaskBridge $taskBridge,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's display name.
	 *
	 * @return string The name shown during upgrade.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
	 */
	public function getName(): string {
		return 'Mirror in-flight hermiq approvals as OpenRegister tasks';
	}//end getName()

	/**
	 * Mirror every pending Approval that has no task yet.
	 *
	 * @param IOutput $output Upgrade progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-in-flight-approvals-migrate-and-roll-back
	 */
	public function run(IOutput $output): void {
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
			// A fresh install has no register yet; an upgrade whose descriptor
			// import has not run this boot is in the same position. The seam
			// mirrors every approval created from here on, so nothing is lost.
			$output->info('Hermiq approval mirroring skipped: ' . $e->getMessage());
			$this->logger->info(
				'MirrorPendingApprovalsToTasks skipped: ' . $e->getMessage(),
				['exception' => $e]
			);

			return;
		}//end try

		$mirrored = 0;
		$already = 0;
		$failed = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['status'] ?? '') !== 'pending') {
				continue;
			}

			if (trim((string)($data['taskUuid'] ?? '')) !== '') {
				$already++;
				continue;
			}

			$taskUuid = $this->taskBridge->ensureTaskFor(approval: $object);
			if ($taskUuid === null) {
				$failed[] = (string)$object->getUuid();
				continue;
			}

			try {
				$data['taskUuid'] = $taskUuid;
				$this->objectService
					->setRegister(self::REGISTER_SLUG)
					->setSchema(self::APPROVAL_SCHEMA)
					->saveObject(
						object: $data,
						register: self::REGISTER_SLUG,
						schema: self::APPROVAL_SCHEMA,
						uuid: (string)$object->getUuid(),
						_rbac: false,
						_multitenancy: false
					);
				$mirrored++;
			} catch (Throwable $e) {
				$failed[] = (string)$object->getUuid();
				$this->logger->warning(
					'MirrorPendingApprovalsToTasks could not write taskUuid onto approval '
					. ((string)$object->getUuid()) . ': ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}//end foreach

		$output->info(
			sprintf(
				'Hermiq approval mirroring: %d mirrored, %d already mirrored, %d failed.',
				$mirrored,
				$already,
				count($failed)
			)
		);

		if ($failed !== []) {
			// Named, loudly, but never fatal: the unmirrored approvals stay
			// decidable on hermiq's own surface, and a re-run picks them up.
			$output->warning('Unmirrored approvals: ' . implode(', ', $failed));
		}

	}//end run()
}//end class
