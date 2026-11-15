<?php

/**
 * Unit tests for AllowedModels: the model candidates a policy allows.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
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
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AllowedModels;
use PHPUnit\Framework\TestCase;

/**
 * A policy's allowed entries become `provider/model` candidates, or the bare
 * provider when it allows any of its models.
 *
 * @covers \OCA\Hermiq\Service\Agent\AllowedModels
 */
class AllowedModelsTest extends TestCase {

	/**
	 * Every listed model of a provider is one candidate, in policy order.
	 *
	 * @return void
	 */
	public function testEachListedModelIsOneCandidate(): void {
		$allowed = new AllowedModels(
			allowed: [
				['provider' => 'mistral', 'models' => ['mistral-large', 'mistral-small']],
				['provider' => 'ollama', 'models' => ['llama3']],
			]
		);

		$this->assertSame(
			['mistral/mistral-large', 'mistral/mistral-small', 'ollama/llama3'],
			$allowed->candidates()
		);

	}//end testEachListedModelIsOneCandidate()

	/**
	 * A provider with no model list (empty or absent) allows any model: the provider alone.
	 *
	 * @return void
	 */
	public function testAProviderWithoutModelsIsTheProviderAlone(): void {
		$allowed = new AllowedModels(
			allowed: [
				['provider' => 'openai', 'models' => []],
				['provider' => 'anthropic'],
				['provider' => 'ollama', 'models' => ['llama3']],
			]
		);

		$this->assertSame(['openai', 'anthropic', 'ollama/llama3'], $allowed->candidates());

	}//end testAProviderWithoutModelsIsTheProviderAlone()

	/**
	 * A single model given as text counts as a list of one; an entry without a
	 * provider still yields its models, under an empty provider.
	 *
	 * @return void
	 */
	public function testLooseEntriesAreReadLeniently(): void {
		$allowed = new AllowedModels(
			allowed: [
				['provider' => 'mistral', 'models' => 'mistral-large'],
				['models' => ['orphan']],
			]
		);

		$this->assertSame(['mistral/mistral-large', '/orphan'], $allowed->candidates());

	}//end testLooseEntriesAreReadLeniently()

	/**
	 * A policy allowing nothing has no candidates.
	 *
	 * @return void
	 */
	public function testNoEntriesNoCandidates(): void {
		$this->assertSame([], (new AllowedModels(allowed: []))->candidates());

	}//end testNoEntriesNoCandidates()
}//end class
