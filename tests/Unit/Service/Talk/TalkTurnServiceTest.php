<?php

/**
 * Golden of TalkTurnService::runTurn: the identity a Talk turn runs as and is
 * restored from, what the engine is asked, where the answer is posted, and how
 * every failure is reported into the room.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One Talk turn, end to end.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */
final class TalkTurnServiceTest extends TestCase {

	/**
	 * Everything that happened, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * A service whose engine answers with the given reply (or throws it).
	 *
	 * @param string|\Throwable $reply      The engine's answer, or what it throws.
	 * @param bool              $knownUser  Whether the speaker resolves.
	 * @param string            $displayName The speaker's display name.
	 * @param bool              $vanishes   Whether the speaker stops resolving after the first lookup.
	 * @param array<string, mixed> $bound   The bound conversation's object.
	 *
	 * @return TalkTurnService
	 */
	private function service(
		string|\Throwable $reply,
		bool $knownUser = true,
		string $displayName = 'Alice A.',
		bool $vanishes = false,
		array $bound = ['agentId' => 'agent-9'],
		?array $claims = null
	): TalkTurnService {
		$prior = $this->createMock(IUser::class);
		$prior->method('getUID')->willReturn('prior');
		$speaker = $this->createMock(IUser::class);
		$speaker->method('getUID')->willReturn('alice');
		$speaker->method('getDisplayName')->willReturn($displayName);

		$users = $this->createMock(IUserManager::class);
		$lookups = 0;
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($knownUser, $speaker, $vanishes, &$lookups): ?IUser {
				$lookups++;
				if ($vanishes === true && $lookups > 1) {
					return null;
				}

				return ($knownUser === true && $uid === 'alice' ? $speaker : null);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($prior);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->events[] = ['setUser', $user?->getUID()];
			}
		);

