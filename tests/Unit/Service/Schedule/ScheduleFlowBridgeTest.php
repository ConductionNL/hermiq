<?php

/**
 * Tests the schedule-to-engine delegation bridge.
 *
 * OpenRegister classes resolve to tests/Stubs (signature-matched); the shape
 * mapping, the identity resolution and the sync outcomes are what these
 * tests pin down, because each is a decision design.md records.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Service\Schedule\ScheduleCadenceMapper;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\Schedule\ScheduleFlowPublisher;
use OCA\Hermiq\Service\Schedule\ScheduleMirrorDefinition;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\Flow\FlowSelection;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridge behaviour: mapping, identity, sync outcomes.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleFlowBridgeTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock ScheduleService.
	 *
	 * @var ScheduleService&MockObject
	 */
	private ScheduleService $scheduleService;

	/**
	 * Mock FlowMapper handed out by the container.
	 *
	 * @var FlowMapper&MockObject
	 */
	private FlowMapper $flowMapper;

	/**
	 * Mock version service handed out by the container.
	 *
	 * Its publish() moves the flow to the published lifecycle, exactly as the
	 * real service and the stub do. That is the pin's contract: OpenRegister's
	 * `FlowRunVersionPin` refuses every scheduled dispatch of an unpublished
	 * flow, so the tests assert the flow's lifecycle, and only a real publish
	 * call can put it there. A fake whose publish did nothing would run
	 * unpublished flows and could never fail.
	 *
	 * @var FlowVersionService&MockObject
	 */
	private FlowVersionService $flowVersions;

	/**
	 * The version-service calls made, in order.
	 *
	 * @var array<int,string>
	 */
	private array $versionCalls = [];

	/**
	 * When set, the version service's publish() throws it.
	 *
	 * @var \RuntimeException|null
	 */
	private ?\RuntimeException $publishFailure = null;

	/**
	 * Mock user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Mock config.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	/**
	 * A logger a test steers; a fresh mock otherwise.
	 *
	 * @var LoggerInterface|null
	 */
	private ?LoggerInterface $loggerOverride = null;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->flowMapper = $this->createMock(FlowMapper::class);
		$this->versionCalls = [];
		$this->publishFailure = null;
		$this->flowVersions = $this->createMock(FlowVersionService::class);
		$this->flowVersions->method('publish')->willReturnCallback(
			function (Flow $flow, ?string $publishedBy = null): FlowVersion {
				$this->versionCalls[] = 'publish';
				if ($this->publishFailure !== null) {
					throw $this->publishFailure;
				}

				// The real service's observable effect: the head is published.
				$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
				$version = new FlowVersion();
				$version->setStatus(FlowVersion::STATUS_PUBLISHED);

				return $version;
			}
		);
		$this->flowVersions->method('createDraft')->willReturnCallback(
			function (Flow $flow): FlowVersion {
				$this->versionCalls[] = 'createDraft';
				$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
				$version = new FlowVersion();
				$version->setStatus(FlowVersion::STATUS_DRAFT);

				return $version;
			}
		);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);

		// Default: every uid is a live, enabled user.
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->willReturn($user);

		// Default: the owner's timezone equals the server default, so plain
		// crons are timezone-safe unless a test says otherwise.
		$this->config->method('getUserValue')->willReturn(date_default_timezone_get());
		$this->config->method('getSystemValueString')->willReturn('UTC');
	}//end setUp()

	/**
	 * Build the bridge with the current mocks.
	 *
	 * @return ScheduleFlowBridge
	 */
	private function bridge(?ScheduleCadenceMapper $cadenceMapper = null): ScheduleFlowBridge {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === FlowVersionService::class) {
					return $this->flowVersions;
				}

				return $this->flowMapper;
			}
		);

		return new ScheduleFlowBridge(
			objectService: $this->objectService,
			scheduleService: $this->scheduleService,
			cadenceMapper: ($cadenceMapper ?? new ScheduleCadenceMapper(config: $this->config)),
			container: $container,
			publisher: new ScheduleFlowPublisher(container: $container),
			definition: new ScheduleMirrorDefinition(),
			userManager: $this->userManager,
			logger: ($this->loggerOverride ?? $this->createMock(LoggerInterface::class)),
		);
	}//end bridge()

	/**
	 * Build a schedule entity.
	 *
	 * @param array<string,mixed> $payload The schedule body.
	 * @param string $uuid The uuid.
	 * @param string $owner The owner uid.
	 * @param string $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(
		array $payload,
		string $uuid = 'sched-1',
		string $owner = 'alice',
		string $organisation = 'org-1',
	): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setOrganisation($organisation);
		$entity->setObject($payload);

		return $entity;
	}//end schedule()

	/**
	 * The shape map: crons pass through, intervals derive a cadence, once and
	 * inexpressible intervals answer null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testMappedCronPerShape(): void {
		$bridge = $this->bridge();

		$this->assertSame(
			'0 8 * * *',
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'cron', 'cronExpr' => '0 8 * * *']))
		);
		$this->assertNull(
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'cron', 'cronExpr' => '0 0 8 * * *'])),
			'A 6-field cron has no engine form.'
		);
		$this->assertSame(
			'*/15 * * * *',
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'interval', 'intervalMinutes' => 15]))
		);
		$this->assertSame(
			'0 * * * *',
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'interval', 'intervalMinutes' => 60]))
		);
		$this->assertSame(
			'0 */2 * * *',
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'interval', 'intervalMinutes' => 120]))
		);
		$this->assertNull(
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'interval', 'intervalMinutes' => 90])),
			'90 minutes has no 5-field cron form.'
		);
		$this->assertNull(
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'once', 'runAt' => '2030-01-01T00:00:00+00:00'])),
			'A deadline is FlowTimerService territory, staged.'
		);

	}//end testMappedCronPerShape()

	/**
	 * An hour-anchored cron for an owner in another timezone stays local; a
	 * pure minute cadence migrates regardless of the timezone difference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testTimezoneSensitiveCronStaysLocal(): void {
		// Pick an owner timezone that cannot equal the server default.
		$other = 'Pacific/Chatham';
		if (date_default_timezone_get() === $other) {
			$other = 'Europe/Amsterdam';
		}

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn($other);
		$this->config->method('getSystemValueString')->willReturn('UTC');

		$bridge = $this->bridge();

		$this->assertNull(
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'cron', 'cronExpr' => '0 9 * * *'])),
			'An hour-anchored cron would silently shift; it stays local.'
		);
		$this->assertSame(
			'*/5 * * * *',
			$bridge->mappedCron(schedule: $this->schedule(['kind' => 'cron', 'cronExpr' => '*/5 * * * *'])),
			'A pure cadence survives any fixed offset.'
		);

	}//end testTimezoneSensitiveCronStaysLocal()

	/**
	 * On an engine that reads the key, a zone-sensitive cron is mirrored with
	 * the owner's timezone on its trigger, and a stored mirror without it has
	 * drifted and is redefined with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#phase-2-the-shapes-the-engine-cannot-time-yet-staged
	 */
	public function testAZoneSensitiveCronIsMirroredWithTheOwnersTimezone(): void {
		$other = (date_default_timezone_get() === 'Pacific/Chatham') ? 'Europe/Amsterdam' : 'Pacific/Chatham';
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn($other);
		$this->config->method('getSystemValueString')->willReturn(date_default_timezone_get());
		$mapper = new class($this->config) extends ScheduleCadenceMapper {
			/**
			 * The engine declares the key.
			 *
			 * @return boolean Always true.
			 */
			protected function engineReadsTimezone(): bool {
				return true;
			}
		};

		$fresh = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 9 * * *', 'name' => 'Morning', 'agentId' => '', 'enabled' => true]);
		$this->objectService->method('findAll')->willReturn([$fresh]);
		$inserted = null;
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $flow) use (&$inserted): Flow {
				$inserted = $flow;
				return $flow;
			}
		);

		$stats = $this->bridge(cadenceMapper: $mapper)->syncAll();

		$this->assertSame(1, $stats['mirrored']);
		$this->assertSame('0 9 * * *', $inserted->getCron());
		$this->assertSame(
			['cron' => '0 9 * * *', 'runAs' => 'alice', 'timezone' => $other],
			$inserted->getNodes()[0]['config'],
			'The engine reads the cron on the owner\'s wall clock only when the trigger names it.'
		);
	}//end testAZoneSensitiveCronIsMirroredWithTheOwnersTimezone()

	/**
	 * A stored mirror whose trigger names no timezone has drifted from a
	 * zone-sensitive schedule and is redefined with the owner's timezone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#phase-2-the-shapes-the-engine-cannot-time-yet-staged
	 */
	public function testAMirrorWithoutTheOwnersTimezoneIsRedefined(): void {
		$other = (date_default_timezone_get() === 'Pacific/Chatham') ? 'Europe/Amsterdam' : 'Pacific/Chatham';
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn($other);
		$this->config->method('getSystemValueString')->willReturn(date_default_timezone_get());
		$mapper = new class($this->config) extends ScheduleCadenceMapper {
			/**
			 * The engine declares the key.
			 *
			 * @return boolean Always true.
			 */
			protected function engineReadsTimezone(): bool {
				return true;
			}
		};

		$schedule = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 9 * * *', 'agentId' => '', 'engineFlowId' => 'flow-1', 'enabled' => true]);
		$this->objectService->method('findAll')->willReturn([$schedule]);
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron('0 9 * * *');
		$flow->setEnabled(true);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setNodes([['id' => 'trigger', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 9 * * *', 'runAs' => 'alice']]]);
		$this->flowMapper->method('findByUuid')->willReturn($flow);
		$updated = null;
		$this->flowMapper->expects($this->once())->method('update')->willReturnCallback(
			function (Flow $flow) use (&$updated): Flow {
				$updated = $flow;
				return $flow;
			}
		);

		$stats = $this->bridge(cadenceMapper: $mapper)->syncAll();

		$this->assertSame(1, $stats['refreshed']);
		$this->assertSame($other, $updated->getNodes()[0]['config']['timezone']);
	}//end testAMirrorWithoutTheOwnersTimezoneIsRedefined()

	/**
	 * runAs is the resolved acting identity: the agent's live actingUser
	 * first, the owner otherwise, and null when nobody live resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testResolveRunAsPrefersLiveActingUser(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['actingUser' => 'svc-runner']);
		$this->objectService->method('find')->willReturn($agent);

		$bridge = $this->bridge();

		$this->assertSame(
			'svc-runner',
			$bridge->resolveRunAs(schedule: $this->schedule(['agentId' => 'agent-1']))
		);

	}//end testResolveRunAsPrefersLiveActingUser()

	/**
	 * When nobody live resolves, the schedule is ineligible instead of being
	 * mirrored with an identity the engine would refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function testNoLiveIdentityIsIneligible(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturn(null);

		$bridge = $this->bridge();
		$schedule = $this->schedule(['kind' => 'interval', 'intervalMinutes' => 15, 'agentId' => '']);

		$this->assertNull($bridge->resolveRunAs(schedule: $schedule));
		$this->assertNotNull($bridge->ineligibilityReason(schedule: $schedule));

	}//end testNoLiveIdentityIsIneligible()

	/**
	 * An organisation-less schedule is never mirrored: the flow row would be
	 * invisible to every tenant forever (hermiq#140).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testOrganisationLessScheduleIsIneligible(): void {
		$bridge = $this->bridge();
		$schedule = $this->schedule(
			['kind' => 'interval', 'intervalMinutes' => 15],
			'sched-1',
			'alice',
			''
		);

		$this->assertStringContainsString(
			'organisation',
			(string)$bridge->ineligibilityReason(schedule: $schedule)
		);

	}//end testOrganisationLessScheduleIsIneligible()

	/**
	 * The sync mirrors an eligible, unmirrored schedule: one flow row with the
	 * trigger's cron and runAs, the dispatch node naming the schedule, and the
	 * delegation marker written back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testSyncMirrorsAnEligibleSchedule(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * *',
				'name' => 'Daily brief',
				'agentId' => '',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$inserted = null;
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $flow) use (&$inserted): Flow {
				$inserted = $flow;
				return $flow;
			}
		);

		$markedFlowId = null;
		$this->scheduleService->expects($this->once())
			->method('markEngineDelegation')
			->willReturnCallback(
				function (ObjectEntity $s, string $flowId) use (&$markedFlowId): void {
					$markedFlowId = $flowId;
				}
			);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['mirrored']);
		$this->assertNotNull($inserted, 'A flow row must be inserted.');
		$this->assertSame('schedule', $inserted->getTrigger());
		$this->assertSame('0 8 * * *', $inserted->getCron());
		$this->assertSame('org-1', $inserted->getOrganisation(), 'An org-less row would be unreachable (hermiq#140).');
		$this->assertSame($inserted->getUuid(), $markedFlowId, 'The marker must name the inserted flow.');

		$nodes = $inserted->getNodes();
		$this->assertSame('openregister.trigger-schedule', $nodes[0]['type']);
		$this->assertSame('alice', $nodes[0]['config']['runAs'], 'The trigger declares the resolved identity.');
		$this->assertSame('hermiq.schedule-dispatch', $nodes[1]['type']);
		$this->assertSame('sched-1', $nodes[1]['config']['scheduleId']);
		$this->assertSame('openregister.end', $nodes[2]['type']);

	}//end testSyncMirrorsAnEligibleSchedule()

	/**
	 * The mirror publishes its flow, and only then writes the marker.
	 *
	 * OpenRegister's FlowRunVersionPin refuses every scheduled dispatch of a
	 * flow with no published version, so a mirror that is merely inserted is
	 * a clock that never ticks. The lifecycle assertion is the pin's
	 * acceptance test: only a publish call can put the flow there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testMirrorPublishesBeforeTheMarkerIsWritten(): void {
		$schedule = $this->schedule(
			['kind' => 'cron', 'cronExpr' => '0 8 * * *', 'agentId' => '', 'enabled' => true]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$inserted = null;
		$this->flowMapper->method('insert')->willReturnCallback(
			function (Flow $flow) use (&$inserted): Flow {
				$inserted = $flow;
				return $flow;
			}
		);

		$this->scheduleService->expects($this->once())
			->method('markEngineDelegation')
			->willReturnCallback(
				function (): void {
					$this->versionCalls[] = 'mark';
				}
			);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['mirrored']);
		$this->assertNotNull($inserted);
		$this->assertSame(
			FlowVersion::STATUS_PUBLISHED,
			$inserted->getLifecycleStatus(),
			'The pin refuses an unpublished mirror; the bridge must publish what it creates.'
		);
		// Whether a createDraft precedes the publish depends on the Flow
		// entity's birth state: the real entity is born draft (no createDraft
		// needed), the standalone stub is born blank (drafted first). Both
		// are correct; what this test pins is publish, then the marker.
		$publishAndMark = array_values(
			array_filter(
				$this->versionCalls,
				static fn (string $call): bool => in_array($call, ['publish', 'mark'], true)
			)
		);
		$this->assertSame(
			['publish', 'mark'],
			$publishAndMark,
			'Publish before the marker: a publish failure must leave the schedule on its local clock.'
		);

	}//end testMirrorPublishesBeforeTheMarkerIsWritten()

	/**
	 * A publish failure unmirrors: no marker, the flow is taken back out,
	 * and the miss is counted. The schedule keeps its local clock instead of
	 * being delegated to a flow the pin refuses on every tick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testMirrorPublishFailureLeavesTheScheduleLocal(): void {
		$schedule = $this->schedule(
			['kind' => 'cron', 'cronExpr' => '0 8 * * *', 'agentId' => '', 'enabled' => true]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->publishFailure = new \RuntimeException('publish refused');

		$this->scheduleService->expects($this->never())->method('markEngineDelegation');
		$this->flowMapper->expects($this->once())->method('delete');

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['failed']);
		$this->assertSame(0, $stats['mirrored']);

	}//end testMirrorPublishFailureLeavesTheScheduleLocal()

	/**
	 * A drifted mirror republishes: the changed cadence must not keep firing
	 * the old pinned version. Draft first, update second, publish last, so a
	 * crash in between is visible as an unpublished head the next pass heals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testRefreshRepublishesAChangedCadence(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 9 * * *',
				'agentId' => '',
				'engineFlowId' => 'flow-1',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron('0 8 * * *');
		$flow->setEnabled(true);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setNodes(
			[
				[
					'id' => 'trigger',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '0 8 * * *', 'runAs' => 'alice'],
				],
			]
		);
		$this->flowMapper->method('findByUuid')->willReturn($flow);

		$updateCalls = [];
		$this->flowMapper->method('update')->willReturnCallback(
			function (Flow $f) use (&$updateCalls): Flow {
				$updateCalls[] = 'update';
				return $f;
			}
		);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['refreshed']);
		$this->assertSame('0 9 * * *', $flow->getCron());
		$this->assertSame(
			['createDraft', 'publish'],
			$this->versionCalls,
			'The new definition must land as a NEW published version, drafted before the row update.'
		);
		$this->assertSame(
			FlowVersion::STATUS_PUBLISHED,
			$flow->getLifecycleStatus(),
			'After a refresh the head is published again; the engine fires the new cadence.'
		);

	}//end testRefreshRepublishesAChangedCadence()

	/**
	 * An undrifted mirror whose head is not published gets published: this
	 * heals mirrors created before the bridge published (dead until a manual
	 * publish) and any crash between an update and its republish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testUnpublishedUndriftedMirrorIsPublished(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * *',
				'agentId' => '',
				'engineFlowId' => 'flow-1',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron('0 8 * * *');
		$flow->setEnabled(true);
		$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
		$flow->setNodes(
			[
				[
					'id' => 'trigger',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '0 8 * * *', 'runAs' => 'alice'],
				],
			]
		);
		$this->flowMapper->method('findByUuid')->willReturn($flow);
		$this->flowMapper->expects($this->never())->method('update');

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['refreshed']);
		$this->assertSame(
			FlowVersion::STATUS_PUBLISHED,
			$flow->getLifecycleStatus(),
			'A pre-fix mirror must be healed by the next sync pass, not left for a manual publish.'
		);

	}//end testUnpublishedUndriftedMirrorIsPublished()

	/**
	 * A mirrored schedule edited into an inexpressible shape is retired:
	 * marker cleared first, flow deleted second.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testSyncRetiresAnIneligibleMirroredSchedule(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'once',
				'runAt' => '2030-01-01T00:00:00+00:00',
				'engineFlowId' => 'flow-1',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$order = [];
		$this->scheduleService->expects($this->once())
			->method('clearEngineDelegation')
			->willReturnCallback(
				function () use (&$order): void {
					$order[] = 'clear';
				}
			);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$this->flowMapper->method('findByUuid')->willReturn($flow);
		$this->flowMapper->method('delete')->willReturnCallback(
			function (Flow $f) use (&$order): Flow {
				$order[] = 'delete';
				return $f;
			}
		);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['retired']);
		$this->assertSame(['clear', 'delete'], $order, 'Marker first, flow second: no double clock on a crash.');

	}//end testSyncRetiresAnIneligibleMirroredSchedule()

	/**
	 * A mirrored, undrifted schedule is a no-op, which is what makes the
	 * repair step idempotent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testSyncSkipsAnUndriftedMirror(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * *',
				'agentId' => '',
				'engineFlowId' => 'flow-1',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron('0 8 * * *');
		$flow->setEnabled(true);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setNodes(
			[
				[
					'id' => 'trigger',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '0 8 * * *', 'runAs' => 'alice'],
				],
			]
		);
		$this->flowMapper->method('findByUuid')->willReturn($flow);
		$this->flowMapper->expects($this->never())->method('update');
		$this->flowMapper->expects($this->never())->method('insert');
		$this->scheduleService->expects($this->never())->method('markEngineDelegation');

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['skipped']);
		$this->assertSame([], $this->versionCalls, 'A published, undrifted mirror needs no lifecycle calls.');

	}//end testSyncSkipsAnUndriftedMirror()

	/**
	 * A drifted mirror (schedule disabled) is refreshed onto the flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testSyncRefreshesADriftedMirror(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * *',
				'agentId' => '',
				'engineFlowId' => 'flow-1',
				'enabled' => false,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron('0 8 * * *');
		$flow->setEnabled(true);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setNodes(
			[
				[
					'id' => 'trigger',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '0 8 * * *', 'runAs' => 'alice'],
				],
			]
		);
		$this->flowMapper->method('findByUuid')->willReturn($flow);

		$updated = null;
		$this->flowMapper->method('update')->willReturnCallback(
			function (Flow $f) use (&$updated): Flow {
				$updated = $f;
				return $f;
			}
		);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['refreshed']);
		$this->assertNotNull($updated);
		$this->assertFalse($updated->getEnabled(), 'A paused schedule pauses its mirror.');
		$this->assertSame(
			FlowVersion::STATUS_PUBLISHED,
			$flow->getLifecycleStatus(),
			'A refreshed mirror must end republished, or the engine keeps running the old pinned version.'
		);

	}//end testSyncRefreshesADriftedMirror()

	/**
	 * A delegated schedule whose mirror flow row is GONE gets its clock back
	 * (marker cleared), so the local dispatcher covers it until the next
	 * pass re-mirrors.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testMissingMirrorFlowHandsTheClockBack(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'cron',
				'cronExpr' => '0 8 * * *',
				'agentId' => '',
				'engineFlowId' => 'flow-gone',
				'enabled' => true,
			]
		);
		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->flowMapper->method('findByUuid')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('gone')
		);
		$this->scheduleService->expects($this->once())->method('clearEngineDelegation');

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['refreshed']);

	}//end testMissingMirrorFlowHandsTheClockBack()

	/**
	 * Deleting a flow that is already gone is the end state a delete wants:
	 * no error, no fallback disable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testDeleteFlowTreatsAlreadyGoneAsDone(): void {
		$this->flowMapper->method('findByUuid')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('gone')
		);
		$this->flowMapper->expects($this->never())->method('delete');
		$this->flowMapper->expects($this->never())->method('update');

		$this->bridge()->deleteFlow(flowId: 'flow-gone');

	}//end testDeleteFlowTreatsAlreadyGoneAsDone()

	/**
	 * A schedule-store read failure is counted and never thrown: the tick
	 * that called the sync must still dispatch locally.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function testScheduleReadFailureIsCountedNotThrown(): void {
		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('db gone'));

		$stats = $this->bridge()->syncAll();

		$this->assertSame(1, $stats['failed']);
		$this->assertSame(0, $stats['mirrored']);

	}//end testScheduleReadFailureIsCountedNotThrown()

	/**
	 * A mirror flow at the given cron, run-as and enabled state.
	 *
	 * @param string $cron The flow cron.
	 * @param string $runAs The trigger's run-as user.
	 * @param bool $enabled Whether the flow is enabled.
	 *
	 * @return Flow
	 */
	private function mirrorFlow(string $cron, string $runAs, bool $enabled): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setCron($cron);
		$flow->setEnabled($enabled);
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$flow->setNodes([['id' => 'trigger', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => $cron, 'runAs' => $runAs]]]);
		return $flow;
	}//end mirrorFlow()

	/**
	 * syncAll() skips what is not a schedule, counts each schedule's outcome, and
	 * counts (and logs) one whose sync throws without stopping the rest.
	 *
	 * @return void
	 */
	public function testSyncCountsEveryOutcomeAndSurvivesAFailingSchedule(): void {
		$skipped = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 8 * * *', 'agentId' => '', 'engineFlowId' => 'flow-1', 'enabled' => true], 'sched-skip');
		$failing = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 8 * * *', 'agentId' => '', 'engineFlowId' => 'flow-gone', 'enabled' => true], 'sched-fail');
		$this->objectService->method('findAll')->willReturn(['not-a-schedule', $failing, $skipped]);
		$this->flowMapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): Flow {
				if ($uuid === 'flow-gone') {
					throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
				}

				return $this->mirrorFlow('0 8 * * *', 'alice', true);
			}
		);
		$this->scheduleService->method('clearEngineDelegation')->willThrowException(new \RuntimeException('store down'));
		$warnings = [];
		$this->loggerOverride = $this->createMock(LoggerInterface::class);
		$this->loggerOverride->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(['mirrored' => 0, 'refreshed' => 0, 'retired' => 0, 'skipped' => 1, 'failed' => 1], $stats);
		$this->assertSame(['[hermiq] Schedule flow sync failed for sched-fail: store down'], $warnings);

	}//end testSyncCountsEveryOutcomeAndSurvivesAFailingSchedule()

	/**
	 * The run-as identity is the agent's live acting user, else the schedule's live
	 * (trimmed) owner, else nobody.
	 *
	 * @return void
	 */
	public function testRunAsFallsBackFromTheActingUserToTheOwner(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['actingUser' => 'svc-off']);
		$this->objectService->method('find')->willReturnCallback(static fn (...$args): ?ObjectEntity => ($args[0] === 'agent-1') ? $agent : null);
		$live = $this->createMock(IUser::class);
		$live->method('isEnabled')->willReturn(true);
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => match ($uid) {
				'alice' => $live,
				'svc-off', 'bob' => $disabled,
				default => null,
			}
		);
		$bridge = $this->bridge();

		$this->assertSame('alice', $bridge->resolveRunAs(schedule: $this->schedule(['agentId' => 'agent-1'], 'sched-1', ' alice ')));
		$this->assertSame('alice', $bridge->resolveRunAs(schedule: $this->schedule(['agentId' => 'unknown'], 'sched-1', 'alice')));
		$this->assertNull($bridge->resolveRunAs(schedule: $this->schedule(['agentId' => 'agent-1'], 'sched-1', 'bob')));
		$this->assertNull($bridge->resolveRunAs(schedule: $this->schedule(['agentId' => 'agent-1'], 'sched-1', '  ')));

	}//end testRunAsFallsBackFromTheActingUserToTheOwner()

	/**
	 * A mirror drifted in any one of cron, run-as or enabled is redefined and
	 * republished: drafted, updated with all three, then published.
	 *
	 * @return void
	 */
	public function testAnyOneDriftRedefinesAndRepublishesTheMirror(): void {
		$schedule = $this->schedule(['kind' => 'cron', 'cronExpr' => '0 8 * * *', 'agentId' => '', 'engineFlowId' => 'flow-1', 'enabled' => true]);
		$this->objectService->method('findAll')->willReturn([$schedule]);
		$flows = [
			'cron' => $this->mirrorFlow('0 9 * * *', 'alice', true),
			'runAs' => $this->mirrorFlow('0 8 * * *', 'bob', true),
			'enabled' => $this->mirrorFlow('0 8 * * *', 'alice', false),
		];
		$current = null;
		$this->flowMapper->method('findByUuid')->willReturnCallback(function () use (&$current): Flow {
			return $current;
		});
		$updates = [];
		$this->flowMapper->method('update')->willReturnCallback(
			function (Flow $flow) use (&$updates): Flow {
				$this->versionCalls[] = 'update';
				$updates[] = [$flow->getCron(), $flow->getEnabled(), $flow->getNodes()[0]['config']['runAs'] ?? null];
				return $flow;
			}
		);

		foreach ($flows as $drift => $flow) {
			$current = $flow;
			$this->versionCalls = [];
			$this->assertSame(1, $this->bridge()->syncAll()['refreshed'], $drift);
			$this->assertSame(['createDraft', 'update', 'publish'], $this->versionCalls, $drift);
		}

		$this->assertSame(array_fill(0, 3, ['0 8 * * *', true, 'alice']), $updates);

	}//end testAnyOneDriftRedefinesAndRepublishesTheMirror()

	/**
	 * A stored mirror flow that dispatches the given schedule.
	 *
	 * @param string $uuid       The flow uuid.
	 * @param string $scheduleId The schedule its dispatch node fires; '' for no dispatch node.
	 *
	 * @return Flow
	 */
	private function storedMirror(string $uuid, string $scheduleId): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$nodes = [['id' => 'trigger', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 8 * * *', 'runAs' => 'alice']]];
		if ($scheduleId !== '') {
			$nodes[] = ['id' => 'dispatch', 'type' => 'hermiq.schedule-dispatch', 'config' => ['scheduleId' => $scheduleId]];
		}

		$flow->setNodes($nodes);
		return $flow;
	}//end storedMirror()

	/**
	 * A mirror flow whose schedule was deleted is removed from the flow side on
	 * the next pass and counted as retired; a mirror of a live schedule and a
	 * flow without a dispatch node are left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#task-3-1
	 */
	public function testAnOrphanedMirrorIsSweptFromTheFlowSide(): void {
		$this->objectService->method('findAll')->willReturn(
			[$this->schedule(['kind' => 'once', 'runAt' => '2026-12-01T08:00:00+00:00', 'enabled' => true], 'sched-live')]
		);
		$flows = [
			'flow-live' => $this->storedMirror('flow-live', 'sched-live'),
			'flow-orphan' => $this->storedMirror('flow-orphan', 'sched-gone'),
			'flow-odd' => $this->storedMirror('flow-odd', ''),
		];
		$selections = [];
		$this->flowMapper->method('findAllFlows')->willReturnCallback(
			function (FlowSelection $selection, ListQuery $query) use (&$selections, $flows): array {
				$selections[] = [$selection->app, $selection->applicationSlug, $query->limit];
				return array_values($flows);
			}
		);
		$this->flowMapper->method('findByUuid')->willReturnCallback(static fn (string $uuid): Flow => $flows[$uuid]);
		$deleted = [];
		$this->flowMapper->method('delete')->willReturnCallback(
			static function (Flow $flow) use (&$deleted): Flow {
				$deleted[] = $flow->getUuid();
				return $flow;
			}
		);

		$stats = $this->bridge()->syncAll();

		$this->assertSame(['flow-orphan'], $deleted);
		$this->assertSame(1, $stats['retired']);
		$this->assertSame([['hermiq', 'hermiq-schedules', 1000]], $selections);
	}//end testAnOrphanedMirrorIsSweptFromTheFlowSide()

	/**
	 * A pass that read a full page of schedules cannot prove one gone, so it
	 * sweeps nothing; a listing that fails sweeps nothing and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#task-3-1
	 */
	public function testNoSweepWithoutTheWholePictureOrTheListing(): void {
		$this->objectService->method('findAll')->willReturn(array_fill(0, 1000, 'not-a-schedule'));
		$this->flowMapper->expects($this->never())->method('findAllFlows');
		$this->flowMapper->expects($this->never())->method('delete');
		$this->assertSame(0, $this->bridge()->syncAll()['retired']);
	}//end testNoSweepWithoutTheWholePictureOrTheListing()

	/**
	 * A listing failure deletes nothing and is logged.
	 *
	 * @return void
	 */
	public function testAFailingListingSweepsNothing(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->flowMapper->method('findAllFlows')->willThrowException(new \RuntimeException('flows table locked'));
		$this->flowMapper->expects($this->never())->method('delete');
		$warnings = [];
		$this->loggerOverride = $this->createMock(LoggerInterface::class);
		$this->loggerOverride->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$this->assertSame(0, $this->bridge()->syncAll()['retired']);
		$this->assertSame(['[hermiq] Schedule flow sync could not list mirror flows; no orphans swept: flows table locked'], $warnings);
	}//end testAFailingListingSweepsNothing()
}//end class
