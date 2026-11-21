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
	 * The research question.
	 *
	 * @var string
	 */
	public readonly string $question;

	/**
	 * The person the run is for.
	 *
	 * @var string
	 */
	public readonly string $userId;

	/**
	 * The agent that runs it.
	 *
	 * @var string
	 */
	public readonly string $agentId;

	/**
	 * The conversation it reports to.
	 *
	 * @var string
	 */
	public readonly string $conversationId;

	/**
	 * The organisation whose kill switch and budget apply.
	 *
	 * @var string
	 */
	public readonly string $organisation;

	/**
	 * The run's caps.
	 *
	 * @var ResearchCaps
	 */
	public readonly ResearchCaps $caps;

	/**
	 * When the run was started.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $startedAt;

	/**
	 * What the run has read.
	 *
	 * @var SourceLedger
	 */
	public readonly SourceLedger $ledger;

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
	 * The state of the run a stored research handoff describes.
	 *
	 * @param string               $handoffId The handoff's uuid.
	 * @param array<string, mixed> $handoff   The stored handoff.
	 * @param DateTimeImmutable    $now       Now, the start when the handoff names none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ResearchCaps and SourceLedger are read back through their named
	 * constructors; neither has state to inject.
	 */
	public function __construct(
		public readonly string $handoffId,
		array $handoff,
		DateTimeImmutable $now,
	) {
		$this->question       = (string)($handoff['label'] ?? '');
		$this->userId         = (string)($handoff['requestedBy'] ?? '');
		$this->agentId        = (string)($handoff['agentId'] ?? '');
		$this->conversationId = (string)($handoff['conversationId'] ?? '');
		$this->organisation   = (string)($handoff['organisation'] ?? '');
		$this->caps           = ResearchCaps::fromSettings(settings: (array)($handoff['caps'] ?? []));
		$this->startedAt      = self::startOf(requestedAt: (string)($handoff['requestedAt'] ?? ''), now: $now);
		$this->ledger         = SourceLedger::fromArray(rows: (array)($handoff['ledger'] ?? []));
		$this->plan           = array_values(array_filter((array)($handoff['plan'] ?? []), 'is_string'));
	}//end __construct()

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
