<?php

/**
 * A research model double that answers a script (tools-deep-research-report).
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research\Fakes;

use OCA\Hermiq\Service\Research\ResearchModel;
use RuntimeException;

/**
 * Answers the scripted answers in order, and fails like a provider that is down when none is left.
 */
class ScriptedResearchModel implements ResearchModel {

	/**
	 * The prompts it was asked, in order.
	 *
	 * @var list<string>
	 */
	public array $prompts = [];

	/**
	 * Constructor.
	 *
	 * @param list<string>  $answers The answers, in order.
	 * @param EventLog|null $log     Where each call is noted as `model`.
	 *
	 * @return void
	 */
	public function __construct(
		private array $answers,
		private readonly ?EventLog $log = null,
	) {
	}//end __construct()

	/**
	 * The next scripted answer.
	 *
	 * @param string $prompt       The prompt.
	 * @param string $userId       The person.
	 * @param string $organisation The organisation.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the script is spent.
	 */
	public function generate(string $prompt, string $userId, string $organisation): string {
		$this->prompts[] = $prompt;
		$this->log?->note(event: 'model');
		if ($this->answers === []) {
			throw new RuntimeException('provider down');
		}

		return array_shift($this->answers);
	}//end generate()
}//end class
