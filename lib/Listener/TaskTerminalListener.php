<?php

/**
 * Hermiq TaskTerminalListener.
 *
 * Consumes OpenRegister's committed `TaskTerminalEvent` and translates a
 * decided mirror task back into hermiq's approval gate: a task completed
 * with an approving outcome applies `ApprovalService::approve()`, a
 * rejecting outcome applies `deny()`. This is what makes OpenRegister's
 * shared task inbox a decision surface for hermiq approvals
 * (approval-task convergence, phase 1; the filinq#988 adopter pattern).
 *
 * The event is registered and routed by FQN string literal: during hermiq's
 * own `register()` the `OCA\OpenRegister\` prefix may not be autoloadable
 * yet, and registering a listener for an event name that is never
 * dispatched is harmless. Every value is read through an `is_callable`
 * dynamic getter (`method_exists()` answers false for NC's `Entity::__call`
 * magic getters), so hermiq tolerates OpenRegister older, newer or absent.
 *
 * Idempotent and loop-safe by construction: `approve()`/`deny()` no-op on a
 * non-pending Approval, and the release call they trigger no-ops on the
 * already-terminal task that raised this event (design.md Decision 1).
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\ApprovalService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies decided OpenRegister mirror tasks onto hermiq Approvals.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
 */
class TaskTerminalListener implements IEventListener {

	/**
	 * FQN of OpenRegister's terminal-task event, as a string on purpose: a
	 * cross-app class name is a runtime lookup, and a literal cannot
	 * accidentally autoload or hard-couple (the filinq#988 pattern).
	 *
	 * @var string
	 */
	public const EVENT_TASK_TERMINAL = 'OCA\\OpenRegister\\Event\\TaskTerminalEvent';

	/**
	 * The task state meaning "the work finished with an explicit outcome".
	 * Terminated and disabled tasks are deliberately NOT decisions: a mirror
	 * cancelled in OpenRegister leaves the Approval pending and decidable in
	 * hermiq (design.md Decision 4).
	 *
	 * @var string
	 */
	private const STATE_COMPLETED = 'completed';

	/**
	 * OpenRegister's published rejecting-outcome vocabulary, mirrored from
	 * `TaskState::REJECTING_OUTCOMES` (a value copy, not an import: the
	 * listener must route even when that class cannot be resolved).
	 *
	 * @var array<int, string>
	 */
	private const REJECTING_OUTCOMES = ['rejected', 'returned', 'declined', 'denied'];

	/**
	 * Constructor.
	 *
	 * @param ApprovalService $approvalService The approval gate's decision write-path.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ApprovalService $approvalService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a committed terminal-task event for a hermiq mirror task.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
	 */
	public function handle(Event $event): void {
		if (get_class($event) !== self::EVENT_TASK_TERMINAL) {
			return;
		}

		// Only the after-commit dispatch may resume work (OpenRegister's own
		// contract for run continuation); the in-transaction dispatch is for
		// timer bookkeeping and would resume a run inside a foreign transaction.
		if ($this->read(subject: $event, getter: 'isCommitted') !== true) {
			return;
		}

		$task = $this->read(subject: $event, getter: 'getTask');
		if (is_object($task) === false) {
			return;
		}

		if ((string)$this->read(subject: $task, getter: 'getState') !== self::STATE_COMPLETED) {
			return;
		}

		$approvalUuid = $this->approvalUuidOf(task: $task);
		if ($approvalUuid === '') {
			return;
		}

		try {
			$this->apply(task: $task, approvalUuid: $approvalUuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not apply the decision of mirror task '
				. ((string)$this->read(subject: $task, getter: 'getUuid'))
				. ' onto approval ' . $approvalUuid . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end handle()

	/**
	 * Apply a completed mirror task's outcome onto its pending Approval.
	 *
	 * @param object $task The completed task.
	 * @param string $approvalUuid The linked Approval's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
	 */
	private function apply(object $task, string $approvalUuid): void {
		$approval = $this->approvalService->loadApproval(uuid: $approvalUuid);
		if ($approval === null) {
			$this->logger->warning(
				'Hermiq mirror task ' . ((string)$this->read(subject: $task, getter: 'getUuid'))
				. ' names approval ' . $approvalUuid . ', which does not resolve.'
			);

			return;
		}

		$decider = trim((string)$this->read(subject: $task, getter: 'getCompletedBy'));
		if ($decider === '') {
			$decider = trim((string)$this->read(subject: $task, getter: 'getAssignee'));
		}

		if ($decider === '') {
			// Never decide as nobody: the decision stays on the task until a
			// person owns it, and the Approval stays pending in hermiq.
			$this->logger->warning(
				'Hermiq skipped mirror task ' . ((string)$this->read(subject: $task, getter: 'getUuid'))
				. ' for approval ' . $approvalUuid . ': it carries no completing identity.'
			);

			return;
		}

		$outcome = strtolower(trim((string)$this->read(subject: $task, getter: 'getOutcome')));
		if (in_array($outcome, self::REJECTING_OUTCOMES, true) === true) {
			$reason = trim((string)$this->read(subject: $task, getter: 'getComment'));
			if ($reason === '') {
				$reason = trim((string)$this->read(subject: $task, getter: 'getResultText'));
			}

			$this->approvalService->deny(approval: $approval, deciderUid: $decider, reason: $reason);

			return;
		}

		$this->approvalService->approve(approval: $approval, deciderUid: $decider);

	}//end apply()

	/**
	 * The hermiq ownership marker: `metadata.hermiq.approvalUuid`.
	 *
	 * @param object $task The terminal task.
	 *
	 * @return string The linked Approval uuid, or '' for a foreign task.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
	 */
	private function approvalUuidOf(object $task): string {
		$metadata = $this->read(subject: $task, getter: 'getMetadata');
		if (is_array($metadata) === false) {
			return '';
		}

		$hermiq = ($metadata['hermiq'] ?? null);
		if (is_array($hermiq) === false) {
			return '';
		}

		return trim((string)($hermiq['approvalUuid'] ?? ''));

	}//end approvalUuidOf()

	/**
	 * Read a value through a dynamic getter that works for real methods and
	 * for NC's `Entity::__call` magic getters alike.
	 *
	 * @param object $subject The event or task.
	 * @param string $getter The getter name.
	 *
	 * @return mixed The value, or null when unreadable.
	 *
	 * @spec openspec/changes/approval-task-convergence/specs/approval-task-convergence/spec.md#requirement-a-task-decision-is-an-approval-decision
	 */
	private function read(object $subject, string $getter): mixed {
		$callable = [$subject, $getter];
		if (is_callable($callable) === false) {
			return null;
		}

		try {
			return $callable();
		} catch (Throwable) {
			return null;
		}

	}//end read()
}//end class
