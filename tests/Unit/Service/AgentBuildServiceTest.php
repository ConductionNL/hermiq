<?php

/**
 * AgentBuildService (tools-agent-workspace-and-installed-tools, task 6).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentBuildService;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceSnapshot;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publish, overview, re-pin, start-from-build and the run record's build.
 *
 * @covers \OCA\Hermiq\Service\AgentBuildService
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class AgentBuildServiceTest extends TestCase {

	private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private ObjectService&MockObject $objects;

	private AgentVersionService&MockObject $versions;

	private WorkspaceSnapshot&MockObject $snapshots;

	private ServerSideWorkspaceProvider&MockObject $workspaces;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every saveObject call: [schema, object, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: ?string, 3: bool}>
	 */
	private array $saved = [];

	/**
	 * The builds findAll returns.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $builds = [];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturnCallback(fn (): array => $this->builds);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options, bool $_rbac = true): ObjectEntity {
				$uuid = $options->uuid;
				$this->saved[] = [(string)$options->target->schema, (array)$object, $uuid, $_rbac];
				return self::entity(uuid: $uuid ?? 'build-new', data: (array)$object);
			}
		);
		$this->versions = $this->createMock(AgentVersionService::class);
		$this->snapshots = $this->createMock(WorkspaceSnapshot::class);
		$this->workspaces = $this->createMock(ServerSideWorkspaceProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function service(): AgentBuildService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);
		return new AgentBuildService(
			objectService: $this->objects,
			versions: $this->versions,
			snapshots: $this->snapshots,
			workspaces: $this->workspaces,
			time: $time,
			logger: $this->logger
		);
	}//end service()

	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	private static function build(string $uuid, int $number, string $versionId, string $agentId = 'agent-1'): ObjectEntity {
		return self::entity(
			uuid: $uuid,
			data: [
				'agentId' => $agentId,
				'number' => $number,
				'agentVersionId' => $versionId,
				'workspaceArchive' => ['contentHash' => self::HASH, 'files' => [['path' => 'a.md', 'size' => 3]], 'repository' => 'org/app', 'ref' => 'main'],
				'tools' => [],
				'note' => 'Build ' . $number,
				'createdBy' => 'alice',
				'createdAt' => '2026-01-01T00:00:00+00:00',
			]
		);
	}//end build()

	public function testPublishArchivesThePublishersWorkspaceAndPinsTheNextBuild(): void {
		$this->builds = [self::build(uuid: 'build-2', number: 2, versionId: 'v2'), self::build(uuid: 'build-1', number: 1, versionId: 'v1')];
		$this->workspaces->expects(self::once())->method('exists')->with('session:agent-1:conv-9:alice')->willReturn(true);
		$this->snapshots->expects(self::once())->method('archive')->with('session:agent-1:conv-9:alice')->willReturn(
			['contentHash' => self::HASH, 'files' => [['path' => 'sjablonen/kwartaal.md', 'size' => 11], ['path' => 'sjablonen/bijlage.csv', 'size' => 4]], 'repository' => 'org/app', 'ref' => 'main']
		);
		$this->versions->method('currentVersionId')->with('agent-1')->willReturn('v3');
		$agent = self::entity(uuid: 'agent-1', data: ['name' => 'Data-analist', 'prompt' => 'p']);

		$view = $this->service()->publish(agent: $agent, sessionId: 'conv-9', userId: 'alice', note: '  Sjablonen kwartaalrapportage ');

		self::assertCount(2, $this->saved);
		[$schema, $build, $uuid, $rbac] = $this->saved[0];
		self::assertSame('agentbuild', $schema);
		self::assertNull($uuid);
		self::assertFalse($rbac);
		self::assertSame('agent-1', $build['agentId']);
		self::assertSame(3, $build['number']);
		self::assertSame('v3', $build['agentVersionId']);
		self::assertSame(self::HASH, $build['workspaceArchive']['contentHash']);
		self::assertSame('conv-9', $build['sourceSessionId']);
		self::assertSame('Sjablonen kwartaalrapportage', $build['note']);
		self::assertSame('alice', $build['createdBy']);
		self::assertSame('2026-01-01T00:00:00+00:00', $build['createdAt']);

		self::assertSame(['agent', ['id' => 'agent-1', 'name' => 'Data-analist', 'prompt' => 'p', 'pinnedBuildId' => 'build-new'], 'agent-1', false], $this->saved[1]);

		self::assertSame('build-new', $view['id']);
		self::assertSame(3, $view['number']);
		self::assertCount(2, $view['files']);
		self::assertArrayNotHasKey('workspaceArchive', $view, 'The archive location never leaves the service.');
	}//end testPublishArchivesThePublishersWorkspaceAndPinsTheNextBuild()

	public function testTheFirstBuildIsNumberOne(): void {
		$this->workspaces->method('exists')->willReturn(true);
		$this->snapshots->method('archive')->willReturn(['contentHash' => self::HASH, 'files' => [], 'repository' => '', 'ref' => '']);

		$view = $this->service()->publish(agent: self::entity(uuid: 'agent-1', data: []), sessionId: 'conv-1', userId: 'alice', note: '');

		self::assertSame(1, $view['number']);
	}//end testTheFirstBuildIsNumberOne()

	public function testPublishWithoutAWorkspaceSavesNothing(): void {
		$this->workspaces->method('exists')->willReturn(false);
		$this->snapshots->expects(self::never())->method('archive');

		try {
			$this->service()->publish(agent: self::entity(uuid: 'agent-1', data: []), sessionId: 'conv-1', userId: 'alice', note: '');
			self::fail('A build was published without a workspace.');
		} catch (WorkspaceException $e) {
			self::assertSame('This conversation has no workspace to publish.', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testPublishWithoutAWorkspaceSavesNothing()

	public function testAConversationIdThatCannotKeyAWorkspaceIsRefused(): void {
		$this->workspaces->expects(self::never())->method('exists');

		$this->expectException(WorkspaceException::class);
		$this->service()->publish(agent: self::entity(uuid: 'agent-1', data: []), sessionId: 'conv:other', userId: 'alice', note: '');
	}//end testAConversationIdThatCannotKeyAWorkspaceIsRefused()

	public function testOverviewSaysUnpublishedChangesWhenAVersionedFieldChangedSinceThePin(): void {
		$this->builds = [self::build(uuid: 'build-2', number: 2, versionId: 'v2'), self::build(uuid: 'build-3', number: 3, versionId: 'v3')];
		$this->versions->method('currentVersionId')->willReturn('v5');
		$this->versions->expects(self::once())->method('diff')->with('agent-1', 'v3', 'v5')->willReturn(['prompt' => ['old' => 'a', 'new' => 'b']]);

		$overview = $this->service()->overview(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']));

		self::assertSame([3, 2], array_column($overview['builds'], 'number'));
		self::assertSame('build-3', $overview['pinnedBuildId']);
		self::assertSame(3, $overview['pinnedBuildNumber']);
		self::assertTrue($overview['unpublishedChanges']);
	}//end testOverviewSaysUnpublishedChangesWhenAVersionedFieldChangedSinceThePin()

	public function testOverviewSaysNothingChangedWhenOnlyUnversionedFieldsChanged(): void {
		$this->builds = [self::build(uuid: 'build-3', number: 3, versionId: 'v3')];
		$this->versions->method('currentVersionId')->willReturn('v4');
		$this->versions->method('diff')->willReturn([]);

		$overview = $this->service()->overview(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']));

		self::assertFalse($overview['unpublishedChanges']);
	}//end testOverviewSaysNothingChangedWhenOnlyUnversionedFieldsChanged()

	public function testOverviewCountsAVersionLeftTheTimelineAsChanged(): void {
		$this->builds = [self::build(uuid: 'build-3', number: 3, versionId: 'v3')];
		$this->versions->method('currentVersionId')->willReturn('v4');
		$this->versions->method('diff')->willThrowException(new RuntimeException('Unknown agent version id.'));

		self::assertTrue($this->service()->overview(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']))['unpublishedChanges']);
	}//end testOverviewCountsAVersionLeftTheTimelineAsChanged()

	public function testOverviewIgnoresAPinThatIsNotOneOfTheAgentsBuilds(): void {
		$this->builds = [self::build(uuid: 'build-x', number: 1, versionId: 'v1', agentId: 'agent-2')];
		$this->versions->expects(self::never())->method('diff');

		$overview = $this->service()->overview(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-x']));

		self::assertSame([], $overview['builds']);
		self::assertNull($overview['pinnedBuildId']);
		self::assertFalse($overview['unpublishedChanges']);
	}//end testOverviewIgnoresAPinThatIsNotOneOfTheAgentsBuilds()

	public function testARollbackToTheVersionOfBuildTwoPinsBuildTwo(): void {
		$this->builds = [self::build(uuid: 'build-3', number: 3, versionId: 'v3'), self::build(uuid: 'build-2', number: 2, versionId: 'v2')];

		$result = $this->service()->repinForVersion(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), versionId: 'v2');

		self::assertSame(['pinned' => true, 'buildId' => 'build-2', 'buildNumber' => 2], $result);
		self::assertSame(['agent', ['id' => 'agent-1', 'pinnedBuildId' => 'build-2'], 'agent-1', false], $this->saved[0]);
	}//end testARollbackToTheVersionOfBuildTwoPinsBuildTwo()

	public function testARollbackToAVersionWithoutABuildKeepsThePin(): void {
		$this->builds = [self::build(uuid: 'build-3', number: 3, versionId: 'v3')];

		$result = $this->service()->repinForVersion(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), versionId: 'v1');

		self::assertSame(['pinned' => false, 'buildId' => 'build-3', 'buildNumber' => null], $result);
		self::assertSame([], $this->saved);
	}//end testARollbackToAVersionWithoutABuildKeepsThePin()

	public function testANewConversationStartsFromThePinnedBuild(): void {
		$this->objects->method('find')->willReturn(self::build(uuid: 'build-3', number: 3, versionId: 'v3'));
		$this->workspaces->method('exists')->willReturn(false);
		$this->snapshots->expects(self::once())->method('restore')->with(
			'session:agent-1:conv-1:bob',
			self::HASH,
			['repository' => 'org/app', 'ref' => 'main', 'buildId' => 'build-3']
		);

		$started = $this->service()->startWorkspace(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), workspaceKey: 'session:agent-1:conv-1:bob');

		self::assertSame('build-3', $started);
	}//end testANewConversationStartsFromThePinnedBuild()

	public function testAnOpenWorkspaceIsLeftAsItIs(): void {
		$this->workspaces->method('exists')->willReturn(true);
		$this->snapshots->expects(self::never())->method('restore');

		self::assertNull($this->service()->startWorkspace(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), workspaceKey: 'session:agent-1:conv-1:bob'));
	}//end testAnOpenWorkspaceIsLeftAsItIs()

	public function testARunKeyedWorkspaceNeverStartsFromABuild(): void {
		$this->workspaces->expects(self::never())->method('exists');
		$this->snapshots->expects(self::never())->method('restore');

		self::assertNull($this->service()->startWorkspace(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), workspaceKey: 'run-1'));
	}//end testARunKeyedWorkspaceNeverStartsFromABuild()

	public function testABuildOfAnotherAgentIsNeverRestored(): void {
		$this->objects->method('find')->willReturn(self::build(uuid: 'build-x', number: 1, versionId: 'v1', agentId: 'agent-2'));
		$this->workspaces->method('exists')->willReturn(false);
		$this->snapshots->expects(self::never())->method('restore');
		$this->logger->expects(self::once())->method('warning');

		self::assertNull($this->service()->startWorkspace(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-x']), workspaceKey: 'session:agent-1:conv-1:bob'));
	}//end testABuildOfAnotherAgentIsNeverRestored()

	public function testAFailedRestoreIsLoggedAndNotFatal(): void {
		$this->objects->method('find')->willReturn(self::build(uuid: 'build-3', number: 3, versionId: 'v3'));
		$this->workspaces->method('exists')->willReturn(false);
		$this->snapshots->method('restore')->willThrowException(new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'The build archive could not be read.'));
		$this->logger->expects(self::once())->method('error');

		self::assertNull($this->service()->startWorkspace(agent: self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']), workspaceKey: 'session:agent-1:conv-1:bob'));
	}//end testAFailedRestoreIsLoggedAndNotFatal()

	public function testTheRunRecordGetsTheAgentsPinnedBuild(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			self::entity(uuid: 'agent-1', data: ['pinnedBuildId' => 'build-3']),
			self::entity(uuid: 'agent-2', data: []),
			null
		);
		$service = $this->service();

		self::assertSame('build-3', $service->pinnedBuildIdOf(agentUuid: 'agent-1'));
		self::assertNull($service->pinnedBuildIdOf(agentUuid: 'agent-2'));
		self::assertNull($service->pinnedBuildIdOf(agentUuid: 'agent-3'));
		self::assertNull($service->pinnedBuildIdOf(agentUuid: ''));
	}//end testTheRunRecordGetsTheAgentsPinnedBuild()
}//end class
