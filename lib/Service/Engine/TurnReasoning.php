<?php

/**
 * Hermiq TurnReasoning.
 *
 * The model's reasoning as it may be stored on an answer (chat-how-an-answer-came-about
 * D3): through the organisation's output filter, then redaction, before it is
 * stored or sent. A blocked reasoning is not stored; the answer says it was
 * withheld. Without redaction available nothing is stored: redaction before
 * persist is not optional (hermiq ADR-004).
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\RedactionService;

/**
 * Reviews one turn's reasoning.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */
final class TurnReasoning {

	/**
	 * Constructor.
	 *
	 * @param TurnGuardrail         $guardrail The turn's guardrail, its policy resolved.
	 * @param RedactionService|null $redaction Masks secrets and personal data; null stores nothing.
	 */
	public function __construct(
		private readonly TurnGuardrail $guardrail,
		private readonly ?RedactionService $redaction,
	) {
	}//end __construct()

	/**
	 * The reasoning to store, and whether the filter withheld it.
	 *
	 * @param string $raw The reasoning the provider returned, '' for none.
	 *
	 * @return array{reasoning: string|null, reasoningWithheld: bool}
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	public function review(string $raw): array {
		if (trim($raw) === '' || $this->redaction === null) {
			return ['reasoning' => null, 'reasoningWithheld' => false];
		}

		$filtered = $this->guardrail->screenReasoning(text: $raw);
		if ($filtered === null) {
			return ['reasoning' => null, 'reasoningWithheld' => true];
		}

		return ['reasoning' => $this->redaction->redact(text: $filtered), 'reasoningWithheld' => false];
	}//end review()
}//end class
