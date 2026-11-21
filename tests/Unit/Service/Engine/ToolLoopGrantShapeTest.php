<?php

/**
 * Unit tests for how ToolLoop reads malformed agent tool grants.
 *
 * An agent record is user-edited data, so its `tools` field can hold
 * anything. A non-list must read as "no grants" (never as "all tools"), and
 * junk entries inside the list must be dropped before the whitelist reaches
 * the registry facade.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\Hermiq\Service\Workspace\RepoEffectingGrants;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for malformed `tools` grants on an agent.
 */
#[CoversClass(ToolLoop::class)]
#[UsesClass(ToolSearchService::class)]
#[UsesClass(RepoEffectingGrants::class)]
class ToolLoopGrantShapeTest extends TestCase {

	/**
	 * A ToolLoop over the given facade, disclosure threshold 30.
	 *
	 * @param ToolRegistryFacade $facade The mocked facade.
	 *
	 * @return ToolLoop
	 */
	private function loop(ToolRegistryFacade $facade): ToolLoop {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);

		return new ToolLoop(
			$facade,
			new NullLogger(),
			new ToolGrantResolver(),
			new ToolSearchService(),
			$this->createMock(ApprovalService::class),
			$appConfig
		);

	}//end loop()

	/**
	 * An agent record with the given raw `tools` value.
	 *
	 * @param mixed $tools The raw tools field.
	 *
	 * @return ObjectEntity
	 */
	private function agent(mixed $tools): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-uuid');
		$entity->setObject(['name' => 'Test agent', 'tools' => $tools]);
		return $entity;

	}//end agent()

	/**
	 * A `tools` value that is not a list reads as no grants: nothing offered.
	 *
	 * @return void
	 */
	public function testANonListToolsFieldGrantsNothing(): void {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())
			->method('listTools')
			->with([])
			->willReturn([['name' => 'openregister_search', 'mcpId' => 'openregister.schemas.search', 'description' => 'Search', 'parameters' => []]]);

		$this->assertSame([], $this->loop(facade: $facade)->listAgentFunctions(agent: $this->agent(tools: 'all')));

	}//end testANonListToolsFieldGrantsNothing()

	/**
	 * Empty and non-string grant entries never reach the facade.
	 *
	 * @return void
	 */
	public function testJunkGrantEntriesAreDropped(): void {
		$meetings = ['name' => 'decidesk_listMeetings'];

		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())
			->method('listTools')
			->with(['decidesk.listMeetings'])
			->willReturn([$meetings]);

		$functions = $this->loop(facade: $facade)->listAgentFunctions(
			agent: $this->agent(tools: ['', 5, null, ['nested'], 'decidesk.listMeetings', 'decidesk.listMeetings'])
		);

		$this->assertSame([$meetings], $functions);

	}//end testJunkGrantEntriesAreDropped()
}//end class
