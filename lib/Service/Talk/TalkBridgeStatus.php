<?php

/**
 * Hermiq TalkBridgeStatus.
 *
 * Answers the question an administrator actually asks when an agent replies in
 * a room: *why is this happening, and where else does it happen?*
 *
 * Before this, that needed `occ` or database access — the bridge's whole
 * configuration lived in an app-config JSON blob and spreed's bot tables.
 *
 * Read-only and defensive throughout: every spreed touch is lazy and guarded,
 * so the panel renders on an instance with no Talk at all rather than 500ing.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/tasks.md#7-opt-in-and-admin-visibility
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports the Talk bridge's effective configuration for the admin panel.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */
class TalkBridgeStatus {

	/**
	 * OpenRegister register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Spreed's bot server mapper, resolved lazily.
	 *
	 * @var string
	 */
	private const BOT_SERVER_MAPPER = 'OCA\\Talk\\Model\\BotServerMapper';

	/**
	 * Spreed's bot service, resolved lazily — used to ask, per room, whether
	 * OUR bot is enabled there.
	 *
	 * @var string
	 */
	private const BOT_SERVICE = 'OCA\\Talk\\Service\\BotService';

	/**
	 * Spreed's room manager, resolved lazily.
	 *
	 * @var string
	 */
	private const TALK_MANAGER = 'OCA\\Talk\\Manager';

	/**
	 * Constructor.
	 *
	 * @param TalkBridge $bridge Talk availability and bot identity.
	 * @param TalkAgentBinding $agentBinding The room→agent
	 *                                       map.
	 * @param TalkTurnDispatcher $dispatcher Reports which hand-off path is active.
	 * @param TalkRoomGrouping $grouping Reports whether tag grouping is supported.
	 * @param ObjectService $objectService Resolves agent names for the panel.
	 * @param ContainerInterface $container Server container for lazy spreed resolution.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly TalkAgentBinding $agentBinding,
		private readonly TalkTurnDispatcher $dispatcher,
		private readonly TalkRoomGrouping $grouping,
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The bridge's effective configuration.
	 *
	 * @return array The status payload for the admin panel.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	public function describe(): array {
		$available = $this->bridge->isAvailable();
		$botInstalled = false;
		$rooms = [];
		if ($available === true) {
			$botInstalled = $this->isBotInstalled();
			$rooms = $this->describeRooms();
		}

		// Reported honestly: `queued` until a triggerable runner is registered.
		// An ISynchronousProvider does NOT count — core runs those on the same
		// cron tick as the queued fallback.
		$handOffPath = 'queued';
		if ($this->dispatcher->hasTriggerableProvider() === true) {
			$handOffPath = 'triggered';
		}

		return [
			'talkAvailable' => $available,
			'botUrl' => TalkBridge::BOT_URL,
			'botInstalled' => $botInstalled,
			'groupingEnabled' => $this->grouping->isSupported(),
			'handOffPath' => $handOffPath,
			'rooms' => $rooms,
		];

	}//end describe()

	/**
	 * Whether Hermiq's bot row exists in spreed.
	 *
	 * @return bool True when the bot is installed.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	private function isBotInstalled(): bool {
		try {
			$this->container->get(self::BOT_SERVER_MAPPER)->findByUrl(TalkBridge::BOT_URL);
			return true;
		} catch (Throwable $e) {
			return false;
		}

	}//end isBotInstalled()

	/**
	 * Every room Hermiq is configured for, and whether it is actually live.
	 *
	 * Enumerated from HERMIQ's own room→agent map rather than from spreed's bot
	 * tables: spreed exposes no supported "which conversations is this bot in"
	 * query (`BotConversationMapper` only looks up by token), and reaching into
	 * another app's tables directly would be worse than the small blind spot
	 * this leaves.
	 *
	 * The blind spot is deliberate and reported in the panel: a room where a
	 * moderator enabled the bot but Hermiq has no binding will not appear.
	 * The inverse — bound here but the bot is NOT enabled there — is the
	 * common misconfiguration, and IS surfaced via `botEnabled`.
	 *
	 * @return array The per-room rows.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	private function describeRooms(): array {
		$rows = [];

		try {
			$manager = $this->container->get(self::TALK_MANAGER);

			foreach ($this->agentBinding->roomAgentMap() as $token => $agentId) {
				$rows[] = [
					'token' => $token,
					'name' => $this->roomName(manager: $manager, token: $token),
					'agentId' => $agentId,
					'agentName' => $this->agentName(agentId: $agentId),
					'botEnabled' => $this->isBotEnabledIn(token: $token),
					// False when the agent is missing or has not opted in —
					// i.e. the room is configured but nothing will answer.
					'agentActive' => ($this->agentBinding->agentForRoom(roomToken: $token) !== null),
				];
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkBridgeStatus] Could not enumerate the bot\'s conversations',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try

		return $rows;
	}//end describeRooms()

	/**
	 * Whether Hermiq's bot is enabled in a given conversation.
	 *
	 * @param string $token The room token.
	 *
	 * @return bool True when spreed will dispatch our bot there.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	private function isBotEnabledIn(string $token): bool {
		try {
			// FEATURE_EVENT (4) — the bit that makes spreed dispatch in-process.
			$bots = $this->container->get(self::BOT_SERVICE)->getBotsForToken($token, 4);
			foreach ($bots as $bot) {
				if ($bot->getBotServer()->getUrl() === TalkBridge::BOT_URL) {
					return true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkBridgeStatus] Could not resolve bot enablement for a room',
				context: ['file' => __FILE__, 'line' => __LINE__, 'token' => $token]
			);
		}

		return false;
	}//end isBotEnabledIn()

	/**
	 * A room's display name, or its token when unresolvable.
	 *
	 * @param object $manager The spreed room manager.
	 * @param string $token The room token.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	private function roomName(object $manager, string $token): string {
		try {
			$name = (string)$manager->getRoomByToken($token)->getName();
			if ($name !== '') {
				return $name;
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkBridgeStatus] Could not resolve a room name',
				context: ['file' => __FILE__, 'line' => __LINE__, 'token' => $token]
			);
		}

		return $token;
	}//end roomName()

	/**
	 * A bound agent's display name, or null when it no longer exists.
	 *
	 * @param string $agentId The agent uuid.
	 *
	 * @return string|null The agent name.
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
	 */
	private function agentName(string $agentId): ?string {
		try {
			$agent = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA
			);

			if (($agent instanceof ObjectEntity) === false) {
				return null;
			}

			$name = (string)($agent->getObject()['name'] ?? '');
			if ($name === '') {
				return null;
			}

			return $name;
		} catch (Throwable $e) {
			return null;
		}//end try

	}//end agentName()
}//end class
