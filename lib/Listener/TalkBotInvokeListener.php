<?php

/**
 * Hermiq TalkBotInvokeListener.
 *
 * The inbound half of the Talk bridge: turns a room message into an agent turn.
 *
 * spreed dispatches `BotInvokeEvent` IN-PROCESS for bots registered with the
 * `nextcloudapp://` URL scheme — no webhook, no shared secret, no egress.
 *
 * 🔴 This listener runs SYNCHRONOUSLY inside the message sender's request
 * (`BotService::afterChatMessageSent`, a plain listener registered at spreed's
 * `Application.php:216`; the HTTP-webhook variant caps at a 5s timeout). An
 * agent turn is 5–60s. It therefore does only cheap work — resolve, gate,
 * acknowledge, hand off — and MUST NOT reach the engine. `TalkBotInvokeListenerTest`
 * asserts exactly that, because it is the kind of property a later refactor
 * quietly undoes.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#2-inbound-listener-cheap-work-only
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens for spreed's in-process bot invocation and hands off an agent turn.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */
class TalkBotInvokeListener implements IEventListener
{

    /**
     * The emoji used to acknowledge receipt within the originating request.
     *
     * The only signal guaranteed to be prompt on either hand-off path — it is
     * what distinguishes "your message landed" from silence while the turn
     * runs.
     *
     * @var string
     */
    private const ACK_REACTION = '⏳';

