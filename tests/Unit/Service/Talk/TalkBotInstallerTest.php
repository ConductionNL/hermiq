<?php

/**
 * TalkBotInstaller: installing, removing and enabling Hermiq's Talk bots.
 *
 * Spreed is optional and absent here, so the two lifecycle events and the bot
 * conversation row are declared below as minimal stand-ins that record what
 * they were built with. They are declared only when nothing else declared them.
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
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
 */

declare(strict_types=1);

namespace OCA\Talk\Events {

	if (class_exists(BotInstallEvent::class) === false) {
		/**
		 * Stand-in for spreed's BotInstallEvent: keeps its constructor arguments.
		 */
		class BotInstallEvent extends \OCP\EventDispatcher\Event {

			/** @var array<int, mixed> */
			public array $args;

			/**
			 * Keep the arguments.
			 *
			 * @param mixed ...$args The constructor arguments.
			 */
			public function __construct(mixed ...$args) {
				$this->args = $args;
			}
		}
	}

	if (class_exists(BotUninstallEvent::class) === false) {
		/**
		 * Stand-in for spreed's BotUninstallEvent: keeps its constructor arguments.
		 */
		class BotUninstallEvent extends \OCP\EventDispatcher\Event {

			/** @var array<int, mixed> */
			public array $args;

			/**
			 * Keep the arguments.
			 *
			 * @param mixed ...$args The constructor arguments.
			 */
			public function __construct(mixed ...$args) {
				$this->args = $args;
			}
		}
	}
}

namespace OCA\Talk\Model {

	if (class_exists(BotConversation::class) === false) {
		/**
		 * Stand-in for spreed's BotConversation row: keeps what was set.
		 */
		class BotConversation {

			/** @var array<int, array{0: string, 1: mixed}> */
			public array $set = [];

			/**
			 * Record a setter call.
			 *
			 * @param string            $name The setter.
			 * @param array<int, mixed> $args Its arguments.
			 *
			 * @return void
			 */
			public function __call(string $name, array $args): void {
				$this->set[] = [$name, $args[0] ?? null];
			}
		}
	}
}

namespace OCA\Hermiq\Tests\Unit\Service\Talk {

	use OCA\Hermiq\Service\Talk\TalkBotInstaller;
	use OCA\Hermiq\Service\Talk\TalkBridge;
	use OCA\Talk\Events\BotInstallEvent;
	use OCA\Talk\Events\BotUninstallEvent;
	use OCA\Talk\Model\BotConversation;
	use OCP\EventDispatcher\IEventDispatcher;
	use OCP\IAppConfig;
	use OCP\Security\ISecureRandom;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * Install, uninstall, per-agent install and room enabling.
	 */
	final class TalkBotInstallerTest extends TestCase {

		/** @var array<int, object> */
		private array $dispatched = [];

		/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
		private array $logged = [];

		/** @var array<int, object> */
		private array $inserted = [];

		/** @var array<int, array{0: string, 1: string, 2: string, 3: bool}> */
		private array $stored = [];

		private bool $failDispatch = false;

		private bool $available = true;

		private string $storedSecret = 'app-secret';

		/** @var array<int, int> */
		private array $enabledBotIds = [];

		/**
		 * The installer over recording doubles.
		 *
		 * @return TalkBotInstaller
		 */
		private function installer(): TalkBotInstaller {
			$dispatcher = $this->createMock(IEventDispatcher::class);
			$dispatcher->method('dispatchTyped')->willReturnCallback(
				function (object $event): void {
					if ($this->failDispatch === true) {
						throw new RuntimeException('spreed said no');
					}

					$this->dispatched[] = $event;
				}
			);

			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->storedSecret);
			$appConfig->method('setValueString')->willReturnCallback(
				function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
					$this->stored[] = [$app, $key, $value, $sensitive];
					$this->storedSecret = $value;
					return true;
				}
			);

			$random = $this->createMock(ISecureRandom::class);
			$random->method('generate')->willReturn('generated-secret');

