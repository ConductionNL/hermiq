<?php

/**
 * Unit tests for WorkspaceFilesController (tools-agent-workspace-and-installed-tools REQ-WKSP-003).
 *
 * The point under test is whose workspace a call reaches: the key is composed
 * from the session's agent, the session and the signed-in user, and a caller
 * who takes no part in the session is answered 404 without any workspace read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\WorkspaceFilesController;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the chat's Files panel routes.
 *
 * @covers \OCA\Hermiq\Controller\WorkspaceFilesController
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
final class WorkspaceFilesControllerTest extends TestCase {

	/**
	 * The session read double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The workspace files double.
	 *
	 * @var WorkspaceFiles&MockObject
	 */
	private WorkspaceFiles $files;

	/**
	 * Fresh doubles; the session belongs to alice and runs agent-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$session = new ObjectEntity();
		$session->setUuid('conv-1');
		$session->setObject(['userId' => 'alice', 'agentId' => 'agent-1', 'participants' => ['bob']]);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn($session);
		$this->files = $this->createMock(WorkspaceFiles::class);

	}//end setUp()

	/**
	 * The controller for a signed-in user, or for nobody.
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return WorkspaceFilesController
	 */
	private function controller(?string $uid): WorkspaceFilesController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new WorkspaceFilesController(
			$this->createMock(IRequest::class),
			$session,
			$this->objects,
			$this->files,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * The session owner lists the files of their own workspace in the session.
	 *
	 * @return void
	 */
	public function testTheOwnerListsTheirOwnWorkspace(): void {
		$listed = ['files' => [['path' => 'rapportage-q3.pdf', 'size' => 8, 'modified' => 1]], 'truncated' => false];
		$this->files->expects($this->once())->method('list')->with('session:agent-1:conv-1:alice')->willReturn($listed);

		$response = $this->controller('alice')->index('conv-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($listed, $response->getData());

	}//end testTheOwnerListsTheirOwnWorkspace()

	/**
	 * A participant reaches their own workspace in the session, never the owner's.
	 *
	 * @return void
	 */
	public function testAParticipantReachesOnlyTheirOwnWorkspace(): void {
		$this->files->expects($this->once())->method('list')->with('session:agent-1:conv-1:bob')->willReturn(['files' => [], 'truncated' => false]);

		$this->controller('bob')->index('conv-1');

	}//end testAParticipantReachesOnlyTheirOwnWorkspace()

	/**
	 * Another user is refused on every route, and no workspace is touched.
	 *
	 * @return void
	 */
	public function testAnotherUserIsRefusedOnEveryRoute(): void {
		$this->files->expects($this->never())->method('list');
		$this->files->expects($this->never())->method('open');
		$this->files->expects($this->never())->method('saveToFiles');
		$controller = $this->controller('mallory');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index('conv-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->download('conv-1', 'rapportage-q3.pdf')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->save('conv-1', 'rapportage-q3.pdf')->getStatus());
		$this->assertSame(['error' => 'Session not found'], $controller->save('conv-1', 'rapportage-q3.pdf')->getData());

	}//end testAnotherUserIsRefusedOnEveryRoute()

	/**
	 * Nobody signed in, a missing session and a session without an agent are all 404.
	 *
	 * @return void
	 */
	public function testNoUserNoSessionOrNoAgentIsNotFound(): void {
		$this->files->expects($this->never())->method('list');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(null)->index('conv-1')->getStatus());

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->index('conv-1')->getStatus());

		$agentless = new ObjectEntity();
		$agentless->setUuid('conv-1');
		$agentless->setObject(['userId' => 'alice']);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn($agentless);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->index('conv-1')->getStatus());

	}//end testNoUserNoSessionOrNoAgentIsNotFound()

	/**
	 * Downloading answers the file's bytes under its own name.
	 *
	 * @return void
	 */
	public function testDownloadAnswersTheFile(): void {
		$path = tempnam(sys_get_temp_dir(), 'wsdl');
		file_put_contents($path, 'PDFBYTES');
		$this->files->method('open')->with('session:agent-1:conv-1:alice', 'rapportage-q3.pdf')->willReturn($path);

		$response = $this->controller('alice')->download('conv-1', 'rapportage-q3.pdf');
		unlink($path);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('PDFBYTES', $response->render());

	}//end testDownloadAnswersTheFile()

	/**
	 * A file the workspace does not hold is 404 with the reason.
	 *
	 * @return void
	 */
	public function testDownloadingAMissingFileIsNotFound(): void {
		$this->files->method('open')->willThrowException(
			new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file does not exist in the workspace.')
		);

		$response = $this->controller('alice')->download('conv-1', 'nope.txt');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'That file does not exist in the workspace.'], $response->getData());

	}//end testDownloadingAMissingFileIsNotFound()

	/**
	 * Saving lands the file in the caller's own Files and answers 201.
	 *
	 * @return void
	 */
	public function testSavingAnswersTheSavedFile(): void {
		$saved = ['fileId' => 42, 'name' => 'rapportage-q3.pdf', 'path' => 'Hermiq/rapportage-q3.pdf'];
		$this->files->expects($this->once())->method('saveToFiles')
			->with('session:agent-1:conv-1:alice', 'rapportage-q3.pdf', 'alice')
			->willReturn($saved);

		$response = $this->controller('alice')->save('conv-1', 'rapportage-q3.pdf');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame($saved, $response->getData());

	}//end testSavingAnswersTheSavedFile()

	/**
	 * A tag that cannot be applied fails the save.
	 *
	 * @return void
	 */
	public function testAFailedTagFailsTheSave(): void {
		$this->files->method('saveToFiles')->willThrowException(new ArtefactMarkingFailedException('not marked'));

		$response = $this->controller('alice')->save('conv-1', 'rapportage-q3.pdf');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The file was not saved: it could not be marked as written by an agent.'], $response->getData());

	}//end testAFailedTagFailsTheSave()
}//end class
