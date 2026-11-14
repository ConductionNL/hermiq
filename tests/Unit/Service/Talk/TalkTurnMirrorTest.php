<?php

/**
 * Unit tests for TalkTurnMirror.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\BackgroundJob\MirrorTurnToTalkJob;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkTurnMirror;
use OCP\BackgroundJob\IJobList;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A Chat-page turn reaches the bound room as two bot messages, out of the request.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnMirror
 */
class TalkTurnMirrorTest extends TestCase {

	private TalkBridge&MockObject $bridge;

	private IJobList&MockObject $jobs;

	private LoggerInterface&MockObject $logger;

	private TalkTurnMirror $mirror;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bridge = $this->createMock(TalkBridge::class);
		$this->jobs   = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$users        = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['anne', 'Anne de Vries'], ['bob', null]]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$this->mirror = new TalkTurnMirror(bridge: $this->bridge, jobs: $this->jobs, users: $users, urls: $urls, logger: $this->logger);
	}//end setUp()

	/**
	 * A queued turn.
	 *
	 * @param array<string, string> $overrides Fields to change.
	 *
	 * @return array<string, string>
	 */
	private static function turn(array $overrides = []): array {
		return array_merge(
			[
				'sessionId' => 'sess-1',
				'roomToken' => 'room1',
				'agentId' => 'agent-1',
				'userId' => 'anne',
				'question' => 'Welke offertes lopen nog?',
				'answer' => 'Twee: Brinkman en De Wit.',
			],
			$overrides
		);
	}//end turn()

	/**
	 * A session with a room queues one mirror with the turn.
	 *
	 * @return void
	 */
	public function testASessionWithARoomQueuesTheMirror(): void {
		$this->jobs->expects(self::once())->method('add')->with(MirrorTurnToTalkJob::class, self::turn());

		self::assertTrue(
			$this->mirror->enqueue(
				session: ['talkRoomToken' => 'room1', 'agentId' => 'agent-1'],
				sessionId: 'sess-1',
				userId: 'anne',
				question: ' Welke offertes lopen nog? ',
				answer: 'Twee: Brinkman en De Wit.'
			)
		);
	}//end testASessionWithARoomQueuesTheMirror()

	/**
	 * A session without a room, or an empty answer, queues nothing.
	 *
	 * @return void
	 */
	public function testNothingIsQueuedWithoutARoomOrAnAnswer(): void {
		$this->jobs->expects(self::never())->method('add');

		self::assertFalse($this->mirror->enqueue(session: ['agentId' => 'agent-1'], sessionId: 'sess-1', userId: 'anne', question: 'q', answer: 'a'));
		self::assertFalse($this->mirror->enqueue(session: ['talkRoomToken' => 'room1'], sessionId: 'sess-1', userId: 'anne', question: 'q', answer: '  '));
	}//end testNothingIsQueuedWithoutARoomOrAnAnswer()

	/**
	 * A failure to queue is logged and never thrown at the Chat page.
	 *
	 * @return void
	 */
	public function testAQueueFailureIsLoggedNotThrown(): void {
		$this->jobs->method('add')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('warning');

		self::assertFalse($this->mirror->enqueue(session: ['talkRoomToken' => 'room1'], sessionId: 'sess-1', userId: 'anne', question: 'q', answer: 'a'));
	}//end testAQueueFailureIsLoggedNotThrown()

	/**
	 * The room gets the question with who asked it, then the answer, as the agent's bot.
	 *
	 * @return void
	 */
	public function testTheRoomGetsTheQuestionAndTheAnswerAsTheAgentsBot(): void {
		$posted = [];
		$this->bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId = null) use (&$posted): bool {
				$posted[] = [$roomToken, $message, $agentId];
				return true;
			}
		);

		self::assertTrue($this->mirror->mirror(turn: self::turn()));
		self::assertSame(
			[
				['room1', 'Anne de Vries via Hermiq: Welke offertes lopen nog?', 'agent-1'],
				['room1', 'Twee: Brinkman en De Wit.', 'agent-1'],
			],
			$posted
		);
	}//end testTheRoomGetsTheQuestionAndTheAnswerAsTheAgentsBot()

	/**
	 * Without a display name the user id names the asker, and without an agent the shared bot posts.
	 *
	 * @return void
	 */
	public function testFallsBackToTheUserIdAndTheSharedBot(): void {
		$this->bridge->expects(self::exactly(2))->method('postToRoom')
			->with(self::anything(), self::anything(), null)
			->willReturnOnConsecutiveCalls(true, true);

		self::assertTrue($this->mirror->mirror(turn: self::turn(['userId' => 'bob', 'agentId' => ''])));
	}//end testFallsBackToTheUserIdAndTheSharedBot()

	/**
	 * Talk refusing the question means the answer is not posted, and it is logged.
	 *
	 * @return void
	 */
	public function testATalkRefusalIsLoggedAndStopsTheMirror(): void {
		$this->bridge->expects(self::once())->method('postToRoom')->willReturn(false);
		$this->logger->expects(self::once())->method('warning');

		self::assertFalse($this->mirror->mirror(turn: self::turn()));
	}//end testATalkRefusalIsLoggedAndStopsTheMirror()

	/**
	 * An answer over the message limit is cut and links to the session.
	 *
	 * @return void
	 */
	public function testALongAnswerIsCutWithALink(): void {
		$messages = [];
		$this->bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message) use (&$messages): bool {
				$messages[] = $message;
				return true;
			}
		);

		$this->mirror->mirror(turn: self::turn(['answer' => str_repeat('a', TalkTurnMirror::MAX_MESSAGE + 10)]));

		self::assertSame(str_repeat('a', TalkTurnMirror::MAX_MESSAGE) . "…\n\nhttps://cloud.example/apps/hermiq/chat?session=sess-1", $messages[1]);
	}//end testALongAnswerIsCutWithALink()

	/**
	 * An incomplete queued turn posts nothing.
	 *
	 * @return void
	 */
	public function testAnIncompleteTurnPostsNothing(): void {
		$this->bridge->expects(self::never())->method('postToRoom');

		self::assertFalse($this->mirror->mirror(turn: self::turn(['roomToken' => ''])));
	}//end testAnIncompleteTurnPostsNothing()
}//end class
