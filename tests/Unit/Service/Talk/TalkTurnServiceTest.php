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
use OCA\Hermiq\Service\Talk\TalkAgentChain;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkTurn;
use OCA\Hermiq\Service\Talk\TalkTurnCollaborators;
use OCA\Hermiq\Service\Talk\TalkTurnOptions;
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
	 * @param TalkAgentChain|null  $chain   Follows the answer to the agents it mentions.
	 *
	 * @return TalkTurnService
	 */
	private function service(
		string|\Throwable $reply,
		bool $knownUser = true,
		string $displayName = 'Alice A.',
		bool $vanishes = false,
		array $bound = ['agentId' => 'agent-9'],
		?array $claims = null,
		?TalkAgentChain $chain = null
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

		return new TalkTurnService(
			engine: $engine,
			talk: new TalkTurnCollaborators(bridge: $bridge, roomBinding: $binding, userManager: $users, userSession: $session),
			logger: $logger,
			options: new TalkTurnOptions(agentChain: $chain, urls: $urls)
		);
	}//end service()

	/**
	 * A posted answer is followed to the agents it mentions, with the turn that
	 * produced it; an empty answer is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function testAPostedAnswerIsFollowedToTheAgentsItMentions(): void {
		$chain = $this->createMock(TalkAgentChain::class);
		$chain->expects($this->once())->method('follow')
			->with(
				$this->callback(static fn (TalkTurn $turn): bool => $turn->speakerUid === 'alice' && $turn->agentId === 'agent-7' && $turn->chain === ['hop' => 1]),
				'@Bram over to you.',
				''
			)
			->willReturn(1);
		$turn = self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1', extra: ['agentId' => 'agent-7', 'chain' => ['hop' => 1]]);
		$this->assertTrue(condition: $this->service(reply: '@Bram over to you.', chain: $chain)->runTurn($turn));

		$silent = $this->createMock(TalkAgentChain::class);
		$silent->expects($this->never())->method('follow');
		$this->assertFalse(condition: $this->service(reply: '', chain: $silent)->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));
	}//end testAPostedAnswerIsFollowedToTheAgentsItMentions()

	/**
	 * The turn runs as the speaker, is answered under the room's agent, and the
	 * prior identity is restored.
	 *
	 * @return void
	 */
	public function testTheTurnRunsAsTheSpeakerAndIsAnsweredInTheRoom(): void {
		$this->assertTrue(condition: $this->service(reply: 'The answer.')->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));

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
		$this->service(reply: 'Answer')->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-2'));

		$this->assertSame(['post', 'room-2', 'Answer', null], $this->events[2]);
	}//end testAnUnboundRoomPostsUnderTheSharedBot()

	/**
	 * An unknown speaker is refused before any impersonation.
	 *
	 * @return void
	 */
	public function testAnUnknownSpeakerIsRefusedWithoutImpersonation(): void {
		$this->assertFalse(condition: $this->service(reply: 'x', knownUser: false)->runTurn(self::turn(speaker: 'bob', message: 'Hallo', room: 'room-1')));

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
		$this->assertFalse(condition: $this->service(reply: "  \n ")->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));

		$this->assertSame(['post', 'room-1', '⚠️ I could not complete that turn: the agent returned an empty answer', null], $this->events[2]);
		$this->assertSame(['setUser', 'prior'], $this->events[3]);
	}//end testAnEmptyAnswerIsReported()

	/**
	 * A failing turn is logged, reported with its reason, and the identity restored.
	 *
	 * @return void
	 */
	public function testAFailingTurnIsLoggedReportedAndRestored(): void {
		$this->assertFalse(condition: $this->service(reply: new RuntimeException('model down'))->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));

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
		$this->assertTrue(condition: $this->service(reply: 'Answer', displayName: '')->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));

		$this->assertSame(['alice', null], array_slice($this->events[1][1], 11, 2));
	}//end testAnEmptyDisplayNameIsRecordedAsNone()

	/**
	 * A speaker who no longer resolves when the display name is read still
	 * gets the turn, with no display name.
	 *
	 * @return void
	 */
	public function testASpeakerGoneAtNameLookupHasNoDisplayName(): void {
		$this->assertTrue(condition: $this->service(reply: 'Answer', vanishes: true)->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1')));

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
		$this->service(reply: 'Answer', bound: ['agentId' => ''])->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1'));
		$this->assertSame(['post', 'room-1', 'Answer', null], $this->events[2]);

		$this->events = [];
		$this->service(reply: 'Answer', bound: ['title' => 'x'])->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1'));
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

		$service->runTurn(self::turn(speaker: 'alice', message: 'Hallo', room: 'room-1'));
		$service->runTurn(self::turn(speaker: 'alice', message: 'Nog iets', room: 'room-1'));

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

	/**
	 * A turn on conv-1, as the dispatcher hands it to the service.
	 *
	 * @param string               $speaker The speaker.
	 * @param string               $message The message.
	 * @param string               $room    The room.
	 * @param array<string, mixed> $extra   `agentId`, `messageId` and `chain`, when set.
	 *
	 * @return TalkTurn
	 */
	private static function turn(string $speaker, string $message, string $room, array $extra = []): TalkTurn {
		$turn = new TalkTurn(conversationUuid: 'conv-1', speakerUid: $speaker);
		$turn->message = $message;
		$turn->roomToken = $room;
		$turn->agentId = (string)($extra['agentId'] ?? '');
		$turn->messageId = (string)($extra['messageId'] ?? '');
		$turn->chain = (array)($extra['chain'] ?? []);

		return $turn;
	}//end turn()
}//end class
