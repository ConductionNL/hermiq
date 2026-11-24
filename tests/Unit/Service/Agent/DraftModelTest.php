<?php

/**
 * Unit tests for DraftModel: the provider and model a draft proposes.
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
 * @spec openspec/changes/archive/2026-11-24-agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\DraftModel;
use PHPUnit\Framework\TestCase;

/**
 * The model a draft wants is written `provider/model`, the same notation the
 * policy candidates use, so the two compare directly.
 *
 * @covers \OCA\Hermiq\Service\Agent\DraftModel
 */
class DraftModelTest extends TestCase {

	/**
	 * The wanted model joins provider and model with one slash.
	 *
	 * @return void
	 */
	public function testWantedJoinsProviderAndModel(): void {
		$this->assertSame('mistral/mistral-large', (new DraftModel(provider: 'mistral', model: 'mistral-large'))->wanted());

	}//end testWantedJoinsProviderAndModel()

	/**
	 * Nothing is trimmed or rewritten: an empty part stays empty around the slash.
	 *
	 * @return void
	 */
	public function testWantedKeepsThePartsVerbatim(): void {
		$this->assertSame('openai/', (new DraftModel(provider: 'openai', model: ''))->wanted());
		$this->assertSame('/gpt-4o', (new DraftModel(provider: '', model: 'gpt-4o'))->wanted());
		$this->assertSame('Ollama/Llama 3', (new DraftModel(provider: 'Ollama', model: 'Llama 3'))->wanted());

	}//end testWantedKeepsThePartsVerbatim()
}//end class
