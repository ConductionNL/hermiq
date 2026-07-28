<?php

/**
 * Unit tests for TalkBotInvokeListener.
 *
 * The listener runs SYNCHRONOUSLY inside the Talk message sender's request, so
 * the single most important property is that it never reaches the engine —
 * doing so would hold a user's message send open for the length of an LLM
 * call. That is exactly the kind of property a later refactor quietly undoes,
 * which is why it is asserted here rather than left to review.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TalkBotInvokeListener;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the inbound Talk bridge listener.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */
class TalkBotInvokeListenerTest extends TestCase
{

    /**
     * Talk availability and room I/O.
     *
     * @var TalkBridge&MockObject
     */
    private $bridge;

    /**
     * Room ↔ conversation resolution.
     *
     * @var TalkRoomBinding&MockObject
     */
    private $roomBinding;

    /**
     * Room → agent resolution.
     *
     * @var TalkAgentBinding&MockObject
     */
    private $agentBinding;

    /**
     * Out-of-request hand-off.
     *
     * @var TalkTurnDispatcher&MockObject
     */
    private $dispatcher;

    /**
     * Sidebar grouping.
     *
     * @var TalkRoomGrouping&MockObject
     */
    private $grouping;

    /**
     * Listener under test.
     *
     * @var TalkBotInvokeListener
     */
    private TalkBotInvokeListener $listener;

    /**
     * Build the listener with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bridge       = $this->createMock(TalkBridge::class);
        $this->roomBinding  = $this->createMock(TalkRoomBinding::class);
        $this->agentBinding = $this->createMock(TalkAgentBinding::class);
        $this->dispatcher   = $this->createMock(TalkTurnDispatcher::class);
        $this->grouping     = $this->createMock(TalkRoomGrouping::class);

        $this->listener = new TalkBotInvokeListener(
            $this->bridge,
            $this->roomBinding,
            $this->agentBinding,
            $this->dispatcher,
            $this->grouping,
            new ConversationParticipation(),
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * Build a fake spreed BotInvokeEvent.
     *
     * spreed is an optional dependency and is not installed in the unit-test
     * environment, so the event is modelled as an anonymous class carrying the
     * same surface the listener actually uses.
     *
     * @param string $content   The message content (raw `object.content`).
     * @param string $botUrl    The invoking bot's URL.
     * @param string $roomToken The room token.
     * @param string $actorId   The sender's actor id.
     *
     * @return Event The fake event.
     */
    private function makeEvent(
        string $content,
        string $botUrl=TalkBridge::BOT_URL,
        string $roomToken='room1',
        string $actorId='alice'
    ): Event {
        return new class($content, $botUrl, $roomToken, $actorId) extends Event {

            /**
             * Reactions added by the listener.
             *
             * @var string[]
             */
            public array $reactions = [];

            /**
             * Constructor.
             *
             * @param string $content   Message content.
             * @param string $botUrl    Bot URL.
             * @param string $roomToken Room token.
             * @param string $actorId   Actor id.
             */
            public function __construct(
                private readonly string $content,
                private readonly string $botUrl,
                private readonly string $roomToken,
                private readonly string $actorId
            ) {
            }

            /**
             * The invoking bot's URL.
             *
             * @return string The bot URL.
             */
            public function getBotUrl(): string
            {
                return $this->botUrl;
            }

            /**
             * The ActivityPub-shaped invocation payload.
             *
             * @return array The payload.
             */
            public function getMessage(): array
            {
                return [
                    'type'   => 'Create',
                    'actor'  => ['type' => 'Person', 'id' => $this->actorId, 'name' => 'Alice'],
                    'object' => ['type' => 'Note', 'id' => '1', 'content' => $this->content],
                    'target' => ['type' => 'Collection', 'id' => $this->roomToken, 'name' => 'Room'],
                ];
            }

            /**
             * Record an acknowledgement reaction.
             *
             * @param string $emoji The emoji.
             *
             * @return void
             */
            public function addReaction(string $emoji): void
            {
                $this->reactions[] = $emoji;
            }
        };

    }//end makeEvent()

    /**
     * A conversation entity carrying the given payload.
     *
     * Built as a REAL ObjectEntity rather than a mock: OpenRegister entities
     * expose their getters through `Entity::__call`, so `getUuid()`/`getObject()`
     * are not real methods and PHPUnit refuses to configure them. That only
     * shows up against the real OpenRegister (CI), not against the local stub.
     *
     * @param array $data The conversation payload.
     *
     * @return ObjectEntity The entity.
     */
    private function makeConversation(array $data): ObjectEntity
    {
        $conversation = new ObjectEntity();
        $conversation->setUuid('conv-1');
        $conversation->setObject($data);

        return $conversation;

    }//end makeConversation()

