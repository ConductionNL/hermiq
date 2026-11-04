<?php

/**
 * Unit tests for ToolGrantWrites and ToolOversightAgents.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ToolOversight
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ToolOversight;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\ToolOversight\ToolGrantWrites;
use OCA\Hermiq\Service\ToolOversight\ToolOversightAgents;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The writes and the lookup behind the tool-oversight endpoints.
 *
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolGrantWrites
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolOversightAgents
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 */
class ToolGrantWritesTest extends TestCase {

	/**
	 * Every saveObject call, by argument name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * An agent with the given grants.
	 *
	 * @param array<int, string> $tools The stored grants.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $tools): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-uuid');
		$agent->setOwner('alice');
		$agent->setObject(['name' => 'Digest', 'tools' => $tools]);
		return $agent;
	}//end agent()

	/**
	 * An object service that records saves and answers with $saved.
	 *
	 * @param ObjectEntity|null $saved What saveObject returns.
	 *
	 * @return ObjectService
	 */
	private function objects(?ObjectEntity $saved = null): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true) use ($saved): ObjectEntity {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
				return ($saved ?? new ObjectEntity());
			}
		);
		return $objects;
	}//end objects()

	/**
	 * A grant write names the requested agent id as the uuid and answers with the tools as saved.
	 *
	 * @return void
	 */
	public function testSaveGrantsWritesUnderTheRequestedIdAndAnswersTheSavedTools(): void {
		$saved = new ObjectEntity();
		$saved->setObject(['tools' => ['as.saved']]);
		$writes = new ToolGrantWrites($this->objects($saved), $this->createMock(ToolAccessRequestService::class));

		$tools = $writes->saveAgent($writes->grantWritePayload($this->agent(['a.b']), ['c.d']), 'agent-1');

		$this->assertSame(['as.saved'], $tools);
		$this->assertSame('agent-1', $this->saves[0]['uuid']);
		$this->assertSame(['c.d'], $this->saves[0]['object']['tools']);
	}//end testSaveGrantsWritesUnderTheRequestedIdAndAnswersTheSavedTools()

	/**
	 * When the saved object carries no tools, the grants written are the answer.
	 *
	 * @return void
	 */
	public function testSaveGrantsFallsBackToTheWrittenGrants(): void {
		$writes = new ToolGrantWrites($this->objects(), $this->createMock(ToolAccessRequestService::class));

		$this->assertSame(['c.d'], $writes->saveAgent($writes->grantWritePayload($this->agent([]), ['c.d']), 'agent-1'));
	}//end testSaveGrantsFallsBackToTheWrittenGrants()

	/**
	 * Granting a tool the agent already holds does not list it twice.
	 *
	 * @return void
	 */
	public function testGrantingAHeldToolIsIdempotent(): void {
		$writes = new ToolGrantWrites($this->objects(), $this->createMock(ToolAccessRequestService::class));

		$writes->grantRequestedTool($this->agent(['a.b']), ['agentId' => 'agent-1', 'toolId' => 'a.b', 'status' => 'granted']);

		$this->assertSame(['a.b'], $this->saves[0]['object']['tools']);
	}//end testGrantingAHeldToolIsIdempotent()

	/**
	 * A granted decision tells the owner; a refused one does not.
	 *
	 * @return void
	 */
	public function testOnlyAGrantedDecisionNotifiesTheOwner(): void {
		$requests = $this->createMock(ToolAccessRequestService::class);
		$requests->expects($this->once())->method('notifyOwner')->with('agent-1', 'a.b', 'granted');
		$writes = new ToolGrantWrites($this->objects(), $requests);

		$writes->saveDecision(['agentId' => 'agent-1', 'toolId' => 'a.b', 'status' => 'refused'], 'req-1');
		$writes->saveDecision(['agentId' => 'agent-1', 'toolId' => 'a.b', 'status' => 'granted'], 'req-2');

		$this->assertSame(['req-1', 'req-2'], array_column($this->saves, 'uuid'));
		$this->assertSame([false, false], array_column($this->saves, 'rbac'));
	}//end testOnlyAGrantedDecisionNotifiesTheOwner()

	/**
	 * A refusal adds nothing to the agent; a grant writes the agent under the request's agent id.
	 *
	 * @return void
	 */
	public function testOnlyAGrantedDecisionWritesTheAgent(): void {
		$writes = new ToolGrantWrites($this->objects(), $this->createMock(ToolAccessRequestService::class));

		$writes->grantRequestedTool($this->agent(['a.b']), ['agentId' => 'agent-1', 'toolId' => 'c.d', 'status' => 'refused']);
		$this->assertSame([], $this->saves);

		$writes->grantRequestedTool($this->agent(['a.b']), ['agentId' => 'agent-1', 'toolId' => 'c.d', 'status' => 'granted']);
		$this->assertSame('agent-1', $this->saves[0]['uuid']);
		$this->assertSame(['a.b', 'c.d'], $this->saves[0]['object']['tools']);
	}//end testOnlyAGrantedDecisionWritesTheAgent()

	/**
	 * The lookup answers null for something that is not an object entity.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForANonEntity(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(null);
		$groups = $this->createMock(IGroupManager::class);
		$logger = $this->createMock(LoggerInterface::class);
		$agents = new ToolOversightAgents($objects, new AgentAccessService($objects, $logger, $groups), $groups, $logger);

		$this->assertNull($agents->find('agent-1'));
	}//end testTheLookupAnswersNullForANonEntity()
}//end class
