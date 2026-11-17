<?php

/**
 * The code step: palette, configuration, and what each run does to its item.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqCodeStepNode;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqCodeStepNode
 * @uses   \OCA\Hermiq\Flow\FlowItemSlot
 * @uses   \OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException
 */
class HermiqCodeStepNodeTest extends TestCase {

	private const NORMALISE = 'import json,sys; i=json.load(sys.stdin); i["postcode"]=i["postcode"].upper().replace(" ",""); print(json.dumps(i))';

	private CodeSandboxClient&MockObject $client;
	private RunCodeTool&MockObject $runCode;

	/**
	 * Fresh doubles: the feature on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(CodeSandboxClient::class);
		$this->runCode = $this->createMock(RunCodeTool::class);
		$this->runCode->method('featureEnabled')->willReturn(true);
	}//end setUp()

	/**
	 * The node over the doubles.
	 *
	 * @param RunCodeTool|null $runCode Another gate.
	 *
	 * @return HermiqCodeStepNode
	 */
	private function node(?RunCodeTool $runCode=null): HermiqCodeStepNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/hermiq/img/app-dark.svg');

		return new HermiqCodeStepNode(client: $this->client, runCode: ($runCode ?? $this->runCode), l10n: $l10n, urls: $urls);
	}//end node()

	/**
	 * Spec scenario "A flow author normalises postcodes": the item's JSON goes in
	 * on stdin, with the step's limit and the run's owner, and the printed JSON
	 * replaces it.
	 *
	 * @return void
	 */
	public function testAFlowAuthorNormalisesPostcodes(): void {
		$this->client->expects($this->once())->method('run')
			->with('python', self::NORMALISE, '{"postcode":"1234 ab","stad":"Zaanstad"}', [], ['wallSeconds' => 5], 'flow-owner')
			->willReturn(['exitCode' => 0, 'stdout' => "{\"postcode\": \"1234AB\", \"stad\": \"Zaanstad\"}\n", 'stderr' => '', 'timedOut' => false]);

		$out = $this->node()->execute(
			new FlowNodeCall(
				items: [['json' => ['postcode' => '1234 ab', 'stad' => 'Zaanstad'], 'binary' => ['a' => 1]]],
				config: ['language' => 'python', 'code' => self::NORMALISE, 'timeoutSeconds' => 5],
				context: ['runAs' => ' ', 'triggeredBy' => 'flow-owner']
			)
		);

		$this->assertSame([['json' => ['postcode' => '1234AB', 'stad' => 'Zaanstad'], 'binary' => ['a' => 1], 'pairedItem' => ['item' => 0]]], $out);
	}//end testAFlowAuthorNormalisesPostcodes()

	/**
	 * Failing code keeps the item and adds an error with the stderr tail; a
	 * time-out and unprintable output do the same; the default limit is 10 s.
	 *
	 * @return void
	 */
	public function testFailingCodeMarksTheItemWithTheStderrTail(): void {
		$long = str_repeat('x', 3000) . 'KeyError: postcode';
		$this->client->method('run')->with($this->anything(), $this->anything(), $this->anything(), [], ['wallSeconds' => 10], null)
			->willReturnOnConsecutiveCalls(
				['exitCode' => 1, 'stdout' => '', 'stderr' => $long, 'timedOut' => false],
				['exitCode' => 137, 'stdout' => '', 'stderr' => '', 'timedOut' => true],
				['exitCode' => 0, 'stdout' => 'klaar', 'stderr' => '', 'timedOut' => false]
			);

		$out = $this->node()->execute(
			new FlowNodeCall(
				items: [['json' => ['n' => 1]], ['json' => ['n' => 2]], ['json' => ['n' => 3]]],
				config: ['language' => 'node', 'code' => 'x'],
				context: []
			)
		);

		$this->assertSame(1, $out[0]['json']['n']);
		$this->assertSame('The code exited with 1.', $out[0]['json']['error']['message']);
		$this->assertSame(HermiqCodeStepNode::STDERR_TAIL_BYTES, strlen($out[0]['json']['error']['stderr']));
		$this->assertStringEndsWith('KeyError: postcode', $out[0]['json']['error']['stderr']);
		$this->assertSame(['message' => 'The code ran out of time.', 'exitCode' => 137, 'timedOut' => true, 'stderr' => ''], $out[1]['json']['error']);
		$this->assertSame(['n' => 3, 'error' => ['message' => 'The code did not print a JSON object.', 'exitCode' => 0, 'timedOut' => false, 'stderr' => '']], $out[2]['json']);
		$this->assertSame(['item' => 2], $out[2]['pairedItem']);
	}//end testFailingCodeMarksTheItemWithTheStderrTail()

	/**
	 * With the AI feature off nothing is sent and the step fails.
	 *
	 * @return void
	 */
	public function testTheFeatureOffFailsTheStepBeforeAnyRun(): void {
		$off = $this->createMock(RunCodeTool::class);
		$off->method('featureEnabled')->willReturn(false);
		$this->client->expects($this->never())->method('run');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(RunCodeTool::NOT_ENABLED);
		$this->node(runCode: $off)->execute(new FlowNodeCall(items: [['json' => []]], config: ['language' => 'python', 'code' => 'print(1)']));
	}//end testTheFeatureOffFailsTheStepBeforeAnyRun()

	/**
	 * No sandbox fails the step with the client's message.
	 *
	 * @return void
	 */
	public function testNoSandboxFailsTheStep(): void {
		$this->client->method('run')->willThrowException(new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED));

		$this->expectExceptionMessage('No code sandbox is installed on this instance.');
		$this->node()->execute(new FlowNodeCall(items: [['json' => []]], config: ['language' => 'python', 'code' => 'print(1)']));
	}//end testNoSandboxFailsTheStep()

	/**
	 * An unvalidated configuration is refused at run time too.
	 *
	 * @return void
	 */
	public function testAnUnvalidatedConfigurationIsRefusedAtRunTime(): void {
		$this->client->expects($this->never())->method('run');

		$this->expectException(UnexpectedValueException::class);
		$this->node()->execute(new FlowNodeCall(items: [['json' => []]], config: ['language' => 'python', 'code' => 'x', 'timeoutSeconds' => 31]));
	}//end testAnUnvalidatedConfigurationIsRefusedAtRunTime()

	/**
	 * The configuration rules: a known language, code, and 1 to 30 seconds.
	 *
	 * @return void
	 */
	public function testValidateConfig(): void {
		$node = $this->node();
		$node->validateConfig(config: ['language' => 'node', 'code' => 'console.log(1)', 'timeoutSeconds' => 30]);

		foreach ([
			['language' => 'ruby', 'code' => 'x'],
			['language' => 'python', 'code' => ' '],
			['language' => 'python'],
			['language' => 'python', 'code' => 'x', 'timeoutSeconds' => 0],
			['language' => 'python', 'code' => 'x', 'timeoutSeconds' => '10'],
		] as $config) {
			try {
				$node->validateConfig(config: $config);
				$this->fail('Refused: ' . json_encode($config));
			} catch (UnexpectedValueException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testValidateConfig()

	/**
	 * The palette entry, present only while a sandbox can take runs, in the admin and user scopes.
	 *
	 * @return void
	 */
	public function testThePaletteEntryNeedsASandbox(): void {
		$this->client->method('isAvailable')->willReturnOnConsecutiveCalls(true, false);
		$node = $this->node();

		$this->assertSame('hermiq.code-step', $node->getId());
		$this->assertSame('[Code step]', $node->getDisplayName());
		$this->assertStringStartsWith('[Run a short Python or JavaScript program', $node->getDescription());
		$this->assertSame('/apps/hermiq/img/app-dark.svg', $node->getIcon());
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertFalse($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(99));
	}//end testThePaletteEntryNeedsASandbox()
}//end class
