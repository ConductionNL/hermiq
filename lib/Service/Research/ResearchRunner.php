<?php

/**
 * Hermiq ResearchRunner.
 *
 * Runs one deep research run in the background (design D1, D2): plan, gather,
 * write, check. The plan is posted in the conversation before any search.
 * Every search and fetch goes through the agent's governed tool chain and
 * every page read gets a number in the source ledger. The report is written
 * from the ledger, its citations are checked against what was read, and it is
 * saved, marked, into the person's Files with a message in the conversation.
 *
 * The run stops (design D4): at a cap, the gathering ends and the report says
 * why in its first line; when the person pressed "Stop research", or the kill
 * switch or the budget blocks the next model call, the run ends without a
 * report and the conversation says why.
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use RuntimeException;
use Throwable;

/**
 * Runs a research handoff from plan to report.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchRunner {

	/**
	 * What the conversation says when the agent may not both search and read the web.
	 */
	public const NO_TOOLS = 'Research ended: this agent may not both search and read the web.';

	/**
	 * What the conversation says when the run failed for another reason.
	 */
	public const FAILED = 'Research ended: something went wrong, and no report was written.';

	/**
	 * The most search results read per sub-question.
	 */
	public const PAGES_PER_QUESTION = 5;

	/**
	 * Constructor.
	 *
	 * @param ResearchRecord $record The run's handoff, messages and clock.
	 * @param ResearchAuthor $author The gated model calls.
	 * @param ResearchTools  $tools  Opens the governed search and fetch tools.
	 * @param ReportWriter   $writer Saves and marks the report, and words the message.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ResearchRecord $record,
		private readonly ResearchAuthor $author,
		private readonly ResearchTools $tools,
		private readonly ReportWriter $writer,
	) {
	}//end __construct()

	/**
	 * Run one research handoff to its end.
	 *
	 * @param string $handoffId The research handoff's uuid.
	 *
	 * @return string `done` (a report was written), `ended` (stopped, blocked or failed,
	 *                no report) or `skipped` (not a waiting research handoff).
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function run(string $handoffId): string {
		$state = $this->record->open(handoffId: $handoffId);
		if ($state === null) {
			return 'skipped';
		}

		try {
			return $this->plan(state: $state)
				?? $this->gather(state: $state)
				?? $this->report(state: $state);
		} catch (Throwable $e) {
			return $this->record->fail(state: $state, error: $e);
		}
	}//end run()

	/**
	 * The plan phase: one model call, the plan stored and posted before any search.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return string|null The outcome when the run ended here, null to go on.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	private function plan(ResearchRunState $state): ?string {
		$blocked = $this->author->blocked(state: $state, stopRequested: $this->record->stopRequested(state: $state));
		if ($blocked !== null) {
			return $this->record->end(state: $state, reason: $blocked);
		}

		$planner = new ResearchPlan();
		$answer  = $this->author->generate(state: $state, prompt: $planner->prompt(question: $state->question));
		$state->plan  = $planner->parse(answer: $answer, question: $state->question);
		$state->phase = 'gather';
		$this->record->save(state: $state);
		$this->record->post(state: $state, content: $planner->message(plan: $state->plan));

		return null;
	}//end plan()

	/**
	 * The gather phase: per sub-question a search and the pages it found, until a cap.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return string|null The outcome when the run ended here, null to go on.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	private function gather(ResearchRunState $state): ?string {
		$toolbox = $this->tools->open(agentId: $state->agentId, organisation: $state->organisation, conversationId: $state->conversationId);
		if ($toolbox === null) {
			return $this->record->end(state: $state, reason: self::NO_TOOLS);
		}

		$status = null;
		foreach ($state->plan as $question) {
			$status = $this->gatherQuestion(state: $state, toolbox: $toolbox, question: $question);
			$this->record->save(state: $state);
			if ($status !== null) {
				break;
			}
		}

		if ($status === 'stopped') {
			return $this->record->end(state: $state, reason: ResearchGate::STOPPED);
		}

		$state->phase = 'write';
		$this->record->save(state: $state);

		return null;
	}//end gather()

	/**
	 * One sub-question: a search, then its first pages, each into the ledger.
	 *
	 * Before every tool call the caps and the person's stop are read again, so the
	 * run reads its last allowed page and then stops, and a stop ends it at the
	 * next step.
	 *
	 * @param ResearchRunState $state    The run.
	 * @param ResearchToolbox  $toolbox  The governed tools.
	 * @param string           $question The sub-question.
	 *
	 * @return string|null `cap` or `stopped` when gathering must end, null to go on.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	private function gatherQuestion(ResearchRunState $state, ResearchToolbox $toolbox, string $question): ?string {
		$halt = $this->halt(state: $state);
		if ($halt !== null) {
			return $halt;
		}

		$state->toolCalls++;
		foreach (array_slice($toolbox->search(query: $question), 0, self::PAGES_PER_QUESTION) as $result) {
			$halt = $this->halt(state: $state);
			if ($halt !== null) {
				return $halt;
			}

			$state->toolCalls++;
			$state->ledger->record(fetch: $toolbox->fetch(url: $result['url']), title: $result['title'], fetchedAt: $this->record->now());
		}

		return null;
	}//end gatherQuestion()

	/**
	 * Why gathering must end before the next tool call: `cap`, `stopped`, or null.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	private function halt(ResearchRunState $state): ?string {
		$state->capReason = $state->caps->stopReason(
			toolCalls: $state->toolCalls,
			sources: $state->ledger->count(),
			elapsedSeconds: $state->elapsedSeconds(now: $this->record->now()),
			tokens: $state->tokens
		);
		if ($state->capReason !== null) {
			return 'cap';
		}

		if ($this->record->stopRequested(state: $state) === true) {
			return 'stopped';
		}

		return null;
	}//end halt()

	/**
	 * The write and check phases: the report from the ledger, checked, saved and announced.
	 *
	 * @param ResearchRunState $state The run.
	 *
	 * @return string The outcome.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	private function report(ResearchRunState $state): string {
		$blocked = $this->author->blocked(state: $state, stopRequested: $this->record->stopRequested(state: $state));
		if ($blocked !== null) {
			return $this->record->end(state: $state, reason: $blocked);
		}

		$report = new ResearchReport();
		$check  = (new CitationChecker())->check(
			draft: $this->author->generate(state: $state, prompt: $report->prompt(state: $state)),
			ledger: $state->ledger
		);

		try {
			$saved = $this->writer->write(
				userId: $state->userId,
				title: $state->question,
				markdown: $report->render(state: $state, check: $check),
				date: $this->record->now()
			);
		} catch (RuntimeException $e) {
			return $this->record->end(state: $state, reason: $e->getMessage());
		}

		$counts = ['sources' => $state->ledger->count(), 'checked' => $check->checked, 'unverified' => $check->failed];
		$this->record->post(
			state: $state,
			content: $this->writer->message(
				summary: $report->summary(checked: $check->report),
				sources: $counts['sources'],
				checked: $counts['checked'],
				unverified: $counts['unverified'],
				report: $saved
			)
		);

		return $this->record->finish(state: $state, result: ['report' => $saved] + $counts);
	}//end report()
}//end class
