<?php

/**
 * Hermiq AgentBuildService.
 *
 * Publishes an agent build and starts new session workspaces from the pinned
 * one. A build records the agent version current at publish time, an archive of
 * the owner's workspace in one conversation and the installed tools; publishing
 * pins it on the agent (`Agent.pinnedBuildId`). Only files and tools are frozen:
 * the prompt and model stay live, and agent versions record them (design D6).
 *
 * Builds are AgentBuild objects that only an admin reads through the object API,
 * so this service reads and writes them with RBAC off; every caller checks access
 * to the agent first (AgentVersionController, McpRunController, ScheduleService).
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Workspace\SessionWorkspaceKey;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceSnapshot;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publish, list and start from agent builds.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) carried in with this batch's feature work; splitting it is
 * follow-up work, not part of this merge.
 */
class AgentBuildService {

	/**
	 * The register holding agents and builds.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The agent schema.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The build schema.
	 *
	 * @var string
	 */
	public const BUILD_SCHEMA = 'agentbuild';

	/**
	 * The longest note a build keeps.
	 *
	 * @var int
	 */
	public const MAX_NOTE_LENGTH = 500;

	/**
	 * The most builds read for one agent.
	 *
	 * @var int
	 */
	private const MAX_BUILDS = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService OpenRegister object read and write.
	 * @param AgentVersionService         $versions      The agent's version timeline.
	 * @param WorkspaceSnapshot           $snapshots     Workspace archives.
	 * @param ServerSideWorkspaceProvider $workspaces    The governed workspaces.
	 * @param ITimeFactory                $time          The clock.
	 * @param LoggerInterface             $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentVersionService $versions,
		private readonly WorkspaceSnapshot $snapshots,
		private readonly ServerSideWorkspaceProvider $workspaces,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a build from the publisher's workspace in one conversation and pin it.
	 *
	 * The caller has checked that the user may modify the agent. The workspace is
	 * the one keyed by this agent, this conversation and this user, so a publisher
	 * can only ever archive their own workspace.
	 *
	 * @param ObjectEntity $agent     The agent.
	 * @param string       $sessionId The publisher's conversation.
	 * @param string       $userId    The publisher.
	 * @param string       $note      The publisher's note.
	 *
	 * @return array<string, mixed> The build as the agent page shows it.
	 *
	 * @throws WorkspaceException When the conversation has no workspace, or it cannot be archived.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SessionWorkspaceKey is used through its static API (a pure helper
	 * or named constructor) with no state to inject.
	 */
	public function publish(ObjectEntity $agent, string $sessionId, string $userId, string $note): array {
		$agentUuid = (string)$agent->getUuid();
		$key = SessionWorkspaceKey::compose(agentId: $agentUuid, conversationId: $sessionId, userId: $userId);
		if ($key === null || $this->workspaces->exists(runKey: $key) === false) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::WORKSPACE_ABSENT,
				message: 'This conversation has no workspace to publish.'
			);
		}

		$archive = $this->snapshots->archive(workspaceKey: $key);
		$payload = [
			'agentId' => $agentUuid,
			'number' => ($this->highestNumber(agentUuid: $agentUuid) + 1),
			'agentVersionId' => $this->versions->currentVersionId(agentUuid: $agentUuid),
			'workspaceArchive' => [
				'contentHash' => $archive['contentHash'],
				'files' => $archive['files'],
				'repository' => $archive['repository'],
				'ref' => $archive['ref'],
			],
			// Installed tools are recorded here once the installer exists; until then
			// a build pins files only (tools-agent-workspace-and-installed-tools task 4).
			'tools' => [],
			'sourceSessionId' => $sessionId,
			'note' => mb_substr(trim($note), 0, self::MAX_NOTE_LENGTH),
			'createdBy' => $userId,
			'createdAt' => (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'))->format('c'),
		];

		$build = $this->objectService->saveObject(
			object: $payload,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::BUILD_SCHEMA)),
			_rbac: false,
			_multitenancy: false
		);

		$this->pin(agent: $agent, buildId: (string)$build->getUuid());
		return $this->view(build: $build);
	}//end publish()

	/**
	 * The agent's builds, newest first, with the pin and whether the agent changed since it.
	 *
	 * @param ObjectEntity $agent The agent the caller may read.
	 *
	 * @return array{builds: array<int, array<string, mixed>>, pinnedBuildId: ?string, pinnedBuildNumber: ?int, unpublishedChanges: bool}
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-edits-after-publishing-are-visible-to-the-owner
	 */
	public function overview(ObjectEntity $agent): array {
		$agentUuid = (string)$agent->getUuid();
		$builds = array_map(fn (ObjectEntity $build): array => $this->view(build: $build), $this->buildsOf(agentUuid: $agentUuid));

		$pinnedId = (string)($agent->getObject()['pinnedBuildId'] ?? '');
		$pinned = null;
		foreach ($builds as $build) {
			if ($build['id'] === $pinnedId) {
				$pinned = $build;
				break;
			}
		}

		return [
			'builds' => $builds,
			'pinnedBuildId' => $pinned['id'] ?? null,
			'pinnedBuildNumber' => $pinned['number'] ?? null,
			'unpublishedChanges' => $pinned !== null && $this->changedSince(agentUuid: $agentUuid, versionId: $pinned['agentVersionId']),
		];
	}//end overview()

	/**
	 * After a rollback: pin the newest build of the version rolled back to.
	 *
	 * When that version has no build the current pin stays, and the result says so.
	 *
	 * @param ObjectEntity $agent     The agent as saved by the rollback.
	 * @param string       $versionId The version rolled back to.
	 *
	 * @return array{pinned: bool, buildId: ?string, buildNumber: ?int}
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-rolling-back-re-pins-the-matching-build-req-build-003
	 */
	public function repinForVersion(ObjectEntity $agent, string $versionId): array {
		foreach ($this->buildsOf(agentUuid: (string)$agent->getUuid()) as $build) {
			if ((string)($build->getObject()['agentVersionId'] ?? '') !== $versionId) {
				continue;
			}

			$this->pin(agent: $agent, buildId: (string)$build->getUuid());
			return ['pinned' => true, 'buildId' => (string)$build->getUuid(), 'buildNumber' => (int)($build->getObject()['number'] ?? 0)];
		}

		$current = (string)($agent->getObject()['pinnedBuildId'] ?? '');
		if ($current === '') {
			$current = null;
		}

		return ['pinned' => false, 'buildId' => $current, 'buildNumber' => null];
	}//end repinForVersion()

	/**
	 * Start a new session workspace from the agent's pinned build.
	 *
	 * Does nothing when the key is not a session key, the agent pins no build, a
	 * workspace is already open under the key, or the pinned build belongs to
	 * another agent. Never fatal: a build that cannot be restored is logged and the
	 * run goes on without it.
	 *
	 * @param ObjectEntity $agent        The run's agent.
	 * @param string       $workspaceKey The run's workspace key.
	 *
	 * @return string|null The build the workspace was started from, or null.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SessionWorkspaceKey is used through its static API (a pure helper
	 * or named constructor) with no state to inject.
	 */
	public function startWorkspace(ObjectEntity $agent, string $workspaceKey): ?string {
		$buildId = (string)($agent->getObject()['pinnedBuildId'] ?? '');
		if ($buildId === '' || SessionWorkspaceKey::isSession(key: $workspaceKey) === false) {
			return null;
		}

		try {
			if ($this->workspaces->exists(runKey: $workspaceKey) === true) {
				return null;
			}

			$build = $this->findBuild(buildId: $buildId);
			$data = $build?->getObject() ?? [];
			if ($build === null || (string)($data['agentId'] ?? '') !== (string)$agent->getUuid()) {
				$this->logger->warning('Hermiq: agent ' . (string)$agent->getUuid() . ' pins a build that is not its own; the workspace starts empty.');
				return null;
			}

			$archive = (array)($data['workspaceArchive'] ?? []);
			$this->snapshots->restore(
				workspaceKey: $workspaceKey,
				contentHash: (string)($archive['contentHash'] ?? ''),
				origin: ['repository' => (string)($archive['repository'] ?? ''), 'ref' => (string)($archive['ref'] ?? ''), 'buildId' => $buildId]
			);
			return $buildId;
		} catch (Throwable $e) {
			$this->logger->error('Hermiq: the pinned build could not start the workspace: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}//end try
	}//end startWorkspace()

	/**
	 * The build an agent pins now, for the run record. Never fatal.
	 *
	 * @param string $agentUuid The agent.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002
	 */
	public function pinnedBuildIdOf(string $agentUuid): ?string {
		if ($agentUuid === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(
				id: $agentUuid,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		$buildId = (string)(($agent?->getObject() ?? [])['pinnedBuildId'] ?? '');
		if ($buildId === '') {
			return null;
		}

		return $buildId;
	}//end pinnedBuildIdOf()

	/**
	 * Whether any versioned field differs between a version and the current one.
	 *
	 * A version that is no longer in the timeline counts as changed.
	 *
	 * @param string      $agentUuid The agent.
	 * @param string|null $versionId The build's version.
	 *
	 * @return bool
	 */
	private function changedSince(string $agentUuid, ?string $versionId): bool {
		$current = $this->versions->currentVersionId(agentUuid: $agentUuid);
		if ($versionId === null || $versionId === '' || $current === null) {
			return true;
		}

		if ($current === $versionId) {
			return false;
		}

		try {
			return $this->versions->diff(agentUuid: $agentUuid, fromId: $versionId, toId: $current) !== [];
		} catch (Throwable $e) {
			return true;
		}
	}//end changedSince()

	/**
	 * Pin a build on the agent.
	 *
	 * RBAC off: `pinnedBuildId` takes an admin-only update rule through the object
	 * API, and the callers checked that the user may modify the agent.
	 *
	 * @param ObjectEntity $agent   The agent.
	 * @param string       $buildId The build.
	 *
	 * @return void
	 */
	private function pin(ObjectEntity $agent, string $buildId): void {
		$data = $agent->getObject();
		$data['pinnedBuildId'] = $buildId;
		$this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA),
				uuid: (string)$agent->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end pin()

	/**
	 * The agent's builds, newest first.
	 *
	 * @param string $agentUuid The agent.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function buildsOf(string $agentUuid): array {
		$found = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::BUILD_SCHEMA)
			->findAll(config: ['filters' => ['agentId' => $agentUuid], 'limit' => self::MAX_BUILDS], _rbac: false, _multitenancy: false);

		$builds = array_values(
			array_filter(
				$found,
				static fn (mixed $build): bool => $build instanceof ObjectEntity && (string)($build->getObject()['agentId'] ?? '') === $agentUuid
			)
		);
		usort(
			$builds,
			static fn (ObjectEntity $left, ObjectEntity $right): int => (int)($right->getObject()['number'] ?? 0) <=> (int)($left->getObject()['number'] ?? 0)
		);
		return $builds;
	}//end buildsOf()

	/**
	 * The highest build number the agent has, 0 for none.
	 *
	 * @param string $agentUuid The agent.
	 *
	 * @return int
	 */
	private function highestNumber(string $agentUuid): int {
		$builds = $this->buildsOf(agentUuid: $agentUuid);
		if ($builds === []) {
			return 0;
		}

		return (int)($builds[0]->getObject()['number'] ?? 0);
	}//end highestNumber()

	/**
	 * One build by uuid, or null.
	 *
	 * @param string $buildId The build.
	 *
	 * @return ObjectEntity|null
	 */
	private function findBuild(string $buildId): ?ObjectEntity {
		return $this->objectService->find(
			id: $buildId,
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::BUILD_SCHEMA),
			_rbac: false,
			_multitenancy: false
		);
	}//end findBuild()

	/**
	 * A build as the agent page shows it: never the archive's location.
	 *
	 * @param ObjectEntity $build The build.
	 *
	 * @return array<string, mixed>
	 */
	private function view(ObjectEntity $build): array {
		$data = $build->getObject();
		$archive = (array)($data['workspaceArchive'] ?? []);
		$versionId = null;
		if (isset($data['agentVersionId']) === true) {
			$versionId = (string)$data['agentVersionId'];
		}

		return [
			'id' => (string)$build->getUuid(),
			'number' => (int)($data['number'] ?? 0),
			'agentVersionId' => $versionId,
			'files' => array_values((array)($archive['files'] ?? [])),
			'tools' => array_values((array)($data['tools'] ?? [])),
			'note' => (string)($data['note'] ?? ''),
			'createdBy' => (string)($data['createdBy'] ?? ''),
			'createdAt' => (string)($data['createdAt'] ?? ''),
		];
	}//end view()
}//end class
