<?php

/**
 * Hermiq ResearchAuthor.
 *
 * The model calls of a research run (design D2, D4): every call is preceded
 * by the gate (the person's stop, the kill switch, the budget), carries the
 * agent owner's prompt first, and is counted against the token cap.
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

/**
 * Gates and makes a research run's model calls.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
class ResearchAuthor {
	/**
	 * Constructor.
	 *
	 * @param ResearchModel   $model   The model.
	 * @param ResearchGate    $gate    The stop, kill switch and budget check.
	 * @param ResearchObjects $objects Reads the agent owner's prompt.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ResearchModel $model,
		private readonly ResearchGate $gate,
		private readonly ResearchObjects $objects,
	) {
	}//end __construct()

	/**
	 * Why the next model call may not be made, or null.
	 *
	 * @param ResearchRunState $state         The run.
	 * @param bool             $stopRequested Whether the person pressed "Stop research".
	 *
	 * @return string|null The sentence that ends the run.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The person's stop is one input of the gate, read fresh by the caller.
	 */
	public function blocked(ResearchRunState $state, bool $stopRequested): ?string {
		return $this->gate->endReason(organisation: $state->organisation, agentId: $state->agentId, stopRequested: $stopRequested);
	}//end blocked()

	/**
	 * One model call with the agent owner's prompt first, its size counted against the token cap.
	 *
	 * The size is estimated as characters / 4 of the prompt and the answer: the
	 * provider path a research run uses answers text only.
	 *
	 * @param ResearchRunState $state  The run.
	 * @param string           $prompt The phase's prompt.
	 *
	 * @return string The answer.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function generate(ResearchRunState $state, string $prompt): string {
		$agentPrompt = $this->objects->agentPrompt(agentId: $state->agentId);
		if ($agentPrompt !== '') {
			$prompt = $agentPrompt . "\n\n" . $prompt;
		}

		$answer = $this->model->generate(prompt: $prompt, userId: $state->userId, organisation: $state->organisation);
		$state->tokens += (int)ceil((mb_strlen($prompt) + mb_strlen($answer)) / 4);

		return $answer;
	}//end generate()
}//end class
