<?php

/**
 * Hermiq ResearchRequest.
 *
 * What a person asks when they start a research run (design D6): which agent
 * researches, in which conversation, what question, how deep. Refused when
 * any part is missing or the depth is unknown.
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

use InvalidArgumentException;

/**
 * A request to start a research run.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
final class ResearchRequest {

	/**
	 * The depths a run may have.
	 */
	public const DEPTHS = ['quick', 'thorough'];

	/**
	 * The question, trimmed.
	 *
	 * @var string
	 */
	public readonly string $question;

	/**
	 * Constructor.
	 *
	 * @param string $agentId        The agent that researches.
	 * @param string $conversationId The conversation the plan and the report go to.
	 * @param string $question       The research question.
	 * @param string $depth          `quick` or `thorough`.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a part is missing or the depth is unknown.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly string $conversationId,
		string $question,
		public readonly string $depth = 'thorough',
	) {
		$this->question = trim($question);
		if ($this->question === '' || $agentId === '' || $conversationId === '') {
			throw new InvalidArgumentException('A research run needs a question, an agent and a conversation.');
		}

		if (in_array($depth, self::DEPTHS, true) === false) {
			throw new InvalidArgumentException('The depth is quick or thorough.');
		}
	}//end __construct()
}//end class
