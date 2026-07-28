<?php

/**
 * Hermiq TalkBridge.
 *
 * The single seam between Hermiq and spreed for the inbound chat bridge:
 * availability probing, bot identity, room resolution and posting an answer
 * back into a room.
 *
 * Every spreed class is resolved LAZILY through the container and every entry
 * point is guarded by `isAvailable()`, so Hermiq constructs and boots cleanly
 * on an instance with no Talk at all — the same pattern `DeliveryService`
 * already uses for outbound delivery (ADR-005).
 *
 * Note the deliberate asymmetry with the listener registration: registering the
 * listener is unconditional (a `class_exists()` guard at `register()` time can
 * return false on a healthy instance, because a sibling app may not be loaded
 * yet, and would silently disable the whole feature). The availability check
 * belongs HERE, at invoke time.
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#1-bot-registration-and-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use DateTime;
use OCP\Talk\IBroker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Talk availability, bot identity and room I/O for the chat bridge.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
 */
class TalkBridge
{

    /**
     * The bot URL. The `nextcloudapp://` scheme makes spreed dispatch
     * `BotInvokeEvent` IN-PROCESS instead of issuing an HTTP webhook — no
     * reachable callback URL, no shared secret to rotate, no egress, and no
     * 5s HTTP timeout.
     *
     * @var string
     */
    public const BOT_URL = 'nextcloudapp://hermiq';

    /**
     * Display name of the bot as it appears in Talk.
     *
     * @var string
     */
    public const BOT_NAME = 'Hermiq';

    /**
     * Spreed's bot actor type (Attendee::ACTOR_BOTS).
     *
     * @var string
     */
    private const ACTOR_BOTS = 'bots';

    /**
     * Spreed's bot actor id prefix (Attendee::ACTOR_BOT_PREFIX).
     *
     * @var string
     */
    private const ACTOR_BOT_PREFIX = 'bot-';

    /**
     * Fully-qualified spreed room manager, resolved lazily.
     *
     * @var string
     */
    private const TALK_MANAGER = 'OCA\\Talk\\Manager';

    /**
     * Fully-qualified spreed chat manager, resolved lazily.
     *
     * @var string
     */
    private const TALK_CHAT_MANAGER = 'OCA\\Talk\\Chat\\ChatManager';

    /**
     * Constructor.
     *
     * Both dependencies are always-present Nextcloud services; spreed is only
     * ever reached through `$container` behind `isAvailable()`.
     *
     * @param IBroker            $talkBroker Core Talk availability probe.
     * @param ContainerInterface $container  Server container for lazy spreed resolution.
     * @param LoggerInterface    $logger     PSR-3 logger.
     */
    public function __construct(
        private readonly IBroker $talkBroker,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether Talk is installed and usable for the bridge.
     *
     * @return bool True when spreed is present and its classes resolve.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-listener-registration-is-unconditional-and-availability-is-probed-at-invoke-time
     */
    public function isAvailable(): bool
    {
        return $this->talkBroker->hasBackend() === true
            && class_exists(self::TALK_MANAGER) === true
            && class_exists(self::TALK_CHAT_MANAGER) === true;

    }//end isAvailable()

    /**
     * The bot's actor id, derived exactly as spreed derives it.
     *
     * Spreed stores `urlHash = sha1($url)` on install and builds the actor id
     * as `ACTOR_BOT_PREFIX . urlHash` (`BotController::sendMessage`), so this
     * is deterministic and needs no database read.
     *
     * @return string The bot actor id.
     *
     * @spec openspec/changes/talk-chat-bridge/contract.md
     */
    public function botActorId(): string
    {
        return self::ACTOR_BOT_PREFIX.sha1(self::BOT_URL);

    }//end botActorId()

    /**
     * Post a message into a room as the Hermiq bot.
     *
     * Best-effort by contract: a room that has been deleted, or a bot that was
     * disabled or uninstalled while a turn was queued, is a terminal and
     * NON-RETRYABLE outcome — it must never fail the run.
     *
     * @param string $roomToken The Talk room token.
     * @param string $message   The message body (markdown).
     *
     * @return bool True when the message was posted.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    public function postToRoom(string $roomToken, string $message): bool
    {
        if ($this->isAvailable() === false || $roomToken === '' || trim($message) === '') {
            return false;
        }

        try {
            $room = $this->container->get(self::TALK_MANAGER)->getRoomByToken($roomToken);
            $this->container->get(self::TALK_CHAT_MANAGER)->sendMessage(
                $room,
                null,
                self::ACTOR_BOTS,
                $this->botActorId(),
                $message,
                new DateTime()
            );
            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBridge] Could not post the agent answer into the room',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end postToRoom()

    /**
     * Whether the given room is a one-to-one conversation.
     *
     * Drives mention gating: in a one-to-one room with the bot every message is
     * a turn; in a group room the agent answers only when addressed.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return bool True when the room is one-to-one.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
     */
    public function isOneToOne(string $roomToken): bool
    {
        if ($this->isAvailable() === false || $roomToken === '') {
            return false;
        }

        try {
            $room = $this->container->get(self::TALK_MANAGER)->getRoomByToken($roomToken);
            // Room::TYPE_ONE_TO_ONE = 1, Room::TYPE_ONE_TO_ONE_FORMER = 5.
            return in_array((int) $room->getType(), [1, 5], true);
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[TalkBridge] Could not resolve the room type; treating it as a group room',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end isOneToOne()

    /**
     * The uids of a room's user participants.
     *
     * Used to seed a new conversation's participant roster at bind time. Note
     * this is a SNAPSHOT: the roster stays explicit thereafter, so "who can use
     * this agent" never changes silently when someone is later added to a room.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return string[] The participant uids, or an empty list when unresolvable.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
     */
    public function roomUserIds(string $roomToken): array
    {
        if ($this->isAvailable() === false || $roomToken === '') {
            return [];
        }

        try {
            $room         = $this->container->get(self::TALK_MANAGER)->getRoomByToken($roomToken);
            $participants = $this->container->get('OCA\\Talk\\Service\\ParticipantService')
                ->getParticipantUserIds($room);

            $uids = [];
            foreach ($participants as $uid) {
                if (is_string($uid) === true && $uid !== '' && in_array($uid, $uids, true) === false) {
                    $uids[] = $uid;
                }
            }

            return $uids;
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[TalkBridge] Could not resolve room participants',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return [];
        }//end try

    }//end roomUserIds()
}//end class
