<?php

/**
 * Hermiq DelegationPart
 *
 * One part of a `hermiq.delegateAgents` call as the model sent it: the agent
 * to hand it to and its task, both trimmed. A value that is not a string or a
 * number reads as empty, so a malformed part is told apart from a good one
 * without trusting its shape.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delegation
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
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delegation;

/**
 * One part of a parallel delegation, read from the model's arguments.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */
final class DelegationPart {

	/**
	 * The agent to hand the part to, trimmed; '' when absent.
	 *
	 * @var string
	 */
	public readonly string $targetAgentId;

	/**
	 * The part's task, trimmed; '' when absent.
	 *
	 * @var string
	 */
	public readonly string $task;

	/**
	 * Read a part from what the model sent.
	 *
	 * @param mixed $part The LLM-supplied part.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function __construct(mixed $part) {
		$fields = [];
		if (is_array($part) === true) {
			$fields = $part;
		}

		$this->targetAgentId = $this->text(value: ($fields['targetAgentId'] ?? null));
		$this->task = $this->text(value: ($fields['task'] ?? null));
	}//end __construct()

	/**
	 * Whether the part names both an agent and a task.
	 *
	 * @return bool True when both are present.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function isComplete(): bool {
		return $this->targetAgentId !== '' && $this->task !== '';
	}//end isComplete()

	/**
	 * This part's slot in the result: the outcome, naming the agent it was for.
	 *
	 * @param array<string, mixed> $outcome The part's result or error envelope.
	 *
	 * @return array<string, mixed> The slot.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function slot(array $outcome): array {
		return ['targetAgentId' => $this->targetAgentId] + $outcome;
	}//end slot()

	/**
	 * A scalar as trimmed text; anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
