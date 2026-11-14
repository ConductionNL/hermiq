<?php

/**
 * Hermiq ContextUsageMeter.
 *
 * How full the model's context is after a turn (chat-how-an-answer-came-about D4):
 * the tokens the session now uses and, when declared, the model's window. The
 * provider's own count is used when it reported one for a single request; any
 * other number is an estimate of four characters per token, and says so.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Measures one turn.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */
final class ContextUsageMeter {

	/**
	 * Characters per token of the estimate.
	 *
	 * @var int
	 */
	public const CHARACTERS_PER_TOKEN = 4;

	/**
	 * The turn's context usage.
	 *
	 * The provider's count stands only for a turn that made no tool calls: a tool
	 * loop sends several requests, and their prompt counts summed are not the size
	 * of the context, so such a turn is estimated.
	 *
	 * @param array<string, mixed> $usage           The provider's usage (`promptTokens`, `completionTokens`).
	 * @param int                  $promptCharacters The characters of every message the model was sent.
	 * @param string               $answer          The stored answer.
	 * @param int|null             $windowTokens    The model's declared window, or null.
	 * @param bool                 $usedTools       Whether the turn made tool calls.
	 *
	 * @return array{usedTokens: int, windowTokens: int|null, estimated: bool}
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
	 */
	public function measure(array $usage, int $promptCharacters, string $answer, ?int $windowTokens, bool $usedTools): array {
		$prompt = $usage['promptTokens'] ?? null;
		if ($usedTools === false && is_int($prompt) === true && $prompt > 0) {
			$completion = $usage['completionTokens'] ?? 0;
			if (is_int($completion) === false || $completion < 0) {
				$completion = 0;
			}

			return ['usedTokens' => ($prompt + $completion), 'windowTokens' => $windowTokens, 'estimated' => false];
		}

		$characters = (max(0, $promptCharacters) + mb_strlen($answer));

		return [
			'usedTokens' => (int)ceil($characters / self::CHARACTERS_PER_TOKEN),
			'windowTokens' => $windowTokens,
			'estimated' => true,
		];
	}//end measure()
}//end class
