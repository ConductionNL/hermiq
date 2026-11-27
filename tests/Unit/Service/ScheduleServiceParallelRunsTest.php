<?php

/**
 * Unit tests for ScheduleService's parallel run workers (operations-parallel-run-workers).
 *
 * The tick commits each due occurrence and queues it instead of running it; a worker
 * runs it only after it won the claim, re-checks the gates and respects the limits;
 * the tick sweeps an occurrence whose worker was lost into a failed run.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\BackgroundJob\ScheduleRunJob;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\Run\OccurrenceClaim;
use OCA\Hermiq\Service\Run\RunConcurrencySettings;
use OCA\Hermiq\Service\Schedule\DispatchOptions;
use OCA\Hermiq\Service\Schedule\FailedAttempt;
use OCA\Hermiq\Service\Schedule\GateSkip;
use OCA\Hermiq\Service\Schedule\RunAuditRecord;
use OCA\Hermiq\Service\Schedule\ScheduleRecordNormaliser;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/agent-schedule/spec.md
 */
#[CoversClass(ScheduleService::class)]
#[UsesClass(OccurrenceClaim::class)]
#[UsesClass(RunConcurrencySettings::class)]
#[UsesClass(TenantKillSwitch::class)]
#[UsesClass(AgentAvailability::class)]
#[UsesClass(ScheduleRecordNormaliser::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(DelegationContext::class)]
#[UsesClass(DispatchOptions::class)]
#[UsesClass(FailedAttempt::class)]
#[UsesClass(GateSkip::class)]
#[UsesClass(RunAuditRecord::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(VendorKeyRedactor::class)]
final class ScheduleServiceParallelRunsTest extends TestCase {

	private ObjectService&MockObject $objectService;
	private IJobList&MockObject $jobList;
	private IAppConfig&MockObject $appConfig;
	private BudgetService&MockObject $budgetService;

	/**
	 * The schema the object service was last pointed at.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The schedules the register holds, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $schedules = [];

	/**
	 * Organisations whose kill switch is engaged.
	 *
	 * @var string[]
	 */
	private array $engaged = [];

	/**
	 * Whether another worker holds the claim.
	 *
	 * @var bool
	 */
	private bool $lockHeld = false;

	/**
	 * Every save, as [uuid, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * Every lock taken and released, as "lock:<identifier>:<process>" or "unlock:<identifier>".
	 *
	 * @var string[]
	 */
	private array $locks = [];

