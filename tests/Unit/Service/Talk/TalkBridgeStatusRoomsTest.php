<?php

/**
 * Unit tests for the rooms half of TalkBridgeStatus.
 *
 * With Talk present the panel lists every conversation the bot is bound to:
 * its display name (or the token when Talk has none), the bound agent and
 * its name, whether the bot is enabled there, and whether the agent still
 * answers. Each lookup degrades on its own, so one broken room or agent
 * never blanks the whole panel.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkBridgeStatus;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the room rows and bot detection of the admin-panel status reporter.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkBridgeStatus
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */
class TalkBridgeStatusRoomsTest extends TestCase {

	/**
	 * Room to agent map.
	 *
	 * @var TalkAgentBinding&MockObject
	 */
	private TalkAgentBinding $agentBinding;

	/**
	 * Agent records.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * The spreed services the container answers, by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Wire the doubles: Talk present, two bound rooms, the bot installed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->agentBinding = $this->createMock(TalkAgentBinding::class);
		$this->agentBinding->method('roomAgentMap')->willReturn(['tok-a' => 'agent-1', 'tok-b' => 'agent-2']);

		$this->objectService = $this->createMock(ObjectService::class);

		$this->services = [
			'OCA\\Talk\\Model\\BotServerMapper' => new class {
				/**
				 * Find the bot by URL.
				 *
				 * @param string $url The bot URL.
				 *
				 * @return object
				 */
				public function findByUrl(string $url): object {
					return (object)['url' => $url];
				}//end findByUrl()
			},
			'OCA\\Talk\\Manager' => new class {
				/**
				 * A room by token: tok-a is named, tok-b has no name.
				 *
				 * @param string $token The room token.
				 *
				 * @return object
				 */
				public function getRoomByToken(string $token): object {
					return new class ($token === 'tok-a' ? 'Planning' : '') {
						/**
						 * Constructor.
						 *
						 * @param string $name The room name.
						 */
						public function __construct(private string $name) {
						}//end __construct()

						/**
						 * The room name.
						 *
						 * @return string
						 */
						public function getName(): string {
							return $this->name;
						}//end getName()
					};
				}//end getRoomByToken()
			},
			'OCA\\Talk\\Service\\BotService' => new class {
				/**
				 * The bots in a room: Hermiq's own bot only in tok-a.
				 *
				 * @param string $token The room token.
				 * @param int    $state The bot state filter.
				 *
				 * @return array
				 */
				public function getBotsForToken(string $token, int $state): array {
					$url = ($token === 'tok-a' && $state === 4) ? TalkBridge::BOT_URL : 'https://other.example/bot';
					return [
						new class ($url) {
							/**
							 * Constructor.
							 *
							 * @param string $url The bot server URL.
							 */
							public function __construct(private string $url) {
							}//end __construct()

							/**
							 * The bot server.
							 *
							 * @return object
							 */
							public function getBotServer(): object {
								return new class ($this->url) {
									/**
									 * Constructor.
									 *
									 * @param string $url The URL.
									 */
									public function __construct(private string $url) {
									}//end __construct()

									/**
									 * The URL.
									 *
									 * @return string
									 */
									public function getUrl(): string {
										return $this->url;
									}//end getUrl()
								};
							}//end getBotServer()
						},
					];
				}//end getBotsForToken()
			},
		];

	}//end setUp()

	/**
	 * The status reporter over the doubles, with Talk available.
	 *
	 * @param LoggerInterface|null $logger The logger, or a silent double.
	 *
	 * @return TalkBridgeStatus
	 */
	private function reporter(?LoggerInterface $logger = null): TalkBridgeStatus {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn(true);
		$grouping = $this->createMock(TalkRoomGrouping::class);
		$grouping->method('isSupported')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException('No service ' . $id);
				}

				return $this->services[$id];
			}
		);

		return new TalkBridgeStatus(
			$bridge,
			$this->agentBinding,
			$this->createMock(TalkTurnDispatcher::class),
			$grouping,
			$this->objectService,
			$container,
			($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end reporter()

	/**
	 * An agent record with the given name.
	 *
	 * @param string $name The agent name.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $name): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => $name]);
		return $entity;

	}//end agent()

	/**
	 * Every bound room is listed with its name, agent, bot state and whether the agent answers.
	 *
	 * @return void
	 */
	public function testEveryBoundRoomIsDescribed(): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id, mixed $_extend, bool $files, mixed $register, mixed $schema): ?ObjectEntity => ($register === 'hermiq' && $schema === 'agent' && $id === 'agent-1') ? $this->agent('Planner') : $this->agent('')
		);
		$this->agentBinding->method('agentForRoom')->willReturnCallback(
			static fn (string $roomToken): ?string => ($roomToken === 'tok-a') ? 'agent-1' : null
		);

		$result = $this->reporter()->describe();

		$this->assertTrue($result['botInstalled']);
		$this->assertTrue($result['groupingEnabled']);
		$this->assertSame(
			[
				['token' => 'tok-a', 'name' => 'Planning', 'agentId' => 'agent-1', 'agentName' => 'Planner', 'botEnabled' => true, 'agentActive' => true],
				['token' => 'tok-b', 'name' => 'tok-b', 'agentId' => 'agent-2', 'agentName' => null, 'botEnabled' => false, 'agentActive' => false],
			],
			$result['rooms']
		);

	}//end testEveryBoundRoomIsDescribed()

	/**
	 * An agent lookup that fails or answers something else gives no name, and the room is still listed.
	 *
	 * @return void
	 */
	public function testAnUnreadableAgentHasNoName(): void {
		$this->objectService->method('find')->willReturnCallback(
			static function (string $id): mixed {
				if ($id === 'agent-1') {
					throw new RuntimeException('register down');
				}

				return ['name' => 'Not an entity'];
			}
		);

		$rooms = $this->reporter()->describe()['rooms'];

		$this->assertSame([null, null], array_column($rooms, 'agentName'));
		$this->assertSame(['tok-a', 'tok-b'], array_column($rooms, 'token'));

	}//end testAnUnreadableAgentHasNoName()

	/**
	 * A room Talk cannot resolve is named by its token, and a bot service that fails reads as not enabled.
	 *
	 * @return void
	 */
	public function testFailingRoomAndBotLookupsDegradePerRoom(): void {
		$this->services['OCA\\Talk\\Manager'] = new class {
			/**
			 * Every room lookup fails.
			 *
			 * @param string $token The room token.
			 *
			 * @return object
			 */
			public function getRoomByToken(string $token): object {
				throw new RuntimeException('Room not found: ' . $token);
			}//end getRoomByToken()
		};
		unset($this->services['OCA\\Talk\\Service\\BotService']);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(4))->method('debug');

		$rooms = $this->reporter(logger: $logger)->describe()['rooms'];

		$this->assertSame(['tok-a', 'tok-b'], array_column($rooms, 'name'));
		$this->assertSame([false, false], array_column($rooms, 'botEnabled'));

	}//end testFailingRoomAndBotLookupsDegradePerRoom()

	/**
	 * Without the Talk manager no room can be listed, but the bot can still be installed.
	 *
	 * @return void
	 */
	public function testWithoutTheTalkManagerNoRoomsAreListed(): void {
		unset($this->services['OCA\\Talk\\Manager']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[TalkBridgeStatus] Could not enumerate the bot\'s conversations',
			$this->callback(static fn (array $context): bool => $context['error'] === 'No service OCA\\Talk\\Manager')
		);

		$result = $this->reporter(logger: $logger)->describe();

		$this->assertTrue($result['botInstalled']);
		$this->assertSame([], $result['rooms']);

	}//end testWithoutTheTalkManagerNoRoomsAreListed()
}//end class
