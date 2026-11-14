<?php

/**
 * AgentVersionController's build routes and the re-pin on rollback
 * (tools-agent-workspace-and-installed-tools, task 6).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentVersionController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentVersionServiceInterface;
use OCA\Hermiq\Service\AgentBuildService;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Who may list and publish builds, what they get back, and the rollback re-pin.
 *
 * @covers \OCA\Hermiq\Controller\AgentVersionController
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class AgentVersionControllerBuildsTest extends TestCase {

	private ObjectService&MockObject $objects;

	private AgentAccessServiceInterface&MockObject $access;

	private AgentBuildService&MockObject $builds;

	private AgentVersionServiceInterface&MockObject $versions;

	private ObjectEntity $agent;

	protected function setUp(): void {
		$this->agent = new ObjectEntity();
		$this->agent->setUuid('agent-1');
		$this->agent->setOwner('alice');
		$this->agent->setObject(['name' => 'Data-analist']);

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturn($this->agent);
		$this->access = $this->createMock(AgentAccessServiceInterface::class);
		$this->builds = $this->createMock(AgentBuildService::class);
		$this->versions = $this->createMock(AgentVersionServiceInterface::class);
	}//end setUp()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(array $params = [], bool $withBuilds = true): AgentVersionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['alice', 'Alice de Vries']]);

		return new AgentVersionController(
			request: $request,
			objectService: $this->objects,
			agentVersionService: $this->versions,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			agentAccess: $this->access,
			agentBuilds: ($withBuilds === true ? $this->builds : null),
			userManager: $users
		);
	}//end controller()

	private function canRead(bool $read, bool $modify = false): void {
		$this->access->method('canUserAccessAgent')->willReturn($read);
		$this->access->method('canUserModifyAgent')->willReturn($modify);
	}//end canRead()

	public function testAReaderSeesTheBuildsWithThePublishersName(): void {
		$this->canRead(read: true);
		$this->builds->method('overview')->willReturn(
			[
				'builds' => [['id' => 'build-3', 'number' => 3, 'createdBy' => 'alice', 'files' => [], 'tools' => []]],
				'pinnedBuildId' => 'build-3',
				'pinnedBuildNumber' => 3,
				'unpublishedChanges' => true,
			]
		);

		$response = $this->controller()->builds(id: 'agent-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('Alice de Vries', $data['builds'][0]['createdByName']);
		self::assertTrue($data['unpublishedChanges']);
		self::assertFalse($data['canPublish']);
	}//end testAReaderSeesTheBuildsWithThePublishersName()

	public function testSomeoneWhoMayNotReadTheAgentGets404(): void {
		$this->canRead(read: false);
		$this->builds->expects(self::never())->method('overview');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->builds(id: 'agent-1')->getStatus());
	}//end testSomeoneWhoMayNotReadTheAgentGets404()

	public function testAReaderWhoMayNotModifyCannotPublish(): void {
		$this->canRead(read: true, modify: false);
		$this->builds->expects(self::never())->method('publish');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(params: ['sessionId' => 'conv-1'])->publishBuild(id: 'agent-1')->getStatus());
	}//end testAReaderWhoMayNotModifyCannotPublish()

	public function testSomeoneWhoMayNotReadTheAgentCannotPublishEither(): void {
		$this->canRead(read: false, modify: false);
		$this->builds->expects(self::never())->method('publish');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(params: ['sessionId' => 'conv-1'])->publishBuild(id: 'agent-1')->getStatus());
	}//end testSomeoneWhoMayNotReadTheAgentCannotPublishEither()

	public function testPublishNeedsAConversation(): void {
		$this->canRead(read: true, modify: true);
		$this->builds->expects(self::never())->method('publish');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->publishBuild(id: 'agent-1')->getStatus());
	}//end testPublishNeedsAConversation()

	public function testTheOwnerPublishesFromTheirOwnWorkspace(): void {
		$this->canRead(read: true, modify: true);
		$this->builds->expects(self::once())->method('publish')
			->with($this->agent, 'conv-1', 'alice', 'Sjablonen kwartaalrapportage')
			->willReturn(['id' => 'build-3', 'number' => 3, 'createdBy' => 'alice']);

		$response = $this->controller(params: ['sessionId' => 'conv-1', 'note' => 'Sjablonen kwartaalrapportage'])->publishBuild(id: 'agent-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 'build-3', 'number' => 3, 'createdBy' => 'alice', 'createdByName' => 'Alice de Vries'], $response->getData());
	}//end testTheOwnerPublishesFromTheirOwnWorkspace()

	public function testAConversationWithoutAWorkspaceIsA400WithTheReason(): void {
		$this->canRead(read: true, modify: true);
		$this->builds->method('publish')->willThrowException(new WorkspaceException(errorCode: WorkspaceException::WORKSPACE_ABSENT, message: 'This conversation has no workspace to publish.'));

		$response = $this->controller(params: ['sessionId' => 'conv-1'])->publishBuild(id: 'agent-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'This conversation has no workspace to publish.'], $response->getData());
	}//end testAConversationWithoutAWorkspaceIsA400WithTheReason()

	public function testAnUnexpectedFailureIsA500WithoutDetail(): void {
		$this->canRead(read: true, modify: true);
		$this->builds->method('publish')->willThrowException(new RuntimeException('disk detail'));

		$response = $this->controller(params: ['sessionId' => 'conv-1'])->publishBuild(id: 'agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not publish the build'], $response->getData());
	}//end testAnUnexpectedFailureIsA500WithoutDetail()

	public function testARollbackToAVersionWithABuildRepinsIt(): void {
		$this->canRead(read: true, modify: true);
		$this->versions->method('rollback')->willReturn($this->agent);
		$this->builds->expects(self::once())->method('repinForVersion')->with($this->agent, 'v2')->willReturn(['pinned' => true, 'buildId' => 'build-2', 'buildNumber' => 2]);

		$data = $this->controller()->rollback(id: 'agent-1', versionId: 'v2')->getData();

		self::assertSame('build-2', $data['pinnedBuildId']);
		self::assertSame(['pinned' => true, 'buildId' => 'build-2', 'buildNumber' => 2, 'message' => 'Build 2 is pinned again.'], $data['build']);
	}//end testARollbackToAVersionWithABuildRepinsIt()

	public function testARollbackToAVersionWithoutABuildSaysThePinStays(): void {
		$this->canRead(read: true, modify: true);
		$this->versions->method('rollback')->willReturn($this->agent);
		$this->builds->method('repinForVersion')->willReturn(['pinned' => false, 'buildId' => 'build-3', 'buildNumber' => null]);

		$data = $this->controller()->rollback(id: 'agent-1', versionId: 'v1')->getData();

		self::assertArrayNotHasKey('pinnedBuildId', $data);
		self::assertSame('This version has no build. The pinned build stays as it was.', $data['build']['message']);
	}//end testARollbackToAVersionWithoutABuildSaysThePinStays()

	public function testARollbackStillAnswersWhenTheRepinFails(): void {
		$this->canRead(read: true, modify: true);
		$this->versions->method('rollback')->willReturn($this->agent);
		$this->builds->method('repinForVersion')->willThrowException(new RuntimeException('down'));

		$response = $this->controller()->rollback(id: 'agent-1', versionId: 'v1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertFalse($response->getData()['build']['pinned']);
	}//end testARollbackStillAnswersWhenTheRepinFails()

	public function testWithoutTheBuildServiceTheRollbackAnswerIsUnchanged(): void {
		$this->canRead(read: true, modify: true);
		$this->versions->method('rollback')->willReturn($this->agent);

		$data = $this->controller(withBuilds: false)->rollback(id: 'agent-1', versionId: 'v1')->getData();

		self::assertArrayNotHasKey('build', $data);
		self::assertSame('agent-1', $data['uuid']);
	}//end testWithoutTheBuildServiceTheRollbackAnswerIsUnchanged()
}//end class