    /**
     * Constructor.
     *
     * @param TalkBridge                $bridge        Talk availability, room type and room I/O.
     * @param TalkRoomBinding           $roomBinding   Resolves/creates the bound conversation.
     * @param TalkAgentBinding          $agentBinding  Resolves the room's opted-in agent.
     * @param TalkTurnDispatcher        $dispatcher    Hands the turn off out of request.
     * @param TalkRoomGrouping          $grouping      Files a newly bound room under each participant's tag.
     * @param ConversationParticipation $participation Owner-or-participant guard.
     * @param LoggerInterface           $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly TalkBridge $bridge,
        private readonly TalkRoomBinding $roomBinding,
        private readonly TalkAgentBinding $agentBinding,
        private readonly TalkTurnDispatcher $dispatcher,
        private readonly TalkRoomGrouping $grouping,
        private readonly ConversationParticipation $participation,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a spreed bot invocation.
     *
     * Never throws: this runs inside someone else's message send, so an
     * exception here would surface as a failed message send for a user who did
     * nothing wrong.
     *
     * @param Event $event The spreed BotInvokeEvent.
     *
     * @return void
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
     */
    public function handle(Event $event): void
    {
        try {
            $this->handleInvocation(event: $event);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[TalkBotInvokeListener] Inbound Talk invocation failed (the sender is unaffected)',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }

    }//end handle()

    /**
     * Resolve, gate, acknowledge and hand off.
     *
     * @param Event $event The spreed BotInvokeEvent.
     *
     * @return void
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    private function handleInvocation(Event $event): void
    {
        // Not our bot, or a spreed too old to carry this event shape.
        if (method_exists($event, 'getBotUrl') === false || $event->getBotUrl() !== TalkBridge::BOT_URL) {
            return;
        }

        if ($this->bridge->isAvailable() === false) {
            return;
        }

        if (method_exists($event, 'getMessage') === false) {
            return;
        }

        $payload = $event->getMessage();
        if (is_array($payload) === false || ($payload['type'] ?? null) !== 'Create') {
            // Joins, leaves, reactions and system messages are not turns.
            return;
        }

        $roomToken  = (string) ($payload['target']['id'] ?? '');
        $speakerUid = (string) ($payload['actor']['id'] ?? '');
        $content    = $this->plainText(raw: (string) ($payload['object']['content'] ?? ''));

        // Spreed prefixes user actors with `users/`.
        if (str_starts_with($speakerUid, 'users/') === true) {
            $speakerUid = substr($speakerUid, strlen('users/'));
        }

        if ($roomToken === '' || $speakerUid === '' || trim($content) === '') {
            return;
        }

        $agentId = $this->agentBinding->agentForRoom(roomToken: $roomToken);
        if ($agentId === null) {
            // No opted-in agent is bound to this room — both opt-ins are
            // required, so this is the default state and not an error.
            return;
        }

        if ($this->isAddressed(payload: $payload, roomToken: $roomToken) === false) {
            return;
        }

        $conversation = $this->roomBinding->findByRoomToken(roomToken: $roomToken);
        if ($conversation === null) {
            $conversation = $this->roomBinding->createBound(
                roomToken: $roomToken,
                agentId: $agentId,
                ownerUid: $speakerUid,
                participants: $this->bridge->roomUserIds(roomToken: $roomToken)
            );

            // Newly bound: file the room under each participant's own Hermiq
            // tag so agent rooms stop competing with their human conversations
            // (talk-room-grouping). Cosmetic and best-effort — it can never
            // fail the bind or the turn.
            if ($conversation !== null) {
                $this->grouping->groupRoom(roomToken: $roomToken);
            }
        }

        if ($conversation === null) {
            return;
        }

        // Owner-or-participant, enforced here as well as in the engine. A room
        // member who is not on the roster of a conversation bound to that room
        // does not get to take a turn on it.
        if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $speakerUid) === false) {
            $this->logger->info(
                message: '[TalkBotInvokeListener] Speaker is not a participant of the bound conversation — refusing the turn',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'roomToken'    => $roomToken,
                    'conversation' => (string) $conversation->getUuid(),
                ]
            );
            return;
        }

        // Acknowledge INSIDE this request — the one prompt signal the user gets.
        if (method_exists($event, 'addReaction') === true) {
            $event->addReaction(self::ACK_REACTION);
        }

        $path = $this->dispatcher->dispatch(
            conversationUuid: (string) $conversation->getUuid(),
            speakerUid: $speakerUid,
            message: $content,
            roomToken: $roomToken
        );

        $this->logger->info(
            message: '[TalkBotInvokeListener] Talk turn handed off',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'roomToken'    => $roomToken,
                'conversation' => (string) $conversation->getUuid(),
                'speaker'      => $speakerUid,
                'path'         => $path,
            ]
        );

    }//end handleInvocation()

    /**
     * Extract the human-readable text from a bot invocation's `object.content`.
     *
     * Spreed does NOT put the message text there: `ActivityPubHelper::generateNote()`
     * sets `content` to `json_encode(['message' => …, 'parameters' => …])`, the
     * PARSED message envelope. Handing that straight to the engine would feed
     * the agent a JSON blob as its prompt — which is exactly what happened on
     * the first live round-trip.
     *
     * Rich mentions arrive as `{mention-user1}`-style placeholders in `message`
     * with their real values in `parameters`, so those are substituted back to
     * their display names to keep the prompt readable.
     *
     * Falls back to the raw string when it is not the expected envelope, so a
     * future spreed change degrades to "slightly odd prompt" rather than to a
     * dropped turn.
     *
     * @param string $raw The invocation payload's `object.content`.
     *
     * @return string The human-readable message text.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    private function plainText(string $raw): string
    {
        if ($raw === '' || str_starts_with(trim($raw), '{') === false) {
            return $raw;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $raw;
        }

        if (is_array($decoded) === false || is_string($decoded['message'] ?? null) === false) {
            return $raw;
        }

        $text       = (string) $decoded['message'];
        $parameters = ($decoded['parameters'] ?? []);
        if (is_array($parameters) === false) {
            return $text;
        }

        foreach ($parameters as $key => $parameter) {
            if (is_array($parameter) === false) {
                continue;
            }

            $name = ($parameter['name'] ?? ($parameter['id'] ?? null));
            if (is_string($name) === true && $name !== '') {
                $text = str_replace('{'.$key.'}', $name, $text);
            }
        }

        return $text;

    }//end plainText()

    /**
     * Whether the agent is being addressed by this message.
     *
     * One-to-one room with the bot: every message is a turn. Group room: only
     * an `@`-mention or a reply to one of the agent's own messages, so the
     * agent does not answer every message in a busy team room — and team rooms
     * are exactly where reports get delivered.
     *
     * @param array  $payload   The invocation payload.
     * @param string $roomToken The Talk room token.
     *
     * @return bool True when the agent should take the turn.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
     */
    private function isAddressed(array $payload, string $roomToken): bool
    {
        if ($this->bridge->isOneToOne(roomToken: $roomToken) === true) {
            return true;
        }

        // Match on the DECODED text, not the raw envelope: the raw JSON also
        // contains the mention parameters, so matching it would fire on
        // messages that merely quote the bot's name.
        $content = $this->plainText(raw: (string) ($payload['object']['content'] ?? ''));
        if (stripos($content, '@'.TalkBridge::BOT_NAME) !== false) {
            return true;
        }

        // A rendered mention arrives as a parameter of type `call`/`user`/`guest`
        // rather than literal text, so check those too.
        $parameters = ($payload['object']['parameters'] ?? []);
        if (is_array($parameters) === true) {
            foreach ($parameters as $parameter) {
                if (is_array($parameter) === false) {
                    continue;
                }

                $name = (string) ($parameter['name'] ?? '');
                if (strcasecmp($name, TalkBridge::BOT_NAME) === 0) {
                    return true;
                }
            }
        }

        // A reply to one of the agent's own messages continues the exchange.
        $parentActor = (string) ($payload['object']['inReplyTo']['actor']['id'] ?? '');

        return ($parentActor !== '' && $parentActor === $this->bridge->botActorId());

    }//end isAddressed()
}//end class
