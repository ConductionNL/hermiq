<?php

/**
 * Unit tests for TalkApprovalReactionListener.
 *
 * The decisive test here is the NEGATIVE one. A reaction is a public,
 * one-click act available to every participant of a room, and the approval gate
 * exists to require a specific person's judgement (EU AI Act Art. 14). If a
 * bystander's 👍 could release a gated run, this feature would make Hermiq's
 * oversight mechanism weaker than not having it — so "a non-reviewer's reaction
 * does nothing" is the property that has to hold.
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
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-only-the-reviewer-may-decide-by-reaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TalkApprovalReactionListener;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Talk\TalkApprovalBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the approval-by-reaction listener.
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-a-reviewers-reaction-decides-the-approval
 */
class TalkApprovalReactionListenerTest extends TestCase {

	/**
	 * Talk availability and room posting.
	 *
	 * @var TalkBridge&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $bridge;

	/**
	 * Approval↔message resolution.
	 *
	 * @var TalkApprovalBinding&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $binding;

	/**
	 * The decision path.
	 *
	 * @var ApprovalService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $approvals;

	/**
	 * Listener under test.
	 *
	 * @var TalkApprovalReactionListener
	 */
	private TalkApprovalReactionListener $listener;

	/**
	 * Wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->bridge = $this->createMock(originalClassName: TalkBridge::class);
		$this->binding = $this->createMock(originalClassName: TalkApprovalBinding::class);
		$this->approvals = $this->createMock(originalClassName: ApprovalService::class);

		$this->bridge->method('isAvailable')->willReturn(true);

		$this->listener = new TalkApprovalReactionListener(
			bridge: $this->bridge,
			approvalBinding: $this->binding,
			approvalService: $this->approvals,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Build a fake spreed reaction invocation.
	 *
	 * Spreed is optional and absent from the unit environment, so the event is
	 * modelled as an anonymous class carrying the surface the listener uses.
	 *
	 * @param string $emoji The reaction emoji.
	 * @param string $actorId The reacting user's actor id.
	 * @param string $type `Like` (added) or `Undo` (removed).
	 * @param string $messageId The reacted-to message id.
	 * @param string $botUrl The invoking bot's URL.
	 *
	 * @return Event The fake event.
	 */
	private function makeReaction(
		string $emoji,
		string $actorId = 'alice',
		string $type = 'Like',
		string $messageId = 'msg-1',
		string $botUrl = TalkBridge::BOT_URL,
	): Event {
		return new class($emoji, $actorId, $type, $messageId, $botUrl) extends Event {
			/**
			 * Constructor.
			 *
			 * @param string $emoji Reaction emoji.
			 * @param string $actorId Actor id.
			 * @param string $type Invocation type.
			 * @param string $messageId Reacted-to message id.
			 * @param string $botUrl Bot URL.
			 */
			public function __construct(
				private readonly string $emoji,
				private readonly string $actorId,
				private readonly string $type,
				private readonly string $messageId,
				private readonly string $botUrl,
			) {
			}//end __construct()

			/**
			 * The invoking bot's URL.
			 *
			 * @return string The bot URL.
			 */
			public function getBotUrl(): string {
				return $this->botUrl;
			}//end getBotUrl()

			/**
			 * The reaction invocation payload, in the shape spreed really sends.
			 *
			 * 🔴 This fixture used to be a hybrid that spreed emits for NEITHER
			 * type: top-level `content` (the `Like` shape) combined with a
			 * nested `object.object.id` (the `Undo` shape). The listener read
			 * one field from each, so the two bugs cancelled out here and the
			 * suite stayed green while every real 👍/👎 in Talk was a silent
			 * no-op. Mirror `BotService::afterReactionAdded()` /
			 * `afterReactionRemoved()` exactly — a payload the server never
			 * sends can only certify a path that can never run.
			 *
			 * @return array The payload.
			 */
			public function getMessage(): array {
				$note = ['type' => 'Note', 'id' => $this->messageId, 'name' => 'message'];
				$reactor = ['type' => 'Person', 'id' => $this->actorId, 'name' => 'A'];
				$room = ['type' => 'Collection', 'id' => 'room1'];

				// `Undo` wraps the undone Like — note AND emoji move one level
				// deeper, and the nested actor is the message's author.
				if ($this->type === 'Undo') {
					return [
						'type' => 'Undo',
						'actor' => $reactor,
						'object' => [
							'type' => 'Like',
							'actor' => ['type' => 'Person', 'id' => 'users/author', 'name' => 'Author'],
							'object' => $note,
							'target' => $room,
							'content' => $this->emoji,
						],
						'target' => $room,
					];
				}

				return [
					'type' => $this->type,
					'actor' => $reactor,
					'object' => $note,
					'target' => $room,
					'content' => $this->emoji,
				];

			}//end getMessage()
		};

	}//end makeReaction()

