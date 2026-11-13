<?php

/**
 * Characterisation tests for three native HermiqToolProvider tools:
 * `hermiq.listFiles`, `hermiq.sendMail` and `hermiq.listDeckBoards`.
 *
 * Pins every refusal envelope, the listing shape, the mail that is composed and the
 * board titles that are read, so the three tools can be split into named steps
 * without changing what an agent receives.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\AgentToolActions;
use OCA\Hermiq\Mcp\AgentToolReaders;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Mcp\NcPlatform;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation tests for the files, mail and Deck tools.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */
class HermiqToolProviderNativeToolsTest extends TestCase {

	/**
	 * Collaborators the tests steer.
	 *
	 * @var array<string, mixed>
	 */
	private array $parts = [];

	/**
	 * The provider, acting as alice, over the steered collaborators.
	 *
	 * @param IUser|null $user The acting user; a default alice when null.
	 *
	 * @return HermiqToolProvider
	 */
	private function provider(?IUser $user = null): HermiqToolProvider {
		if ($user === null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new HermiqToolProvider(
			userSession: $session,
			platform: new NcPlatform(
				rootFolder: ($this->parts['rootFolder'] ?? $this->createMock(IRootFolder::class)),
				contactsManager: $this->createMock(IContactsManager::class),
				calendarManager: $this->createMock(ICalendarManager::class),
				mailer: ($this->parts['mailer'] ?? $this->createMock(IMailer::class)),
				appManager: ($this->parts['appManager'] ?? $this->createMock(IAppManager::class))
			),
			container: ($this->parts['container'] ?? $this->createMock(ContainerInterface::class)),
			readers: new AgentToolReaders(
				courseEngine: $this->createMock(CourseRecommendationEngine::class),
				memoryService: $this->createMock(MemoryService::class),
				webSearchClient: $this->createMock(WebSearchClient::class),
				webFetchService: $this->createMock(WebFetchService::class)
			),
			actions: new AgentToolActions(
				delegationService: $this->createMock(DelegationService::class),
				writeService: $this->createMock(NcNativeWriteService::class),
				mailReadService: $this->createMock(MailReadService::class),
				accessRequests: $this->createMock(ToolAccessRequestService::class)
			),
			logger: ($this->parts['logger'] ?? $this->createMock(LoggerInterface::class))
		);
	}//end provider()

	/**
	 * A node mock.
	 *
	 * @param string $class File or Folder.
	 * @param string $name The node name.
	 * @param int $size The node size.
	 *
	 * @return File|Folder
	 */
	private function node(string $class, string $name, int $size): File|Folder {
		$node = $this->createMock($class);
		$node->method('getName')->willReturn($name);
		$node->method('getSize')->willReturn($size);
		return $node;
	}//end node()

	/**
	 * listFiles lists the root or a sub-folder with each entry's name, type and size,
	 * and refuses a missing path or a file.
	 *
	 * @return void
	 */
	public function testListFilesShapesAndRefusals(): void {
		$sub = $this->node(Folder::class, 'docs', 0);
		$sub->method('getDirectoryListing')->willReturn([$this->node(File::class, 'a.txt', 3)]);
		$root = $this->createMock(Folder::class);
		$root->method('getDirectoryListing')->willReturn([$this->node(File::class, 'r.md', 10), $this->node(Folder::class, 'docs', 0)]);
		$root->method('nodeExists')->willReturnCallback(static fn (string $path): bool => in_array($path, ['docs', 'r.md'], true));
		$root->method('get')->willReturnCallback(fn (string $path) => ($path === 'docs') ? $sub : $this->node(File::class, 'r.md', 10));
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($root);
		$this->parts['rootFolder'] = $rootFolder;
		$provider = $this->provider();

		$listing = [['name' => 'r.md', 'type' => 'file', 'size' => 10], ['name' => 'docs', 'type' => 'folder', 'size' => 0]];
		$this->assertSame(['path' => '/', 'entries' => $listing], $provider->invokeTool('hermiq.listFiles', []));
		$this->assertSame(['path' => '/', 'entries' => $listing], $provider->invokeTool('hermiq.listFiles', ['path' => '/']));
		$this->assertSame(['path' => 'docs', 'entries' => [['name' => 'a.txt', 'type' => 'file', 'size' => 3]]], $provider->invokeTool('hermiq.listFiles', ['path' => 'docs']));
		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Path not found in your files.']], $provider->invokeTool('hermiq.listFiles', ['path' => 'gone']));
		$this->assertSame(['error' => ['code' => 'not_a_folder', 'message' => 'Path is not a folder.']], $provider->invokeTool('hermiq.listFiles', ['path' => 'r.md']));

	}//end testListFilesShapesAndRefusals()

	/**
	 * sendMail refuses missing parts, an invalid address and a user without an
	 * address, in that order; otherwise it composes and sends the mail as the user.
	 *
	 * @return void
	 */
	public function testSendMailRefusalsAndTheComposedMail(): void {
		$composed = [];
		$message = $this->createMock(IMessage::class);
		foreach (['setFrom', 'setTo', 'setSubject', 'setPlainBody'] as $setter) {
			$message->method($setter)->willReturnCallback(
				function (mixed $value) use (&$composed, $setter, $message) {
					$composed[] = [$setter, $value];
					return $message;
				}
			);
		}

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $to): bool => str_contains($to, '@'));
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnOnConsecutiveCalls([], ['bob@example.org']);
		$this->parts['mailer'] = $mailer;

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getEMailAddress')->willReturn('alice@example.org');
		$alice->method('getDisplayName')->willReturn('Alice');
		$nobody = $this->createMock(IUser::class);
		$nobody->method('getUID')->willReturn('nobody');
		$nobody->method('getEMailAddress')->willReturn(null);

