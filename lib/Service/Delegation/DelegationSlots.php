<?php

/**
 * Hermiq DelegationSlots
 *
 * The slots of one `hermiq.delegateAgents` call, in call order: a part that
 * passed its gates and waits to run, or the error slot of a part that did
 * not. It counts the passed parts, which is what each later part's fan-out
 * check adds to the turn's own count.
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
 * The ordered slots of a parallel delegation.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */
final class DelegationSlots {

	/**
	 * The slots, in call order.
	 *
	 * @var array<int, PassedDelegation|array<string, mixed>>
	 */
	private array $slots = [];

	/**
	 * How many slots passed their gates.
	 *
	 * @var int
	 */
	private int $passed = 0;

	/**
	 * Add the next part's slot.
	 *
	 * @param PassedDelegation|array<string, mixed> $slot The passed part, or its error slot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function add(PassedDelegation|array $slot): void {
		$this->slots[] = $slot;
		if ($slot instanceof PassedDelegation) {
			$this->passed++;
		}
	}//end add()

	/**
	 * How many parts so far passed their gates.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function passed(): int {
		return $this->passed;
	}//end passed()

	/**
	 * Run every passed part, in call order, and give each slot its result.
	 *
	 * @param callable(PassedDelegation): array<string, mixed> $run Runs one passed part.
	 *
	 * @return array<int, array<string, mixed>> One result per part, in call order.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function results(callable $run): array {
		$results = [];
		foreach ($this->slots as $slot) {
			if (is_array($slot) === true) {
				$results[] = $slot;
				continue;
			}

			$results[] = ['targetAgentId' => (string)$slot->targetAgent->getUuid()] + $run($slot);
		}

		return $results;
	}//end results()
}//end class
