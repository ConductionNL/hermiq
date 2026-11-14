<?php

/**
 * A turn of an agent in validated mode resolves no tools.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Engine\ResponseGenerationHandler
 * @uses \OCA\Hermiq\Service\RetrievalScope\ValidatedMode
 */
class ResponseGenerationValidatedToolsTest extends TestCase {

	/**
	 * Answer one turn of an agent, with a tool loop that offers one tool.
	 *
	 * @param array<string, mixed> $agentData The agent.
	 * @param int                  $listed    How often the tools may be listed.
	 *
	 * @return string The answer.
	 */
	private function answer(array $agentData, int $listed): string {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(provider: 'fireworks', chat: null, model: 'llama', credentialId: 'cred', baseUrl: 'https://example.test/v1')
		);
		$factory->method('callFireworksChat')->willReturn('Het KCC is open van 8 tot 17 uur.');

		$loop = $this->createMock(ToolLoop::class);
		$loop->expects($this->exactly($listed))->method('listAgentFunctions')->willReturn([]);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject($agentData + ['prompt' => 'You answer citizens.', 'tools' => ['openregister.objects.search']]);

		return (new ResponseGenerationHandler($factory, $loop, new NullLogger()))->generateResponse(
			userMessage: 'Wanneer is het KCC open?',
			context: ['text' => 'Openingstijden KCC: 8 tot 17 uur.', 'sources' => []],
			messageHistory: [],
			agent: $agent,
			selectedTools: ['openregister.objects.search']
		);
	}//end answer()

	public function testAValidatedTurnListsNoTools(): void {
		$this->assertSame('Het KCC is open van 8 tot 17 uur.', $this->answer(['answerOnlyFromValidated' => true], 0));
	}//end testAValidatedTurnListsNoTools()

	public function testAnOrdinaryTurnStillListsItsTools(): void {
		$this->assertSame('Het KCC is open van 8 tot 17 uur.', $this->answer([], 1));
	}//end testAnOrdinaryTurnStillListsItsTools()
}//end class
