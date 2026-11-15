<?php

/**
 * Hermiq Llm run-value construction unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\AnthropicChatRun;
use OCA\Hermiq\Service\Llm\AnthropicToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriverRequest;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the values these run objects derive at construction.
 *
 * @covers \OCA\Hermiq\Service\Llm\AnthropicToolLoop
 * @covers \OCA\Hermiq\Service\Llm\ChatDriverRequest
 * @covers \OCA\Hermiq\Service\Llm\ModelPolicyViolationException
 * @uses   \OCA\Hermiq\Service\Llm\AnthropicChatRun
 */
class LlmRunValuesTest extends TestCase {

	/**
	 * The loop posts to `<base>/messages` with a single slash and takes the default cap when the run sets none.
	 *
	 * @return void
	 */
	public function testTheToolLoopJoinsTheUrlAndDefaultsTheCap(): void {
		$run = new AnthropicChatRun(credentialId: 'cred', model: 'claude-sonnet-5');
		$run->baseUrl = 'https://api.anthropic.com/v1/';

		$loop = new AnthropicToolLoop(run: $run, defaultCap: 12);

		$this->assertSame('https://api.anthropic.com/v1/messages', $loop->url);
		$this->assertSame(12, $loop->cap);
		$this->assertSame($run, $loop->run);
	}//end testTheToolLoopJoinsTheUrlAndDefaultsTheCap()

	/**
	 * A run-level cap wins over the default, including zero.
	 *
	 * @return void
	 */
	public function testARunCapWinsOverTheDefault(): void {
		$run = new AnthropicChatRun(credentialId: 'cred', model: 'claude-sonnet-5');
		$run->baseUrl = 'http://proxy';
		$run->maxToolCalls = 0;

		$loop = new AnthropicToolLoop(run: $run, defaultCap: 12);

		$this->assertSame('http://proxy/messages', $loop->url);
		$this->assertSame(0, $loop->cap);
	}//end testARunCapWinsOverTheDefault()

	/**
	 * The request takes its chat provider from the config, and none when the config names none.
	 *
	 * @return void
	 */
	public function testTheRequestTakesTheChatProviderFromTheConfig(): void {
		$named = new ChatDriverRequest(llmConfig: ['chatProvider' => 'ollama'], organisation: 'org-1');
		$unnamed = new ChatDriverRequest(llmConfig: [], organisation: null);

		$this->assertSame('ollama', $named->chatProvider);
		$this->assertSame('org-1', $named->organisation);
		$this->assertNull($unnamed->chatProvider);
	}//end testTheRequestTakesTheChatProviderFromTheConfig()

	/**
	 * A model-policy violation names the model-policy step.
	 *
	 * @return void
	 */
	public function testAPolicyViolationNamesItsStep(): void {
		$violation = new ModelPolicyViolationException('model not allowed');

		$this->assertSame('model-policy', $violation->step());
		$this->assertSame(ModelPolicyViolationException::STEP, $violation->step());
		$this->assertSame('model not allowed', $violation->getMessage());
	}//end testAPolicyViolationNamesItsStep()
}//end class