		$engine = $this->createMock(Engine::class);
		$engine->method('processMessage')->willReturnCallback(
			function (...$args) use ($reply): array {
				$this->events[] = ['engine', $args];
				if ($reply instanceof \Throwable) {
					throw $reply;
				}

				return ['message' => $reply];
			}
		);

		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId = null): bool {
				$this->events[] = ['post', $roomToken, $message, $agentId];
				return true;
			}
		);

		$conversation = new ObjectEntity();
		$conversation->setObject($bound);
		$binding = $this->createMock(TalkRoomBinding::class);
		$binding->method('findByRoomToken')->willReturnCallback(fn (string $token) => ($token === 'room-1' ? $conversation : null));
		$urls = null;
		if ($claims !== null) {
			// The first-answer claims in order: the session uuid, then null once claimed.
			$binding->method('claimFirstAnswerLink')->with('room-1')->willReturnOnConsecutiveCalls(...$claims);
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context): void {
				$this->events[] = ['log', $message, $context['error'] ?? null];
			}
		);

		return new TalkTurnService($engine, $bridge, $binding, $users, $session, $logger, null, $urls);
	}//end service()

	/**
	 * The turn runs as the speaker, is answered under the room's agent, and the
	 * prior identity is restored.
	 *
	 * @return void
	 */
	public function testTheTurnRunsAsTheSpeakerAndIsAnsweredInTheRoom(): void {
		$this->assertTrue($this->service('The answer.')->runTurn('conv-1', 'alice', 'Hallo', 'room-1'));

		$this->assertSame(['setUser', 'alice'], $this->events[0]);
		$this->assertSame('engine', $this->events[1][0]);
		$args = $this->events[1][1];
		$this->assertSame(['conv-1', 'alice', 'Hallo'], array_slice($args, 0, 3));
		$this->assertSame(['alice', 'Alice A.'], array_slice($args, 11, 2));
		$this->assertSame('talk', $args[14] ?? null, 'A turn from a Talk room is recorded as typed in Talk.');
		$this->assertSame(['post', 'room-1', 'The answer.', 'agent-9'], $this->events[2]);
		$this->assertSame(['setUser', 'prior'], $this->events[3]);
		$this->assertCount(4, $this->events);
	}//end testTheTurnRunsAsTheSpeakerAndIsAnsweredInTheRoom()

	/**
	 * An unbound room posts under the shared bot.
	 *
	 * @return void
	 */
	public function testAnUnboundRoomPostsUnderTheSharedBot(): void {
		$this->service('Answer')->runTurn('conv-1', 'alice', 'Hallo', 'room-2');

		$this->assertSame(['post', 'room-2', 'Answer', null], $this->events[2]);
	}//end testAnUnboundRoomPostsUnderTheSharedBot()

	/**
	 * An unknown speaker is refused before any impersonation.
	 *
	 * @return void
	 */
	public function testAnUnknownSpeakerIsRefusedWithoutImpersonation(): void {
		$this->assertFalse($this->service('x', knownUser: false)->runTurn('conv-1', 'bob', 'Hallo', 'room-1'));

		$this->assertSame(
			[['post', 'room-1', '⚠️ I could not complete that turn: the sender could not be resolved to a Nextcloud user', null]],
			$this->events
		);
	}//end testAnUnknownSpeakerIsRefusedWithoutImpersonation()

	/**
	 * An empty answer is reported, and the identity restored.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsReported(): void {
		$this->assertFalse($this->service("  \n ")->runTurn('conv-1', 'alice', 'Hallo', 'room-1'));

		$this->assertSame(['post', 'room-1', '⚠️ I could not complete that turn: the agent returned an empty answer', null], $this->events[2]);
		$this->assertSame(['setUser', 'prior'], $this->events[3]);
	}//end testAnEmptyAnswerIsReported()

	/**
	 * A failing turn is logged, reported with its reason, and the identity restored.
	 *
	 * @return void
	 */
	public function testAFailingTurnIsLoggedReportedAndRestored(): void {
		$this->assertFalse($this->service(new RuntimeException('model down'))->runTurn('conv-1', 'alice', 'Hallo', 'room-1'));

		$this->assertSame(['log', '[TalkTurnService] Talk-originated agent turn failed', 'model down'], $this->events[2]);
		$this->assertSame(['post', 'room-1', '⚠️ I could not complete that turn: model down', null], $this->events[3]);
		$this->assertSame(['setUser', 'prior'], $this->events[4]);
	}//end testAFailingTurnIsLoggedReportedAndRestored()
	/**
	 * An empty display name is recorded as no display name, not as ''.
	 *
	 * @return void
	 */
	public function testAnEmptyDisplayNameIsRecordedAsNone(): void {
		$this->assertTrue($this->service('Answer', displayName: '')->runTurn('conv-1', 'alice', 'Hallo', 'room-1'));

		$this->assertSame(['alice', null], array_slice($this->events[1][1], 11, 2));
	}//end testAnEmptyDisplayNameIsRecordedAsNone()

	/**
	 * A speaker who no longer resolves when the display name is read still
	 * gets the turn, with no display name.
	 *
	 * @return void
	 */
	public function testASpeakerGoneAtNameLookupHasNoDisplayName(): void {
		$this->assertTrue($this->service('Answer', vanishes: true)->runTurn('conv-1', 'alice', 'Hallo', 'room-1'));

		$this->assertSame(['alice', null], array_slice($this->events[1][1], 11, 2));
		$this->assertSame(['post', 'room-1', 'Answer', 'agent-9'], $this->events[2]);
	}//end testASpeakerGoneAtNameLookupHasNoDisplayName()

	/**
	 * A bound session without an agent, or with a blank one, posts under the
	 * shared bot.
	 *
	 * @return void
	 */
	public function testABoundSessionWithoutAnAgentPostsUnderTheSharedBot(): void {
		$this->service('Answer', bound: ['agentId' => ''])->runTurn('conv-1', 'alice', 'Hallo', 'room-1');
		$this->assertSame(['post', 'room-1', 'Answer', null], $this->events[2]);

		$this->events = [];
		$this->service('Answer', bound: ['title' => 'x'])->runTurn('conv-1', 'alice', 'Hallo', 'room-1');
		$this->assertSame(['post', 'room-1', 'Answer', null], $this->events[2]);
	}//end testABoundSessionWithoutAnAgentPostsUnderTheSharedBot()

	/**
	 * The agent's first answer in a room ends with the link to the session; the
	 * second does not (chat-carry-a-conversation-across-channels REQ-SCHAN-003).
	 *
	 * @return void
	 */
	public function testOnlyTheFirstAnswerInTheRoomCarriesTheSessionLink(): void {
		$service = $this->service(reply: 'The answer.', claims: ['sess-1', null]);

		$service->runTurn('conv-1', 'alice', 'Hallo', 'room-1');
		$service->runTurn('conv-1', 'alice', 'Nog iets', 'room-1');

		$posts = array_values(array_filter($this->events, static fn (array $event): bool => $event[0] === 'post'));
		$this->assertSame("The answer.\n\nContinue in Hermiq: https://cloud.example/apps/hermiq/chat?session=sess-1", $posts[0][2]);
		$this->assertSame('The answer.', $posts[1][2]);
	}//end testOnlyTheFirstAnswerInTheRoomCarriesTheSessionLink()

	/**
	 * The formatter ends the answer with the link on its own paragraph.
	 *
	 * @return void
	 */
	public function testTheContinueLineEndsTheAnswer(): void {
		$this->assertSame(
			"Twee offertes.\n\nContinue in Hermiq: https://x/apps/hermiq/chat?session=s",
			TalkTurnService::continueInHermiq(answer: "Twee offertes.\n", url: 'https://x/apps/hermiq/chat?session=s')
		);
	}//end testTheContinueLineEndsTheAnswer()
}//end class
