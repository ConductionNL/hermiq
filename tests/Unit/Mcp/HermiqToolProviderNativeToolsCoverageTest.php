<?php

/**
 * Unit tests for the nc-native tools HermiqToolProvider runs itself: files,
 * contacts, mail, Deck, web research, delegation and tool-access routing.
 *
 * Every tool is reached through the public invokeTool() facade, so each test
 * also proves the id routes to the right handler and that the never-throws
 * contract turns a failing collaborator into an error envelope.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\AgentMemoryTools;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Mcp\NativeToolCall;
use OCA\Hermiq\Mcp\ToolErrorEnvelope;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An agent's native tools act only as the signed-in user and answer every bad input with an error envelope.
 */
#[CoversClass(HermiqToolProvider::class)]
#[UsesClass(NativeToolCall::class)]
#[UsesClass(AgentMemoryTools::class)]
#[UsesClass(ToolErrorEnvelope::class)]
final class HermiqToolProviderNativeToolsCoverageTest extends TestCase {

	private IUser&MockObject $user;
	private IRootFolder&MockObject $rootFolder;
	private IContactsManager&MockObject $contacts;
	private IMailer&MockObject $mailer;
	private IAppManager&MockObject $appManager;
	private ContainerInterface&MockObject $container;
	private WebSearchClient&MockObject $webSearch;
	private WebFetchService&MockObject $webFetch;
	private DelegationService&MockObject $delegation;
	private NcNativeWriteService&MockObject $writes;
	private MailReadService&MockObject $mailReads;
	private ToolAccessRequestService&MockObject $accessRequests;
	private LoggerInterface&MockObject $logger;
	private HermiqToolProvider $provider;

	protected function setUp(): void {
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);

		$this->rootFolder     = $this->createMock(IRootFolder::class);
		$this->contacts       = $this->createMock(IContactsManager::class);
		$this->mailer         = $this->createMock(IMailer::class);
		$this->appManager     = $this->createMock(IAppManager::class);
		$this->container      = $this->createMock(ContainerInterface::class);
		$this->webSearch      = $this->createMock(WebSearchClient::class);
		$this->webFetch       = $this->createMock(WebFetchService::class);
		$this->delegation     = $this->createMock(DelegationService::class);
		$this->writes         = $this->createMock(NcNativeWriteService::class);
		$this->mailReads      = $this->createMock(MailReadService::class);
		$this->accessRequests = $this->createMock(ToolAccessRequestService::class);
		$this->logger         = $this->createMock(LoggerInterface::class);

