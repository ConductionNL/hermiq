<?php

/**
 * Hermiq TalkProgressiveReply tests.
 *
 * @category Tests
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
 *
 * @spec openspec/changes/instant-talk/tasks.md#3-progressive-replies
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkProgressiveReply;
use OCA\Hermiq\Service\Talk\TalkReplyTarget;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Post early, edit throttled, finish exact; never lose the answer.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkProgressiveReply
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
 */
final class TalkProgressiveReplyTest extends TestCase {

	/**
	 * Every room write, in order: [kind, text].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $writes = [];

	/**
	 * The fake clock's current time.
	 *
	 * @var float
	 */
	private float $now = 100.0;

	/**
	 * A bridge that records posts and edits.
	 *
	 * @param bool $editsLand Whether edits succeed.
	 *
	 * @return TalkBridge&MockObject
	 */
	private function bridge(bool $editsLand = true): TalkBridge {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('postToRoomReturningId')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId): string {
				$this->assertSame(['room-1', 'agent-a'], [$roomToken, $agentId]);
				$this->writes[] = ['post', $message];
				return '77';
			}
		);
		$bridge->method('editMessage')->willReturnCallback(
			function (string $roomToken, string $messageId, string $newText, ?string $agentId) use ($editsLand): bool {
				$this->assertSame(['room-1', '77', 'agent-a'], [$roomToken, $messageId, $agentId]);
				$this->writes[] = ['edit', $newText];
				return $editsLand;
			}
		);
		$bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId): bool {
				$this->writes[] = ['message', $message];
				return true;
			}
		);

		return $bridge;
	}//end bridge()

	/**
	 * A reply over the bridge with a two-second throttle and the fake clock.
	 *
	 * @param TalkBridge $bridge  The bridge.
	 * @param bool       $canEdit Whether Talk can edit.
	 *
	 * @return TalkProgressiveReply
	 */
	private function reply(TalkBridge $bridge, bool $canEdit = true): TalkProgressiveReply {
		return new TalkProgressiveReply(
			bridge: $bridge,
			target: new TalkReplyTarget(roomToken: 'room-1', agentId: 'agent-a', canEdit: $canEdit, throttleSeconds: 2.0),
			logger: $this->createMock(LoggerInterface::class),
			clock: fn (): float => $this->now
		);
	}//end reply()

	/**
	 * The first content posts, later content edits no more than once per
	 * throttle interval, and the final edit is the exact answer.
	 *
	 * @return void
	 */
	public function testALongAnswerArrivesProgressivelyAndEndsExact(): void {
		$reply = $this->reply(bridge: $this->bridge());

		$reply->append(delta: 'The ');
		$reply->append(delta: 'tender ');
		$this->now += 1.0;
		$reply->append(delta: 'closes ');
		$this->now += 1.5;
		$reply->append(delta: 'on Friday');

		$this->assertTrue($reply->started());
		$this->assertTrue($reply->finish(answer: 'The tender closes on Friday.'));

		$this->assertSame(
			[
				['post', 'The ' . TalkProgressiveReply::PENDING_MARK],
				['edit', 'The tender closes on Friday' . TalkProgressiveReply::PENDING_MARK],
				['edit', 'The tender closes on Friday.'],
			],
			$this->writes
		);
	}//end testALongAnswerArrivesProgressivelyAndEndsExact()

	/**
	 * Without edit support nothing is posted early; the answer is one message.
	 *
	 * @return void
	 */
	public function testWithoutEditSupportTheAnswerIsOneMessage(): void {
		$reply = $this->reply(bridge: $this->bridge(), canEdit: false);
		$reply->append(delta: 'Partial');
		$this->now += 10;
		$reply->append(delta: ' more');

		$this->assertFalse($reply->started());
		$this->assertTrue($reply->finish(answer: 'Partial more.'));
		$this->assertSame([['message', 'Partial more.']], $this->writes);
	}//end testWithoutEditSupportTheAnswerIsOneMessage()

	/**
	 * Once an edit fails, editing stops and the complete answer is posted.
	 *
	 * @return void
	 */
	public function testAnEditFailureDoesNotEatTheAnswer(): void {
		$reply = $this->reply(bridge: $this->bridge(editsLand: false));
		$reply->append(delta: 'One');
		$this->now += 3;
		$reply->append(delta: ' two');
		$this->now += 3;
		$reply->append(delta: ' three');

		$this->assertTrue($reply->finish(answer: 'One two three.'));
		$this->assertSame(
			[
				['post', 'One' . TalkProgressiveReply::PENDING_MARK],
				['edit', 'One two' . TalkProgressiveReply::PENDING_MARK],
				['message', 'One two three.'],
			],
			$this->writes,
			'After the failed edit no further edit is tried, and the whole answer is posted.'
		);
	}//end testAnEditFailureDoesNotEatTheAnswer()

	/**
	 * Whitespace-only content posts nothing early.
	 *
	 * @return void
	 */
	public function testBlankContentDoesNotPostEarly(): void {
		$reply = $this->reply(bridge: $this->bridge());
		$reply->append(delta: "  \n");

		$this->assertFalse($reply->started());
		$this->assertSame([], $this->writes);
	}//end testBlankContentDoesNotPostEarly()
}//end class
