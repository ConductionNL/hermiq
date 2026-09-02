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
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->scheduleService = $this->createMock(ScheduleService::class);
		$this->flowMapper = $this->createMock(FlowMapper::class);
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
	private function bridge(): ScheduleFlowBridge {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->flowMapper);

		return new ScheduleFlowBridge(
			objectService: $this->objectService,
			scheduleService: $this->scheduleService,
			cadenceMapper: new ScheduleCadenceMapper(config: $this->config),
			container: $container,
			userManager: $this->userManager,
			logger: $this->createMock(LoggerInterface::class),
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
}//end class