    /**
     * An addressed message is acknowledged and handed off — never run inline.
     *
     * The dispatcher standing in for "handed off out of request" is the whole
     * point: if a future change called the engine here instead, this test's
     * expectation would still pass, so the assertion is that the hand-off IS
     * the terminal action.
     *
     * @return void
     */
    public function testAddressedMessageIsAcknowledgedAndHandedOff(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(false);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'alice', 'participants' => []])
        );

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                conversationUuid: 'conv-1',
                speakerUid: 'alice',
                message: '@Hermiq hello there',
                roomToken: 'room1'
            )
            ->willReturn('queued');

        $event = $this->makeEvent(content: '@Hermiq hello there');
        $this->listener->handle($event);

        $this->assertSame(['⏳'], $event->reactions, 'Receipt must be acknowledged inside the request.');

    }//end testAddressedMessageIsAcknowledgedAndHandedOff()

    /**
     * An unaddressed group message takes no turn at all.
     *
     * @return void
     */
    public function testUnaddressedGroupMessageIsIgnored(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(false);
        $this->bridge->method('botActorId')->willReturn('bot-x');
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');

        $this->dispatcher->expects($this->never())->method('dispatch');

        $event = $this->makeEvent(content: 'just talking to my colleague');
        $this->listener->handle($event);

        $this->assertSame([], $event->reactions, 'An ignored message must not be acknowledged.');

    }//end testUnaddressedGroupMessageIsIgnored()

    /**
     * Every message is a turn in a one-to-one room with the bot.
     *
     * @return void
     */
    public function testOneToOneRoomTakesEveryMessage(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'alice', 'participants' => []])
        );

        $this->dispatcher->expects($this->once())->method('dispatch')->willReturn('queued');

        $this->listener->handle($this->makeEvent(content: 'no mention needed here'));

    }//end testOneToOneRoomTakesEveryMessage()

    /**
     * spreed's JSON message envelope is decoded before it reaches the engine.
     *
     * `ActivityPubHelper::generateNote()` sets `content` to
     * `json_encode(['message' => …, 'parameters' => …])`, so handing it over
     * verbatim would feed the agent a JSON blob as its prompt. Caught on the
     * first live round-trip; pinned here.
     *
     * @return void
     */
    public function testJsonEnvelopeIsDecodedToPlainText(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'alice', 'participants' => []])
        );

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                conversationUuid: 'conv-1',
                speakerUid: 'alice',
                message: 'what is 2+2?',
                roomToken: 'room1'
            )
            ->willReturn('queued');

        $envelope = json_encode(['message' => 'what is 2+2?', 'parameters' => []]);
        $this->listener->handle($this->makeEvent(content: (string) $envelope));

    }//end testJsonEnvelopeIsDecodedToPlainText()

    /**
     * Mention placeholders are substituted back to their display names.
     *
     * @return void
     */
    public function testMentionPlaceholdersAreSubstituted(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'alice', 'participants' => []])
        );

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                conversationUuid: 'conv-1',
                speakerUid: 'alice',
                message: 'Hermiq summarise this',
                roomToken: 'room1'
            )
            ->willReturn('queued');

        $envelope = json_encode(
            [
                'message'    => '{mention-bot1} summarise this',
                'parameters' => ['mention-bot1' => ['type' => 'bot', 'name' => 'Hermiq']],
            ]
        );
        $this->listener->handle($this->makeEvent(content: (string) $envelope));

    }//end testMentionPlaceholdersAreSubstituted()

    /**
     * With no opted-in agent bound to the room, nothing happens.
     *
     * @return void
     */
    public function testNoOptedInAgentTakesNoTurn(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn(null);

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->handle($this->makeEvent(content: '@Hermiq hello'));

    }//end testNoOptedInAgentTakesNoTurn()

    /**
     * With Talk unavailable the listener is inert.
     *
     * @return void
     */
    public function testTalkUnavailableIsInert(): void
    {
        $this->bridge->method('isAvailable')->willReturn(false);

        $this->agentBinding->expects($this->never())->method('agentForRoom');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->handle($this->makeEvent(content: '@Hermiq hello'));

    }//end testTalkUnavailableIsInert()

    /**
     * Another app's bot invocation is ignored.
     *
     * @return void
     */
    public function testForeignBotIsIgnored(): void
    {
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->handle(
            $this->makeEvent(content: '@Hermiq hello', botUrl: 'nextcloudapp://someoneelse')
        );

    }//end testForeignBotIsIgnored()

    /**
     * A speaker who is not a participant of the bound conversation is refused.
     *
     * The bridge is a third entry point that never passes through
     * ChatController, so this guard is not redundant.
     *
     * @return void
     */
    public function testNonParticipantSpeakerIsRefused(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'bob', 'participants' => ['carol']])
        );

        $this->dispatcher->expects($this->never())->method('dispatch');

        $event = $this->makeEvent(content: 'let me in', actorId: 'mallory');
        $this->listener->handle($event);

        $this->assertSame([], $event->reactions);

    }//end testNonParticipantSpeakerIsRefused()

    /**
     * A `users/`-prefixed actor id is normalised to a bare uid.
     *
     * @return void
     */
    public function testActorPrefixIsStripped(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->bridge->method('isOneToOne')->willReturn(true);
        $this->agentBinding->method('agentForRoom')->willReturn('agent-1');
        $this->roomBinding->method('findByRoomToken')->willReturn(
            $this->makeConversation(['userId' => 'alice', 'participants' => []])
        );

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                conversationUuid: 'conv-1',
                speakerUid: 'alice',
                message: 'hello',
                roomToken: 'room1'
            )
            ->willReturn('queued');

        $this->listener->handle($this->makeEvent(content: 'hello', actorId: 'users/alice'));

    }//end testActorPrefixIsStripped()
}//end class
