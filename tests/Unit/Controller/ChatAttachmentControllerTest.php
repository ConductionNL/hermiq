<?php

/**
 * Unit tests for ChatAttachmentController (hermiq-chat-attachments).
 *
 * Covers the upload endpoint's happy path (folder created on demand, {path, name}
 * returned), the text-decodable/size-cap validation (Task 2), the never-overwrite
 * de-duplication via Folder::getNonExistingName(), filename basename-sanitisation
 * against traversal, the 401/no-write unauthenticated path, and a 500 on a
 * downstream write failure.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatAttachmentController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the chat attachment upload endpoint.
 *
 * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
 */
class ChatAttachmentControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock Files root.
     *
     * @var IRootFolder&MockObject
     */
    private IRootFolder $rootFolder;

    /**
     * Mock user session (alice by default).
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Mock OpenRegister object service (agent reads for uploadFolder).
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Temp files created by a test, cleaned up in tearDown().
     *
     * @var array<int, string>
     */
    private array $tempFiles = [];

    /**
     * Wire fresh mocks before each test; default an authenticated 'alice'.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request       = $this->createMock(IRequest::class);
        $this->rootFolder    = $this->createMock(IRootFolder::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

    }//end setUp()

    /**
     * Remove any temp files created for an uploaded-file fixture.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path) === true) {
                unlink($path);
            }
        }

        parent::tearDown();

    }//end tearDown()

    /**
     * Build the controller wired to the current mocks.
     *
     * @return ChatAttachmentController
     */
    private function controller(): ChatAttachmentController
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                if ($parameters === []) {
                    return $text;
                }

                return vsprintf($text, $parameters);
            }
        );

        return new ChatAttachmentController(
            $this->request,
            $this->rootFolder,
            $this->userSession,
            $l10n,
            $this->logger,
            $this->objectService
        );

    }//end controller()

    /**
     * Write $content to a real temp file and return its path (getUploadedFile()'s
     * `tmp_name` must be a real, readable path — file_get_contents() is not mocked).
     *
     * @param string $content The bytes to write.
     *
     * @return string The temp file path.
     */
    private function tempFileWith(string $content): string
    {
        $path                = (string) tempnam(sys_get_temp_dir(), 'hermiq-attachment-test-');
        $this->tempFiles[] = $path;
        file_put_contents($path, $content);
        return $path;

    }//end tempFileWith()

    /**
     * Stub `IRequest::getUploadedFile('file')` with a `$_FILES`-shaped array.
     *
     * @param string      $content The uploaded content (written to a real temp file).
     * @param string      $name    The uploaded (client-supplied) filename.
     * @param int|null    $size    Reported size (defaults to strlen($content)).
     * @param int         $error   Upload error code (default UPLOAD_ERR_OK).
     *
     * @return void
     */
    private function stubUpload(string $content, string $name='report.txt', ?int $size=null, int $error=UPLOAD_ERR_OK): void
    {
        $tmpPath = $this->tempFileWith(content: $content);
        $this->request->method('getUploadedFile')->with('file')->willReturn(
            [
                'tmp_name' => $tmpPath,
                'name'     => $name,
                'error'    => $error,
                'size'     => ($size ?? strlen($content)),
                'type'     => 'text/plain',
            ]
        );

    }//end stubUpload()

    /**
     * A folder mock wired so `Hermiq/Attachments/` does not yet exist, tracking
     * every call the controller is expected to make against it.
     *
     * @param string $nonExistingNameReturn What `getNonExistingName()` should return
     *                                      (defaults to echoing the requested name —
     *                                      no collision).
     *
     * @return array{userFolder: Folder&MockObject, attachmentsFolder: Folder&MockObject}
     */
    private function freshAttachmentsFolder(?string $nonExistingNameReturn=null): array
    {
        $attachmentsFolder = $this->createMock(Folder::class);
        $attachmentsFolder->method('getNonExistingName')->willReturnCallback(
            static fn (string $name): string => ($nonExistingNameReturn ?? $name)
        );

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('nodeExists')->with('Hermiq/Attachments')->willReturn(false);
        $userFolder->expects($this->once())->method('newFolder')->with('Hermiq/Attachments');
        $userFolder->method('get')->with('Hermiq/Attachments')->willReturn($attachmentsFolder);

        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        return ['userFolder' => $userFolder, 'attachmentsFolder' => $attachmentsFolder];

    }//end freshAttachmentsFolder()

    /**
     * The happy path: a UTF-8 text file is stored under Hermiq/Attachments/
     * (created on demand) and the response is 200 with {path, name}.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    public function testUploadStoresTextFileAndReturnsReference(): void
    {
        $this->stubUpload(content: 'Revenue was 12M', name: 'report.txt');
        // Note: `Folder::verifyPath()` is not part of the OCP v31 interface this
        // app's composer.lock pins (added in NC 32), so it is NOT asserted here —
        // the controller guards the call with `method_exists()` for exactly this
        // reason (see ChatAttachmentController::store()).
        $folders = $this->freshAttachmentsFolder();
        $folders['attachmentsFolder']->expects($this->once())
            ->method('newFile')
            ->with('report.txt', 'Revenue was 12M');

        $response = $this->controller()->upload();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(
            ['path' => 'Hermiq/Attachments/report.txt', 'name' => 'report.txt'],
            $response->getData()
        );

    }//end testUploadStoresTextFileAndReturnsReference()

    /**
     * An upload never overwrites an existing file: the stored `path` reflects
     * `getNonExistingName()`'s de-duplicated name, while the returned `name`
     * stays the ORIGINAL requested filename.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    public function testUploadNeverOverwritesAndDeduplicatesPath(): void
    {
        $this->stubUpload(content: 'Second report', name: 'report.txt');
        $folders = $this->freshAttachmentsFolder(nonExistingNameReturn: 'report (2).txt');
        $folders['attachmentsFolder']->expects($this->once())
            ->method('newFile')
            ->with('report (2).txt', 'Second report');

        $response = $this->controller()->upload();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Hermiq/Attachments/report (2).txt', $response->getData()['path']);
        $this->assertSame('report.txt', $response->getData()['name']);

    }//end testUploadNeverOverwritesAndDeduplicatesPath()

    /**
     * When an `agentId` is given and that agent carries an `uploadFolder`, the
     * file lands in the agent's folder (created on demand) and the returned
     * path reflects it — not the default `Hermiq/Attachments/`.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder
     */
    public function testUploadUsesAgentUploadFolder(): void
    {
        $this->stubUpload(content: 'Agent note', name: 'note.txt');
        $this->request->method('getParam')->with('agentId', '')->willReturn('agent-uuid-1');

        $agent = new ObjectEntity();
        $agent->setUuid('agent-uuid-1');
        $agent->setObject(['uploadFolder' => 'Projects/AgentX']);
        $this->objectService->method('find')->willReturn($agent);

        $attachmentsFolder = $this->createMock(Folder::class);
        $attachmentsFolder->method('getNonExistingName')->willReturnCallback(
            static fn (string $name): string => $name
        );
        $attachmentsFolder->expects($this->once())->method('newFile')->with('note.txt', 'Agent note');

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('nodeExists')->with('Projects/AgentX')->willReturn(false);
        $userFolder->expects($this->once())->method('newFolder')->with('Projects/AgentX');
        $userFolder->method('get')->with('Projects/AgentX')->willReturn($attachmentsFolder);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $response = $this->controller()->upload();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Projects/AgentX/note.txt', $response->getData()['path']);

    }//end testUploadUsesAgentUploadFolder()

    /**
     * A hostile `uploadFolder` (traversal) on the agent is void: the upload
     * falls back to the default `Hermiq/Attachments/` rather than escaping the
     * acting user's Files.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-agent-files/specs/agent-files/spec.md#requirement-chat-attachments-are-stored-in-the-agents-configured-upload-folder
     */
    public function testUploadSanitisesTraversalInAgentFolder(): void
    {
        $this->stubUpload(content: 'Safe', name: 'note.txt');
        $this->request->method('getParam')->with('agentId', '')->willReturn('agent-uuid-1');

        $agent = new ObjectEntity();
        $agent->setUuid('agent-uuid-1');
        $agent->setObject(['uploadFolder' => '../../../../etc/cron.d']);
        $this->objectService->method('find')->willReturn($agent);

        // Falls back to the default folder — proves no traversal reached storage.
        $folders = $this->freshAttachmentsFolder();
        $folders['attachmentsFolder']->expects($this->once())->method('newFile')->with('note.txt', 'Safe');

        $response = $this->controller()->upload();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Hermiq/Attachments/note.txt', $response->getData()['path']);

    }//end testUploadSanitisesTraversalInAgentFolder()

    /**
     * A binary (non-UTF-8) upload is rejected with 400 and nothing is written.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
     */
    public function testUploadRejectsBinaryContent(): void
    {
        // Invalid UTF-8 byte sequence (0xFF is never valid on its own).
        $this->stubUpload(content: "\xFF\xFE\x00binary", name: 'image.png');
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $response = $this->controller()->upload();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testUploadRejectsBinaryContent()

    /**
     * An oversized text file is rejected with 400 (checked from the reported
     * size, before any content read) and nothing is written.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
     */
    public function testUploadRejectsOversizedFile(): void
    {
        $this->stubUpload(content: str_repeat('a', 100), name: 'big.txt', size: 20001);
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $response = $this->controller()->upload();

        $this->assertSame(400, $response->getStatus());

    }//end testUploadRejectsOversizedFile()

    /**
     * No authenticated user: 401, and the upload is never even inspected.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    public function testUploadRequiresAnAuthenticatedUser(): void
    {
        $anonymousSession = $this->createMock(IUserSession::class);
        $anonymousSession->method('getUser')->willReturn(null);
        $this->userSession = $anonymousSession;

        $this->request->expects($this->never())->method('getUploadedFile');

        $response = $this->controller()->upload();

        $this->assertSame(401, $response->getStatus());

    }//end testUploadRequiresAnAuthenticatedUser()

    /**
     * A traversal-style filename is reduced to a basename before it ever
     * reaches the folder write — `../../evil.txt` cannot escape
     * Hermiq/Attachments/.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-uploads-are-restricted-to-text-decodable-files-within-a-size-cap
     */
    public function testUploadSanitizesATraversalFilenameToABasename(): void
    {
        $this->stubUpload(content: 'hostile', name: '../../evil.txt');
        $folders = $this->freshAttachmentsFolder();
        $folders['attachmentsFolder']->expects($this->once())
            ->method('newFile')
            ->with('evil.txt', 'hostile');

        $response = $this->controller()->upload();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Hermiq/Attachments/evil.txt', $response->getData()['path']);

    }//end testUploadSanitizesATraversalFilenameToABasename()

    /**
     * A downstream write failure (quota, storage error) surfaces as a 500,
     * logged, never leaking the raw exception message to the response.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-an-upload-endpoint-stores-an-attachment-in-the-acting-users-nextcloud
     */
    public function testUploadReturns500OnWriteFailure(): void
    {
        $this->stubUpload(content: 'content', name: 'report.txt');
        $folders = $this->freshAttachmentsFolder();
        $folders['attachmentsFolder']->method('newFile')->willThrowException(
            new RuntimeException('quota exceeded')
        );

        $this->logger->expects($this->once())->method('error');

        $response = $this->controller()->upload();

        $this->assertSame(500, $response->getStatus());

    }//end testUploadReturns500OnWriteFailure()
}//end class
