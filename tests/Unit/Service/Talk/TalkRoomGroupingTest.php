<?php

/**
 * TalkRoomGrouping: filing a bound agent room under each participant's Hermiq tag.
 *
 * Spreed's conversation tag service is absent here, so its class is declared
 * below as an empty stand-in (only when nothing else declared it): the grouping
 * checks that it exists and resolves it through the container, which is a double.
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
 *
 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
 */

declare(strict_types=1);

namespace OCA\Talk\Service {

	if (class_exists(ConversationTagService::class) === false) {
		/**
		 * Stand-in so the grouping sees tag support.
		 */
		class ConversationTagService {
		}
	}
}

namespace OCA\Hermiq\Tests\Unit\Service\Talk {

	use OCA\Hermiq\Service\Talk\TalkBridge;
	use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
	use OCP\IConfig;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * Eligibility, read-modify-write tagging, tag creation and the best-effort failures.
	 */
	final class TalkRoomGroupingTest extends TestCase {

		/** @var array<string, string|null> Tag-id json per participant. */
		public array $tagIds = [];

		/** @var array<string, array<int, object>> Existing tags per user. */
		public array $tags = [];

		/** @var array<int, array{0: string, 1: array<int, string>}> */
		public array $assigned = [];

		/** @var array<int, array{0: string, 1: string}> */
		public array $created = [];

		/** @var array<int, mixed> */
		public array $participants = ['alice', 'bob'];

		/** @var array<string, string> */
		public array $preferences = [];

		/** @var array<int, array{0: string, 1: array<string, mixed>}> */
		public array $logged = [];

		public ?string $failOn = null;

		public bool $createConflicts = false;

		public bool $listFails = false;

		/**
		 * A tag double.
		 *
		 * @param string $id   The id.
		 * @param string $name The name.
		 *
		 * @return object
		 */
		public static function tag(string $id, string $name): object {
			return new class ($id, $name) {
				/**
				 * Keep the fields.
				 *
				 * @param string $id   The id.
				 * @param string $name The name.
				 */
				public function __construct(private string $id, private string $name) {
				}

				/**
				 * The id.
				 *
				 * @return string
				 */
				public function getId(): string {
					return $this->id;
				}

				/**
				 * The name.
				 *
				 * @return string
				 */
				public function getName(): string {
					return $this->name;
				}
			};
		}//end tag()

		/**
		 * The grouping over recording doubles.
		 *
		 * @param bool $available Whether Talk is available.
		 *
		 * @return TalkRoomGrouping
		 */
		private function grouping(bool $available = true): TalkRoomGrouping {
			$bridge = $this->createMock(TalkBridge::class);
			$bridge->method('isAvailable')->willReturn($available);
			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturnCallback(fn (string $uid): string => ($this->preferences[$uid] ?? 'yes'));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('debug')->willReturnCallback(
				function (string|\Stringable $message, array $context = []): void {
					$this->logged[] = [(string)$message, $context];
				}
			);
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(fn (string $id): object => $this->service(id: $id));

			return new TalkRoomGrouping(bridge: $bridge, container: $container, config: $config, logger: $logger);
		}//end grouping()

