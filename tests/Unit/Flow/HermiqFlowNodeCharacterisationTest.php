<?php

/**
 * Pins what the agent and workload steps put on an item, before their split.
 *
 * The failure and identity tests next to this one cover the refusals. These
 * cover the happy paths nothing else pins: the prompt the agent receives (the
 * placeholder rendering and the JSON instruction), the session pointer, the
 * item shape (binary and pairing), and the asynchronous workload dispatch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqAgentNode;
use OCA\Hermiq\Flow\HermiqWorkloadNode;
use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\StageDispatchService;
use OCA\Hermiq\Service\StageRequest;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqAgentNode
 * @covers \OCA\Hermiq\Flow\HermiqWorkloadNode
 */
class HermiqFlowNodeCharacterisationTest extends TestCase {

	private const AGENT_ID = '352f552f-0000-0000-0000-000000000001';

	/**
	 * Every argument list the agent turn was called with.
	 *
	 * @var array<int, array>
	 */
	private array $turns = [];

	/**
	 * The arguments the last async dispatch was called with.
	 *
	 * @var array
	 */
	private array $asyncCall = [];

	/**
	 * A translator that formats its parameters in.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return $l10n;
	}//end l10n()

	/**
	 * An agent node whose turns answer as told and report a session.
	 *
	 * @param string $answer  What every turn answers.
	 * @param string $session The session uuid the last turn produced.
	 *
	 * @return HermiqAgentNode The node.
	 */
	private function agentNode(string $answer, string $session): HermiqAgentNode {
		$schedule = $this->createMock(ScheduleService::class);
		$schedule->method('runAgentAsOwner')->willReturnCallback(
			function (...$arguments) use ($answer): string {
				$this->turns[] = $arguments;

				return $answer;
			}
		);
		$schedule->method('lastRunConversationUuid')->willReturn($session);

		return new HermiqAgentNode(scheduleService: $schedule, l10n: $this->l10n(), urls: $this->createMock(IURLGenerator::class));
	}//end agentNode()

	/**
	 * The prompt is rendered from the item: nested paths, arrays as JSON, unknown as empty.
	 *
	 * @return void
	 */
	public function testThePromptIsRenderedFromTheItem(): void {
		$node = $this->agentNode(answer: 'fine', session: '');

		$node->execute(
			new FlowNodeCall(
				items: [['json' => ['pr' => ['number' => 7, 'labels' => ['a', 'b']], '@id' => 'x']]],
				config: ['agentId' => ' ' . self::AGENT_ID . ' ', 'prompt' => 'PR {{ pr.number }} {{pr.labels}} [{{missing.path}}] {{@id}} {{pr.number.deeper}}', 'organisation' => 'org-1'],
				context: ['runAs' => ' ruben ']
			)
		);

		$this->assertCount(1, $this->turns);
		// Positional order of runAgentAsOwner(): owner, agentId, prompt, organisation, dryRun, forceOwner, anchor.
		$this->assertSame(['ruben', self::AGENT_ID, 'PR 7 ["a","b"] [] x ', 'org-1', false, false, null], array_slice($this->turns[0], 0, 7));
	}//end testThePromptIsRenderedFromTheItem()

	/**
	 * With `expectJson` the JSON instruction is appended to the prompt.
	 *
	 * @return void
	 */
	public function testExpectJsonAppendsTheInstruction(): void {
		$node = $this->agentNode(answer: '{"ok":true}', session: '');

		$node->execute(
			new FlowNodeCall(
				items: [['json' => []]],
				config: ['agentId' => self::AGENT_ID, 'prompt' => 'Judge', 'expectJson' => true],
				context: ['triggeredBy' => 'ruben']
			)
		);

		$this->assertSame(
			"Judge\n\nReply with a single valid JSON object and nothing else. No prose before or after it, and no markdown code fence.",
			$this->turns[0][2]
		);
		$this->assertSame('', $this->turns[0][3]);
	}//end testExpectJsonAppendsTheInstruction()

	/**
	 * Each item keeps its binary, is paired by index and carries the session pointer.
	 *
	 * @return void
	 */
	public function testEachItemIsAnsweredPairedAndPointsAtItsSession(): void {
		$node = $this->agentNode(answer: 'done', session: 'sess-1');

		$out = $node->execute(
			new FlowNodeCall(
				items: [['json' => ['n' => 1], 'binary' => ['f' => 'x']], ['json' => ['n' => 2]]],
				config: ['agentId' => self::AGENT_ID, 'output' => 'answer'],
				context: ['triggeredBy' => 'ruben']
			)
		);

		$this->assertSame(
			[
				['json' => ['n' => 1, 'answer' => 'done', 'sessionId' => 'sess-1'], 'binary' => ['f' => 'x'], 'pairedItem' => ['item' => 0]],
				['json' => ['n' => 2, 'answer' => 'done', 'sessionId' => 'sess-1'], 'binary' => [], 'pairedItem' => ['item' => 1]],
			],
			$out
		);
		$this->assertCount(2, $this->turns);
	}//end testEachItemIsAnsweredPairedAndPointsAtItsSession()

