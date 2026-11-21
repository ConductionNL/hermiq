<?php

/**
 * Hermiq ResearchRecord.
 *
 * What a research run keeps and says: its handoff (state, stop flag, close),
 * its messages in the conversation, and the clock its time cap is judged by.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a research run's handoff and posts its messages.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
class ResearchRecord {
	/**
	 * Constructor.
	 *
	 * @param HandoffService                 $handoffs The research handoff.
	 * @param MessageHistoryHandlerInterface $messages Posts into the conversation.
	 * @param ITimeFactory                   $time     The clock.
	 * @param LoggerInterface                $logger   The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HandoffService $handoffs,
		private readonly MessageHistoryHandlerInterface $messages,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The run of a waiting research handoff, or null when the handoff is not one.
	 *
	 * @param string $handoffId The handoff's uuid.
	 *
	 * @return ResearchRunState|null
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function open(string $handoffId): ?ResearchRunState {
		$handoff = $this->handoffs->find(handoffId: $handoffId);
		if (($handoff['kind'] ?? '') !== HandoffService::RESEARCH || ($handoff['status'] ?? '') !== HandoffService::WAITING) {
			return null;
		}

		return new ResearchRunState(handoffId: $handoffId, handoff: $handoff, now: $this->now());
	}//end open()

	/**
	 * Whether the person pressed "Stop research", read fresh. A handoff that is gone
	 * or no longer waiting counts as stopped.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function stopRequested(ResearchRunState $state): bool {
		$handoff = $this->handoffs->find(handoffId: $state->handoffId);
		if (($handoff['status'] ?? '') !== HandoffService::WAITING) {
			return true;
		}

		return (($handoff['stopRequested'] ?? false) === true);
	}//end stopRequested()

	/**
	 * Write the run's plan, ledger and progress onto its handoff.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function save(ResearchRunState $state): void {
		$this->handoffs->update(
			handoffId: $state->handoffId,
			fields: ['plan' => $state->plan, 'ledger' => $state->ledger->toArray(), 'progress' => $state->progress()]
		);
	}//end save()

	/**
	 * Post a message from the research into its conversation.
	 *
	 * @param ResearchRunState $state   The run.
	 * @param string           $content The message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function post(ResearchRunState $state, string $content): void {
		$this->messages->storeMessage(conversationId: $state->conversationId, role: 'assistant', content: $content);
	}//end post()

	/**
	 * Close the run with its report.
	 *
	 * @param ResearchRunState     $state  The run.
	 * @param array<string, mixed> $result The report and its counts.
	 *
	 * @return string `done`.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	public function finish(ResearchRunState $state, array $result): string {
		$state->phase = 'done';
		$this->save(state: $state);
		$this->handoffs->close(handoffId: $state->handoffId, status: 'done', result: $result);

		return 'done';
	}//end finish()

	/**
	 * End the run without a report: say why in the conversation and close the handoff.
	 *
	 * @param ResearchRunState $state  The run.
	 * @param string           $reason The sentence the conversation shows.
	 *
	 * @return string `ended`.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function end(ResearchRunState $state, string $reason): string {
		try {
			$this->post(state: $state, content: $reason);
		} catch (Throwable $e) {
			$this->logger->warning('[ResearchRecord] The end of a research run could not be posted: ' . $e->getMessage(), ['handoffId' => $state->handoffId]);
		}

		$this->handoffs->close(handoffId: $state->handoffId, status: 'failed', result: ['reason' => $reason, 'progress' => $state->progress()]);

		return 'ended';
	}//end end()

	/**
	 * End a run that failed: log why, and end it with the general sentence.
	 *
	 * @param ResearchRunState $state The run.
	 * @param Throwable        $error What went wrong.
	 *
	 * @return string `ended`.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function fail(ResearchRunState $state, Throwable $error): string {
		$this->logger->warning(
			'[ResearchRecord] The research run failed: ' . $error->getMessage(),
			['handoffId' => $state->handoffId, 'exception' => $error]
		);

		return $this->end(state: $state, reason: ResearchRunner::FAILED);
	}//end fail()

	/**
	 * Now, from the injected clock.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable('@' . $this->time->getTime());
	}//end now()
}//end class