		/**
		 * The spreed service double for a container id.
		 *
		 * @param string $id The container id.
		 *
		 * @return object
		 */
		private function service(string $id): object {
			if ($this->failOn === $id) {
				throw new RuntimeException('no ' . $id);
			}

			$test = $this;
			if (str_ends_with($id, 'Manager') === true) {
				return new class {
					/**
					 * The room for a token.
					 *
					 * @param string $token The token.
					 *
					 * @return object
					 */
					public function getRoomByToken(string $token): object {
						return (object)['token' => $token];
					}
				};
			}

			if (str_ends_with($id, 'ParticipantService') === true) {
				return new class ($test) {
					/**
					 * Keep the test.
					 *
					 * @param TalkRoomGroupingTest $test The test.
					 */
					public function __construct(private TalkRoomGroupingTest $test) {
					}

					/**
					 * The participants' user ids.
					 *
					 * @param object $room The room.
					 *
					 * @return array<int, mixed>
					 */
					public function getParticipantUserIds(object $room): array {
						return $this->test->participants;
					}

					/**
					 * One participant.
					 *
					 * @param object $room The room.
					 * @param string $uid  The user.
					 *
					 * @return object
					 */
					public function getParticipant(object $room, string $uid): object {
						$raw = ($this->test->tagIds[$uid] ?? null);
						return new class ($uid, $raw) {
							/**
							 * Keep the fields.
							 *
							 * @param string      $uid The user.
							 * @param string|null $raw The stored tag ids.
							 */
							public function __construct(public string $uid, private ?string $raw) {
							}

							/**
							 * The attendee row.
							 *
							 * @return object
							 */
							public function getAttendee(): object {
								$raw = $this->raw;
								return new class ($raw) {
									/**
									 * Keep the raw ids.
									 *
									 * @param string|null $raw The stored tag ids.
									 */
									public function __construct(private ?string $raw) {
									}

									/**
									 * The stored tag ids.
									 *
									 * @return string|null
									 */
									public function getTagIds(): ?string {
										return $this->raw;
									}
								};
							}
						};
					}

					/**
					 * Replace a participant's tags on the room.
					 *
					 * @param object             $participant The participant.
					 * @param array<int, string> $ids         The tag ids.
					 *
					 * @return void
					 */
					public function assignConversationToTags(object $participant, array $ids): void {
						$this->test->assigned[] = [$participant->uid, $ids];
					}
				};
			}

			return new class ($test) {
				/**
				 * Keep the test.
				 *
				 * @param TalkRoomGroupingTest $test The test.
				 */
				public function __construct(private TalkRoomGroupingTest $test) {
				}

				/**
				 * A user's tags.
				 *
				 * @param string $uid The user.
				 *
				 * @return array<int, object>
				 */
				public function getTags(string $uid): array {
					if ($this->test->listFails === true) {
						throw new RuntimeException('cannot list ' . $uid);
					}

					return ($this->test->tags[$uid] ?? []);
				}

				/**
				 * Create a tag.
				 *
				 * @param string $uid  The user.
				 * @param string $name The name.
				 *
				 * @return object
				 */
				public function createTag(string $uid, string $name): object {
					$this->test->created[] = [$uid, $name];
					if ($this->test->createConflicts === true) {
						$this->test->tags[$uid] = [TalkRoomGroupingTest::tag(id: 'raced-' . $uid, name: $name)];
						throw new RuntimeException('duplicate');
					}

					return TalkRoomGroupingTest::tag(id: 'new-' . $uid, name: $name);
				}
			};
		}//end service()

		/**
		 * Each enabled participant gets the Hermiq tag added to the tags they
		 * already had, created on first use; a tag already there is left alone.
		 *
		 * @return void
		 */
		public function testEveryEnabledParticipantGetsTheTagAddedToTheirOwn(): void {
			$this->participants = ['alice', 'bob', 'carol', '', 7, 'dave'];
			$this->preferences = ['carol' => 'no'];
			$this->tags = ['alice' => [self::tag(id: 'other', name: 'Work'), self::tag(id: 't-alice', name: 'Hermiq')]];
			$this->tagIds = ['alice' => '["other"]', 'bob' => 'x, y,x', 'dave' => '["t-dave"]'];
			$this->tags['dave'] = [self::tag(id: 't-dave', name: 'Hermiq')];

			self::assertSame(3, $this->grouping()->groupRoom(roomToken: 'room-1'));

			self::assertSame(
				[['alice', ['other', 't-alice']], ['bob', ['x', 'y', 'new-bob']]],
				$this->assigned
			);
			self::assertSame([['bob', 'Hermiq']], $this->created);
			self::assertSame([], $this->logged);
		}//end testEveryEnabledParticipantGetsTheTagAddedToTheirOwn()

		/**
		 * Without tag support or a token nothing happens.
		 *
		 * @return void
		 */
		public function testUnsupportedOrEmptyTokenFilesNothing(): void {
			self::assertSame(0, $this->grouping(available: false)->groupRoom(roomToken: 'room-1'));
			self::assertSame(0, $this->grouping()->groupRoom(roomToken: ''));
			self::assertSame([], $this->assigned);
		}//end testUnsupportedOrEmptyTokenFilesNothing()

		/**
		 * A conflicting create re-reads the tag the race created.
		 *
		 * @return void
		 */
		public function testAConflictingCreateUsesTheRacedTag(): void {
			$this->participants = ['alice'];
			$this->createConflicts = true;

			self::assertSame(1, $this->grouping()->groupRoom(roomToken: 'room-1'));
			self::assertSame([['alice', ['raced-alice']]], $this->assigned);
			self::assertSame([], $this->logged);
		}//end testAConflictingCreateUsesTheRacedTag()

