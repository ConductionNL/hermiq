<?php

/**
 * Unit tests for per-(room, agent) sessions in TalkRoomBinding.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each agent in a room has its own session; a session without an agent is adopted.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkRoomBinding
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-sessions-are-per-room-per-agent-and-ordered
 */
final class TalkRoomBindingPerAgentTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The filters of every findAll() call.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $queries = [];

	/**
	 * The objects every saveObject() call wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the object service over the given sessions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
	}//end setUp()

	/**
	 * Hold these sessions, newest first; findAll() applies the filters.
	 *
	 * @param array<int, array<string, mixed>> $sessions Session payloads with a `uuid` key.
	 *
	 * @return TalkRoomBinding
	 */
	private function binding(array $sessions): TalkRoomBinding {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($sessions): array {
				$filters = $config['filters'];
				$this->queries[] = $filters;
				$rows = [];
				foreach ($sessions as $session) {
					foreach ($filters as $key => $value) {
						if (($session[$key] ?? '') !== $value) {
							continue 2;
						}
					}

					$entity = new ObjectEntity();
					$entity->setUuid($session['uuid']);
					$data = $session;
					unset($data['uuid']);
					$entity->setObject($data);
					$rows[] = $entity;
				}

				return array_slice($rows, 0, 1);
			}
		);

		return new TalkRoomBinding($this->objectService, $this->createMock(LoggerInterface::class));
	}//end binding()

	/**
	 * Each agent gets its own session in the room, never another agent's.
	 *
	 * @return void
	 */
	public function testEachAgentFindsItsOwnSession(): void {
		$binding = $this->binding(
			[
				['uuid' => 's-bram', 'talkRoomToken' => 'room-1', 'agentId' => 'agent-bram'],
				['uuid' => 's-ada', 'talkRoomToken' => 'room-1', 'agentId' => 'agent-ada'],
			]
		);

		self::assertSame('s-ada', $binding->findByRoomAndAgent(roomToken: 'room-1', agentId: 'agent-ada')?->getUuid());
		self::assertSame('s-bram', $binding->findByRoomAndAgent(roomToken: 'room-1', agentId: 'agent-bram')?->getUuid());
		self::assertNull($binding->findByRoomAndAgent(roomToken: 'room-1', agentId: 'agent-cas'));
		self::assertSame([], $this->saved);
	}//end testEachAgentFindsItsOwnSession()

	/**
	 * A room's session without an agent is adopted by the agent taking the turn,
	 * every field carried forward.
	 *
	 * @return void
	 */
	public function testASessionWithoutAnAgentIsAdopted(): void {
		$binding = $this->binding(
			[['uuid' => 's-old', 'talkRoomToken' => 'room-1', 'userId' => 'alice', 'participants' => ['bob']]]
		);

		$session = $binding->findByRoomAndAgent(roomToken: 'room-1', agentId: 'agent-ada');

		self::assertSame('s-old', $session?->getUuid());
		self::assertSame('agent-ada', ($session?->getObject() ?? [])['agentId'] ?? null);
		self::assertSame(
			[['id' => 's-old', 'talkRoomToken' => 'room-1', 'userId' => 'alice', 'participants' => ['bob'], 'agentId' => 'agent-ada']],
			$this->saved
		);
		self::assertSame([['talkRoomToken' => 'room-1', 'agentId' => 'agent-ada'], ['talkRoomToken' => 'room-1']], $this->queries);
	}//end testASessionWithoutAnAgentIsAdopted()

	/**
	 * No token or no agent asks nothing; a failing read resolves nothing.
	 *
	 * @return void
	 */
	public function testMissingKeysOrAFailingReadResolveNothing(): void {
		$binding = $this->binding([['uuid' => 's-1', 'talkRoomToken' => 'room-1', 'agentId' => 'agent-ada']]);
		self::assertNull($binding->findByRoomAndAgent(roomToken: '', agentId: 'agent-ada'));
		self::assertNull($binding->findByRoomAndAgent(roomToken: 'room-1', agentId: ''));
		self::assertSame([], $this->queries);

		$failing = $this->createMock(ObjectService::class);
		$failing->method('setRegister')->willReturnSelf();
		$failing->method('setSchema')->willReturnSelf();
		$failing->method('findAll')->willThrowException(new RuntimeException('db down'));
		self::assertNull((new TalkRoomBinding($failing, $this->createMock(LoggerInterface::class)))->findByRoomAndAgent(roomToken: 'room-1', agentId: 'agent-ada'));
	}//end testMissingKeysOrAFailingReadResolveNothing()

	/**
	 * A second agent's session in a room Hermiq created keeps the room's origin.
	 *
	 * @return void
	 */
	public function testASecondAgentsSessionKeepsTheRoomsOrigin(): void {
		$binding = $this->binding(
			[['uuid' => 's-ada', 'talkRoomToken' => 'room-1', 'agentId' => 'agent-ada', 'talkRoomOrigin' => TalkRoomBinding::ORIGIN_CREATED]]
		);

		$binding->createBound(roomToken: 'room-1', agentId: 'agent-bram', ownerUid: 'alice', participants: ['alice', 'bob']);

		self::assertSame(
			[
				[
					'title' => 'Talk conversation',
					'userId' => 'alice',
					'agentId' => 'agent-bram',
					'talkRoomToken' => 'room-1',
					'participants' => ['bob'],
					'talkRoomOrigin' => TalkRoomBinding::ORIGIN_CREATED,
				],
			],
			$this->saved
		);
	}//end testASecondAgentsSessionKeepsTheRoomsOrigin()
}//end class