	/**
	 * No session means no `sessionId` key at all.
	 *
	 * @return void
	 */
	public function testNoSessionMeansNoPointer(): void {
		$node = $this->agentNode(answer: 'done', session: '');

		$out = $node->execute(
			new FlowNodeCall(items: [['json' => ['n' => 1]]], config: ['agent' => self::AGENT_ID], context: ['triggeredBy' => 'ruben'])
		);

		$this->assertSame(['n' => 1, 'result' => 'done'], $out[0]['json']);
	}//end testNoSessionMeansNoPointer()

	/**
	 * Saving a step that names no agent is refused with the same message execute gives.
	 *
	 * @return void
	 */
	public function testValidateConfigRefusesANamelessAgent(): void {
		$node = $this->agentNode(answer: '', session: '');

		$node->validateConfig(config: ['agent' => 'a']);
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('An agent step needs an agent.');
		$node->validateConfig(config: ['agentId' => '  ']);
	}//end testValidateConfigRefusesANamelessAgent()

	/**
	 * A workload node with an async transport that records its call.
	 *
	 * @param array $result What the async transport returns.
	 *
	 * @return HermiqWorkloadNode The node.
	 */
	private function asyncNode(array $result): HermiqWorkloadNode {
		$async = $this->createMock(AsyncStageDispatchService::class);
		$async->method('dispatchStageAsync')->willReturnCallback(
			function (StageRequest $request) use ($result): array {
				// Kept in the positional order of dispatchAsync().
				$this->asyncCall = [
					$request->repo,
					$request->ref,
					$request->command,
					$request->uid,
					$request->options->credentialId,
					$request->options->timeoutMs,
					$request->options->toolRepo,
					$request->options->toolRef,
					$request->options->push,
					$request->options->pushCredentialId,
					$request->options->llmCredentialId,
					$request->options->jobKey,
					$request->options->collect,
				];

				return $result;
			}
		);
		$stages = $this->createMock(StageDispatchService::class);
		$stages->expects($this->never())->method($this->anything());

		return new HermiqWorkloadNode($stages, $this->l10n(), $this->createMock(IURLGenerator::class), $async);
	}//end asyncNode()

	/**
	 * An async step renders every field, forwards the job key and attributes the handle.
	 *
	 * @return void
	 */
	public function testAnAsyncStepForwardsEveryRenderedField(): void {
		$node = $this->asyncNode(result: ['job' => ['id' => 'j1', 'status' => 'queued']]);

		$out = $node->execute(
			new FlowNodeCall(
				items: [['json' => ['repo' => 'r', 'issue' => 9, 'cred' => 'c1'], 'binary' => ['b' => 1]]],
				config: [
					'repo' => 'https://x/{{repo}}',
					'ref' => 'feat/{{issue}}',
					'command' => ['run', '--issue={{issue}}'],
					'async' => true,
					'timeoutMs' => '1500',
					'toolRepo' => 'tools/{{repo}}',
					'toolRef' => 'main',
					'credentialId' => ' {{cred}} ',
					'pushCredentialId' => ' push-{{cred}} ',
					'llmCredentialId' => ' llm-{{cred}} ',
					'jobKey' => ' job-{{issue}} ',
					'collect' => ['out/*.json'],
					'push' => ['branch' => 'b-{{issue}}', 'issue' => '{{issue}}', 'scope' => ['lib/{{repo}}', ['nested']]],
					'output' => 'handle',
				],
				context: ['runAs' => 'ruben']
			)
		);

		$this->assertSame(
			[
				'https://x/r',
				'feat/9',
				['run', '--issue=9'],
				'ruben',
				'c1',
				1500,
				'tools/r',
				'main',
				['branch' => 'b-9', 'issue' => '9', 'scope' => ['lib/r']],
				'push-c1',
				'llm-c1',
				'job-9',
				['out/*.json'],
			],
			$this->asyncCall
		);
		$this->assertSame(
			[
				[
					'json' => [
						'repo' => 'r',
						'issue' => 9,
						'cred' => 'c1',
						'handle' => [
							'job' => ['id' => 'j1', 'status' => 'queued'],
							'owner' => 'ruben',
							'credential_owner' => 'ruben',
							'credential_name' => 'push-c1',
						],
					],
					'binary' => ['b' => 1],
					'pairedItem' => ['item' => 0],
				],
			],
			$out
		);
	}//end testAnAsyncStepForwardsEveryRenderedField()

	/**
	 * An async step with no credentials attributes the owner and no credential.
	 *
	 * @return void
	 */
	public function testAnAsyncStepWithoutCredentialsAttributesNoCredential(): void {
		$node = $this->asyncNode(result: ['job' => ['id' => 'j2']]);

		$out = $node->execute(
			new FlowNodeCall(
				items: [['json' => []]],
				config: ['repo' => 'r', 'ref' => 'f', 'command' => ['x'], 'async' => true],
				context: ['triggeredBy' => 'ruben']
			)
		);

		$this->assertSame(['r', 'f', ['x'], 'ruben', '', 0, '', '', [], '', '', '', []], $this->asyncCall);
		$this->assertSame(
			['job' => ['id' => 'j2'], 'owner' => 'ruben', 'credential_owner' => null, 'credential_name' => null],
			$out[0]['json']['stage']
		);
	}//end testAnAsyncStepWithoutCredentialsAttributesNoCredential()
}//end class
