<?php

/**
 * Unit tests for TalkBotInstaller::enabledAgentIds(): which agents a room holds.
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

use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Talk's bot state is the room's agent list.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkBotInstaller
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-a-room-may-host-several-agents-managed-by-talk-moderators
 */
final class TalkBotInstallerEnabledAgentsTest extends TestCase {

	/**
	 * Bot id to bot url, as spreed's server mapper holds them.
	 *
	 * @var array<int, string>
	 */
	private array $botUrls = [];

	/**
	 * The room's bot-conversation rows: bot id and state.
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private array $rows = [];

	/**
	 * The installer over stand-in spreed mappers.
	 *
	 * @param bool $available Whether Talk is available.
	 * @param bool $failing   Whether reading the rows throws.
	 *
	 * @return TalkBotInstaller
	 */
	private function installer(bool $available=true, bool $failing=false): TalkBotInstaller {
		$botUrls = $this->botUrls;
		$botMapper = new class ($botUrls) {
			/**
			 * Keep the urls.
			 *
			 * @param array<int, string> $urls Bot id to url.
			 */
			public function __construct(private array $urls) {
			}

			/**
			 * A bot row by id.
			 *
			 * @param int $id The bot id.
			 *
			 * @return object
			 */
			public function findById(int $id): object {
				$url = $this->urls[$id];
				return new class ($url) {
					/**
					 * Keep the url.
					 *
					 * @param string $url The bot url.
					 */
					public function __construct(private string $url) {
					}

					/**
					 * The url.
					 *
					 * @return string
					 */
					public function getUrl(): string {
						return $this->url;
					}
				};
			}
		};

		$rows = $this->rows;
		$conversationMapper = new class ($rows, $failing) {
			/**
			 * Keep the rows.
			 *
			 * @param array<int, array{0: int, 1: int}> $rows    Bot id and state.
			 * @param bool                              $failing Whether the read throws.
			 */
			public function __construct(private array $rows, private bool $failing) {
			}

			/**
			 * The rows of a room.
			 *
			 * @param string $token The room token.
			 *
			 * @return array<int, object>
			 */
			public function findForToken(string $token): array {
				if ($this->failing === true) {
					throw new RuntimeException('spreed table missing');
				}

				return array_map(
					static fn (array $row): object => new class ($row[0], $row[1]) {
						/**
						 * Keep the bot id and state.
						 *
						 * @param int $botId The bot id.
						 * @param int $state The state.
						 */
						public function __construct(private int $botId, private int $state) {
						}

						/**
						 * The bot id, as spreed returns it.
						 *
						 * @return string
						 */
						public function getBotId(): string {
							return (string)$this->botId;
						}

						/**
						 * The state.
						 *
						 * @return int
						 */
						public function getState(): int {
							return $this->state;
						}
					},
					$this->rows
				);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => (str_ends_with($id, 'BotServerMapper') === true ? $botMapper : $conversationMapper)
		);

		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn($available);

		return new TalkBotInstaller(
			$container,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(ISecureRandom::class),
			$bridge,
			$this->createMock(LoggerInterface::class)
		);
	}//end installer()

	/**
	 * Each enabled per-agent bot names its agent; the shared bot, a foreign
	 * bot and a disabled row add none.
	 *
	 * @return void
	 */
	public function testTheEnabledPerAgentBotsNameTheRoomsAgents(): void {
		$this->botUrls = [
			1 => TalkBridge::botUrlFor(agentId: 'agent-a'),
			2 => TalkBridge::BOT_URL,
			3 => 'https://other.example/bot',
			4 => TalkBridge::botUrlFor(agentId: 'agent-b'),
			5 => TalkBridge::botUrlFor(agentId: 'agent-c'),
		];
		$this->rows = [[1, 1], [2, 1], [3, 1], [4, 1], [5, 0]];

		self::assertSame(['agent-a', 'agent-b'], $this->installer()->enabledAgentIds(roomToken: 'room-1'));
	}//end testTheEnabledPerAgentBotsNameTheRoomsAgents()

	/**
	 * Without Talk, without a token, or when spreed cannot be read, no agents.
	 *
	 * @return void
	 */
	public function testNoTalkOrAFailingReadMeansNoAgents(): void {
		$this->botUrls = [1 => TalkBridge::botUrlFor(agentId: 'agent-a')];
		$this->rows = [[1, 1]];

		self::assertSame([], $this->installer(available: false)->enabledAgentIds(roomToken: 'room-1'));
		self::assertSame([], $this->installer()->enabledAgentIds(roomToken: ''));
		self::assertSame([], $this->installer(failing: true)->enabledAgentIds(roomToken: 'room-1'));
	}//end testNoTalkOrAFailingReadMeansNoAgents()
}//end class