			$bridge = $this->createMock(TalkBridge::class);
			$bridge->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);

			$logger = $this->createMock(LoggerInterface::class);
			foreach (['info', 'warning'] as $level) {
				$logger->method($level)->willReturnCallback(
					function (string|\Stringable $message, array $context = []) use ($level): void {
						$this->logged[] = [$level, (string)$message, $context];
					}
				);
			}

			return new TalkBotInstaller(
				container: $this->container(),
				dispatcher: $dispatcher,
				appConfig: $appConfig,
				secureRandom: $random,
				bridge: $bridge,
				logger: $logger
			);
		}//end installer()

		/**
		 * A container serving the two spreed mappers.
		 *
		 * @return ContainerInterface
		 */
		private function container(): ContainerInterface {
			$test = $this;
			$botMapper = new class {
				/** @var array<int, string> */
				public array $urls = [];

				/**
				 * The bot row for a URL.
				 *
				 * @param string $url The bot URL.
				 *
				 * @return object
				 */
				public function findByUrl(string $url): object {
					$this->urls[] = $url;
					return new class {
						/**
						 * The bot id.
						 *
						 * @return string
						 */
						public function getId(): string {
							return '42';
						}
					};
				}
			};
			$conversationMapper = new class ($test) {
				/**
				 * Keep the test.
				 *
				 * @param TalkBotInstallerTest $test The test.
				 */
				public function __construct(private TalkBotInstallerTest $test) {
				}

				/**
				 * The rows enabled in a room.
				 *
				 * @param string $token The room token.
				 *
				 * @return array<int, object>
				 */
				public function findForToken(string $token): array {
					return $this->test->enabledRows();
				}

				/**
				 * Insert a row.
				 *
				 * @param object $row The row.
				 *
				 * @return object
				 */
				public function insert(object $row): object {
					$this->test->recordInsert(row: $row);
					return $row;
				}
			};

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				fn (string $id): object => (str_ends_with($id, 'BotServerMapper') === true ? $botMapper : $conversationMapper)
			);
			return $container;
		}//end container()

		/**
		 * The rows the conversation mapper reports for a room.
		 *
		 * @return array<int, object>
		 */
		public function enabledRows(): array {
			return array_map(
				static fn (int $botId): object => new class ($botId) {
					/**
					 * Keep the id.
					 *
					 * @param int $botId The bot id.
					 */
					public function __construct(private int $botId) {
					}

					/**
					 * The bot id, as spreed returns it.
					 *
					 * @return string
					 */
					public function getBotId(): string {
						return (string)$this->botId;
					}
				},
				$this->enabledBotIds
			);
		}//end enabledRows()

		/**
		 * Record an inserted row.
		 *
		 * @param object $row The row.
		 *
		 * @return void
		 */
		public function recordInsert(object $row): void {
			$this->inserted[] = $row;
		}//end recordInsert()

		/**
		 * The shared bot is installed with its name, the app secret, its URL, the
		 * description and the feature bits, and the install is logged.
		 *
		 * @return void
		 */
		public function testInstallDispatchesTheSharedBotAndLogsIt(): void {
			self::assertTrue($this->installer()->install());

			self::assertCount(1, $this->dispatched);
			self::assertInstanceOf(BotInstallEvent::class, $this->dispatched[0]);
			self::assertSame(
				[TalkBridge::BOT_NAME, 'app-secret', TalkBridge::BOT_URL, 'Converse with a Hermiq agent from this conversation.', 14],
				$this->dispatched[0]->args
			);
			self::assertSame('info', $this->logged[0][0]);
			self::assertSame('[TalkBotInstaller] Hermiq Talk bot installed', $this->logged[0][1]);
			self::assertSame(['file', 'line', 'url'], array_keys($this->logged[0][2]));
			self::assertSame(TalkBridge::BOT_URL, $this->logged[0][2]['url']);
		}//end testInstallDispatchesTheSharedBotAndLogsIt()

		/**
		 * Without Talk nothing is dispatched; a refused dispatch is a logged false.
		 *
		 * @return void
		 */
		public function testInstallWithoutTalkOrWithARefusalIsFalse(): void {
			$this->available = false;
			self::assertFalse($this->installer()->install());
			self::assertSame([], $this->dispatched);

			$this->available = true;
			$this->failDispatch = true;
			self::assertFalse($this->installer()->install());
			self::assertSame(['warning', '[TalkBotInstaller] Could not install the Hermiq Talk bot'], array_slice($this->logged[0], 0, 2));
			self::assertSame(['file', 'line', 'error'], array_keys($this->logged[0][2]));
			self::assertSame('spreed said no', $this->logged[0][2]['error']);
		}//end testInstallWithoutTalkOrWithARefusalIsFalse()

		/**
		 * A missing app secret is generated once and stored as sensitive.
		 *
		 * @return void
		 */
		public function testAMissingSecretIsGeneratedAndStored(): void {
			$this->storedSecret = '';
			self::assertTrue($this->installer()->uninstall());

			self::assertSame([['hermiq', 'talk_bot_secret', 'generated-secret', true]], $this->stored);
			self::assertInstanceOf(BotUninstallEvent::class, $this->dispatched[0]);
			self::assertSame(['generated-secret', TalkBridge::BOT_URL], $this->dispatched[0]->args);
		}//end testAMissingSecretIsGeneratedAndStored()

		/**
		 * A refused uninstall is a logged false.
		 *
		 * @return void
		 */
		public function testARefusedUninstallIsFalse(): void {
			$this->failDispatch = true;
			self::assertFalse($this->installer()->uninstall());
			self::assertSame('[TalkBotInstaller] Could not remove the Hermiq Talk bot', $this->logged[0][1]);
			self::assertSame(['file', 'line', 'error'], array_keys($this->logged[0][2]));

			$this->available = false;
			$this->logged = [];
			self::assertFalse($this->installer()->uninstall());
			self::assertSame([], $this->logged);
		}//end testARefusedUninstallIsFalse()

		/**
		 * An agent's bot carries its trimmed name (Hermiq when empty, at most 64
		 * characters), its derived secret and its own URL.
		 *
		 * @return void
		 */
		public function testAnAgentBotCarriesItsNameSecretAndUrl(): void {
			$installer = $this->installer();
			self::assertTrue($installer->installForAgent(agentId: 'agent-1', agentName: '  Loket  '));
			self::assertTrue($installer->installForAgent(agentId: 'agent-1', agentName: '   '));
			self::assertTrue($installer->installForAgent(agentId: 'agent-1', agentName: str_repeat('é', 70)));

			$secret = hash_hmac('sha256', 'agent-1', 'app-secret');
			$url = TalkBridge::botUrlFor(agentId: 'agent-1');
			$description = 'Converse with this Hermiq agent from this conversation.';
			self::assertSame(['Loket', $secret, $url, $description, 14], $this->dispatched[0]->args);
			self::assertSame([TalkBridge::BOT_NAME, $secret, $url, $description, 14], $this->dispatched[1]->args);
			self::assertSame(str_repeat('é', 64), $this->dispatched[2]->args[0]);
			self::assertSame([], $this->logged);
		}//end testAnAgentBotCarriesItsNameSecretAndUrl()

		/**
		 * No agent id, no Talk, or a refusal: false, the refusal logged with the agent.
		 *
		 * @return void
		 */
		public function testAnAgentBotInstallIsRefusedWithoutAnIdOrTalk(): void {
			self::assertFalse($this->installer()->installForAgent(agentId: '', agentName: 'Loket'));
			$this->available = false;
			self::assertFalse($this->installer()->installForAgent(agentId: 'agent-1', agentName: 'Loket'));
			self::assertSame([], $this->dispatched);

			$this->available = true;
			$this->failDispatch = true;
			self::assertFalse($this->installer()->installForAgent(agentId: 'agent-1', agentName: 'Loket'));
			self::assertSame('[TalkBotInstaller] Could not install or rename the agent bot', $this->logged[0][1]);
			self::assertSame(['file', 'line', 'agentId', 'error'], array_keys($this->logged[0][2]));
			self::assertSame('agent-1', $this->logged[0][2]['agentId']);
		}//end testAnAgentBotInstallIsRefusedWithoutAnIdOrTalk()

		/**
		 * An agent's bot is removed with (secret, url); a refusal is logged with the agent.
		 *
		 * @return void
		 */
		public function testAnAgentBotIsRemovedWithSecretThenUrl(): void {
			self::assertFalse($this->installer()->uninstallForAgent(agentId: ''));
			self::assertTrue($this->installer()->uninstallForAgent(agentId: 'agent-1'));
			self::assertInstanceOf(BotUninstallEvent::class, $this->dispatched[0]);
			self::assertSame([hash_hmac('sha256', 'agent-1', 'app-secret'), TalkBridge::botUrlFor(agentId: 'agent-1')], $this->dispatched[0]->args);

			$this->failDispatch = true;
			self::assertFalse($this->installer()->uninstallForAgent(agentId: 'agent-1'));
			self::assertSame('[TalkBotInstaller] Could not remove the agent bot', $this->logged[0][1]);
			self::assertSame(['file', 'line', 'agentId', 'error'], array_keys($this->logged[0][2]));
		}//end testAnAgentBotIsRemovedWithSecretThenUrl()

		/**
		 * Enabling inserts one enabled row for the bot and room; a bot already
		 * enabled there is left alone.
		 *
		 * @return void
		 */
		public function testEnablingInsertsOneRowUnlessAlreadyEnabled(): void {
			self::assertFalse($this->installer()->enableInRoom(agentId: '', roomToken: 'room-1'));
			self::assertFalse($this->installer()->enableInRoom(agentId: 'agent-1', roomToken: ''));

			$this->enabledBotIds = [7];
			self::assertTrue($this->installer()->enableInRoom(agentId: 'agent-1', roomToken: 'room-1'));
			self::assertCount(1, $this->inserted);
			self::assertInstanceOf(BotConversation::class, $this->inserted[0]);
			self::assertSame([['setBotId', 42], ['setToken', 'room-1'], ['setState', 1]], $this->inserted[0]->set);

			$this->enabledBotIds = [7, 42];
			self::assertTrue($this->installer()->enableInRoom(agentId: 'agent-1', roomToken: 'room-1'));
			self::assertCount(1, $this->inserted, 'An already enabled bot is left alone.');
			self::assertSame([], $this->logged);
		}//end testEnablingInsertsOneRowUnlessAlreadyEnabled()

		/**
		 * Without Talk nothing is enabled; a failing mapper is a logged false naming agent and room.
		 *
		 * @return void
		 */
		public function testEnablingWithoutTalkOrWithAFailureIsFalse(): void {
			$this->available = false;
			self::assertFalse($this->installer()->enableInRoom(agentId: 'agent-1', roomToken: 'room-1'));

			$this->available = true;
			$this->enabledBotIds = [];
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willThrowException(new RuntimeException('no mapper'));
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				function (string|\Stringable $message, array $context = []): void {
					$this->logged[] = ['warning', (string)$message, $context];
				}
			);
			$bridge = $this->createMock(TalkBridge::class);
			$bridge->method('isAvailable')->willReturn(true);
			$installer = new TalkBotInstaller(
				container: $container,
				dispatcher: $this->createMock(IEventDispatcher::class),
				appConfig: $this->createMock(IAppConfig::class),
				secureRandom: $this->createMock(ISecureRandom::class),
				bridge: $bridge,
				logger: $logger
			);

			self::assertFalse($installer->enableInRoom(agentId: 'agent-1', roomToken: 'room-1'));
			self::assertSame('[TalkBotInstaller] Could not enable the agent bot in the room', $this->logged[0][1]);
			self::assertSame(['file', 'line', 'agentId', 'roomToken', 'error'], array_keys($this->logged[0][2]));
			self::assertSame(['agent-1', 'room-1', 'no mapper'], [$this->logged[0][2]['agentId'], $this->logged[0][2]['roomToken'], $this->logged[0][2]['error']]);
		}//end testEnablingWithoutTalkOrWithAFailureIsFalse()
	}//end class
}
