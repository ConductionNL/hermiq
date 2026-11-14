<?php

/**
 * Unit tests for MirrorTurnToTalkJob.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\MirrorTurnToTalkJob;
use OCA\Hermiq\Kernel\Contracts\TalkTurnMirrorInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The job hands the queued turn to the mirror, field by field.
 *
 * @covers \OCA\Hermiq\BackgroundJob\MirrorTurnToTalkJob
 */
class MirrorTurnToTalkJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param TalkTurnMirrorInterface $mirror   The mirror.
	 * @param mixed                   $argument The queued argument.
	 *
	 * @return void
	 */
	private function runJob(TalkTurnMirrorInterface $mirror, mixed $argument): void {
		$job = new MirrorTurnToTalkJob(time: $this->createMock(ITimeFactory::class), mirror: $mirror);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The queued turn reaches the mirror with every field as a string.
	 *
	 * @return void
	 */
	public function testTheQueuedTurnReachesTheMirror(): void {
		$mirror = $this->createMock(TalkTurnMirrorInterface::class);
		$mirror->expects(self::once())->method('mirror')->with(
			[
				'sessionId' => 'sess-1',
				'roomToken' => 'room1',
				'agentId' => '',
				'userId' => 'anne',
				'question' => 'q',
				'answer' => 'a',
			]
		)->willReturn(true);

		$this->runJob(mirror: $mirror, argument: ['sessionId' => 'sess-1', 'roomToken' => 'room1', 'userId' => 'anne', 'question' => 'q', 'answer' => 'a', 'extra' => 'x']);
	}//end testTheQueuedTurnReachesTheMirror()

	/**
	 * A non-array argument does nothing.
	 *
	 * @return void
	 */
	public function testANonArrayArgumentDoesNothing(): void {
		$mirror = $this->createMock(TalkTurnMirrorInterface::class);
		$mirror->expects(self::never())->method('mirror');

		$this->runJob(mirror: $mirror, argument: 'sess-1');
	}//end testANonArrayArgumentDoesNothing()
}//end class
