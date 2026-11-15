<?php

/**
 * Unit tests for TurnSystemPrompt.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\TurnSystemPrompt;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The system prompt of a turn: the agent's instructions, filled, plus the
 * preamble, the app context and the retrieved context.
 *
 * @covers \OCA\Hermiq\Service\Engine\TurnSystemPrompt
 * @uses   \OCA\Hermiq\Service\Engine\ResponseTurnRun
 * @uses   \OCA\Hermiq\Service\Engine\TurnProgress
 * @uses   \OCA\Hermiq\Service\Engine\PromptVariableResolver
 */
final class TurnSystemPromptTest extends TestCase {
	private function turn(array $agentData = [], array $context = []): ResponseTurnRun {
		$run = new ResponseTurnRun('hi', $context);
		if ($agentData !== []) {
			$agent = new ObjectEntity();
			$agent->setObject($agentData);
			$run->agent = $agent;
		}
		return $run;
	}

	public function testWithoutAgentTheDefaultPromptIsUsed(): void {
		$this->assertSame(
			'You are a helpful AI assistant that helps users find and understand their data.',
			(new TurnSystemPrompt())->compose($this->turn())
		);
	}

	public function testTheAgentPromptIsFilledWithItsVariables(): void {
		$run = $this->turn(['prompt' => 'Help {{ user }} with {{missing}}.']);
		$run->promptVariables = ['user' => 'Alice'];

		$this->assertSame('Help Alice with {{missing}}.', (new TurnSystemPrompt())->compose($run));
	}

	public function testThePreambleFollowsThePrompt(): void {
		$run = $this->turn(['prompt' => 'P']);
		$run->contextPreamble = 'You are on page X.';

		$this->assertSame("P\n\nYou are on page X.", (new TurnSystemPrompt())->compose($run));
	}

	public function testTheAppContextListsScalarsAndEncodesTheRest(): void {
		$run = $this->turn(['prompt' => 'P']);
		$run->cnAiContext = ['app' => 'pipelinq', 'count' => 3, 'route' => ['path' => '/a/b']];

		$this->assertSame(
			"P\n\nCURRENT APP CONTEXT (this is where the user is RIGHT NOW — prefer tools that match this app):\n"
			. "- app: pipelinq\n- count: 3\n- route: {\"path\":\"/a/b\"}\n",
			(new TurnSystemPrompt())->compose($run)
		);
	}

	public function testRetrievedContextIsAppendedLast(): void {
		$run = $this->turn(['prompt' => 'P'], ['text' => 'Doc 1 says yes.']);
		$run->contextPreamble = 'Pre';
		$run->cnAiContext = ['app' => 'x'];

		$prompt = (new TurnSystemPrompt())->compose($run);

		$this->assertStringStartsWith("P\n\nPre\n\nCURRENT APP CONTEXT", $prompt);
		$this->assertStringEndsWith(
			"\n\nUse the following context to answer the user's question:\n\nCONTEXT:\nDoc 1 says yes.\n\n"
			. "If the context doesn't contain relevant information, say so honestly. Always cite which sources you used when answering.",
			$prompt
		);
	}

	public function testEmptyRetrievedTextAddsNothing(): void {
		$this->assertSame('P', (new TurnSystemPrompt())->compose($this->turn(['prompt' => 'P'], ['text' => '', 'sources' => [1]])));
	}
}
