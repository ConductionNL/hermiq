<?php

/**
 * Edge-path tests for TalkBotInvokeListener.
 *
 * The bot listener runs inside a Talk message send. Anything that is not a
 * readable, non-empty room message must take no turn, and a failure while
 * handling one must be logged rather than surface as a failed send.
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
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TalkBotInvokeListener;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the bot listener's refusal and failure paths.
 *
 * @covers \OCA\Hermiq\Listener\TalkBotInvokeListener
 * @uses   \OCA\Hermiq\Listener\TalkMessageText
 * @uses   \OCA\Hermiq\Listener\TalkInvocation
 * @uses   \OCA\Hermiq\Service\Talk\TalkBridge
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */
class TalkBotInvokeListenerEdgeTest extends TestCase {

	/**
	 * Talk availability and room I/O.
	 *
	 * @var TalkBridge&MockObject
	 */
	private $bridge;

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
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

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
	protected function setUp(): void {
		parent::setUp();

		$this->bridge = $this->createMock(TalkBridge::class);
		$this->agentBinding = $this->createMock(TalkAgentBinding::class);
		$this->dispatcher = $this->createMock(TalkTurnDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new TalkBotInvokeListener(
			$this->bridge,
			$this->createMock(TalkRoomBinding::class),
			$this->agentBinding,
			$this->dispatcher,
			$this->createMock(TalkRoomGrouping::class),
			new ConversationParticipation(),
			new TalkMentionMatcher(),
			$this->logger
		);

	}//end setUp()

	/**
	 * A Hermiq bot invocation with the given type and content.
	 *
	 * @param string $type    The ActivityPub type.
	 * @param string $content The raw `object.content`.
	 *
	 * @return Event The fake spreed event.
	 */
	private function invocation(string $type, string $content): Event {
		return new class($type, $content) extends Event {
			/**
			 * Constructor.
			 *
			 * @param string $type    The invocation type.
			 * @param string $content The message content.
			 */
			public function __construct(
				private readonly string $type,
				private readonly string $content,
			) {
			}//end __construct()

			/**
			 * The invoking bot's URL.
			 *
			 * @return string The bot URL.
			 */
			public function getBotUrl(): string {
				return TalkBridge::BOT_URL;
			}//end getBotUrl()

			/**
			 * The invocation payload.
			 *
			 * @return array The payload.
			 */
			public function getMessage(): array {
				return [
					'type' => $this->type,
					'actor' => ['type' => 'Person', 'id' => 'users/alice'],
					'object' => ['type' => 'Note', 'id' => '1', 'content' => $this->content],
					'target' => ['type' => 'Collection', 'id' => 'room1'],
				];
			}//end getMessage()
		};

	}//end invocation()

	/**
	 * A join, leave or reaction invocation is not a turn.
	 *
	 * @return void
	 */
	public function testANonCreateInvocationTakesNoTurn(): void {
		$this->bridge->method('isAvailable')->willReturn(true);

		$this->agentBinding->expects($this->never())->method('agentForRoom');
		$this->dispatcher->expects($this->never())->method('dispatch');

		$this->listener->handle($this->invocation(type: 'Join', content: '@Hermiq hello'));

	}//end testANonCreateInvocationTakesNoTurn()

	/**
	 * A message that is blank once reduced to plain text is not a turn.
	 *
	 * @return void
	 */
	public function testABlankMessageTakesNoTurn(): void {
		$this->bridge->method('isAvailable')->willReturn(true);

		$this->agentBinding->expects($this->never())->method('agentForRoom');
		$this->dispatcher->expects($this->never())->method('dispatch');

		$this->listener->handle($this->invocation(type: 'Create', content: "  \n\t "));

	}//end testABlankMessageTakesNoTurn()

	/**
	 * A failure while handling a turn is logged once and never thrown.
	 *
	 * @return void
	 */
	public function testAFailureWhileHandlingIsLoggedAndNotThrown(): void {
		$this->bridge->method('isAvailable')->willReturn(true);
		$this->agentBinding->expects($this->once())
			->method('agentForRoom')
			->willThrowException(new RuntimeException('binding store offline'));
		$this->dispatcher->expects($this->never())->method('dispatch');

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->identicalTo('[TalkBotInvokeListener] Inbound Talk invocation failed (the sender is unaffected)'),
				$this->callback(static fn (array $context): bool => ($context['error'] ?? null) === 'binding store offline')
			);

		$this->listener->handle($this->invocation(type: 'Create', content: '@Hermiq hello'));

	}//end testAFailureWhileHandlingIsLoggedAndNotThrown()
}//end class
