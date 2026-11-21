<?php

/**
 * Hermiq ResearchRunState.
 *
 * What one research run knows while it runs: who it runs for, its caps, its
 * plan, its ledger, and how much it has used. Read from the research handoff
 * when the job starts; written back after each phase and sub-question.
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
use Exception;

/**
 * The state of one research run.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
final class ResearchRunState {

	/**
	 * Tool calls made so far.
	 *
	 * @var integer
	 */
	public int $toolCalls = 0;

	/**
	 * Tokens used so far.
	 *
	 * @var integer
	 */
	public int $tokens = 0;

	/**
	 * The phase: plan, gather, write or done.
	 *
	 * @var string
	 */
	public string $phase = 'plan';

	/**
	 * The report's first line when a cap ended the gathering, else null.
	 *
	 * @var string|null
	 */
	public ?string $capReason = null;

	/**
	 * The sub-questions.
	 *
	 * @var list<string>
	 */
	public array $plan = [];

	/**
	 * Constructor.
	 *
	 * @param string            $handoffId      The research handoff.
	 * @param string            $question       The research question.
	 * @param string            $userId         The user it runs for.
	 * @param string            $agentId        The agent that runs it.
	 * @param string            $conversationId The conversation it reports to.
	 * @param string            $organisation   The organisation whose gates apply.
	 * @param ResearchCaps      $caps           The run's caps.
	 * @param DateTimeImmutable $startedAt      When the run was started.
	 * @param SourceLedger      $ledger         What the run has read.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One value object holding the run's fixed identity.
	 */
	public function __construct(
		public readonly string $handoffId,
		public readonly string $question,
		public readonly string $userId,
		public readonly string $agentId,
		public readonly string $conversationId,
		public readonly string $organisation,
		public readonly ResearchCaps $caps,
		public readonly DateTimeImmutable $startedAt,
		public readonly SourceLedger $ledger,
	) {
	}//end __construct()

	/**
	 * The state of the run a stored research handoff describes.
	 *
	 * @param string               $handoffId The handoff's uuid.
	 * @param array<string, mixed> $handoff   The stored handoff.
	 * @param DateTimeImmutable    $now       Now, the start when the handoff names none.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ResearchCaps and SourceLedger are read back through their named constructors; neither has state to inject.
	 */
	public static function fromHandoff(string $handoffId, array $handoff, DateTimeImmutable $now): self {
		$state = new self(
			handoffId: $handoffId,
			question: (string)($handoff['label'] ?? ''),
			userId: (string)($handoff['requestedBy'] ?? ''),
			agentId: (string)($handoff['agentId'] ?? ''),
			conversationId: (string)($handoff['conversationId'] ?? ''),
			organisation: (string)($handoff['organisation'] ?? ''),
			caps: ResearchCaps::fromSettings(settings: (array)($handoff['caps'] ?? [])),
			startedAt: self::startOf(requestedAt: (string)($handoff['requestedAt'] ?? ''), now: $now),
			ledger: SourceLedger::fromArray(rows: (array)($handoff['ledger'] ?? []))
		);
		$state->plan = array_values(array_filter((array)($handoff['plan'] ?? []), 'is_string'));

		return $state;
	}//end fromHandoff()

	/**
	 * Seconds since the run was started.
	 *
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function elapsedSeconds(DateTimeImmutable $now): int {
		return max(0, ($now->getTimestamp() - $this->startedAt->getTimestamp()));
	}//end elapsedSeconds()

	/**
	 * The progress as stored on the handoff.
	 *
	 * @return array{phase: string, toolCalls: int, sources: int, tokens: int}
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function progress(): array {
		return [
			'phase'     => $this->phase,
			'toolCalls' => $this->toolCalls,
			'sources'   => $this->ledger->count(),
			'tokens'    => $this->tokens,
		];
	}//end progress()

	/**
	 * The start the handoff records, or now when it records none that reads as a date.
	 *
	 * @param string            $requestedAt The stored start.
	 * @param DateTimeImmutable $now         Now.
	 *
	 * @return DateTimeImmutable
	 */
	private static function startOf(string $requestedAt, DateTimeImmutable $now): DateTimeImmutable {
		if ($requestedAt === '') {
			return $now;
		}

		try {
			return new DateTimeImmutable($requestedAt);
		} catch (Exception $e) {
			return $now;
		}
	}//end startOf()
}//end class
