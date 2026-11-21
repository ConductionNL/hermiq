<?php

/**
 * Hermiq AskPersonCall
 *
 * One `hermiq.askPerson` call in a run: the question checked, recorded on the run, and the turn ended.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use InvalidArgumentException;

/**
 * An invalid question, or a second one in the same run, is refused and the turn
 * goes on. A valid one is recorded on the trace, where the chat answer and an
 * unattended run read it, and the turn stops, so the model does not carry on as
 * if it had an answer.
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */
final class AskPersonCall {

	/**
	 * What the model is told once its question is sent.
	 *
	 * @var string
	 */
	public const QUESTION_SENT = 'Question sent to the person. End your answer now.';

	/**
	 * Why the turn ends after a question.
	 *
	 * @var string
	 */
	public const WAITING_FOR_ANSWER = 'Waiting for the person to answer';

	/**
	 * Constructor.
	 *
	 * @param TurnGuard               $turnGuard The turn's guard, stopped once the question is sent.
	 * @param RunTraceCollector|null  $trace     The run trace that carries the question.
	 * @param StreamYieldChannel|null $channel   The chat stream, when the run is streamed.
	 */
	public function __construct(
		private readonly TurnGuard $turnGuard,
		private readonly ?RunTraceCollector $trace,
		private readonly ?StreamYieldChannel $channel,
	) {
	}//end __construct()

	/**
	 * Handle the call.
	 *
	 * @param array<string, mixed> $arguments Decoded arguments (`{question, options, allowOther}`).
	 *
	 * @return string JSON-encoded tool result.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
	 */
	public function answer(array $arguments): string {
		$this->channel?->emitToolCall(payload: ['toolId' => 'hermiq.askPerson', 'arguments' => $arguments]);
		try {
			if ($this->trace?->question() !== null) {
				throw new InvalidArgumentException('One question per run: wait for the answer to the first.');
			}

			$question = new AskPersonQuestion(arguments: $arguments);
		} catch (InvalidArgumentException $invalid) {
			$refusal = ['result' => ['error' => $invalid->getMessage()], 'isError' => true];
			$this->channel?->emitToolResult(payload: (['toolId' => 'hermiq.askPerson'] + $refusal));
			return (string)json_encode($refusal);
		}

		$this->trace?->recordQuestion(question: $question->toArray());
		$this->turnGuard->stop(reason: self::WAITING_FOR_ANSWER);
		$this->channel?->emitToolResult(payload: ['toolId' => 'hermiq.askPerson', 'result' => self::QUESTION_SENT, 'isError' => false]);

		return (string)json_encode(['result' => self::QUESTION_SENT, 'isError' => false]);
	}//end answer()
}//end class
