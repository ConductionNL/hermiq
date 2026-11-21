<?php

/**
 * Unit tests for GovernedResearchTools and InvokerToolbox (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
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

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Research\GovernedResearchTools;
use OCA\Hermiq\Service\Research\InvokerToolbox;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Research\GovernedResearchTools
 * @covers \OCA\Hermiq\Service\Research\InvokerToolbox
 */
final class GovernedResearchToolsTest extends TestCase {

	/**
	 * The calls the governed invoker received, as [name, arguments].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * What the invoker answers per function name.
	 *
	 * @var array<string, string>
	 */
	public array $answers = [];

	/**
	 * @var ToolLoop&MockObject
	 */
	private ToolLoop&MockObject $toolLoop;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * @var ObjectEntity&MockObject
	 */
	private ObjectEntity&MockObject $agent;

	protected function setUp(): void {
		$this->toolLoop = $this->createMock(ToolLoop::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->agent = $this->createMock(ObjectEntity::class);
		$this->objects->method('find')->willReturn($this->agent);
	}

	public function testTheToolboxIsBoundToTheAgentsGovernedChain(): void {
		$functions = [
			['name' => 'hermiq_webSearch', 'mcpId' => 'hermiq.webSearch'],
			['name' => 'hermiq_webFetch', 'mcpId' => 'hermiq.webFetch'],
		];
		$this->toolLoop->expects(self::once())->method('listAgentFunctions')
			->with($this->agent, ['hermiq.webSearch', 'hermiq.webFetch'])
			->willReturn($functions);
		$this->toolLoop->expects(self::once())->method('buildFunctionInfos')
			->with($functions, null, null, $this->agent, 'org-1', false, 'conv-1')
			->willReturn($this->infos());

		$toolbox = (new GovernedResearchTools($this->toolLoop, $this->objects))->open('agent-1', 'org-1', 'conv-1');

		self::assertInstanceOf(InvokerToolbox::class, $toolbox);
	}

	public function testAnAgentWithoutBothToolsGetsNoToolbox(): void {
		$this->toolLoop->method('listAgentFunctions')->willReturn([['name' => 'hermiq_webSearch', 'mcpId' => 'hermiq.webSearch'], 'junk']);
		$this->toolLoop->expects(self::never())->method('buildFunctionInfos');

		self::assertNull((new GovernedResearchTools($this->toolLoop, $this->objects))->open('agent-1', 'org-1', 'conv-1'));
	}

	public function testAnAgentThatCannotBeReadGetsNoToolbox(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new RuntimeException('not found'));
		$this->toolLoop->expects(self::never())->method('listAgentFunctions');

		self::assertNull((new GovernedResearchTools($this->toolLoop, $objects))->open('agent-1', 'org-1', 'conv-1'));
		self::assertNull((new GovernedResearchTools($this->toolLoop, $this->objects))->open('', 'org-1', 'conv-1'));
	}

	public function testANameWithoutARegistryIdIsReadAsTheId(): void {
		$functions = [['name' => 'hermiq.webSearch'], ['name' => 'hermiq.webFetch']];
		$this->toolLoop->method('listAgentFunctions')->willReturn($functions);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([
			new FunctionInfo('hermiq.webSearch', $this->invoker(), '', []),
			new FunctionInfo('hermiq.webFetch', $this->invoker(), '', []),
		]);

		self::assertNotNull((new GovernedResearchTools($this->toolLoop, $this->objects))->open('agent-1', 'org-1', 'conv-1'));
	}

	public function testSearchAndFetchPassTheInvokerAndRefusalsBecomeErrors(): void {
		$infos = $this->infos();
		$toolbox = new InvokerToolbox($infos[0], $infos[1]);

		$this->answers['hermiq_webSearch'] = json_encode(['results' => [['title' => 'A', 'url' => 'https://a'], ['title' => 'No url'], 'junk']]);
		self::assertSame([['title' => 'A', 'url' => 'https://a']], $toolbox->search('q'));
		self::assertSame(['hermiq_webSearch', ['query' => 'q']], $this->calls[0]);

		$this->answers['hermiq_webFetch'] = json_encode(['url' => 'https://a', 'content' => 'text']);
		self::assertSame(['url' => 'https://a', 'content' => 'text'], $toolbox->fetch('https://a'));
		self::assertSame(['hermiq_webFetch', ['url' => 'https://a']], $this->calls[1]);

		$this->answers['hermiq_webFetch'] = json_encode(['ok' => false, 'isError' => true, 'error' => 'egress_denied']);
		self::assertSame(['error' => 'egress_denied'], $toolbox->fetch('http://10.0.0.1'));

		$this->answers['hermiq_webFetch'] = 'not json';
		self::assertArrayHasKey('error', $toolbox->fetch('https://a'));

		$this->answers['hermiq_webSearch'] = json_encode(['isError' => true]);
		self::assertSame([], $toolbox->search('q'));
	}

	/**
	 * The two bound functions over the recording invoker.
	 *
	 * @return list<FunctionInfo>
	 */
	private function infos(): array {
		return [
			new FunctionInfo('hermiq_webSearch', $this->invoker(), '', []),
			new FunctionInfo('hermiq_webFetch', $this->invoker(), '', []),
		];
	}

	/**
	 * An invoker double that records the call and answers the queued JSON.
	 *
	 * @return object
	 */
	private function invoker(): object {
		$test = $this;
		return new class($test) {
			public function __construct(private readonly GovernedResearchToolsTest $test) {
			}

			public function __call(string $name, array $arguments): string {
				$this->test->calls[] = [$name, $arguments];
				return $this->test->answers[$name] ?? '{}';
			}
		};
	}
}
