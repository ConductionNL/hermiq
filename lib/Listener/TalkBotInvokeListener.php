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
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
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
     * @param TalkMentionMatcher        $mentionMatcher Decides whether a message addresses the agent by name.
     * @param LoggerInterface           $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly TalkBridge $bridge,
        private readonly TalkRoomBinding $roomBinding,
        private readonly TalkAgentBinding $agentBinding,
        private readonly TalkTurnDispatcher $dispatcher,
        private readonly TalkRoomGrouping $grouping,
        private readonly ConversationParticipation $participation,
        private readonly TalkMentionMatcher $mentionMatcher,
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
        $payload = $this->readPayload(event: $event);
        if ($payload === null) {
            return;
        }

        $turn = $this->readTurn(payload: $payload);
        if ($turn === null) {
            return;
        }

        $agentId = $this->agentBinding->agentForRoom(roomToken: $turn['roomToken']);
        if ($agentId === null) {
            // No opted-in agent is bound to this room — both opt-ins are
            // required, so this is the default state and not an error.
            return;
        }

        if ($this->isAddressed(payload: $payload, roomToken: $turn['roomToken'], agentId: $agentId) === false) {
            return;
        }

        $conversation = $this->resolveConversation(
            roomToken: $turn['roomToken'],
            agentId: $agentId,
            speakerUid: $turn['speakerUid']
        );
        if ($conversation === null) {
            return;
        }

        if ($this->admitAndAcknowledge(event: $event, conversation: $conversation, turn: $turn) === false) {
            return;
        }

        $path = $this->dispatcher->dispatch(
            conversationUuid: (string) $conversation->getUuid(),
            speakerUid: $turn['speakerUid'],
            message: $turn['content'],
            roomToken: $turn['roomToken']
        );

        $this->logger->info(
            message: '[TalkBotInvokeListener] Talk turn handed off',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'roomToken'    => $turn['roomToken'],
                'conversation' => (string) $conversation->getUuid(),
                'speaker'      => $turn['speakerUid'],
                'path'         => $path,
            ]
        );

    }//end handleInvocation()

    /**
     * Admit the speaker and acknowledge receipt, or refuse the turn.
     *
     * The participant check is enforced here as well as in the engine: a room
     * member who is not on the roster of the conversation bound to that room
     * does not get to take a turn on it.
     *
     * @param Event        $event        The spreed BotInvokeEvent.
     * @param ObjectEntity $conversation The bound conversation.
     * @param array        $turn         The read turn (room, speaker, content).
     *
     * @return bool True when the turn may proceed.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
     */
    private function admitAndAcknowledge(Event $event, ObjectEntity $conversation, array $turn): bool
    {
        if ($this->participation->mayTakeTurn(conversationData: $conversation->getObject(), userId: $turn['speakerUid']) === false) {
            $this->logger->info(
                message: '[TalkBotInvokeListener] Speaker is not a participant of the bound conversation — refusing the turn',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'roomToken'    => $turn['roomToken'],
                    'conversation' => (string) $conversation->getUuid(),
                ]
            );
            return false;
        }

        // Acknowledge INSIDE this request — the one prompt signal the user gets.
        if (method_exists($event, 'addReaction') === true) {
            $event->addReaction(self::ACK_REACTION);
        }

        return true;

    }//end admitAndAcknowledge()

    /**
     * Read the invocation payload, or null when this event is not our turn.
     *
     * Guards on `method_exists` rather than a type check because the event is a
     * third-party shape from an OPTIONAL app — a spreed too old to carry it must
     * degrade to "no turn", never to a fatal inside someone's message send. The
     * guard and the call live together deliberately: split apart, static
     * analysis cannot see that the method is checked before it is used.
     *
     * @param Event $event The spreed BotInvokeEvent.
     *
     * @return array|null The actionable payload, or null.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-listener-registration-is-unconditional-and-availability-is-probed-at-invoke-time
     *
     * @SuppressWarnings(PHPMD.StaticAccess) TalkBridge::isHermiqBotUrl() is static
     * DELIBERATELY: it is a pure function of the URL and it guards the approval
     * path. As an instance method it was mockable, and a bare double answers
     * false — which forced a blanket stub in every listener test and thereby
     * disabled the one test proving a foreign bot is rejected. Injecting it to
     * satisfy this rule would reintroduce exactly that hole.
     */
    private function readPayload(Event $event): ?array
    {
        // Any Hermiq bot, not one constant — see the note in
        // TalkApprovalReactionListener::readPayload() on why the predicate is
        // strict about what counts as ours.
        if (method_exists($event, 'getBotUrl') === false || TalkBridge::isHermiqBotUrl((string) $event->getBotUrl()) === false) {
            return null;
        }

        if (method_exists($event, 'getMessage') === false || $this->bridge->isAvailable() === false) {
            return null;
        }

        $payload = $event->getMessage();

        // Joins, leaves, reactions and system messages are not turns.
        if (is_array($payload) === false || ($payload['type'] ?? null) !== 'Create') {
            return null;
        }

        return $payload;

    }//end readPayload()

    /**
     * Read the room, speaker and text out of an invocation payload.
     *
     * @param array $payload The ActivityPub-shaped invocation payload.
     *
     * @return array{roomToken: string, speakerUid: string, content: string}|null
     *         The turn, or null when the payload is not actionable.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    private function readTurn(array $payload): ?array
    {
        $roomToken  = (string) ($payload['target']['id'] ?? '');
        $speakerUid = (string) ($payload['actor']['id'] ?? '');
        $content    = $this->plainText(raw: (string) ($payload['object']['content'] ?? ''));

        // Spreed prefixes user actors with `users/`.
        if (str_starts_with($speakerUid, 'users/') === true) {
            $speakerUid = substr($speakerUid, strlen('users/'));
        }

        if ($roomToken === '' || $speakerUid === '' || trim($content) === '') {
            return null;
        }

        return [
            'roomToken'  => $roomToken,
            'speakerUid' => $speakerUid,
            'content'    => $content,
        ];

    }//end readTurn()

    /**
     * Resolve the conversation bound to the room, opening one on first contact.
     *
     * @param string $roomToken  The Talk room token.
     * @param string $agentId    The opted-in agent bound to the room.
     * @param string $speakerUid The uid opening the session, when new.
     *
     * @return ObjectEntity|null The bound conversation, or null when unavailable.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    private function resolveConversation(string $roomToken, string $agentId, string $speakerUid): ?ObjectEntity
    {
        $conversation = $this->roomBinding->findByRoomToken(roomToken: $roomToken);
        if ($conversation !== null) {
            // A late joiner needs their own Hermiq tag too — tags are PER USER
            // (the row carries a user_id and the room joins through the
            // attendee), so filing the room once at bind time only ever filed
            // it for whoever was in it then. groupRoom() is additive and
            // idempotent, so re-filing on a turn costs nothing and is the only
            // hook that sees somebody who joined afterwards.
            $this->grouping->groupRoom(roomToken: $roomToken);

            // 🔴 Sync the roster BEFORE the participation check, not after.
            //
            // Authorization reads the STORED roster, never live room membership
            // (talk-shared-sessions is explicit about that). So somebody invited
            // to the room after it was bound is a room member who is not yet on
            // the roster — and their very first message would be refused. The
            // check that runs a moment later is exactly the thing this has to
            // beat, which is why it lives here and not beside the bind below.
            return $this->roomBinding->syncParticipants(
                conversation: $conversation,
                participants: $this->bridge->roomUserIds(roomToken: $roomToken)
            );
        }

        $conversation = $this->roomBinding->createBound(
            roomToken: $roomToken,
            agentId: $agentId,
            ownerUid: $speakerUid,
            participants: $this->bridge->roomUserIds(roomToken: $roomToken)
        );

        // Newly bound: file the room under each participant's own Hermiq tag so
        // agent rooms stop competing with their human conversations
        // (talk-room-grouping). Cosmetic and best-effort — it can never fail the
        // bind or the turn.
        if ($conversation !== null) {
            $this->grouping->groupRoom(roomToken: $roomToken);
        }

        return $conversation;

    }//end resolveConversation()

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

        $parameters = ($decoded['parameters'] ?? []);
        if (is_array($parameters) === false) {
            return (string) $decoded['message'];
        }

        return $this->substituteParameters(text: (string) $decoded['message'], parameters: $parameters);

    }//end plainText()

    /**
     * Replace `{mention-user1}`-style placeholders with their display names.
     *
     * @param string $text       The message text carrying placeholders.
     * @param array  $parameters The invocation payload's parameter map.
     *
     * @return string The text with placeholders substituted.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    private function substituteParameters(string $text, array $parameters): string
    {
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

    }//end substituteParameters()

    /**
     * Whether the agent is being addressed by this message.
     *
     * 🔴 The room's ORIGIN decides the rule, not the room's type.
     *
     * A room Hermiq created for a session exists for no other purpose, so every
     * human message in it is a turn — requiring a mention there made users
     * `@`-address an agent in its own dedicated room, which reads as broken.
     *
     * A room Hermiq was merely invited into belongs to somebody else, and the
     * mention gate stays exactly as it was: team rooms are where scheduled
     * reports get delivered, and an agent that answered every message there
     * would be the reason nobody keeps it in the room. One-to-one and
     * reply-to-the-agent both survive inside that gate.
     *
     * The origin is READ from stored data, never inferred from the room's
     * current shape — inferring it (say, "owner plus one bot") would silently
     * flip this behaviour the moment somebody invites a second person.
     *
     * @param array  $payload   The invocation payload.
     * @param string $roomToken The Talk room token.
     * @param string $agentId   The agent bound to this room, whose name is the mention target.
     *
     * @return bool True when the agent should take the turn.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-room-it-did-not-create
     */
    private function isAddressed(array $payload, string $roomToken, string $agentId): bool
    {
        if ($this->roomBinding->isCreatedRoom(roomToken: $roomToken) === true) {
            return true;
        }

        if ($this->bridge->isOneToOne(roomToken: $roomToken) === true) {
            return true;
        }

        // The mention target is the AGENT's name now, not the single word
        // "Hermiq" — that is what a per-agent bot renders as, and what a user
        // sees to type. Fall back to the shared bot name so a room bound before
        // this change keeps working.
        $targets   = [TalkBridge::BOT_NAME];
        $agentName = $this->agentBinding->agentName(agentId: $agentId);
        if ($agentName !== '') {
            array_unshift($targets, $agentName);
        }

        // Match on the DECODED text, not the raw envelope: the raw JSON also
        // contains the mention parameters, so matching it would fire on
        // messages that merely quote the bot's name.
        $content = $this->plainText(raw: (string) ($payload['object']['content'] ?? ''));
        if ($this->mentionMatcher->matchesAny(content: $content, names: $targets) === true) {
            return true;
        }

        $parameters = ($payload['object']['parameters'] ?? []);
        if (is_array($parameters) === true
            && $this->mentionMatcher->matchesParameters(parameters: $parameters, names: $targets) === true
        ) {
            return true;
        }

        // A reply to one of the agent's own messages continues the exchange.
        // Both actor ids are accepted so a room bound before per-agent bots,
        // whose history is signed by the shared bot, still continues.
        $parentActor = (string) ($payload['object']['inReplyTo']['actor']['id'] ?? '');

        return ($parentActor !== ''
            && ($parentActor === $this->bridge->botActorId(agentId: $agentId)
                || $parentActor === $this->bridge->botActorId()));

    }//end isAddressed()

    /**
     * Whether Hermiq created this room for a session.
     *
     * Absent or unreadable means "bound", which is the pre-change behaviour and
     * the safe default: it keeps the mention gate on rather than turning a
     * quiet agent into one that answers everything in somebody's team room.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return bool True when Hermiq created the room.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
     */
}//end class
