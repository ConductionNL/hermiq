<?php

/**
 * Minimal OpenRegister FlowNodeResumeState stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/FlowNodeResumeState.php.
 * This is the state a SUSPENDING node keeps across wakes, and it is the only
 * place such state can live: throwing FlowSuspension abandons the step, so every
 * item mutation made on the way to the throw is discarded. Registered at TEST
 * TIME only by tests/bootstrap.php and scanned (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal FlowNodeResumeState stub.
 */
class FlowNodeResumeState {
	/**
	 * The run-context key this object is carried under.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'resume';

	/**
	 * Constructor.
	 *
	 * @param string $nodeId The node this state belongs to.
	 * @param array<string,mixed> $values What the node kept last time.
	 * @param bool $resuming Whether this pass is a resume rather than a first entry.
	 */
	public function __construct(
		private readonly string $nodeId = '',
		private array $values = [],
		private readonly bool $resuming = false,
	) {
	}//end __construct()

	/**
	 * The node this state belongs to.
	 *
	 * @return string
	 */
	public function nodeId(): string {
		return $this->nodeId;
	}//end nodeId()

	/**
	 * Whether this pass is a resume.
	 *
	 * @return bool
	 */
	public function isResuming(): bool {
		return $this->resuming;
	}//end isResuming()

	/**
	 * One kept value.
	 *
	 * @param string $key The key.
	 * @param mixed $default What to answer when it is absent.
	 *
	 * @return mixed
	 */
	public function get(string $key, mixed $default = null): mixed {
		return ($this->values[$key] ?? $default);
	}//end get()

	/**
	 * Whether a key was kept.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	public function has(string $key): bool {
		return array_key_exists($key, $this->values);
	}//end has()

	/**
	 * Keep one value.
	 *
	 * @param string $key The key.
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	public function set(string $key, mixed $value): void {
		$this->values[$key] = $value;
	}//end set()

	/**
	 * Keep several values.
	 *
	 * @param array<string,mixed> $values The values.
	 *
	 * @return void
	 */
	public function merge(array $values): void {
		$this->values = array_merge($this->values, $values);
	}//end merge()
}//end class