	/**
	 * Every job added, as [class, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $jobs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnCallback(function (string $schema): ObjectService {
			$this->schema = $schema;
			return $this->objectService;
		});
		$this->objectService->method('findAll')->willReturnCallback(function (array $config = []): array {
			$filters = (array)($config['filters'] ?? []);
			if ($this->schema === 'tenantcontrol') {
				return array_map(function (string $organisation): ObjectEntity {
					$control = new ObjectEntity();
					$control->setOrganisation($organisation);
					$control->setObject(['engaged' => true]);
					return $control;
				}, $this->engaged);
			}

			if ($this->schema !== 'schedule') {
				return [];
			}

			return array_values(array_filter(
				$this->schedules,
				static function (ObjectEntity $schedule) use ($filters): bool {
					foreach ($filters as $field => $value) {
						if (($schedule->getObject()[$field] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			));
		});
		$this->objectService->method('find')->willReturnCallback(
			fn (string $id, ObjectFindOptions $options = new ObjectFindOptions()): ?ObjectEntity => ($this->schedules[$id] ?? null)
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$this->saves[] = [(string)$options->uuid, $object];
				if (isset($this->schedules[(string)$options->uuid]) === true) {
					$this->schedules[(string)$options->uuid]->setObject($object);
				}

				return new ObjectEntity();
			}
		);
		$this->objectService->method('lockObject')->willReturnCallback(function (LockRequest $request, bool $advisory = false): array {
			if ($this->lockHeld === true) {
				throw new LockedException('held');
			}

			$this->locks[] = 'lock:' . $request->identifier . ':' . $request->process;
			return ['uuid' => $request->identifier];
		});
		$this->objectService->method('unlockObject')->willReturnCallback(function (UnlockRequest $request): bool {
			$this->locks[] = 'unlock:' . $request->identifier;
			return true;
		});

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')->willReturnCallback(
			fn (string $class, mixed $argument): bool => in_array([$class, $argument], $this->jobs, true)
		);
		$this->jobList->method('add')->willReturnCallback(function (string $class, mixed $argument = null): void {
			$this->jobs[] = [$class, $argument];
		});

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'run_concurrency') ? '{"maxParallel":4,"maxParallelPerOrganisation":2}' : $default
		);
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(false);
	}//end setUp()

	private function service(): ScheduleService {
		$config = $this->createMock(IConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new ScheduleService(
			objectService: $this->objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			conversationMapper: $this->createMock(ConversationMapper::class),
			chatService: $this->createMock(ChatService::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			config: $config,
			logger: $logger,
			deliveryService: $this->createMock(DeliveryService::class),
			auditTrailMapper: $this->createMock(AuditTrailMapper::class),
			redactionService: new RedactionService($config),
			approvalService: $this->createMock(ApprovalService::class),
			appConfig: $this->appConfig,
			engine: $this->createMock(Engine::class),
			budgetService: $this->budgetService,
			guardrailPolicyService: $this->createMock(GuardrailPolicyService::class),
			agentVersionService: $this->createMock(AgentVersionService::class),
			skillVersionService: $this->createMock(SkillVersionService::class),
			delegationContext: new DelegationContext(),
			jobList: $this->jobList,
			occurrenceClaim: new OccurrenceClaim(objectService: $this->objectService, logger: $logger),
			runConcurrency: new RunConcurrencySettings(appConfig: $this->appConfig),
		);
	}//end service()

	/**
	 * Put a schedule in the register.
	 *
	 * @param string               $uuid         The schedule.
	 * @param array<string, mixed> $data         Its data.
	 * @param string               $organisation Its organisation.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(string $uuid, array $data, string $organisation = 'tilburg'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('alice');
		$entity->setOrganisation($organisation);
		$entity->setObject($data + ['enabled' => true, 'prompt' => 'brief', 'kind' => 'interval', 'intervalMinutes' => 60]);
		$this->schedules[$uuid] = $entity;
		return $entity;
	}//end schedule()

	/**
	 * The time a minute ago, as the register writes it.
	 *
	 * @param string $modify How far back.
	 *
	 * @return string
	 */
	private static function ago(string $modify): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $modify)->format('c');
	}//end ago()

	/**
	 * The tick commits a due occurrence, marks it queued and queues one job for it, without running the agent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-fire-due-schedules-and-run-the-agent-mvp
	 */
	public function testTheTickQueuesADueOccurrenceInsteadOfRunningIt(): void {
		$this->schedule('sched-1', ['nextRun' => '2000-01-01T00:00:00+00:00', 'repeat' => ['times' => 5, 'completed' => 0]]);

		$this->service()->run();

		$this->assertCount(1, $this->saves);
		[$uuid, $saved] = $this->saves[0];
		$this->assertSame('sched-1', $uuid);
		$this->assertSame('queued', $saved['lastStatus']);
		$this->assertSame('sched-1:2000-01-01T00:00:00+00:00', $saved['pendingOccurrence']);
		$this->assertNotSame('2000-01-01T00:00:00+00:00', $saved['nextRun'], 'nextRun is advanced before any worker runs');
		$this->assertSame(1, $saved['repeat']['completed']);
		$this->assertSame(
			[[ScheduleRunJob::class, ['scheduleId' => 'sched-1', 'occurrenceKey' => 'sched-1:2000-01-01T00:00:00+00:00']]],
			$this->jobs
		);
		$this->assertSame([], $this->locks, 'the tick takes no claim');
	}//end testTheTickQueuesADueOccurrenceInsteadOfRunningIt()

	/**
	 * A second tick adds no second job for an occurrence already in flight.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-fire-due-schedules-and-run-the-agent-mvp
	 */
	public function testASecondTickQueuesNothingForAnOccurrenceInFlight(): void {
		// A pending retry stays due by its retry state, even after its commit.
		$this->schedule('sched-1', [
			'nextRun' => '2999-01-01T00:00:00+00:00',
			'retryEnabled' => true,
			'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
		]);

		$this->service()->run();
		$this->service()->run();

		$this->assertCount(1, $this->jobs);
		$this->assertSame('sched-1:2000-01-01T00:00:00+00:00:a2', $this->jobs[0][1]['occurrenceKey']);
		$this->assertCount(1, $this->saves);
	}//end testASecondTickQueuesNothingForAnOccurrenceInFlight()

	/**
	 * A worker whose occurrence is no longer the one in flight exits without a claim or a save.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function testAWorkerWithAStaleOccurrenceDoesNothing(): void {
		$this->schedule('sched-1', ['pendingOccurrence' => 'sched-1:later', 'lastStatus' => 'queued', 'pendingSince' => self::ago('1 minute')]);

		$this->assertSame('stale', $this->service()->runQueued(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:earlier'));
		$this->assertSame('gone', $this->service()->runQueued(scheduleUuid: 'sched-404', occurrenceKey: 'sched-404:x'));
		$this->assertSame([], $this->locks);
		$this->assertSame([], $this->saves);
	}//end testAWorkerWithAStaleOccurrenceDoesNothing()

	/**
	 * Four workers, one occurrence: the worker that loses the claim exits without a run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function testAWorkerThatLosesTheClaimDoesNotRun(): void {
		$this->schedule('sched-1', ['pendingOccurrence' => 'sched-1:k', 'lastStatus' => 'queued', 'pendingSince' => self::ago('1 minute')]);
		$this->lockHeld = true;

		$this->assertSame('claimed_elsewhere', $this->service()->runQueued(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:k'));
		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->jobs);
	}//end testAWorkerThatLosesTheClaimDoesNotRun()

	/**
	 * A kill switch engaged after queueing stops the run: the occurrence is recorded as skipped, its claim released.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function testAKillSwitchEngagedAfterQueueingSkipsTheRun(): void {
		$this->schedule('sched-1', ['pendingOccurrence' => 'sched-1:k', 'lastStatus' => 'queued', 'pendingSince' => self::ago('1 minute'), 'nextRun' => '2999-01-01T00:00:00+00:00']);
		$this->engaged = ['tilburg'];

		$this->assertSame('ran', $this->service()->runQueued(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:k'));

		$this->assertCount(1, $this->saves);
		$saved = $this->saves[0][1];
		$this->assertSame('skipped_killswitch', $saved['lastStatus']);
		$this->assertNull($saved['pendingOccurrence']);
		$this->assertSame(['lock:hermiq-schedule-run:sched-1:sched-1:k', 'unlock:hermiq-schedule-run:sched-1'], $this->locks);
	}//end testAKillSwitchEngagedAfterQueueingSkipsTheRun()

	/**
	 * The worker that wins the claim marks the occurrence running, records its outcome and clears it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-fire-due-schedules-and-run-the-agent-mvp
	 */
	public function testTheClaimingWorkerRunsAndRecordsTheOccurrence(): void {
		// No agent bound: the turn fails, which is an outcome like any other.
		$this->schedule('sched-1', [
			'pendingOccurrence' => 'sched-1:k',
			'lastStatus' => 'queued',
			'pendingSince' => self::ago('1 minute'),
			'nextRun' => '2999-01-01T00:00:00+00:00',
			'repeat' => ['times' => 5, 'completed' => 1],
		]);

		$this->assertSame('ran', $this->service()->runQueued(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:k'));

		$this->assertGreaterThanOrEqual(2, count($this->saves));
		$this->assertSame('running', $this->saves[0][1]['lastStatus']);
		$this->assertSame('sched-1:k', $this->saves[0][1]['pendingOccurrence']);
		$last = $this->saves[array_key_last($this->saves)][1];
		$this->assertContains($last['lastStatus'], ['ok', 'error']);
		$this->assertNull($last['pendingOccurrence']);
		$this->assertSame('2999-01-01T00:00:00+00:00', $last['nextRun'], 'the worker does not advance the schedule a second time');
		$this->assertSame(1, $last['repeat']['completed']);
		$this->assertSame('unlock:hermiq-schedule-run:sched-1', $this->locks[array_key_last($this->locks)]);
	}//end testTheClaimingWorkerRunsAndRecordsTheOccurrence()

	/**
	 * Over the per-organisation limit the occurrence goes back in the queue, unclaimed and not dropped; another organisation still runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	public function testAWorkerOverTheOrganisationLimitRequeuesTheOccurrence(): void {
		$this->schedule('run-1', ['pendingOccurrence' => 'run-1:k', 'lastStatus' => 'running', 'pendingSince' => self::ago('1 minute')]);
		$this->schedule('run-2', ['pendingOccurrence' => 'run-2:k', 'lastStatus' => 'running', 'pendingSince' => self::ago('2 minutes')]);
		// A run past its time-to-live no longer counts.
		$this->schedule('run-3', ['pendingOccurrence' => 'run-3:k', 'lastStatus' => 'running', 'pendingSince' => self::ago('2 hours')]);
		$this->schedule('sched-1', ['pendingOccurrence' => 'sched-1:k', 'lastStatus' => 'queued', 'pendingSince' => self::ago('1 minute')]);
		$this->schedule('other-1', ['pendingOccurrence' => 'other-1:k', 'lastStatus' => 'queued', 'pendingSince' => self::ago('1 minute'), 'nextRun' => '2999-01-01T00:00:00+00:00'], 'breda');
		$this->engaged = ['breda'];

		$this->assertSame('deferred', $this->service()->runQueued(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:k'));
		$this->assertSame([[ScheduleRunJob::class, ['scheduleId' => 'sched-1', 'occurrenceKey' => 'sched-1:k']]], $this->jobs);
		$this->assertSame([], $this->locks);
		$this->assertSame('queued', $this->schedules['sched-1']->getObject()['lastStatus']);

		// Breda's run is not held back by Tilburg's (its kill switch then skips it, which shows it got through).
		$this->assertSame('ran', $this->service()->runQueued(scheduleUuid: 'other-1', occurrenceKey: 'other-1:k'));
	}//end testAWorkerOverTheOrganisationLimitRequeuesTheOccurrence()

	/**
	 * The sweep fails an occurrence whose worker was lost, and its retry runs under a new attempt key.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-a-lost-worker-is-recorded-as-a-failed-run-req-prun-002
	 */
	public function testTheSweepFailsALostOccurrenceAndRetriesUnderANewKey(): void {
		$this->schedule('sched-1', [
			'pendingOccurrence' => 'sched-1:2026-09-28T02:00:00+00:00',
			'lastStatus' => 'running',
			'pendingSince' => self::ago('2 hours'),
			'nextRun' => '2999-01-01T00:00:00+00:00',
			'retryEnabled' => true,
			'retryMaxAttempts' => 3,
			'repeat' => ['times' => 0, 'completed' => 1],
		]);

		$this->service()->run();

		$swept = $this->saves[0][1];
		$this->assertSame('retry_pending', $swept['lastStatus']);
		$this->assertSame(ScheduleService::LOST_WORKER_MESSAGE, $swept['lastError']);
		$this->assertSame(1, $swept['retryState']['attempt']);
		$this->assertNull($swept['pendingOccurrence']);
		$this->assertSame(['lock:hermiq-schedule-run:sched-1:sweep', 'unlock:hermiq-schedule-run:sched-1'], array_slice($this->locks, 0, 2));

		// The retry is due at once only after its backoff; when it is, it is a new occurrence.
		$data = $this->schedules['sched-1']->getObject();
		$data['retryState']['nextAttemptAt'] = '2000-01-01T00:00:00+00:00';
		$this->schedules['sched-1']->setObject($data);
		$this->service()->run();

		$this->assertSame('sched-1:2000-01-01T00:00:00+00:00:a2', $this->jobs[0][1]['occurrenceKey']);
	}//end testTheSweepFailsALostOccurrenceAndRetriesUnderANewKey()

	/**
	 * The sweep leaves a live worker alone: within the time-to-live, or while it still holds the claim.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-a-lost-worker-is-recorded-as-a-failed-run-req-prun-002
	 */
	public function testTheSweepLeavesALiveWorkerAlone(): void {
		$this->schedule('young', ['pendingOccurrence' => 'young:k', 'lastStatus' => 'running', 'pendingSince' => self::ago('5 minutes'), 'nextRun' => '2999-01-01T00:00:00+00:00']);
		$this->schedule('held', ['pendingOccurrence' => 'held:k', 'lastStatus' => 'running', 'pendingSince' => self::ago('2 hours'), 'nextRun' => '2999-01-01T00:00:00+00:00']);
		$this->lockHeld = true;

		$this->service()->run();

		$this->assertSame([], $this->saves);
		$this->assertSame('running', $this->schedules['held']->getObject()['lastStatus']);
	}//end testTheSweepLeavesALiveWorkerAlone()

	/**
	 * An engine-fired run (runNow) takes the same claim: while a worker holds it, the run does not start.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function testRunNowTakesTheSameClaim(): void {
		$schedule = $this->schedule('sched-1', ['nextRun' => '2000-01-01T00:00:00+00:00']);
		$this->lockHeld = true;

		$this->service()->runNow(schedule: $schedule);

		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->jobs);
	}//end testRunNowTakesTheSameClaim()
}//end class
