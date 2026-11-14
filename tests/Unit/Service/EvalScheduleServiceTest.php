<?php

/**
 * Unit tests for EvalScheduleService (reuse-scheduled-bias-evals).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Service\EvalActionException;
use OCA\Hermiq\Service\EvalOwnership;
use OCA\Hermiq\Service\EvalScheduleService;
use OCA\Hermiq\Tests\Unit\Support\InMemoryEvalObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Owners set schedules; the job runs due ones as the owner, next run first,
 * and tells the owner when a run fails its regression gate.
 *
 * @covers \OCA\Hermiq\Service\EvalScheduleService
 * @uses   \OCA\Hermiq\Service\EvalOwnership
 * @uses   \OCA\Hermiq\Service\EvalScheduleDrop
 * @uses   \OCA\Hermiq\Service\EvalActionException
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleServiceTest extends TestCase {

	/**
	 * 2026-09-01T06:30:00Z, a Tuesday.
	 *
	 * @var int
	 */
	private const NOW = 1788244200;

	/**
	 * The object store.
	 *
	 * @var InMemoryEvalObjects
	 */
	private InMemoryEvalObjects $objects;

	/**
	 * The eval run service double.
	 *
	 * @var EvalRunServiceInterface&MockObject
	 */
	private EvalRunServiceInterface&MockObject $runs;

	/**
	 * The notifications sent.
	 *
	 * @var list<array{user: string, subject: string, params: array<string, mixed>, link: string, object: array{0: string, 1: string}}>
	 */
	private array $sent = [];

	/**
	 * What was set on each notification, by object id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $states = [];

	/**
	 * A dataset and an agent owned by alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryEvalObjects();
		$this->objects->put(schema: 'evaldataset', uuid: 'ds-1', data: ['name' => 'Bias check — Helper', 'templateKey' => 'bias-public-services'], owner: 'alice');
		$this->runs = $this->createMock(EvalRunServiceInterface::class);
		$this->sent = [];
	}//end setUp()

	/**
	 * The service over the store, with agent "agent-1" owned by alice.
	 *
	 * @return EvalScheduleService
	 */
	private function service(): EvalScheduleService {
		$agent = new Agent();
		$agent->setUuid('agent-1');
		$agent->setOwner('alice');
		$agent->setName('Permit intake helper');
		$mapper = $this->createMock(AgentMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($agent): Agent {
				if ($uuid !== 'agent-1') {
					throw new RuntimeException('not found');
				}

				return $agent;
			}
		);
		$custody = $this->createMock(SeedCustodyServiceInterface::class);
		$custody->method('actsAsOwner')->willReturnCallback(static fn (?string $owner, string $uid): bool => $owner === $uid);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		return new EvalScheduleService(
			objectService: $this->objects,
			ownership: new EvalOwnership(objectService: $this->objects, agentMapper: $mapper, seedCustody: $custody),
			evalRuns: $this->runs,
			notifications: $this->notifications(),
			urlGenerator: $urls,
			time: $time,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A notification manager that records what it is asked to send.
	 *
	 * @return INotificationManager
	 */
	private function notifications(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$state = ['user' => '', 'subject' => '', 'params' => [], 'link' => '', 'object' => ['', '']];
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime'] as $method) {
					$notification->method($method)->willReturnSelf();
				}

				$notification->method('setUser')->willReturnCallback(static function (string $user) use (&$state, $notification) {
					$state['user'] = $user;
					return $notification;
				});
				$notification->method('setSubject')->willReturnCallback(static function (string $subject, array $params) use (&$state, $notification) {
					$state['subject'] = $subject;
					$state['params'] = $params;
					return $notification;
				});
				$notification->method('setLink')->willReturnCallback(static function (string $link) use (&$state, $notification) {
					$state['link'] = $link;
					return $notification;
				});
				$notification->method('setObject')->willReturnCallback(static function (string $type, string $id) use (&$state, $notification) {
					$state['object'] = [$type, $id];
					return $notification;
				});
				$this->states[spl_object_id($notification)] = &$state;
				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $this->states[spl_object_id($notification)];
			}
		);

		return $manager;
	}//end notifications()

	/**
	 * A stored schedule of ds-1 for agent-1.
	 *
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(array $fields = []): ObjectEntity {
		return $this->objects->put(
			schema: 'evalschedule',
			uuid: 'sched-1',
			data: $fields + [
				'datasetId' => 'ds-1',
				'agentId' => 'agent-1',
				'cronExpr' => '0 6 1 * *',
				'enabled' => true,
				'nextRun' => '2026-09-01T06:00:00+00:00',
				'regressionThresholdPercent' => 10,
				'owner' => 'alice',
			],
			owner: 'alice'
		);
	}//end schedule()

	/**
	 * The owner sets a monthly schedule; it is stored with its next run, owned by them.
	 *
	 * @return void
	 */
	public function testTheOwnerSetsAMonthlySchedule(): void {
		$view = $this->service()->save(datasetId: 'ds-1', input: ['agentId' => 'agent-1', 'regressionThresholdPercent' => '10'], uid: 'alice');

		$this->assertSame('0 6 1 * *', $view['cronExpr']);
		$this->assertSame('2026-10-01T06:00:00+00:00', $view['nextRun'], 'The next first of the month at 06:00 after now.');
		$this->assertTrue($view['enabled']);
		$this->assertSame(10, $view['regressionThresholdPercent']);
		$this->assertCount(1, $this->objects->saved);
		$this->assertSame('alice', $this->objects->saved[0]['owner']);
		$this->assertSame('alice', $this->objects->saved[0]['object']['owner']);
		$this->assertSame('evalschedule', $this->objects->saved[0]['schema']);

		$this->assertSame([$view], $this->service()->listForDataset(datasetId: 'ds-1', uid: 'alice'));
	}//end testTheOwnerSetsAMonthlySchedule()

	/**
	 * Someone who does not own the dataset gets 404 for both reading and saving.
	 *
	 * @return void
	 */
	public function testANonOwnerGets404(): void {
		foreach ([
			fn () => $this->service()->save(datasetId: 'ds-1', input: ['agentId' => 'agent-1'], uid: 'mallory'),
			fn () => $this->service()->listForDataset(datasetId: 'ds-1', uid: 'mallory'),
		] as $call) {
			try {
				$call();
				$this->fail('A non-owner must be refused.');
			} catch (EvalActionException $e) {
				$this->assertSame(404, $e->getCode());
				$this->assertSame('Dataset not found', $e->getMessage());
			}
		}

		$this->assertSame([], $this->objects->saved);
	}//end testANonOwnerGets404()

	/**
	 * An agent the caller does not own is refused, and so is an unreadable schedule.
	 *
	 * @return void
	 */
	public function testAnUnownedAgentAndAnUnreadableScheduleAreRefused(): void {
		try {
			$this->service()->save(datasetId: 'ds-1', input: ['agentId' => 'agent-2'], uid: 'alice');
			$this->fail('An agent that is not the caller\'s must be refused.');
		} catch (EvalActionException $e) {
			$this->assertSame([404, 'Agent not found'], [$e->getCode(), $e->getMessage()]);
		}

		try {
			$this->service()->save(datasetId: 'ds-1', input: ['agentId' => 'agent-1', 'cronExpr' => 'every month'], uid: 'alice');
			$this->fail('An unreadable schedule must be refused.');
		} catch (EvalActionException $e) {
			$this->assertSame(400, $e->getCode());
		}

		$this->assertSame([], $this->objects->saved);
	}//end testAnUnownedAgentAndAnUnreadableScheduleAreRefused()

	/**
	 * A due schedule's next run is stored before its run, which runs as the owner with its threshold.
	 *
	 * @return void
	 */
	public function testADueScheduleAdvancesFirstThenRunsAsTheOwner(): void {
		$this->schedule();
		$this->runs->expects($this->once())->method('run')->willReturnCallback(
			function (ObjectEntity $dataset, Agent $agent, ?string $agentVersionId = null, ?int $regressionThresholdOverride = null): array {
				$this->objects->events[] = 'run';
				$this->assertSame('ds-1', $dataset->getUuid());
				$this->assertSame('agent-1', $agent->getUuid());
				$this->assertSame(10, $regressionThresholdOverride);
				return ['evalRunId' => 'run-9', 'status' => 'completed', 'passRate' => 0.95, 'regressionGateResult' => 'passed', 'previousPassRate' => 0.95];
			}
		);

		$this->assertSame(1, $this->service()->runDue());

		$this->assertSame(['save:evalschedule', 'run', 'save:evalschedule'], $this->objects->events, 'Commit before run.');
		$this->assertSame('2026-10-01T06:00:00+00:00', $this->objects->saved[0]['object']['nextRun']);
		$stored = $this->objects->objects['evalschedule']['sched-1']->getObject();
		$this->assertSame('run-9', $stored['lastRunId']);
		$this->assertSame('2026-09-01T06:30:00+00:00', $stored['lastRunAt']);
		$this->assertSame([], $this->sent, 'A run that passes its gate tells nobody.');
	}//end testADueScheduleAdvancesFirstThenRunsAsTheOwner()

	/**
	 * A drop past the threshold tells the owner, with the agent and both pass rates.
	 *
	 * @return void
	 */
	public function testADropPastTheThresholdNotifiesTheOwner(): void {
		$this->schedule();
		$this->runs->method('run')->willReturn(
			['evalRunId' => 'run-10', 'status' => 'completed', 'passRate' => 0.8, 'regressionGateResult' => 'failed', 'previousPassRate' => 0.95]
		);

		$this->service()->runDue();

		$this->assertCount(1, $this->sent);
		$this->assertSame('alice', $this->sent[0]['user']);
		$this->assertSame('eval_schedule_regressed', $this->sent[0]['subject']);
		$this->assertSame(
			['agent' => 'Permit intake helper', 'dataset' => 'Bias check — Helper', 'bias' => true, 'previous' => 95, 'current' => 80],
			$this->sent[0]['params']
		);
		$this->assertSame(['evalrun', 'run-10'], $this->sent[0]['object']);
		$this->assertSame('https://cloud.example/index.php/apps/hermiq/evals/ds-1', $this->sent[0]['link']);
	}//end testADropPastTheThresholdNotifiesTheOwner()

	/**
	 * A schedule that is not due, or is disabled, does not run.
	 *
	 * @return void
	 */
	public function testANotDueOrDisabledScheduleDoesNotRun(): void {
		$this->schedule(['nextRun' => '2026-10-01T06:00:00+00:00']);
		$this->objects->put(schema: 'evalschedule', uuid: 'sched-2', data: ['datasetId' => 'ds-1', 'agentId' => 'agent-1', 'cronExpr' => '0 6 1 * *', 'enabled' => false, 'nextRun' => '2026-08-01T06:00:00+00:00', 'owner' => 'alice']);
		$this->runs->expects($this->never())->method('run');

		$this->assertSame(0, $this->service()->runDue());
		$this->assertSame([], $this->objects->saved);
	}//end testANotDueOrDisabledScheduleDoesNotRun()

	/**
	 * When the owner no longer owns the dataset the occurrence is skipped, but it still advances.
	 *
	 * @return void
	 */
	public function testAnOwnerWhoLostTheDatasetIsSkippedButTheScheduleAdvances(): void {
		$this->schedule(['owner' => 'bob']);
		$this->runs->expects($this->never())->method('run');

		$this->assertSame(0, $this->service()->runDue());

		$this->assertCount(1, $this->objects->saved);
		$this->assertSame('2026-10-01T06:00:00+00:00', $this->objects->saved[0]['object']['nextRun']);
	}//end testAnOwnerWhoLostTheDatasetIsSkippedButTheScheduleAdvances()

	/**
	 * The schedule's next run is in UTC, whatever the server zone.
	 *
	 * @return void
	 */
	public function testTheNextRunIsAfterNow(): void {
		$view = $this->service()->save(datasetId: 'ds-1', input: ['agentId' => 'agent-1', 'cronExpr' => '0 * * * *'], uid: 'alice');

		$this->assertGreaterThan(new DateTimeImmutable('@' . self::NOW), new DateTimeImmutable($view['nextRun']));
		$this->assertSame('2026-09-01T07:00:00+00:00', $view['nextRun']);
	}//end testTheNextRunIsAfterNow()
}//end class