		/**
		 * A room that cannot be resolved is a debug line naming the room.
		 *
		 * @return void
		 */
		public function testAnUnresolvableRoomIsLoggedAndFilesNothing(): void {
			$this->failOn = 'OCA\\Talk\\Manager';

			self::assertSame(0, $this->grouping()->groupRoom(roomToken: 'room-1'));
			self::assertSame('[TalkRoomGrouping] Could not group the room (chat is unaffected)', $this->logged[0][0]);
			self::assertSame(['file', 'line', 'roomToken', 'error'], array_keys($this->logged[0][1]));
			self::assertSame(['room-1', 'no OCA\\Talk\\Manager'], [$this->logged[0][1]['roomToken'], $this->logged[0][1]['error']]);
		}//end testAnUnresolvableRoomIsLoggedAndFilesNothing()

		/**
		 * A tag service that cannot be reached logs the listing failure, then the
		 * failure to file, naming the user, and files nothing for them.
		 *
		 * @return void
		 */
		public function testAnUnreachableTagServiceIsLoggedPerUser(): void {
			$this->participants = ['alice'];
			$this->failOn = 'OCA\\Talk\\Service\\ConversationTagService';

			self::assertSame(0, $this->grouping()->groupRoom(roomToken: 'room-1'));
			self::assertSame(['[TalkRoomGrouping] Could not file the room for this user'], array_column($this->logged, 0));
			self::assertSame(['file', 'line', 'uid', 'error'], array_keys($this->logged[0][1]));
			self::assertSame('alice', $this->logged[0][1]['uid']);
		}//end testAnUnreachableTagServiceIsLoggedPerUser()

		/**
		 * A create that fails without a raced tag is logged and files nothing.
		 *
		 * @return void
		 */
		public function testAFailedCreateWithoutARaceIsLogged(): void {
			$this->participants = ['alice'];
			$test = $this;

			// A create that throws and leaves no tag behind.
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $id) use ($test): object {
					if (str_ends_with($id, 'ConversationTagService') === false) {
						return (new \ReflectionMethod($test, 'service'))->invoke($test, $id);
					}

					return new class {
						/**
						 * No tags.
						 *
						 * @param string $uid The user.
						 *
						 * @return array<int, object>
						 */
						public function getTags(string $uid): array {
							return [];
						}

						/**
						 * Always fails.
						 *
						 * @param string $uid  The user.
						 * @param string $name The name.
						 *
						 * @return object
						 */
						public function createTag(string $uid, string $name): object {
							throw new RuntimeException('read-only');
						}
					};
				}
			);
			$bridge = $this->createMock(TalkBridge::class);
			$bridge->method('isAvailable')->willReturn(true);
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('debug')->willReturnCallback(
				function (string|\Stringable $message, array $context = []): void {
					$this->logged[] = [(string)$message, $context];
				}
			);
			$grouping = new TalkRoomGrouping(bridge: $bridge, container: $container, config: $this->createMock(IConfig::class), logger: $logger);

			self::assertSame(0, $grouping->groupRoom(roomToken: 'room-1'));
			self::assertSame(['[TalkRoomGrouping] Could not create the Hermiq tag'], array_column($this->logged, 0));
			self::assertSame(['file', 'line', 'uid', 'error'], array_keys($this->logged[0][1]));
			self::assertSame([], $this->assigned);
		}//end testAFailedCreateWithoutARaceIsLogged()
		/**
		 * A tag list that cannot be read is logged with the user and the
		 * error, and treated as "no tag yet": the tag is created and the room
		 * filed under it.
		 *
		 * @return void
		 */
		public function testAnUnreadableTagListIsLoggedAndTheTagCreated(): void {
			$this->participants = ['alice'];
			$this->listFails = true;

			self::assertSame(1, $this->grouping()->groupRoom(roomToken: 'room-1'));
			self::assertSame([['alice', 'Hermiq']], $this->created);
			self::assertSame([['alice', ['new-alice']]], $this->assigned);
			self::assertSame(["[TalkRoomGrouping] Could not list the user's conversation tags"], array_column($this->logged, 0));
			self::assertSame(['file', 'line', 'uid', 'error'], array_keys($this->logged[0][1]));
			self::assertSame(['alice', 'cannot list alice'], [$this->logged[0][1]['uid'], $this->logged[0][1]['error']]);
		}//end testAnUnreadableTagListIsLoggedAndTheTagCreated()

	}//end class
}
