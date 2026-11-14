<?php

/**
 * Edge-path tests for TalkApprovalReactionListener.
 *
 * The reaction listener runs inside someone else's reaction request, so the
 * paths that matter most here are the quiet ones: a payload it cannot read
 * decides nothing, and a failure while deciding is logged, never thrown.
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
 * @spec openspec/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TalkApprovalReactionListener;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the reaction listener's refusal and failure paths.
 *
 * @covers \OCA\Hermiq\Listener\TalkApprovalReactionListener
 * @uses   \OCA\Hermiq\Service\Talk\TalkBridge
 *
 * @spec openspec/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
 */
class TalkApprovalReactionListenerEdgeTest extends TestCase {

	/**
	 * Talk availability.
	 *
	 * @var TalkBridge&MockObject
	 */
	private $bridge;

	/**
	 * Message id → approval resolution.
	 *
	 * @var TalkApprovalBinding&MockObject
	 */
	private $binding;

	/**
	 * Reviewer check and decisions.
	 *
	 * @var ApprovalService&MockObject
	 */
	private $approvals;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Listener under test.
	 *
	 * @var TalkApprovalReactionListener
	 */
	private TalkApprovalReactionListener $listener;

	/**
	 * Build the listener with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->bridge = $this->createMock(originalClassName: TalkBridge::class);
		$this->binding = $this->createMock(originalClassName: TalkApprovalBinding::class);
		$this->approvals = $this->createMock(originalClassName: ApprovalService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->listener = new TalkApprovalReactionListener(
			bridge: $this->bridge,
			approvalBinding: $this->binding,
			approvalService: $this->approvals,
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * A Hermiq bot invocation carrying the given payload.
	 *
	 * @param mixed $payload What getMessage() returns.
	 *
	 * @return Event The fake spreed event.
	 */
	private function invocation(mixed $payload): Event {
		return new class($payload) extends Event {
			/**
			 * Constructor.
			 *
			 * @param mixed $payload The invocation payload.
			 */
			public function __construct(private readonly mixed $payload) {
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
			 * @return mixed The payload.
			 */
			public function getMessage(): mixed {
				return $this->payload;
			}//end getMessage()
		};

	}//end invocation()

	/**
	 * A well-formed 👍 on message msg-1 by alice.
	 *
	 * @return array The payload.
	 */
	private function like(): array {
		return [
			'type' => 'Like',
			'actor' => ['type' => 'Person', 'id' => 'users/alice'],
			'object' => ['type' => 'Note', 'id' => 'msg-1'],
			'target' => ['type' => 'Collection', 'id' => 'room1'],
			'content' => '👍',
		];

	}//end like()

	/**
	 * With Talk unavailable a reaction is not even resolved to an approval.
	 *
	 * @return void
	 */
	public function testAReactionWhileTalkIsUnavailableDecidesNothing(): void {
		$this->bridge->method('isAvailable')->willReturn(false);

		$this->binding->expects($this->never())->method('findByMessageId');
		$this->approvals->expects($this->never())->method('isReviewer');

		$this->listener->handle($this->invocation($this->like()));

	}//end testAReactionWhileTalkIsUnavailableDecidesNothing()

	/**
	 * A payload that is not an array is not a reaction.
	 *
	 * @return void
	 */
	public function testANonArrayPayloadDecidesNothing(): void {
		$this->bridge->method('isAvailable')->willReturn(true);

		$this->binding->expects($this->never())->method('findByMessageId');

		$this->listener->handle($this->invocation('{"type":"Like"}'));

	}//end testANonArrayPayloadDecidesNothing()

	/**
	 * A reaction naming no message, or no reactor, is not resolved.
	 *
	 * @return void
	 */
	public function testAReactionWithoutMessageOrReactorDecidesNothing(): void {
		$this->bridge->method('isAvailable')->willReturn(true);
		$this->binding->expects($this->never())->method('findByMessageId');

		$noMessage = $this->like();
		$noMessage['object']['id'] = '';
		$this->listener->handle($this->invocation($noMessage));

		$noReactor = $this->like();
		$noReactor['actor']['id'] = 'users/';
		$this->listener->handle($this->invocation($noReactor));

	}//end testAReactionWithoutMessageOrReactorDecidesNothing()

	/**
	 * A failure while resolving the approval is logged once and swallowed.
	 *
	 * @return void
	 */
	public function testAFailureWhileDecidingIsLoggedAndNotThrown(): void {
		$this->bridge->method('isAvailable')->willReturn(true);
		$this->binding->expects($this->once())
			->method('findByMessageId')
			->with(messageId: 'msg-1')
			->willThrowException(new RuntimeException('store offline'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->identicalTo('[TalkApprovalReactionListener] Reaction handling failed (the reactor is unaffected)'),
				$this->callback(static fn (array $context): bool => ($context['error'] ?? null) === 'store offline')
			);

		$this->listener->handle($this->invocation($this->like()));

	}//end testAFailureWhileDecidingIsLoggedAndNotThrown()
}//end class