		$mail = ['to' => ' bob@example.org ', 'subject' => 'S', 'body' => 'B'];
		$missing = ['error' => ['code' => 'invalid_argument', 'message' => 'to, subject and body are all required.']];
		$this->assertSame($missing, $this->provider($alice)->invokeTool('hermiq.sendMail', ['to' => '  '] + $mail));
		$this->assertSame($missing, $this->provider($alice)->invokeTool('hermiq.sendMail', ['subject' => ''] + $mail));
		$this->assertSame($missing, $this->provider($alice)->invokeTool('hermiq.sendMail', ['body' => ''] + $mail));
		$this->assertSame(
			['error' => ['code' => 'invalid_argument', 'message' => 'The recipient address is not a valid email.']],
			$this->provider($nobody)->invokeTool('hermiq.sendMail', ['to' => 'bob'] + $mail)
		);
		$this->assertSame(
			['error' => ['code' => 'no_sender', 'message' => 'Your account has no email address to send from.']],
			$this->provider($nobody)->invokeTool('hermiq.sendMail', $mail)
		);
		$this->assertSame([], $composed);

		$this->assertSame(['sent' => true, 'failedRecipients' => []], $this->provider($alice)->invokeTool('hermiq.sendMail', $mail));
		$this->assertSame(
			[['setFrom', ['alice@example.org' => 'Alice']], ['setTo', ['bob@example.org']], ['setSubject', 'S'], ['setPlainBody', 'B']],
			$composed
		);
		$this->assertSame(['sent' => false, 'failedRecipients' => ['bob@example.org']], $this->provider($alice)->invokeTool('hermiq.sendMail', $mail));

	}//end testSendMailRefusalsAndTheComposedMail()

	/**
	 * listDeckBoards refuses without Deck or when Deck cannot be read, and lists the
	 * scalar titles of readable boards, skipping the rest with a warning for each
	 * unreadable one.
	 *
	 * @return void
	 */
	public function testListDeckBoardsRefusalsAndTitles(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnOnConsecutiveCalls(false, true, true);
		$this->parts['appManager'] = $appManager;

		$board = static fn (mixed $title): object => new class($title) {
			/**
			 * @var mixed
			 */
			public mixed $title;

			/**
			 * @param mixed $title The title.
			 */
			public function __construct(mixed $title) {
				$this->title = $title;
			}

			/**
			 * @return mixed
			 */
			public function getTitle(): mixed {
				if ($this->title instanceof RuntimeException) {
					throw $this->title;
				}

				return $this->title;
			}
		};
		$boardService = new class([$board('Plan'), 'not-a-board', new \stdClass(), $board(new RuntimeException('locked')), $board(['x']), $board(7)]) {
			/**
			 * @param array<int, mixed> $boards The boards.
			 */
			public function __construct(private readonly array $boards) {
			}

			/**
			 * @return array<int, mixed>
			 */
			public function findAll(): array {
				return $this->boards;
			}
		};
		$calls = 0;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$calls, $boardService) {
				$calls++;
				if ($calls === 1) {
					throw new RuntimeException('deck broke');
				}

				return $boardService;
			}
		);
		$this->parts['container'] = $container;
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$this->parts['logger'] = $logger;
		$provider = $this->provider();

		$this->assertSame(['error' => ['code' => 'deck_unavailable', 'message' => 'The Deck app is not installed or enabled.']], $provider->invokeTool('hermiq.listDeckBoards', []));
		$this->assertSame(['error' => ['code' => 'deck_unavailable', 'message' => 'Could not read Deck boards.']], $provider->invokeTool('hermiq.listDeckBoards', []));
		$this->assertSame(['boards' => [['title' => 'Plan'], ['title' => '7']]], $provider->invokeTool('hermiq.listDeckBoards', []));
		$this->assertSame(['Hermiq Deck board listing failed: deck broke', 'Hermiq skipped an unreadable Deck board: locked'], $warnings);

	}//end testListDeckBoardsRefusalsAndTitles()
}//end class
