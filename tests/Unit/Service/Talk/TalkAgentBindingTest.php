<?php

/**
 * Hermiq TalkAgentBinding unit tests.
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

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which agent answers in which Talk room.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkAgentBinding
 */
final class TalkAgentBindingTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->appConfig     = $this->createMock(IAppConfig::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A binding whose app config holds the given room map and default agent.
	 *
	 * @param string $map The raw room map value.
	 * @param string $default The default agent.
	 *
	 * @return TalkAgentBinding The binding.
	 */
	private function binding(string $map, string $default = ''): TalkAgentBinding {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $fallback = '') use ($map, $default): string {
					self::assertSame('hermiq', $app);
					return match ($key) {
						TalkAgentBinding::ROOM_AGENT_MAP_KEY => $map,
						TalkAgentBinding::DEFAULT_AGENT_KEY => $default,
						default => $fallback,
					};
				}
			);

		return new TalkAgentBinding(objectService: $this->objectService, appConfig: $this->appConfig, logger: $this->logger);
	}//end binding()

	/**
	 * An agent entity.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function agent(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;
	}//end agent()

	/**
	 * The map keeps only non-empty string pairs.
	 *
	 * @return void
	 */
	public function testRoomAgentMapKeepsOnlyNonEmptyStringPairs(): void {
		$binding = $this->binding('{"r1":"agent-1","r2":"","r3":7,"":"agent-x","4":"agent-4"}');

		self::assertSame(['r1' => 'agent-1'], $binding->roomAgentMap());
	}//end testRoomAgentMapKeepsOnlyNonEmptyStringPairs()

	/**
	 * Empty, invalid or non-object JSON reads as an empty map; invalid JSON warns.
	 *
	 * @return void
	 */
	public function testRoomAgentMapTreatsBadValuesAsEmpty(): void {
		self::assertSame([], $this->binding('')->roomAgentMap());

		$this->setUp();
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('not valid JSON'));
		self::assertSame([], $this->binding('{nope')->roomAgentMap());

		$this->setUp();
		self::assertSame([], $this->binding('"just-a-string"')->roomAgentMap());
	}//end testRoomAgentMapTreatsBadValuesAsEmpty()

	/**
	 * A room's own binding wins over the default; the default fills in otherwise.
	 *
	 * @return void
	 */
	public function testBoundAgentIdPrefersTheRoomThenTheDefault(): void {
		$binding = $this->binding('{"r1":"agent-1"}', 'agent-default');

		self::assertSame('agent-1', $binding->boundAgentId('r1'));
		self::assertSame('agent-default', $binding->boundAgentId('r2'));
		self::assertNull($binding->boundAgentId(''));
	}//end testBoundAgentIdPrefersTheRoomThenTheDefault()

	/**
	 * Without a room binding or default there is no agent.
	 *
	 * @return void
	 */
	public function testBoundAgentIdIsNullWithoutBindingOrDefault(): void {
		self::assertNull($this->binding('{"r1":"agent-1"}')->boundAgentId('r2'));
	}//end testBoundAgentIdIsNullWithoutBindingOrDefault()

	/**
	 * The reverse lookup finds the room an agent is bound to.
	 *
	 * @return void
	 */
	public function testRoomForAgentFindsTheBoundRoom(): void {
		$binding = $this->binding('{"r1":"agent-1","r2":"agent-2"}');

		self::assertSame('r2', $binding->roomForAgent('agent-2'));
		self::assertNull($binding->roomForAgent('agent-3'));
		self::assertNull($binding->roomForAgent(''));
	}//end testRoomForAgentFindsTheBoundRoom()

	/**
	 * Binding replaces the room's entry, and an empty agent unbinds it.
	 *
	 * @return void
	 */
	public function testBindRoomWritesTheUpdatedMap(): void {
		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $value) use (&$written): bool {
					$written[] = [$app, $key, $value];
					return true;
				}
			);
		$binding = $this->binding('{"r1":"agent-1","r2":"agent-2"}');

		$binding->bindRoom(roomToken: 'r1', agentId: 'agent-9');
		$binding->bindRoom(roomToken: 'r2', agentId: '');
		$binding->bindRoom(roomToken: '', agentId: 'agent-5');

		self::assertSame(
			[
				['hermiq', TalkAgentBinding::ROOM_AGENT_MAP_KEY, '{"r2":"agent-2","r1":"agent-9"}'],
				['hermiq', TalkAgentBinding::ROOM_AGENT_MAP_KEY, '{"r1":"agent-1"}'],
			],
			$written
		);
	}//end testBindRoomWritesTheUpdatedMap()

	/**
	 * A bound, Talk-enabled agent answers; the agent is loaded without RBAC.
	 *
	 * @return void
	 */
	public function testAgentForRoomReturnsATalkEnabledAgent(): void {
		$this->objectService->expects(self::once())
			->method('find')
			->willReturnCallback(
				function (...$args) {
					self::assertSame('agent-1', $args[0]);
					self::assertSame('hermiq', $args[3]);
					self::assertSame('agent', $args[4]);
					self::assertFalse($args[5]);
					return $this->agent(['talkEnabled' => true]);
				}
			);

		self::assertSame('agent-1', $this->binding('{"r1":"agent-1"}')->agentForRoom('r1'));
	}//end testAgentForRoomReturnsATalkEnabledAgent()

	/**
	 * An agent not literally Talk-enabled, missing, failing to load or unbound
	 * takes no turn.
	 *
	 * @return void
	 */
	public function testAgentForRoomRefusesWhenTheAgentCannotAnswer(): void {
		$this->objectService->method('find')
			->willReturnOnConsecutiveCalls(
				$this->agent(['talkEnabled' => 'true']),
				null,
				self::throwException(new RuntimeException('gone'))
			);
		$this->logger->expects(self::exactly(2))->method('debug');
		$binding = $this->binding('{"r1":"agent-1"}');

		self::assertNull($binding->agentForRoom('r1'));
		self::assertNull($binding->agentForRoom('r1'));
		self::assertNull($binding->agentForRoom('r1'));
		self::assertNull($binding->agentForRoom('r2'));
	}//end testAgentForRoomRefusesWhenTheAgentCannotAnswer()

	/**
	 * Talk-enabled check and name read the stored agent.
	 *
	 * @return void
	 */
	public function testIsAgentTalkEnabledAndAgentName(): void {
		$this->objectService->method('find')
			->willReturnOnConsecutiveCalls(
				$this->agent(['talkEnabled' => true, 'name' => 'Clerk']),
				$this->agent(['talkEnabled' => false]),
				null,
				$this->agent(['name' => 'Clerk']),
				$this->agent([]),
				null
			);
		$binding = $this->binding('');

		self::assertTrue($binding->isAgentTalkEnabled('agent-1'));
		self::assertFalse($binding->isAgentTalkEnabled('agent-1'));
		self::assertFalse($binding->isAgentTalkEnabled('agent-1'));
		self::assertSame('Clerk', $binding->agentName('agent-1'));
		self::assertSame('', $binding->agentName('agent-1'));
		self::assertSame('', $binding->agentName('agent-1'));
	}//end testIsAgentTalkEnabledAndAgentName()
}//end class
