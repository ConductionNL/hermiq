<?php

/**
 * Unit tests for TalkRoomBinding::claimFirstAnswerLink().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Only the agent's first answer in a room claims the link to the session.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkRoomBinding
 */
class TalkRoomBindingFirstAnswerTest extends TestCase {

	/**
	 * Every payload handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A binding over one stored session bound to `room-1`, or none.
	 *
	 * @param array<string, mixed>|null $session The stored session's data.
	 * @param bool                      $failing Whether saving throws.
	 *
	 * @return TalkRoomBinding The binding.
	 */
	private function binding(?array $session, bool $failing = false): TalkRoomBinding {
		$this->saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			static function (mixed ...$args) use ($session): array {
				if ($session === null || ($args[0]['filters']['talkRoomToken'] ?? null) !== 'room-1') {
					return [];
				}

				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject($session);
				return [$entity];
			}
		);
		// Variadic: the caller uses named arguments (see TalkRoomBindingSyncTest).
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($failing): ObjectEntity {
				if ($failing === true) {
					throw new RuntimeException('register down');
				}

				$this->saved[] = $args[0];
				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject($args[0]);
				return $entity;
			}
		);

		return new TalkRoomBinding($objectService, new NullLogger());
	}//end binding()

	/**
	 * The first claim marks the session and answers its uuid, carrying every field forward.
	 *
	 * @return void
	 */
	public function testTheFirstClaimMarksTheSession(): void {
		$binding = $this->binding(['title' => 'Inkoop overleg', 'talkRoomToken' => 'room-1']);

		$this->assertSame('sess-1', $binding->claimFirstAnswerLink(roomToken: 'room-1'));
		$this->assertSame([['id' => 'sess-1', 'title' => 'Inkoop overleg', 'talkRoomToken' => 'room-1', 'talkLinkPosted' => true]], $this->saved);
	}//end testTheFirstClaimMarksTheSession()

	/**
	 * A session whose link was posted claims nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testALaterClaimAnswersNull(): void {
		$binding = $this->binding(['talkRoomToken' => 'room-1', 'talkLinkPosted' => true]);

		$this->assertNull($binding->claimFirstAnswerLink(roomToken: 'room-1'));
		$this->assertSame([], $this->saved);
	}//end testALaterClaimAnswersNull()

	/**
	 * An unbound room, or a failing write, claims nothing.
	 *
	 * @return void
	 */
	public function testAnUnboundRoomOrAFailingWriteClaimsNothing(): void {
		$this->assertNull($this->binding(null)->claimFirstAnswerLink(roomToken: 'room-1'));
		$this->assertNull($this->binding(['talkRoomToken' => 'room-1'], failing: true)->claimFirstAnswerLink(roomToken: 'room-1'));
	}//end testAnUnboundRoomOrAFailingWriteClaimsNothing()
}//end class
