<?php

/**
 * A governed-invoker double that records every call (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research\Fakes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research\Fakes;

/**
 * Answers every function call like FacadeToolInvoker does: a JSON string.
 */
class RecordingInvoker {

	/**
	 * The calls, as [name, arguments].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * The JSON answer per function name; '{}' when none is set.
	 *
	 * @var array<string, string>
	 */
	public array $answers = [];

	/**
	 * Record the call and answer its JSON.
	 *
	 * @param string               $name      The function name.
	 * @param array<string, mixed> $arguments The named arguments.
	 *
	 * @return string
	 */
	public function __call(string $name, array $arguments): string {
		$this->calls[] = [$name, $arguments];

		return ($this->answers[$name] ?? '{}');
	}//end __call()
}//end class
