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
use OCA\Hermiq\Service\Research\ResearchObjects;
use OCA\Hermiq\Tests\Unit\Service\Research\Fakes\RecordingInvoker;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\GovernedResearchTools
 * @covers \OCA\Hermiq\Service\Research\InvokerToolbox
 */
final class GovernedResearchToolsTest extends TestCase {

	/**
	 * The tool loop.
	 *
	 * @var ToolLoop&MockObject
	 */
	private ToolLoop&MockObject $toolLoop;

	/**
	 * The objects reader.
	 *
	 * @var ResearchObjects&MockObject
	 */
	private ResearchObjects&MockObject $objects;

	/**
	 * The agent.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $agent;

	/**
	 * The governed invoker double.
	 *
	 * @var RecordingInvoker
	 */
	private RecordingInvoker $invoker;

	/**
	 * A readable agent and a recording invoker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->toolLoop = $this->createMock(originalClassName: ToolLoop::class);
		$this->objects  = $this->createMock(originalClassName: ResearchObjects::class);
		$this->agent    = new ObjectEntity();
		$this->objects->method('readable')->willReturnCallback(
			fn (string $uuid, string $schema): ?ObjectEntity => $this->agentFor(uuid: $uuid, schema: $schema)
		);
		$this->invoker = new RecordingInvoker();
	}//end setUp()

	/**
	 * The toolbox binds the agent's two granted functions to its governed chain.
	 *
	 * @return void
	 */
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
			->willReturn($this->infos(search: 'hermiq_webSearch', fetch: 'hermiq_webFetch'));

		$toolbox = $this->tools()->open(agentId: 'agent-1', organisation: 'org-1', conversationId: 'conv-1');

		self::assertInstanceOf(expected: InvokerToolbox::class, actual: $toolbox);
	}//end testTheToolboxIsBoundToTheAgentsGovernedChain()

	/**
	 * An agent with only one of the two tools gets no toolbox.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutBothToolsGetsNoToolbox(): void {
		$this->toolLoop->method('listAgentFunctions')->willReturn([['name' => 'hermiq_webSearch', 'mcpId' => 'hermiq.webSearch'], 'junk']);
		$this->toolLoop->expects(self::never())->method('buildFunctionInfos');

		self::assertNull(actual: $this->tools()->open(agentId: 'agent-1', organisation: 'org-1', conversationId: 'conv-1'));
	}//end testAnAgentWithoutBothToolsGetsNoToolbox()

	/**
	 * An agent the person cannot read gets no toolbox, and no function is listed.
	 *
	 * @return void
	 */
	public function testAnAgentThatCannotBeReadGetsNoToolbox(): void {
		$this->toolLoop->expects(self::never())->method('listAgentFunctions');

		self::assertNull(actual: $this->tools()->open(agentId: 'other', organisation: 'org-1', conversationId: 'conv-1'));
	}//end testAnAgentThatCannotBeReadGetsNoToolbox()

	/**
	 * A function without a registry id is read by its name, as ToolLoop does.
	 *
	 * @return void
	 */
	public function testANameWithoutARegistryIdIsReadAsTheId(): void {
		$this->toolLoop->method('listAgentFunctions')->willReturn([['name' => 'hermiq.webSearch'], ['name' => 'hermiq.webFetch']]);
		$this->toolLoop->method('buildFunctionInfos')->willReturn($this->infos(search: 'hermiq.webSearch', fetch: 'hermiq.webFetch'));

		self::assertNotNull(actual: $this->tools()->open(agentId: 'agent-1', organisation: 'org-1', conversationId: 'conv-1'));
	}//end testANameWithoutARegistryIdIsReadAsTheId()

	/**
	 * A bound function that is missing after binding gives no toolbox.
	 *
	 * @return void
	 */
	public function testAFunctionMissingAfterBindingGivesNoToolbox(): void {
		$this->toolLoop->method('listAgentFunctions')->willReturn([['name' => 'hermiq.webSearch'], ['name' => 'hermiq.webFetch']]);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([new FunctionInfo('hermiq.webSearch', $this->invoker, '', [])]);

		self::assertNull(actual: $this->tools()->open(agentId: 'agent-1', organisation: 'org-1', conversationId: 'conv-1'));
	}//end testAFunctionMissingAfterBindingGivesNoToolbox()

	/**
	 * Searches and fetches go through the invoker; refusals and unreadable answers become errors.
	 *
	 * @return void
	 */
	public function testSearchAndFetchPassTheInvokerAndRefusalsBecomeErrors(): void {
		$infos   = $this->infos(search: 'hermiq_webSearch', fetch: 'hermiq_webFetch');
		$toolbox = new InvokerToolbox(search: $infos[0], fetch: $infos[1]);

		$this->invoker->answers['hermiq_webSearch'] = json_encode(['results' => [['title' => 'A', 'url' => 'https://a'], ['title' => 'No url'], 'junk']]);
		self::assertSame(expected: [['title' => 'A', 'url' => 'https://a']], actual: $toolbox->search(query: 'q'));
		self::assertSame(expected: ['hermiq_webSearch', ['query' => 'q']], actual: $this->invoker->calls[0]);

		$this->invoker->answers['hermiq_webFetch'] = json_encode(['url' => 'https://a', 'content' => 'text']);
		self::assertSame(expected: ['url' => 'https://a', 'content' => 'text'], actual: $toolbox->fetch(url: 'https://a'));
		self::assertSame(expected: ['hermiq_webFetch', ['url' => 'https://a']], actual: $this->invoker->calls[1]);

		$this->invoker->answers['hermiq_webFetch'] = json_encode(['ok' => false, 'isError' => true, 'error' => 'egress_denied']);
		self::assertSame(expected: ['error' => 'egress_denied'], actual: $toolbox->fetch(url: 'http://10.0.0.1'));

		$this->invoker->answers['hermiq_webFetch'] = 'not json';
		self::assertArrayHasKey(key: 'error', array: $toolbox->fetch(url: 'https://a'));

		$this->invoker->answers['hermiq_webSearch'] = json_encode(['isError' => true]);
		self::assertSame(expected: [], actual: $toolbox->search(query: 'q'));
	}//end testSearchAndFetchPassTheInvokerAndRefusalsBecomeErrors()

	/**
	 * The tools under test.
	 *
	 * @return GovernedResearchTools
	 */
	private function tools(): GovernedResearchTools {
		return new GovernedResearchTools(toolLoop: $this->toolLoop, objects: $this->objects);
	}//end tools()

	/**
	 * The two bound functions over the recording invoker.
	 *
	 * @param string $search The search function's name.
	 * @param string $fetch  The fetch function's name.
	 *
	 * @return list<FunctionInfo>
	 */
	private function infos(string $search, string $fetch): array {
		return [new FunctionInfo($search, $this->invoker, '', []), new FunctionInfo($fetch, $this->invoker, '', [])];
	}//end infos()

	/**
	 * The agent for `agent-1`, nothing for any other uuid.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $schema The schema.
	 *
	 * @return ObjectEntity|null
	 */
	private function agentFor(string $uuid, string $schema): ?ObjectEntity {
		if ($uuid !== 'agent-1' || $schema !== ResearchObjects::AGENT) {
			return null;
		}

		return $this->agent;
	}//end agentFor()
}//end class