	/**
	 * A pending approval entity.
	 *
	 * @param string $status The approval status.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function makeApproval(string $status = 'pending'): ObjectEntity {
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(
			[
				'status' => $status,
				'reviewer' => 'alice',
				'reviewerType' => 'user',
				'talkRoomToken' => 'room1',
			]
		);

		return $approval;
	}//end makeApproval()

	/**
	 * 🔴 A non-reviewer's reaction decides nothing.
	 *
	 * The property this whole feature rests on.
	 *
	 * @return void
	 */
	public function testNonReviewerReactionIsIgnored(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval());
		$this->approvals->method('isReviewer')->willReturn(false);

		$this->approvals->expects($this->never())->method('approve');
		$this->approvals->expects($this->never())->method('deny');
		$this->binding->expects($this->never())->method('recordDecidedVia');

		$this->listener->handle($this->makeReaction(emoji: '👍', actorId: 'mallory'));

	}//end testNonReviewerReactionIsIgnored()

	/**
	 * The reviewer's thumbs-up approves and records provenance.
	 *
	 * @return void
	 */
	public function testReviewerThumbsUpApproves(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval());
		$this->approvals->method('isReviewer')->willReturn(true);

		$this->approvals->expects($this->once())
			->method('approve')
			->willReturn(['status' => 'approved', 'ran' => true]);
		$this->binding->expects($this->once())
			->method('recordDecidedVia')
			->with(approvalUuid: 'appr-1', via: 'reaction');

		$this->listener->handle($this->makeReaction(emoji: '👍'));

	}//end testReviewerThumbsUpApproves()

	/**
	 * The reviewer's thumbs-down denies.
	 *
	 * @return void
	 */
	public function testReviewerThumbsDownDenies(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval());
		$this->approvals->method('isReviewer')->willReturn(true);

		$this->approvals->expects($this->once())->method('deny');
		$this->approvals->expects($this->never())->method('approve');

		$this->listener->handle($this->makeReaction(emoji: '👎'));

	}//end testReviewerThumbsDownDenies()

	/**
	 * Any other emoji is ignored rather than guessed at.
	 *
	 * @return void
	 */
	public function testOtherEmojiIsIgnored(): void {
		$this->binding->expects($this->never())->method('findByMessageId');
		$this->approvals->expects($this->never())->method('approve');

		$this->listener->handle($this->makeReaction(emoji: '🎉'));

	}//end testOtherEmojiIsIgnored()

	/**
	 * Removing a reaction does not reverse a decision, and says so.
	 *
	 * @return void
	 */
	public function testUndoDoesNotReverseAndIsAnswered(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval(status: 'approved'));
		$this->approvals->method('isReviewer')->willReturn(true);

		$this->approvals->expects($this->never())->method('approve');
		$this->approvals->expects($this->never())->method('deny');
		// Silence would read as "it worked" — the room must be told.
		$this->bridge->expects($this->once())->method('postToRoom');

		$this->listener->handle($this->makeReaction(emoji: '👍', type: 'Undo'));

	}//end testUndoDoesNotReverseAndIsAnswered()

	/**
	 * An already-decided approval is a visible no-op.
	 *
	 * @return void
	 */
	public function testAlreadyDecidedIsVisibleNoOp(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval(status: 'denied'));
		$this->approvals->method('isReviewer')->willReturn(true);

		$this->approvals->expects($this->never())->method('approve');
		$this->bridge->expects($this->once())->method('postToRoom');

		$this->listener->handle($this->makeReaction(emoji: '👍'));

	}//end testAlreadyDecidedIsVisibleNoOp()

	/**
	 * A message carrying no approval is ignored.
	 *
	 * @return void
	 */
	public function testUnboundMessageIsIgnored(): void {
		$this->binding->method('findByMessageId')->willReturn(null);

		$this->approvals->expects($this->never())->method('approve');

		$this->listener->handle($this->makeReaction(emoji: '👍'));

	}//end testUnboundMessageIsIgnored()

	/**
	 * Another app's bot invocation is ignored.
	 *
	 * @return void
	 */
	public function testForeignBotIsIgnored(): void {
		$this->binding->expects($this->never())->method('findByMessageId');

		$this->listener->handle($this->makeReaction(emoji: '👍', botUrl: 'nextcloudapp://other'));

	}//end testForeignBotIsIgnored()

	/**
	 * A chat message invocation belongs to the other listener, not this one.
	 *
	 * @return void
	 */
	public function testChatMessageInvocationIsIgnored(): void {
		$this->binding->expects($this->never())->method('findByMessageId');

		$this->listener->handle($this->makeReaction(emoji: '👍', type: 'Create'));

	}//end testChatMessageInvocationIsIgnored()

	/**
	 * A `users/`-prefixed actor id is normalised before the reviewer check.
	 *
	 * @return void
	 */
	public function testActorPrefixIsStrippedBeforeAuthorization(): void {
		$this->binding->method('findByMessageId')->willReturn($this->makeApproval());
		$this->approvals->expects($this->once())
			->method('isReviewer')
			->with($this->anything(), 'alice')
			->willReturn(true);
		$this->approvals->method('approve')->willReturn(['status' => 'approved', 'ran' => true]);

		$this->listener->handle($this->makeReaction(emoji: '👍', actorId: 'users/alice'));

	}//end testActorPrefixIsStrippedBeforeAuthorization()
}//end class
