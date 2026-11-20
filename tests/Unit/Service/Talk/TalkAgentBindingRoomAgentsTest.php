<?php

/**
 * Unit tests for TalkAgentBinding::agentsForRoom(): the agents a room holds.
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

use OCA\Hermiq\Kernel\Contracts\TalkBotInstallerInterface;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Talk's bot state first, the single-agent binding as the fallback.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkAgentBinding
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-a-room-may-host-several-agents-managed-by-talk-moderators
 */
final class TalkAgentBindingRoomAgentsTest extends TestCase {

	/**
	 * A binding over agents with the given Talk opt-in, a room map binding
	 * room-1 to agent-bound, and the given enabled bots.
	 *
	 * @param array<string, bool>      $optIn     Talk opt-in per agent uuid.
	 * @param array<int, string>|null  $botAgents The agents whose bot is enabled, or null for no installer.
	 *
	 * @return TalkAgentBinding
	 */
	private function binding(array $optIn, ?array $botAgents): TalkAgentBinding {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (string $id) use ($optIn): ?ObjectEntity {
				if (array_key_exists($id, $optIn) === false) {
					return null;
				}

				$agent = new ObjectEntity();
				$agent->setUuid($id);
				$agent->setObject(['name' => $id, 'talkEnabled' => $optIn[$id]]);
				return $agent;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $fallback = ''): string => match ($key) {
				TalkAgentBinding::ROOM_AGENT_MAP_KEY => '{"room-1":"agent-bound"}',
				default => $fallback,
			}
		);

		$installer = null;
		if ($botAgents !== null) {
			$installer = $this->createMock(TalkBotInstallerInterface::class);
			$installer->method('enabledAgentIds')->willReturn($botAgents);
		}

		return new TalkAgentBinding($objectService, $appConfig, $this->createMock(LoggerInterface::class), $installer);
	}//end binding()

	/**
	 * The room holds the opted-in agents whose bot is enabled there.
	 *
	 * @return void
	 */
	public function testTheEnabledBotsOfOptedInAgentsAreTheRoomsAgents(): void {
		$binding = $this->binding(
			optIn: ['agent-a' => true, 'agent-b' => true, 'agent-off' => false, 'agent-bound' => true],
			botAgents: ['agent-a', 'agent-off', 'agent-gone', 'agent-b']
		);

		self::assertSame(['agent-a', 'agent-b'], $binding->agentsForRoom(roomToken: 'room-1'));
	}//end testTheEnabledBotsOfOptedInAgentsAreTheRoomsAgents()

	/**
	 * With no per-agent bot enabled, or no installer, the single-agent binding answers.
	 *
	 * @return void
	 */
	public function testWithoutPerAgentBotsTheRoomBindingAnswers(): void {
		self::assertSame(['agent-bound'], $this->binding(optIn: ['agent-bound' => true], botAgents: [])->agentsForRoom(roomToken: 'room-1'));
		self::assertSame(['agent-bound'], $this->binding(optIn: ['agent-bound' => true], botAgents: null)->agentsForRoom(roomToken: 'room-1'));
	}//end testWithoutPerAgentBotsTheRoomBindingAnswers()

	/**
	 * A room whose bound agent opted out, an unbound room, or no token: no agents.
	 *
	 * @return void
	 */
	public function testNoOptedInAgentMeansNone(): void {
		self::assertSame([], $this->binding(optIn: ['agent-bound' => false], botAgents: [])->agentsForRoom(roomToken: 'room-1'));
		self::assertSame([], $this->binding(optIn: ['agent-bound' => true], botAgents: [])->agentsForRoom(roomToken: 'room-2'));
		self::assertSame([], $this->binding(optIn: ['agent-a' => true], botAgents: ['agent-a'])->agentsForRoom(roomToken: ''));
	}//end testNoOptedInAgentMeansNone()
}//end class