		$this->provider = new HermiqToolProvider(
			$session,
			$this->rootFolder,
			$this->contacts,
			$this->createMock(ICalendarManager::class),
			$this->mailer,
			$this->appManager,
			$this->container,
			$this->createMock(CourseRecommendationEngine::class),
			$this->createMock(MemoryService::class),
			$this->webSearch,
			$this->webFetch,
			$this->delegation,
			$this->writes,
			$this->mailReads,
			$this->accessRequests,
			$this->logger
		);
	}//end setUp()

	/**
	 * The error envelope invokeTool returns.
	 *
	 * @param string $code The machine code.
	 * @param string $message The message.
	 *
	 * @return array<string, mixed>
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()

	/**
	 * A node double with a name and size.
	 *
	 * @param class-string $type File::class or Folder::class.
	 * @param string $name The node name.
	 * @param int $size The node size.
	 *
	 * @return File&MockObject|Folder&MockObject
	 */
	private function node(string $type, string $name, int $size): MockObject {
		$node = $this->createMock($type);
		$node->method('getName')->willReturn($name);
		$node->method('getSize')->willReturn($size);
		return $node;
	}//end node()

	/**
	 * The acting user's folder, looked up for alice only.
	 *
	 * @return Folder&MockObject
	 */
	private function userFolder(): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);
		return $folder;
	}//end userFolder()

	public function testListFilesWithoutAPathListsTheUserRoot(): void {
		$folder = $this->userFolder();
		$folder->expects($this->never())->method('nodeExists');
		$folder->method('getDirectoryListing')->willReturn([
			$this->node(File::class, 'notes.txt', 12),
			$this->node(Folder::class, 'Photos', 2048),
		]);

		$this->assertSame(
			[
				'path' => '/',
				'entries' => [
					['name' => 'notes.txt', 'type' => 'file', 'size' => 12],
					['name' => 'Photos', 'type' => 'folder', 'size' => 2048],
				],
			],
			$this->provider->invokeTool('hermiq.listFiles', [])
		);
	}//end testListFilesWithoutAPathListsTheUserRoot()

	public function testListFilesListsTheNamedSubfolder(): void {
		$sub = $this->createMock(Folder::class);
		$sub->method('getDirectoryListing')->willReturn([$this->node(File::class, 'a.pdf', 5)]);
		$folder = $this->userFolder();
		$folder->method('nodeExists')->with('/Docs')->willReturn(true);
		$folder->method('get')->with('/Docs')->willReturn($sub);
		$folder->expects($this->never())->method('getDirectoryListing');

		$this->assertSame(
			['path' => '/Docs', 'entries' => [['name' => 'a.pdf', 'type' => 'file', 'size' => 5]]],
			$this->provider->invokeTool('hermiq.listFiles', ['path' => '/Docs'])
		);
	}//end testListFilesListsTheNamedSubfolder()

	public function testListFilesRefusesAMissingPath(): void {
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(false);
		$folder->expects($this->never())->method('get');

		$this->assertSame(
			self::error('not_found', 'Path not found in your files.'),
			$this->provider->invokeTool('hermiq.listFiles', ['path' => 'gone'])
		);
	}//end testListFilesRefusesAMissingPath()

	public function testListFilesRefusesAFile(): void {
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($this->createMock(File::class));

		$this->assertSame(
			self::error('not_a_folder', 'Path is not a folder.'),
			$this->provider->invokeTool('hermiq.listFiles', ['path' => 'notes.txt'])
		);
	}//end testListFilesRefusesAFile()

	public function testReadFileRequiresAPath(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->assertSame(
			self::error('invalid_argument', 'A file path is required.'),
			$this->provider->invokeTool('hermiq.readFile', ['path' => '/'])
		);
	}//end testReadFileRequiresAPath()

	public function testReadFileRefusesAMissingFile(): void {
		$this->userFolder()->method('nodeExists')->with('x.txt')->willReturn(false);

		$this->assertSame(
			self::error('not_found', 'File not found in your files.'),
			$this->provider->invokeTool('hermiq.readFile', ['path' => 'x.txt'])
		);
	}//end testReadFileRefusesAMissingFile()

	public function testReadFileRefusesAFolder(): void {
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($this->createMock(Folder::class));

		$this->assertSame(
			self::error('not_a_file', 'Path is not a file.'),
			$this->provider->invokeTool('hermiq.readFile', ['path' => 'Photos'])
		);
	}//end testReadFileRefusesAFolder()

	public function testReadFileReturnsTheContentUntruncated(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('hello');
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->with('notes.txt')->willReturn($file);

		$this->assertSame(
			['path' => 'notes.txt', 'content' => 'hello', 'truncated' => false],
			$this->provider->invokeTool('hermiq.readFile', ['path' => 'notes.txt'])
		);
	}//end testReadFileReturnsTheContentUntruncated()

	public function testReadFileCapsTheContentAtTwentyThousandBytes(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn(str_repeat('a', 20000) . 'b');
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		$result = $this->provider->invokeTool('hermiq.readFile', ['path' => 'big.txt']);

		$this->assertSame(str_repeat('a', 20000), $result['content']);
		$this->assertTrue($result['truncated']);
	}//end testReadFileCapsTheContentAtTwentyThousandBytes()

	public function testReadFileOfExactlyTheCapIsNotTruncated(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn(str_repeat('a', 20000));
		$folder = $this->userFolder();
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		$this->assertFalse($this->provider->invokeTool('hermiq.readFile', ['path' => 'cap.txt'])['truncated']);
	}//end testReadFileOfExactlyTheCapIsNotTruncated()

	public function testSearchContactsRequiresAQuery(): void {
		$this->contacts->expects($this->never())->method('search');

		$this->assertSame(
			self::error('invalid_argument', 'A search query is required.'),
			$this->provider->invokeTool('hermiq.searchContacts', ['query' => '  '])
		);
	}//end testSearchContactsRequiresAQuery()

	public function testSearchContactsReturnsNameAndFirstEmail(): void {
		$this->contacts->expects($this->once())
			->method('search')
			->with('ann', ['FN', 'EMAIL'], ['limit' => 25])
			->willReturn([
				['FN' => 'Ann One', 'EMAIL' => ['ann@one.test', 'ann@two.test']],
				['FN' => 'Ann Two', 'EMAIL' => 'ann2@test.test'],
				[],
			]);

		$this->assertSame(
			[
				'query' => 'ann',
				'results' => [
					['name' => 'Ann One', 'email' => 'ann@one.test'],
					['name' => 'Ann Two', 'email' => 'ann2@test.test'],
					['name' => '', 'email' => ''],
				],
			],
			$this->provider->invokeTool('hermiq.searchContacts', ['query' => 'ann'])
		);
	}//end testSearchContactsReturnsNameAndFirstEmail()

	public function testSendMailRequiresRecipientSubjectAndBody(): void {
		$this->mailer->expects($this->never())->method('send');

		$this->assertSame(
			self::error('invalid_argument', 'to, subject and body are all required.'),
			$this->provider->invokeTool('hermiq.sendMail', ['to' => 'bob@test.test', 'subject' => 'Hi'])
		);
	}//end testSendMailRequiresRecipientSubjectAndBody()

	public function testSendMailRefusesAnInvalidRecipient(): void {
		$this->mailer->method('validateMailAddress')->with('not-an-address')->willReturn(false);
		$this->mailer->expects($this->never())->method('send');

		$this->assertSame(
			self::error('invalid_argument', 'The recipient address is not a valid email.'),
			$this->provider->invokeTool('hermiq.sendMail', ['to' => ' not-an-address ', 'subject' => 'Hi', 'body' => 'Text'])
		);
	}//end testSendMailRefusesAnInvalidRecipient()

	public function testSendMailRefusesAUserWithoutAnAddress(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->user->method('getEMailAddress')->willReturn('');
		$this->mailer->expects($this->never())->method('createMessage');

		$this->assertSame(
			self::error('no_sender', 'Your account has no email address to send from.'),
			$this->provider->invokeTool('hermiq.sendMail', ['to' => 'bob@test.test', 'subject' => 'Hi', 'body' => 'Text'])
		);
	}//end testSendMailRefusesAUserWithoutAnAddress()

	public function testSendMailSendsFromTheActingUser(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->user->method('getEMailAddress')->willReturn('alice@test.test');
		$this->user->method('getDisplayName')->willReturn('Alice');
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setFrom')->with(['alice@test.test' => 'Alice'])->willReturnSelf();
		$message->expects($this->once())->method('setTo')->with(['bob@test.test'])->willReturnSelf();
		$message->expects($this->once())->method('setSubject')->with('Hi')->willReturnSelf();
		$message->expects($this->once())->method('setPlainBody')->with('Text')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$this->assertSame(
			['sent' => true, 'failedRecipients' => []],
			$this->provider->invokeTool('hermiq.sendMail', ['to' => 'bob@test.test', 'subject' => 'Hi', 'body' => 'Text'])
		);
	}//end testSendMailSendsFromTheActingUser()

	public function testSendMailReportsFailedRecipients(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->user->method('getEMailAddress')->willReturn('alice@test.test');
		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->method('send')->willReturn(['bob@test.test']);

		$this->assertSame(
			['sent' => false, 'failedRecipients' => ['bob@test.test']],
			$this->provider->invokeTool('hermiq.sendMail', ['to' => 'bob@test.test', 'subject' => 'Hi', 'body' => 'Text'])
		);
	}//end testSendMailReportsFailedRecipients()

	public function testListDeckBoardsWithoutDeckSaysSo(): void {
		$this->appManager->method('isEnabledForUser')->with('deck')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$this->assertSame(
			self::error('deck_unavailable', 'The Deck app is not installed or enabled.'),
			$this->provider->invokeTool('hermiq.listDeckBoards', [])
		);
	}//end testListDeckBoardsWithoutDeckSaysSo()

	public function testListDeckBoardsLogsAndReportsAnUnreachableBoardService(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->container->method('get')->with('OCA\\Deck\\Service\\BoardService')->willThrowException(new RuntimeException('no deck'));
		$this->logger->expects($this->once())->method('warning')->with('Hermiq Deck board listing failed: no deck');

		$this->assertSame(
			self::error('deck_unavailable', 'Could not read Deck boards.'),
			$this->provider->invokeTool('hermiq.listDeckBoards', [])
		);
	}//end testListDeckBoardsLogsAndReportsAnUnreachableBoardService()

	public function testWebSearchRequiresAQuery(): void {
		$this->webSearch->expects($this->never())->method('search');

		$this->assertSame(
			self::error('invalid_argument', 'A search query is required.'),
			$this->provider->invokeTool('hermiq.webSearch', [])
		);
	}//end testWebSearchRequiresAQuery()

	public function testWebSearchForwardsTheQueryAndActingUser(): void {
		$this->webSearch->expects($this->once())
			->method('search')
			->with('nextcloud', 'alice')
			->willReturn(['results' => [['title' => 'NC']]]);

		$this->assertSame(
			['results' => [['title' => 'NC']]],
			$this->provider->invokeTool('hermiq.webSearch', ['query' => 'nextcloud'])
		);
	}//end testWebSearchForwardsTheQueryAndActingUser()

	public function testWebFetchRequiresAUrl(): void {
		$this->webFetch->expects($this->never())->method('fetch');

		$this->assertSame(
			self::error('invalid_argument', 'A URL is required.'),
			$this->provider->invokeTool('hermiq.webFetch', ['url' => ' '])
		);
	}//end testWebFetchRequiresAUrl()

	public function testWebFetchForwardsTheUrl(): void {
		$this->webFetch->expects($this->once())->method('fetch')->with('https://example.test/')->willReturn(['text' => 'page']);

		$this->assertSame(['text' => 'page'], $this->provider->invokeTool('hermiq.webFetch', ['url' => 'https://example.test/']));
	}//end testWebFetchForwardsTheUrl()

	public function testDelegateAgentNeedsAnAgentContext(): void {
		$this->delegation->expects($this->never())->method('delegate');

		$result = $this->provider->invokeTool('hermiq.delegateAgent', ['targetAgentId' => 't', 'task' => 'do it']);

		$this->assertSame('no_agent_context', $result['error']['code']);
	}//end testDelegateAgentNeedsAnAgentContext()

	public function testDelegateAgentNeedsATarget(): void {
		$this->delegation->expects($this->never())->method('delegate');

		$this->assertSame(
			self::error('invalid_argument', 'targetAgentId is required.'),
			$this->provider->invokeTool('hermiq.delegateAgent', ['agentId' => 'a', 'task' => 'do it'])
		);
	}//end testDelegateAgentNeedsATarget()

	public function testDelegateAgentNeedsATask(): void {
		$this->delegation->expects($this->never())->method('delegate');

		$this->assertSame(
			self::error('invalid_argument', 'A non-empty task is required.'),
			$this->provider->invokeTool('hermiq.delegateAgent', ['agentId' => 'a', 'targetAgentId' => 't', 'task' => ' '])
		);
	}//end testDelegateAgentNeedsATask()

	public function testDelegateAgentForwardsTrimmedArguments(): void {
		$this->delegation->expects($this->once())
			->method('delegate')
			->with('caller', 'target', 'summarise')
			->willReturn(['result' => 'done']);

		$this->assertSame(
			['result' => 'done'],
			$this->provider->invokeTool('hermiq.delegateAgent', ['agentId' => ' caller ', 'targetAgentId' => ' target ', 'task' => ' summarise '])
		);
	}//end testDelegateAgentForwardsTrimmedArguments()

	public function testNoteAndMailReadToolsActAsTheActingUser(): void {
		$this->writes->expects($this->once())->method('listNotes')->with('alice')->willReturn(['notes' => []]);
		$this->writes->expects($this->once())->method('createNote')->with('alice', ['title' => 'N'])->willReturn(['created' => 1]);
		$this->writes->expects($this->once())->method('updateNote')->with('alice', ['id' => 1])->willReturn(['updated' => 1]);
		$this->mailReads->expects($this->once())->method('listAccounts')->with('alice')->willReturn(['accounts' => []]);
		$this->mailReads->expects($this->once())->method('listMessages')->with('alice', ['accountId' => 2])->willReturn(['messages' => []]);
		$this->mailReads->expects($this->once())->method('readMessage')->with('alice', ['id' => 3])->willReturn(['message' => 'm']);

		$this->assertSame(['notes' => []], $this->provider->invokeTool('hermiq.listNotes', []));
		$this->assertSame(['created' => 1], $this->provider->invokeTool('hermiq.createNote', ['title' => 'N']));
		$this->assertSame(['updated' => 1], $this->provider->invokeTool('hermiq.updateNote', ['id' => 1]));
		$this->assertSame(['accounts' => []], $this->provider->invokeTool('hermiq.listMailAccounts', []));
		$this->assertSame(['messages' => []], $this->provider->invokeTool('hermiq.listMailMessages', ['accountId' => 2]));
		$this->assertSame(['message' => 'm'], $this->provider->invokeTool('hermiq.readMailMessage', ['id' => 3]));
	}//end testNoteAndMailReadToolsActAsTheActingUser()

	public function testAttributedWritesRecordTheInjectedAgent(): void {
		$args = ['summary' => 'Meet', 'agentId' => 'agent-7'];
		$this->writes->expects($this->once())->method('createCalendarEvent')->with('alice', $args, 'agent-7')->willReturn(['event' => 1]);
		$this->writes->expects($this->once())->method('upsertContact')->with('alice', ['fn' => 'B'], '')->willReturn(['contact' => 1]);

		$this->assertSame(['event' => 1], $this->provider->invokeTool('hermiq.createCalendarEvent', $args));
		$this->assertSame(['contact' => 1], $this->provider->invokeTool('hermiq.upsertContact', ['fn' => 'B']));
	}//end testAttributedWritesRecordTheInjectedAgent()

	public function testToolAccessToolsForwardTheAgentAndArguments(): void {
		$this->accessRequests->expects($this->once())
			->method('listAvailable')
			->with('alice', 'agent-1', 'mail')
			->willReturn(['tools' => []]);
		$this->accessRequests->expects($this->once())
			->method('request')
			->with('alice', 'agent-1', 'hermiq.sendMail', 'to reply')
			->willReturn(['requested' => true]);

		$this->assertSame(['tools' => []], $this->provider->invokeTool('hermiq.listAvailableTools', ['agentId' => 'agent-1', 'query' => 'mail']));
		$this->assertSame(
			['requested' => true],
			$this->provider->invokeTool('hermiq.requestToolAccess', ['agentId' => 'agent-1', 'toolId' => 'hermiq.sendMail', 'reason' => 'to reply'])
		);
	}//end testToolAccessToolsForwardTheAgentAndArguments()

	public function testSearchToolsIsNotInvocableThroughTheFacade(): void {
		$this->assertSame(
			self::error('internal_only', 'hermiq.searchTools is handled internally by the Hermiq engine and is not invocable via the facade.'),
			$this->provider->invokeTool('hermiq.searchTools', ['query' => 'x'])
		);
	}//end testSearchToolsIsNotInvocableThroughTheFacade()

	public function testAThrowingToolBecomesAToolFailedEnvelope(): void {
		$this->writes->method('listNotes')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('Hermiq nc-native tool failed: db down', $this->callback(static fn (array $context): bool => $context['tool'] === 'hermiq.listNotes'));

		$this->assertSame(
			self::error('tool_failed', 'The tool call failed.'),
			$this->provider->invokeTool('hermiq.listNotes', [])
		);
	}//end testAThrowingToolBecomesAToolFailedEnvelope()

	public function testAnUnknownToolNamesTheAvailableIds(): void {
		$result = $this->provider->invokeTool('hermiq.nope', []);

		$this->assertSame('unknown_tool', $result['error']['code']);
		$this->assertStringStartsWith("Unknown tool id 'hermiq.nope'. Available: hermiq.listFiles, hermiq.readFile, ", $result['error']['message']);
		$this->assertStringEndsWith('hermiq.delegateAgent.', $result['error']['message']);
	}//end testAnUnknownToolNamesTheAvailableIds()
}//end class
