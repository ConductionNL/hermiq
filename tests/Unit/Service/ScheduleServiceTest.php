<?php

/**
 * Unit tests for ScheduleService (agent-schedule-dispatcher).
 *
 * Exercises the dispatch contract without a live Nextcloud/OpenRegister:
 *   - next-run computation per kind (cron / interval / once)
 *   - commit-before-run ordering (at-most-once crash safety)
 *   - per-schedule error isolation (a bad schedule does not abort the tick)
 *   - finite repeat self-deletes at its limit
 *
 * ObjectService, AgentMapper, ConversationMapper, ChatService, IUserSession,
 * IUserManager and IConfig are all mocked (OpenRegister classes are supplied by
 * tests/Stubs).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\EngineRequiredException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-schedule-dispatcher ScheduleService.
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */
class ScheduleServiceTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock AgentMapper.
	 *
	 * @var AgentMapper&MockObject
	 */
	private AgentMapper $agentMapper;

	/**
	 * Mock ConversationMapper.
	 *
	 * @var ConversationMapper&MockObject
	 */
	private ConversationMapper $conversationMapper;

	/**
	 * Mock ChatService.
	 *
	 * @var ChatService&MockObject
	 */
	private ChatService $chatService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock IUserManager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Mock IConfig.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig $config;

	/**
	 * Mock DeliveryService.
	 *
	 * @var DeliveryService&MockObject
	 */
	private DeliveryService $deliveryService;

	/**
	 * Mock AuditTrailMapper (captures explicit per-run entries).
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Real RedactionService (force-redacts the audited summary).
	 *
	 * @var RedactionService
	 */
	private RedactionService $redactionService;

	/**
	 * Recorded createAuditTrailEntry() calls: each ['action' => ..., 'context' => ...].
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $auditCalls = [];

	/**
	 * Mock ApprovalService (human-approval gate).
	 *
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService $approvalService;

	/**
	 * Mock IAppConfig (agent-engine-port feature flag, default off).
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock in-app Engine facade (agent-engine-port; only used when the flag is on).
	 *
	 * @var Engine&MockObject
	 */
	private Engine $engine;

	/**
	 * Mock BudgetService (cost-guardrails hard-cap gate + soft-threshold warning).
	 *
	 * @var BudgetService&MockObject
	 */
	private BudgetService $budgetService;

	/**
	 * Mock GuardrailPolicyService (agent-guardrails) — defaults to the fully-open
	 * policy so the base dispatcher tests see zero behavior change unless a test
	 * overrides it.
	 *
	 * @var GuardrailPolicyService&MockObject
	 */
	private GuardrailPolicyService $guardrailPolicyService;

	/**
	 * Mock AgentVersionService (agent-versioning) — defaults to a fixed version
	 * id so the base dispatcher tests see a stable, non-null pin unless a test
	 * overrides it.
	 *
	 * @var AgentVersionService&MockObject
	 */
	private AgentVersionService $agentVersionService;

	/**
	 * Real DelegationContext (sub-agent-delegation) — a plain, stateful value
	 * object, not a mock, so tests can inspect the pushed/popped frame after a
	 * run (depth/anchor/ancestry/fan-out).
	 *
	 * @var DelegationContext
	 */
	private DelegationContext $delegationContext;

	/**
	 * Service under test.
	 *
	 * @var ScheduleService
	 */
	private ScheduleService $service;

	/**
	 * Wire up fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->agentMapper = $this->createMock(AgentMapper::class);
		$this->conversationMapper = $this->createMock(ConversationMapper::class);
		$this->chatService = $this->createMock(ChatService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);

		// setRegister/setSchema are chainable — return the service itself.
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

		// Default timezone resolution → Europe/Amsterdam for the owner.
		$this->config->method('getUserValue')->willReturn('Europe/Amsterdam');
		$this->config->method('getSystemValueString')->willReturn('UTC');

		// Owner resolves to a live user by default (per-UID); tests may narrow this.
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'ghost') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}
		);

		// Agent + conversation wiring for the agent turn.
		$agent = new Agent();
		$agent->setId(7);
		$this->agentMapper->method('findByUuid')->willReturn($agent);
		$this->conversationMapper->method('insert')->willReturnCallback(
			function (Conversation $c): Conversation {
				$c->setId(99);
				return $c;
			}
		);
		$this->chatService->method('processMessage')->willReturn(['message' => 'agent output']);

		// Delivery succeeds cleanly by default (no warning ⇒ lastDeliveryError null).
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: true, channel: 'none', fellBack: false, warning: null)
		);

		// Capture every explicit per-run audit entry the dispatcher writes.
		$this->auditCalls = [];
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->auditCalls[] = ['action' => $action, 'context' => $context];
				$entry = new AuditTrail();
				$entry->setAction($action);
				$entry->setChanged($context);
				return $entry;
			}
		);

		// Real redactor (force-redacts regardless of the frozen toggle).
		$this->redactionService = new RedactionService($this->config);

		// Approval gate is not exercised by the base dispatcher tests.
		$this->approvalService = $this->createMock(ApprovalService::class);

		// Agent-engine-port feature flag defaults OFF; the in-app Engine mock is
		// untouched unless a test flips the flag to 'true'.
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('false');
		$this->engine = $this->createMock(Engine::class);

		// Budget gate is not exercised by the base dispatcher tests: never blocked,
		// and the soft-threshold check is a no-op unless a test overrides it.
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(false);

		// Fully-open policy by default (no organisation opted in) — zero behavior
		// change for the base dispatcher tests unless a test overrides it.
		$this->guardrailPolicyService = $this->createMock(GuardrailPolicyService::class);
		$this->guardrailPolicyService->method('effectivePolicyFor')->willReturn(
			[
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => 'off'],
				'toolPolicy' => [],
			]
		);
		$this->guardrailPolicyService->method('filterInput')->willReturnCallback(
			static fn (array $policy, string $text): array => ['text' => $text, 'blocked' => false, 'reason' => null]
		);
		$this->guardrailPolicyService->method('filterOutput')->willReturnCallback(
			static fn (array $policy, string $text): array => ['text' => $text, 'blocked' => false, 'reason' => null]
		);

		// agent-versioning: a stable, non-null pin by default (never fatal to a run).
		$this->agentVersionService = $this->createMock(AgentVersionService::class);
		$this->agentVersionService->method('currentVersionId')->willReturn('version-1');

		// Sub-agent-delegation: a real, fresh call-stack per test (no frame pushed
		// until an Engine-path run actually starts).
		$this->delegationContext = new DelegationContext();

		$this->service = $this->makeService();

	}//end setUp()

	/**
	 * Build a ScheduleService wired to the current mocks.
	 *
	 * @return ScheduleService
	 */
	private function makeService(): ScheduleService {
		return new ScheduleService(
			objectService: $this->objectService,
			agentMapper: $this->agentMapper,
			conversationMapper: $this->conversationMapper,
			chatService: $this->chatService,
			userSession: $this->userSession,
			userManager: $this->userManager,
			config: $this->config,
			logger: $this->createMock(LoggerInterface::class),
			deliveryService: $this->deliveryService,
			auditTrailMapper: $this->auditTrailMapper,
			redactionService: $this->redactionService,
			approvalService: $this->approvalService,
			appConfig: $this->appConfig,
			engine: $this->engine,
			budgetService: $this->budgetService,
			guardrailPolicyService: $this->guardrailPolicyService,
			agentVersionService: $this->agentVersionService,
			skillVersionService: $this->createMock(SkillVersionService::class),
			delegationContext: $this->delegationContext,
			jobList: $this->createMock(IJobList::class),
		);

	}//end makeService()

	/**
	 * Build a schedule ObjectEntity with the given payload.
	 *
	 * @param array<string,mixed> $payload The schedule object body.
	 * @param string $uuid The object UUID.
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(array $payload, string $uuid = 'sched-1', string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end schedule()

	/**
	 * cron next-run is computed with dragonmantank/cron-expression in the owner tz;
	 * interval adds intervalMinutes; once does not recur (nextRun cleared).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-2
	 */
	public function testNextRunPerKind(): void {
		$due = [
			$this->schedule(
				[
					'kind' => 'cron',
					'cronExpr' => '0 8 * * *',
					'agentId' => 'agent-uuid',
					'prompt' => 'daily brief',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2000-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'cron-sched'
			),
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 90,
					'agentId' => 'agent-uuid',
					'prompt' => 'poll',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2000-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'interval-sched'
			),
			$this->schedule(
				[
					'kind' => 'once',
					'runAt' => '2000-01-01T00:00:00+00:00',
					'agentId' => 'agent-uuid',
					'prompt' => 'one shot',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2000-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'once-sched'
			),
		];

		$this->objectService->method('findAll')->willReturn($due);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		// First save per schedule is the commit-before-run state.
		$cronCommit = $saved[0];
		$intervalCommit = null;
		$onceCommit = null;
		foreach ($saved as $s) {
			if (($s['kind'] ?? '') === 'interval' && $intervalCommit === null) {
				$intervalCommit = $s;
			}

			if (($s['kind'] ?? '') === 'once' && $onceCommit === null) {
				$onceCommit = $s;
			}
		}

		// cron → next 08:00 Europe/Amsterdam expressed in UTC is 06:00 or 07:00 (DST).
		$cronNext = new \DateTimeImmutable($cronCommit['nextRun']);
		$this->assertSame('08', $cronNext->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H'));

		// interval → advanced (not the ancient stored value).
		$this->assertGreaterThan(
			new \DateTimeImmutable('2001-01-01T00:00:00+00:00'),
			new \DateTimeImmutable($intervalCommit['nextRun'])
		);

		// once → does not recur: nextRun null and schedule disabled.
		$this->assertNull($onceCommit['nextRun']);
		$this->assertFalse($onceCommit['enabled']);

	}//end testNextRunPerKind()

	/**
	 * Run-state is committed BEFORE the agent turn (at-most-once).
	 *
	 * Asserts saveObject with lastStatus=running and an advanced nextRun is called
	 * before ChatService::processMessage fires.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
	 */
	public function testCommitBeforeRun(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);

		$order = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$order): ObjectEntity {
				if (($object['lastStatus'] ?? '') === 'running') {
					$order[] = 'commit';
				}

				return new ObjectEntity();
			}
		);
		$this->chatService->method('processMessage')->willReturnCallback(
			function () use (&$order): array {
				$order[] = 'agent';
				return ['message' => 'out'];
			}
		);

		$this->service->run();

		$commitIndex = array_search('commit', $order, true);
		$agentIndex = array_search('agent', $order, true);
		$this->assertNotFalse($commitIndex, 'A running-state commit must occur.');
		$this->assertNotFalse($agentIndex, 'The agent must be invoked.');
		$this->assertLessThan($agentIndex, $commitIndex, 'Commit must precede the agent turn.');

	}//end testCommitBeforeRun()

	/**
	 * A failing schedule is isolated: its error is recorded and later schedules
	 * still run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
	 */
	public function testPerScheduleErrorIsolation(): void {
		$bad = $this->schedule(
			[
				'kind' => 'once',
				'runAt' => '2000-01-01T00:00:00+00:00',
				'agentId' => 'agent-uuid',
				'prompt' => 'boom',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'bad-sched',
			'ghost'
		);
		$good = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 30,
				'agentId' => 'agent-uuid',
				'prompt' => 'ok',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'good-sched',
			'alice'
		);

		$this->objectService->method('findAll')->willReturn([$bad, $good]);

		// Owner "ghost" does not resolve (see setUp) → the bad schedule throws
		// inside dispatch and is isolated.
		$errorStatuses = [];
		$okStatuses = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$errorStatuses, &$okStatuses): ObjectEntity {
				if (($object['lastStatus'] ?? '') === 'error') {
					$errorStatuses[] = $uuid;
				}

				if (($object['lastStatus'] ?? '') === 'ok') {
					$okStatuses[] = $uuid;
				}

				return new ObjectEntity();
			}
		);

		$this->service->run();

		$this->assertContains('bad-sched', $errorStatuses, 'The failing schedule must record lastStatus=error.');
		$this->assertContains('good-sched', $okStatuses, 'The healthy schedule must still complete after the failure.');

	}//end testPerScheduleErrorIsolation()

	/**
	 * Crash-safety invariant (task 4.2): a failed agent turn must NOT revert the
	 * committed run-state advance.
	 *
	 * A one-shot whose agent invocation throws must end with:
	 *   - nextRun ADVANCED away from the original past value (once → null),
	 *   - lastStatus = error,
	 *   - enabled = false (one-shot retired),
	 * so it never stays perpetually due and re-fires every tick. The failure branch
	 * must operate on the post-commit $data, never re-read the stale pre-commit
	 * entity (BUG 4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-7
	 */
	public function testFailureDoesNotRevertCommittedRunState(): void {
		$originalNextRun = '2000-01-01T00:00:00+00:00';

		// A once schedule owned by a resolvable user, but whose agent turn throws.
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'once',
						'runAt' => $originalNextRun,
						'agentId' => 'agent-uuid',
						'prompt' => 'will fail',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => $originalNextRun,
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'crash-sched',
					'alice'
				),
			]
		);

		// The agent invocation throws — simulating a missing/erroring agent.
		$this->chatService->method('processMessage')->willThrowException(
			new \RuntimeException('agent exploded')
		);

		// find() re-fetch (last-resort recordFailure path) must not be needed here,
		// but if it is, return the committed state so nothing is reverted.
		$this->objectService->method('find')->willReturn(null);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		// The LAST save is the finalised (post-turn) state — assert the advance held.
		$this->assertNotEmpty($saved, 'The schedule must persist run-state.');
		$final = $saved[array_key_last($saved)];

		$this->assertSame('error', $final['lastStatus'], 'A failed turn must record lastStatus=error.');
		$this->assertSame('agent exploded', $final['lastError'], 'lastError must carry the agent failure.');
		// once → nextRun advanced to null (does not recur); crucially NOT the original.
		$this->assertNotSame(
			$originalNextRun,
			$final['nextRun'],
			'nextRun must NOT be reverted to the original past value on failure.'
		);
		$this->assertNull($final['nextRun'], 'A one-shot must not recur after firing.');
		$this->assertFalse($final['enabled'], 'A one-shot must be retired (enabled=false) even on failure.');

	}//end testFailureDoesNotRevertCommittedRunState()

	/**
	 * A finite repeat that reaches its limit is deleted via ObjectService.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
	 */
	public function testFiniteRepeatDeletesAtLimit(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 1440,
						'agentId' => 'agent-uuid',
						'prompt' => 'nightly',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 3, 'completed' => 2],
					],
					'finite-sched'
				),
			]
		);

		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$deleted): bool {
				$deleted[] = $uuid;
				return true;
			}
		);

		$this->service->run();

		$this->assertContains('finite-sched', $deleted, 'Finite repeat at its limit must be deleted.');

	}//end testFiniteRepeatDeletesAtLimit()

	/**
	 * All date-time fields are ISO-8601 normalised (with `T`) before every save.
	 *
	 * OpenRegister's getObject() returns date-times as `Y-m-d H:i:s` (space, no
	 * `T`), but saveObject re-validates the whole object against the schema's
	 * `date-time` format. A once schedule carries `runAt` through unchanged, so the
	 * dispatcher must normalise it (and nextRun) before saving or the write is
	 * rejected. This also covers the recordFailure() save path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-3
	 */
	public function testDateFieldsAreIsoNormalisedBeforeSave(): void {
		// Space-format (Y-m-d H:i:s) date-times as OR's getObject() would return them.
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'once',
						'runAt' => '2020-01-01 00:00:00',
						'agentId' => 'agent-uuid',
						'prompt' => 'one shot',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01 00:00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'once-sched'
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$this->assertNotEmpty($saved, 'A save must occur.');
		foreach ($saved as $object) {
			// runAt was carried through from getObject in space format — must be ISO.
			if (array_key_exists('runAt', $object) === true && $object['runAt'] !== null && $object['runAt'] !== '') {
				$this->assertStringContainsString(
					'T',
					(string)$object['runAt'],
					'runAt must be ISO-8601 (with T) before save.'
				);
				$this->assertStringNotContainsString(
					'2020-01-01 00:00:00',
					(string)$object['runAt'],
					'runAt must not remain in space-separated form.'
				);
			}

			// nextRun, whether recomputed or carried through, must be ISO too.
			if (array_key_exists('nextRun', $object) === true && $object['nextRun'] !== null && $object['nextRun'] !== '') {
				$this->assertStringContainsString(
					'T',
					(string)$object['nextRun'],
					'nextRun must be ISO-8601 (with T) before save.'
				);
			}
		}//end foreach

	}//end testDateFieldsAreIsoNormalisedBeforeSave()

	/**
	 * An infinite schedule serialises to a save payload with `repeat = null`.
	 *
	 * A schedule created with `repeat: null` is infinite, but OR's getObject()
	 * materialises the nullable object as `{"times": 0, "completed": 0}` on read,
	 * which then fails the schema's `repeat.times` `minimum: 1` when the whole object
	 * is saved back. The dispatcher must collapse any non-finite repeat to `null`
	 * before every save so the write is not rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
	 */
	public function testInfiniteScheduleSerialisesRepeatAsNull(): void {
		// OR returns the nullable repeat as {times:0, completed:0} on read.
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'forever',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'infinite-sched'
				),
			]
		);

		$saved = [];
		$deleted = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$deleted): bool {
				$deleted[] = $uuid;
				return true;
			}
		);

		$this->service->run();

		$this->assertNotEmpty($saved, 'An infinite schedule must persist run-state.');
		foreach ($saved as $object) {
			$this->assertArrayHasKey('repeat', $object, 'The saved payload must carry a repeat key.');
			$this->assertNull(
				$object['repeat'],
				'An infinite schedule must serialise repeat as null (never {times:0}).'
			);
		}

		// Infinite schedules must never self-delete.
		$this->assertNotContains('infinite-sched', $deleted, 'An infinite schedule must not be deleted.');

	}//end testInfiniteScheduleSerialisesRepeatAsNull()

	/**
	 * A genuine finite repeat (`times >= 1`) is preserved verbatim on save.
	 *
	 * The repeat sanitiser must repair OR's round-trip artifact only — it must not
	 * discard a user-supplied finite repeat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-3-6
	 */
	public function testFiniteRepeatIsPreservedOnSave(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'finite',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 5, 'completed' => 1],
					],
					'finite-live'
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$this->assertNotEmpty($saved, 'A finite schedule must persist run-state.');
		foreach ($saved as $object) {
			$this->assertIsArray($object['repeat'], 'A finite repeat must stay an object.');
			$this->assertSame(5, $object['repeat']['times'], 'The finite times value must be preserved.');
			$this->assertGreaterThanOrEqual(0, $object['repeat']['completed'], 'completed must be a non-negative int.');
		}

	}//end testFiniteRepeatIsPreservedOnSave()

	/**
	 * A delivery failure keeps the run 'ok' and persists lastDeliveryError (never fatal).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-3-3
	 */
	public function testDeliveryFailurePersistsLastDeliveryErrorAndKeepsRunOk(): void {
		// Delivery reports a warning (degraded) rather than throwing.
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: true, channel: 'notification', fellBack: true, warning: 'talk unavailable')
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'talk',
						'deliverTarget' => 'room-x',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('ok', $final['lastStatus'], 'A delivery failure must NOT fail the run.');
		$this->assertNull($final['lastError'], 'No run error on a delivery-only failure.');
		$this->assertSame('talk unavailable', $final['lastDeliveryError'], 'The warning must persist as lastDeliveryError.');

	}//end testDeliveryFailurePersistsLastDeliveryErrorAndKeepsRunOk()

	/**
	 * A clean delivery clears lastDeliveryError (null).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-3-3
	 */
	public function testSuccessfulDeliveryClearsLastDeliveryError(): void {
		// Default deliveryService (setUp) returns a clean result (warning null).
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'talk',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
						'lastDeliveryError' => 'a previous failure',
					]
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('ok', $final['lastStatus']);
		$this->assertNull($final['lastDeliveryError'], 'A clean delivery must clear lastDeliveryError.');

	}//end testSuccessfulDeliveryClearsLastDeliveryError()

	/**
	 * A successful run writes an explicit action='run' audit entry with owner status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-2-4
	 */
	public function testSuccessfulRunWritesRunAuditEntry(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->run();

		$this->assertCount(1, $this->auditCalls, 'Exactly one run audit entry must be written.');
		$this->assertSame('run', $this->auditCalls[0]['action']);
		$context = $this->auditCalls[0]['context'];
		$this->assertSame('ok', $context['status'], 'A successful run must record status=ok.');
		$this->assertSame('agent-uuid', $context['agentId']);
		$this->assertArrayHasKey('startedAt', $context);
		$this->assertArrayHasKey('endedAt', $context);
		$this->assertArrayHasKey('summary', $context);
		$this->assertSame('version-1', $context['agentVersion'], 'agent-versioning: the executing agent version must be pinned.');

	}//end testSuccessfulRunWritesRunAuditEntry()

	/**
	 * agent-versioning: the pinned agentVersion is looked up for the SCHEDULE's
	 * bound agentId, and a version-lookup failure never breaks the run itself
	 * (the entry is still written, without the pin).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it
	 */
	public function testVersionPinFailureNeverBreaksTheRun(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->agentVersionService = $this->createMock(AgentVersionService::class);
		$this->agentVersionService->method('currentVersionId')->with('agent-uuid')->willReturn(null);
		$this->service = $this->makeService();

		$this->service->run();

		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status'], 'A version-pin miss must not affect the run outcome.');
		$this->assertNull($this->auditCalls[0]['context']['agentVersion']);

	}//end testVersionPinFailureNeverBreaksTheRun()

	/**
	 * A failed run still writes an audit entry with status=error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-2-4
	 */
	public function testFailedRunStillWritesRunAuditEntry(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'once',
						'runAt' => '2000-01-01T00:00:00+00:00',
						'agentId' => 'agent-uuid',
						'prompt' => 'will fail',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'crash-sched',
					'alice'
				),
			]
		);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('boom'));
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->run();

		$this->assertCount(1, $this->auditCalls, 'A failed run must still be audited.');
		$this->assertSame('error', $this->auditCalls[0]['context']['status']);

	}//end testFailedRunStillWritesRunAuditEntry()

	/**
	 * An audit-write failure is swallowed — it must not abort the tick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-2-4
	 */
	public function testAuditWriteFailureDoesNotFailTheTick(): void {
		// A mapper that throws on every audit write.
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('createAuditTrailEntry')->willThrowException(
			new \RuntimeException('audit backend down')
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		// Must not throw despite the audit backend failing.
		$this->service->run();

		$final = end($saved);
		$this->assertSame('ok', $final['lastStatus'], 'The run must still finalise despite an audit-write failure.');

	}//end testAuditWriteFailureDoesNotFailTheTick()

	/**
	 * The audited summary is redacted BEFORE the write (append-only chain).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-2-4
	 */
	public function testRunAuditSummaryIsRedactedBeforeWrite(): void {
		// The agent output leaks an API-key-shaped token.
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willReturn(
			['message' => 'done, key=sk-ABCDEF1234567890XYZ used']
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'nextRun' => '2020-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					]
				),
			]
		);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->run();

		$this->assertCount(1, $this->auditCalls);
		$summary = (string)$this->auditCalls[0]['context']['summary'];
		$this->assertStringNotContainsString(
			'sk-ABCDEF1234567890XYZ',
			$summary,
			'The raw API key must never reach the immutable audit context.'
		);

	}//end testRunAuditSummaryIsRedactedBeforeWrite()

	/**
	 * runNow() drives the SAME dispatch path as a tick for one schedule: it persists
	 * the run-state, invokes the agent, and writes exactly one action='run' audit
	 * entry with status=ok — without going through findDueSchedules().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
	 */
	public function testRunNowDrivesDispatchPath(): void {
		$schedule = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'run me now',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'now-sched'
		);

		// findDueSchedules() must NOT be consulted — runNow targets one schedule
		// directly. It does call findAll once to load engaged kill-switches (none here).
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->atLeastOnce())->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow($schedule);

		// status=ok proves the agent turn ran (setUp stubs processMessage → 'agent output').
		$this->assertCount(1, $this->auditCalls, 'A manual run must write exactly one run audit entry.');
		$this->assertSame('run', $this->auditCalls[0]['action']);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status']);
		$this->assertSame('agent-uuid', $this->auditCalls[0]['context']['agentId']);

	}//end testRunNowDrivesDispatchPath()

	/**
	 * An engaged kill-switch halts a due schedule for that organisation: the agent
	 * NEVER runs, the schedule records lastStatus='skipped_killswitch', and one audit
	 * entry captures the skip. Runs for other organisations are unaffected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-3-2
	 */
	public function testKillSwitchSkipsRun(): void {
		// The agent must never be invoked for a halted organisation.
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');
		$this->service = $this->makeService();

		$killed = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'killed-sched'
		);
		$killed->setOrganisation('org-x');

		$control = new ObjectEntity();
		$control->setUuid('ctrl-1');
		$control->setOrganisation('org-x');
		$control->setObject(['engaged' => true]);

		// findAll: call 1 = due schedules; call 2 = engaged kill-switches.
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$killed], [$control]);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$this->assertNotEmpty($saved, 'A halted schedule must still persist its skip state.');
		$final = end($saved);
		$this->assertSame('skipped_killswitch', $final['lastStatus'], 'A killed run must record skipped_killswitch.');
		$this->assertCount(1, $this->auditCalls, 'A halted run must still be audited.');
		$this->assertSame('skipped_killswitch', $this->auditCalls[0]['context']['status']);

	}//end testKillSwitchSkipsRun()

	/**
	 * isOrganisationEngaged() — the reusable public kill-switch check
	 * FlowAgentRunService calls so a flow-triggered run is halted by the SAME
	 * TenantControl data source a scheduled tick already respects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
	 */
	public function testIsOrganisationEngagedReflectsTenantControl(): void {
		$control = new ObjectEntity();
		$control->setUuid('ctrl-1');
		$control->setOrganisation('org-x');
		$control->setObject(['engaged' => true]);

		$this->objectService->method('findAll')->willReturn([$control]);

		$this->assertTrue($this->service->isOrganisationEngaged(organisation: 'org-x'));
		$this->assertFalse($this->service->isOrganisationEngaged(organisation: 'org-y'));
		$this->assertFalse($this->service->isOrganisationEngaged(organisation: ''));

	}//end testIsOrganisationEngagedReflectsTenantControl()

	/**
	 * A schedule requiring approval does NOT run its agent: the gate ensures a pending
	 * Approval (idempotent, once per due occurrence) and records lastStatus=awaiting_approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-1
	 */
	public function testApprovalGateCreatesPendingAndSkipsRun(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');

		// The gate must ask ApprovalService for exactly one pending Approval.
		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->approvalService->expects($this->once())->method('ensurePendingApproval');
		$this->service = $this->makeService();

		$gated = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'sensitive',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'gated-sched'
		);

		// call 1 = due; call 2 = engaged kill-switches (none).
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$gated], []);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('awaiting_approval', $final['lastStatus'], 'A gated run must await approval, not run.');
		$this->assertSame('awaiting_approval', $this->auditCalls[0]['context']['status']);

	}//end testApprovalGateCreatesPendingAndSkipsRun()

	/**
	 * "Run now" on a gated schedule also gates: the agent does not run and a pending
	 * Approval is ensured (default bypass=false).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-1
	 */
	public function testRunNowGatesApprovalWhenNotBypassed(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');

		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->approvalService->expects($this->once())->method('ensurePendingApproval');
		$this->service = $this->makeService();

		$gated = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'sensitive',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'gated-sched'
		);

		// runNow only loads engaged kill-switches (none) — no due-schedule scan.
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow($gated);

		$this->assertSame('awaiting_approval', $this->auditCalls[0]['context']['status']);

	}//end testRunNowGatesApprovalWhenNotBypassed()

	/**
	 * An authorised approval-run (runNow bypass=true) executes the agent WITHOUT
	 * re-gating — it never creates another pending Approval.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
	 */
	public function testApprovalBypassRunsAgentWithoutGating(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->once())->method('processMessage')->willReturn(['message' => 'ran']);

		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->approvalService->expects($this->never())->method('ensurePendingApproval');
		$this->service = $this->makeService();

		$gated = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'authorised',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'gated-sched'
		);

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow($gated, true);

		$this->assertSame('run', $this->auditCalls[0]['action']);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status'], 'An approved run must execute the agent.');

	}//end testApprovalBypassRunsAgentWithoutGating()

	/**
	 * The kill-switch takes priority over an authorised approval-run: even with the
	 * approval gate bypassed, a halted organisation's run is skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-3-2
	 */
	public function testKillSwitchOverridesApprovalBypass(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');
		$this->service = $this->makeService();

		$gated = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'authorised but halted',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'gated-sched'
		);
		$gated->setOrganisation('org-x');

		$control = new ObjectEntity();
		$control->setUuid('ctrl-1');
		$control->setOrganisation('org-x');
		$control->setObject(['engaged' => true]);

		// runNow loads engaged kill-switches → org-x engaged.
		$this->objectService->method('findAll')->willReturn([$control]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow($gated, true);

		$this->assertSame('skipped_killswitch', $this->auditCalls[0]['context']['status']);

	}//end testKillSwitchOverridesApprovalBypass()

	/**
	 * GATE 2 (cost-guardrails): a budget at its hard cap blocks a due schedule — the
	 * agent is NEVER invoked, the schedule records lastStatus='skipped_budget', and one
	 * audit entry captures the skip (mirrors testKillSwitchSkipsRun()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testBudgetHardCapSkipsRun(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');

		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(true);
		$this->service = $this->makeService();

		$budgeted = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'budgeted-sched'
		);
		$budgeted->setOrganisation('org-x');

		// call 1 = due schedules; call 2 = engaged kill-switches (none).
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$budgeted], []);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$this->assertNotEmpty($saved, 'A budget-exhausted schedule must still persist its skip state.');
		$final = end($saved);
		$this->assertSame('skipped_budget', $final['lastStatus'], 'A budget-exhausted run must record skipped_budget.');
		$this->assertCount(1, $this->auditCalls, 'A budget-exhausted run must still be audited.');
		$this->assertSame('skipped_budget', $this->auditCalls[0]['context']['status']);

	}//end testBudgetHardCapSkipsRun()

	/**
	 * The budget gate blocks even an authorised approval-run bypass — mirrors the
	 * kill-switch's absolute priority (see testKillSwitchOverridesApprovalBypass()):
	 * a budget-exhausted schedule never runs, no matter the approval state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testBudgetHardCapOverridesApprovalBypass(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');

		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(true);

		// The budget gate must take priority: no pending Approval is ever created for
		// a budget-exhausted occurrence, even though this schedule requiresApproval
		// and the caller passed bypassApprovalGate=true.
		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->approvalService->expects($this->never())->method('ensurePendingApproval');
		$this->service = $this->makeService();

		$gated = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'authorised but budget-exhausted',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'gated-sched'
		);

		// runNow loads engaged kill-switches (none) via findAll.
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow($gated, true);

		$this->assertSame('skipped_budget', $this->auditCalls[0]['context']['status']);

	}//end testBudgetHardCapOverridesApprovalBypass()

	/**
	 * The soft-threshold check runs on every dispatch tick the schedule is due,
	 * independent of whether the hard cap is reached — DeliveryService's warning is a
	 * side effect of BudgetService::checkAndDeliverWarnings(), invoked with the
	 * schedule's own organisation/agentId.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testBudgetSoftThresholdCheckRunsEveryDispatch(): void {
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(false);
		$this->budgetService->expects($this->once())
			->method('checkAndDeliverWarnings')
			->with('org-y', 'agent-uuid');
		$this->service = $this->makeService();

		$schedule = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2000-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'sched-warn'
		);
		$schedule->setOrganisation('org-y');

		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$schedule], []);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->run();

		$this->assertSame('ok', $this->auditCalls[0]['context']['status'], 'Below the hard cap, the run must proceed normally.');

	}//end testBudgetSoftThresholdCheckRunsEveryDispatch()

	/**
	 * Flag OFF (default): the run goes through OpenRegister's ChatService exactly
	 * as before agent-engine-port — the in-app Engine is NEVER touched — and the
	 * usage shape from the ChatService result is captured into the run audit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-6-2
	 */
	public function testEngineFlagOffUsesOrChatService(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->once())->method('processMessage')->willReturn(
			[
				'message' => 'or output',
				'usage' => [
					'promptTokens' => 3,
					'completionTokens' => 7,
				],
			]
		);
		$this->engine = $this->createMock(Engine::class);
		$this->engine->expects($this->never())->method('processMessage');
		$this->service = $this->makeService();

		// runNow loads engaged kill-switches (none) via findAll.
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'flag-off-sched'
			)
		);

		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status']);
		$this->assertSame(
			[
				'promptTokens' => 3,
				'completionTokens' => 7,
			],
			$this->auditCalls[0]['context']['usage'],
			'The flag-off path must keep capturing the ChatService usage shape.'
		);

	}//end testEngineFlagOffUsesOrChatService()

	/**
	 * Flag ON: the run goes through the in-app Engine against hermiq-register
	 * objects — OpenRegister's ChatService, AgentMapper and ConversationMapper
	 * are NEVER touched — the agent resolves via ObjectService against the
	 * `agent` schema, a hermiq `conversation` object is created, and the
	 * engine-reported usage shape is captured identically to the flag-off path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-6-1
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-6-2
	 */
	public function testEngineFlagOnUsesInAppEngine(): void {
		// Flip the feature flag ON.
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');

		// The OR chat path must be fully bypassed.
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');
		$this->agentMapper = $this->createMock(AgentMapper::class);
		$this->agentMapper->expects($this->never())->method('findByUuid');
		$this->conversationMapper = $this->createMock(ConversationMapper::class);
		$this->conversationMapper->expects($this->never())->method('insert');

		// The in-app Engine runs the turn against the created conversation UUID.
		$this->engine = $this->createMock(Engine::class);
		$this->engine->expects($this->once())->method('processMessage')->with(
			$this->equalTo('conv-uuid-1'),
			$this->equalTo('alice'),
			$this->equalTo('go')
		)->willReturn(
			[
				'message' => 'engine output',
				'usage' => [
					'promptTokens' => 11,
					'completionTokens' => 22,
				],
			]
		);
		$this->service = $this->makeService();

		// The agent resolves as a hermiq-register object (not via AgentMapper).
		$agentObject = new ObjectEntity();
		$agentObject->setUuid('agent-uuid');
		$agentObject->setObject(['name' => 'Scheduled agent']);
		$this->objectService->method('find')->willReturn($agentObject);

		// runNow loads engaged kill-switches (none) via findAll.
		$this->objectService->method('findAll')->willReturn([]);

		// Capture the conversation write; run-state persists share the same mock.
		$savedConversations = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null) use (&$savedConversations): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($savedConversations));
				if ($schema === 'conversation') {
					$savedConversations[] = $object;
					$entity->setUuid('conv-uuid-1');
				}

				return $entity;
			}
		);

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'flag-on-sched'
			)
		);

		// The hermiq conversation object carries the ported title/owner/agent shape.
		$this->assertCount(1, $savedConversations);
		$this->assertSame(
			[
				'title' => 'Hermiq scheduled run',
				'userId' => 'alice',
				'agentId' => 'agent-uuid',
			],
			$savedConversations[0]
		);

		// The run finalised ok and the engine usage shape survived into the audit.
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('ok', $this->auditCalls[0]['context']['status']);
		$this->assertSame(
			[
				'promptTokens' => 11,
				'completionTokens' => 22,
			],
			$this->auditCalls[0]['context']['usage'],
			'The flag-on path must capture the Engine usage shape identically.'
		);

	}//end testEngineFlagOnUsesInAppEngine()

	/**
	 * run-trace-observability (TC-1): on the in-app Engine path, the persisted
	 * run audit entry's `changed.steps` includes the tool step the Engine's
	 * RunTraceCollector recorded (threaded in via the `trace` argument
	 * ScheduleService now passes), plus the `delivery` step ScheduleService
	 * itself appends, and `toolStepsAvailable=true`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function testEngineFlagOnCapturesToolStepsFromCollector(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');

		$this->engine = $this->createMock(Engine::class);
		$this->engine->method('processMessage')->willReturnCallback(
			static function (
				string $conversationId,
				string $userId,
				string $userMessage,
				array $selectedViews = [],
				array $selectedTools = [],
				array $ragSettings = [],
				array $context = [],
				$channel = null,
				$trace = null,
			): array {
				// Simulate a tool call happening during the turn, on the SAME
				// collector ScheduleService threaded through processMessage().
				$token = $trace?->startStep(type: 'tool', name: 'openregister.searchObjects');
				if ($token !== null) {
					$trace?->endStep(token: $token, outcome: 'ok');
				}

				return ['message' => 'engine output', 'usage' => []];
			}
		);
		$this->service = $this->makeService();

		$agentObject = new ObjectEntity();
		$agentObject->setUuid('agent-uuid');
		$agentObject->setObject(['name' => 'Scheduled agent']);
		$this->objectService->method('find')->willReturn($agentObject);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('conv-uuid-1');
				return $entity;
			}
		);

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'flag-on-steps-sched'
			)
		);

		$this->assertCount(1, $this->auditCalls);
		$context = $this->auditCalls[0]['context'];
		$this->assertTrue($context['toolStepsAvailable']);
		$this->assertSame(
			['tool', 'delivery'],
			array_column($context['steps'], 'type'),
			'The collector-recorded tool step and the appended delivery step must both be present.'
		);
		$this->assertSame('openregister.searchObjects', $context['steps'][0]['name']);
		$this->assertSame('ok', $context['steps'][0]['outcome']);

	}//end testEngineFlagOnCapturesToolStepsFromCollector()

	/**
	 * run-trace-observability (TC-2): on the default OpenRegister `ChatService`
	 * path, coarse context/history/llm steps are derived from the `timings`
	 * bucket the call already returns, a `delivery` step is appended, no
	 * `tool`-type step is ever fabricated, and `toolStepsAvailable=false`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function testOrChatServicePathCapturesCoarseStepsOnly(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willReturn(
			[
				'message' => 'or output',
				'usage' => [],
				'timings' => [
					'context' => '0.18s',
					'history' => '0.01s',
					'llm' => '2.94s',
					'total' => '3.13s',
				],
			]
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'flag-off-steps-sched'
			)
		);

		$this->assertCount(1, $this->auditCalls);
		$context = $this->auditCalls[0]['context'];
		$this->assertFalse($context['toolStepsAvailable']);
		$this->assertSame(
			['context', 'history', 'llm', 'delivery'],
			array_column($context['steps'], 'type'),
			'Coarse steps only — never a fabricated tool step on the OR ChatService path.'
		);
		$this->assertSame([0, 1, 2, 3], array_column($context['steps'], 'seq'));

	}//end testOrChatServicePathCapturesCoarseStepsOnly()

	/**
	 * run-trace-observability: when `$result['timings']` is absent, the coarse
	 * steps are simply omitted — never a fabricated duration. Only the
	 * ScheduleService-appended `delivery` step survives.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function testMissingTimingsOmitsCoarseStepsWithoutFabrication(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willReturn(['message' => 'or output', 'usage' => []]);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'no-timings-sched'
			)
		);

		$this->assertCount(1, $this->auditCalls);
		$context = $this->auditCalls[0]['context'];
		$this->assertFalse($context['toolStepsAvailable']);
		$this->assertSame(['delivery'], array_column($context['steps'], 'type'));

	}//end testMissingTimingsOmitsCoarseStepsWithoutFabrication()

	/**
	 * run-trace-observability (TC-8-adjacent): a delivery failure is recorded
	 * as a `delivery` step with `outcome=error`, and the run still finalises
	 * `ok` — the pre-existing non-fatal delivery contract is unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function testDeliveryFailureRecordsErrorStepButRunStaysOk(): void {
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: false, channel: 'talk', fellBack: false, warning: 'Talk room misconfigured')
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'talk',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'delivery-fail-sched'
			)
		);

		$this->assertCount(1, $this->auditCalls);
		$context = $this->auditCalls[0]['context'];
		$this->assertSame('ok', $context['status'], 'A delivery failure must never fail the run itself.');

		$deliverySteps = array_values(
			array_filter($context['steps'], static fn (array $step): bool => $step['type'] === 'delivery')
		);
		$this->assertCount(1, $deliverySteps);
		$this->assertSame('error', $deliverySteps[0]['outcome']);

	}//end testDeliveryFailureRecordsErrorStepButRunStaysOk()

	/**
	 * delivery-channels: the `delivery` trace step's `name` reflects the channel
	 * `DeliveryResult` reports as actually used, not a single hard-coded
	 * "Talk delivery" literal — `type` stays `delivery` in every case.
	 *
	 * @param string $channel The channel DeliveryResult reports as used.
	 * @param string $expectedName The expected trace step `name`.
	 *
	 * @return void
	 *
	 * @dataProvider deliveryChannelNameProvider
	 *
	 * @spec openspec/changes/delivery-channels/specs/run-audit-log/spec.md#requirement-the-delivery-trace-step-reflects-the-channel-actually-used-mvp
	 */
	public function testDeliveryStepNameReflectsChannelUsed(string $channel, string $expectedName): void {
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: true, channel: $channel, fellBack: false, warning: null)
		);
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => $channel,
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'delivery-name-sched-' . $channel
			)
		);

		$context = $this->auditCalls[0]['context'];
		$deliverySteps = array_values(
			array_filter($context['steps'], static fn (array $step): bool => $step['type'] === 'delivery')
		);
		$this->assertCount(1, $deliverySteps);
		$this->assertSame($expectedName, $deliverySteps[0]['name']);
		$this->assertSame('delivery', $deliverySteps[0]['type']);

	}//end testDeliveryStepNameReflectsChannelUsed()

	/**
	 * Channel ⇒ expected trace-step name pairs for testDeliveryStepNameReflectsChannelUsed().
	 *
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function deliveryChannelNameProvider(): array {
		return [
			'talk' => ['talk', 'Talk delivery'],
			'notification' => ['notification', 'Notification delivery'],
			'email' => ['email', 'Email delivery'],
			'webhook' => ['webhook', 'Webhook delivery'],
			'none' => ['none', 'No delivery'],
		];

	}//end deliveryChannelNameProvider()

	/**
	 * agent-capability-profile: when the bound Agent declares a valid, active
	 * `actingUser`, the engine-enabled run impersonates THAT identity instead of the
	 * schedule owner — the conversation's userId, the Engine's userId argument, and
	 * the run audit's `runAsUser` all reflect the acting user, not the owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-2
	 * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-3
	 */
	public function testActingUserOverridesOwnerImpersonation(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');

		$this->engine = $this->createMock(Engine::class);
		$this->engine->expects($this->once())->method('processMessage')->with(
			$this->equalTo('conv-uuid-1'),
			$this->equalTo('svc-bot'),
			$this->equalTo('go')
		)->willReturn(['message' => 'engine output', 'usage' => []]);
		$this->service = $this->makeService();

		$agentObject = new ObjectEntity();
		$agentObject->setUuid('agent-uuid');
		$agentObject->setObject(['name' => 'Scheduled agent', 'actingUser' => 'svc-bot']);
		$this->objectService->method('find')->willReturn($agentObject);
		$this->objectService->method('findAll')->willReturn([]);

		$impersonated = [];
		$this->userSession->method('setUser')->willReturnCallback(
			function (?IUser $user) use (&$impersonated): void {
				if ($user !== null) {
					$impersonated[] = $user->getUID();
				}
			}
		);

		$savedConversations = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null) use (&$savedConversations): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($savedConversations));
				if ($schema === 'conversation') {
					$savedConversations[] = $object;
					$entity->setUuid('conv-uuid-1');
				}

				return $entity;
			}
		);

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'acting-user-sched',
				'alice'
			)
		);

		$this->assertContains('svc-bot', $impersonated, 'The actingUser must be impersonated, not the schedule owner.');
		$this->assertNotContains('alice', $impersonated, 'The schedule owner must NOT be impersonated when actingUser overrides it.');
		$this->assertSame('svc-bot', $savedConversations[0]['userId'], 'The conversation must be attributed to the acting user.');
		$this->assertSame(
			'svc-bot',
			$this->auditCalls[0]['context']['runAsUser'],
			'The audit trail must record the identity that actually ran.'
		);

	}//end testActingUserOverridesOwnerImpersonation()

	/**
	 * agent-capability-profile: an actingUser naming a nonexistent user falls back to
	 * the schedule owner — the run is NOT failed by a misconfigured profile field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-1
	 */
	public function testActingUserFallsBackToOwnerWhenNonexistent(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');

		$this->engine = $this->createMock(Engine::class);
		$this->engine->expects($this->once())->method('processMessage')->with(
			$this->anything(),
			$this->equalTo('alice'),
			$this->anything()
		)->willReturn(['message' => 'engine output', 'usage' => []]);
		$this->service = $this->makeService();

		$agentObject = new ObjectEntity();
		$agentObject->setUuid('agent-uuid');
		// 'ghost' is the sentinel the shared userManager mock resolves to null (setUp()).
		$agentObject->setObject(['name' => 'Scheduled agent', 'actingUser' => 'ghost']);
		$this->objectService->method('find')->willReturn($agentObject);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'acting-user-invalid-sched',
				'alice'
			)
		);

		$this->assertSame('ok', $this->auditCalls[0]['context']['status'], 'An invalid actingUser must not fail the run.');
		$this->assertSame('alice', $this->auditCalls[0]['context']['runAsUser']);

	}//end testActingUserFallsBackToOwnerWhenNonexistent()

	/**
	 * agent-capability-profile: actingUser is never consulted on the flag-off (legacy
	 * ChatService) path — a set actingUser has zero effect until the engine flag is on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-capability-profile/tasks.md#task-3-1
	 */
	public function testActingUserIgnoredOnFlagOffPath(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->chatService->method('processMessage')->willReturn(['message' => 'or output', 'usage' => []]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->runNow(
			$this->schedule(
				[
					'kind' => 'interval',
					'intervalMinutes' => 60,
					'agentId' => 'agent-uuid',
					'prompt' => 'go',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'flag-off-acting-user-sched',
				'alice'
			)
		);

		$this->assertSame('alice', $this->auditCalls[0]['context']['runAsUser']);

	}//end testActingUserIgnoredOnFlagOffPath()

	/**
	 * run-reliability: findDueSchedules() selects a schedule via its pending retry
	 * even though its own nextRun has not arrived yet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function testFindDueSchedulesSelectsRetryDueEvenWithFutureNextRun(): void {
		$retryDue = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
				'retryEnabled' => true,
				'retryMaxAttempts' => 3,
				'retryBackoffBaseSeconds' => 60,
				'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
			],
			'retry-due-sched'
		);

		// call 1 = due schedules; call 2 = engaged kill-switches (none).
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$retryDue], []);
		$this->objectService->method('saveObject')->willReturn(new ObjectEntity());

		$this->service->run();

		// The agent must have been invoked — proof the schedule was selected as due.
		$this->assertNotEmpty($this->auditCalls, 'A retry-due schedule with a future nextRun must still be dispatched.');

	}//end testFindDueSchedulesSelectsRetryDueEvenWithFutureNextRun()

	/**
	 * run-reliability: unchanged behavior — a schedule with no retryState and a
	 * future nextRun is NOT selected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function testFindDueSchedulesSkipsFutureNextRunWithoutRetryState(): void {
		$notDue = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
			],
			'future-sched'
		);

		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn([$notDue]);

		$this->service->run();

		$this->assertEmpty($this->auditCalls, 'A schedule with a future nextRun and no retryState must not be dispatched.');

	}//end testFindDueSchedulesSkipsFutureNextRunWithoutRetryState()

	/**
	 * run-reliability: retryEnabled=false behaves exactly as before this change —
	 * no retryState is ever set on a failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function testRetryDisabledLeavesNoRetryState(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('boom'));
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => false,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'no-retry-sched'
				),
			]
		);
		$this->objectService->method('find')->willReturn(null);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('error', $final['lastStatus'], 'retryEnabled=false must behave exactly as before.');
		$this->assertArrayNotHasKey('retryState', $final);

	}//end testRetryDisabledLeavesNoRetryState()

	/**
	 * run-reliability: a retry-enabled schedule's first failure schedules a retry
	 * with the base backoff delay and records lastStatus=retry_pending.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function testFirstFailureSchedulesRetryWithBaseBackoff(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('transient'));
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 3,
						'retryBackoffBaseSeconds' => 60,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'first-fail-sched'
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$this->service->run();

		$final = end($saved);
		$this->assertSame('retry_pending', $final['lastStatus']);
		$this->assertSame(1, $final['retryState']['attempt']);
		$nextAttemptAt = new \DateTimeImmutable($final['retryState']['nextAttemptAt']);
		$this->assertGreaterThanOrEqual($before->getTimestamp() + 55, $nextAttemptAt->getTimestamp());
		$this->assertLessThanOrEqual($before->getTimestamp() + 65, $nextAttemptAt->getTimestamp());
		$this->assertSame('retry_pending', $this->auditCalls[0]['context']['status']);
		$this->assertSame(1, $this->auditCalls[0]['context']['attempt']);

	}//end testFirstFailureSchedulesRetryWithBaseBackoff()

	/**
	 * run-reliability: a second consecutive failure (the schedule already carries
	 * an open retryState) doubles the backoff delay per the exponential formula.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function testSecondFailureDoublesBackoff(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('still failing'));
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 3,
						'retryBackoffBaseSeconds' => 60,
						'nextRun' => '2030-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
						'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
					],
					'second-fail-sched'
				),
			],
			[]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$this->service->run();

		$final = end($saved);
		$this->assertSame('retry_pending', $final['lastStatus']);
		$this->assertSame(2, $final['retryState']['attempt'], 'The second failure must record attempt=2.');
		$nextAttemptAt = new \DateTimeImmutable($final['retryState']['nextAttemptAt']);
		// 60 * 2^(2-1) = 120 seconds.
		$this->assertGreaterThanOrEqual($before->getTimestamp() + 115, $nextAttemptAt->getTimestamp());
		$this->assertLessThanOrEqual($before->getTimestamp() + 125, $nextAttemptAt->getTimestamp());
		$this->assertSame(2, $this->auditCalls[0]['context']['attempt']);

	}//end testSecondFailureDoublesBackoff()

	/**
	 * run-reliability: once the retryMaxAttempts-th attempt fails, the occurrence is
	 * marked dead_letter, retryState is cleared, consecutiveDeadLetters increments,
	 * and the owner receives a dead-letter alert via DeliveryService.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
	 */
	public function testRetryBudgetExhaustedMarksDeadLetterAndAlertsOwner(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('final failure'));

		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: true, channel: 'none', fellBack: false, warning: null)
		);
		$this->deliveryService->expects($this->once())
			->method('deliverFailureAlert')
			->with($this->anything(), 'final failure')
			->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));
		$this->deliveryService->expects($this->never())->method('deliverCircuitBreakerAlert');
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 2,
						'retryBackoffBaseSeconds' => 60,
						'circuitBreakerThreshold' => 3,
						'consecutiveDeadLetters' => 0,
						'nextRun' => '2030-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
						'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
					],
					'exhausted-sched'
				),
			],
			[]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('dead_letter', $final['lastStatus']);
		$this->assertNull($final['retryState']);
		$this->assertSame(1, $final['consecutiveDeadLetters']);
		$this->assertSame('dead_letter', $this->auditCalls[0]['context']['status']);
		$this->assertSame(2, $this->auditCalls[0]['context']['attempt']);

	}//end testRetryBudgetExhaustedMarksDeadLetterAndAlertsOwner()

	/**
	 * run-reliability: a kind='once' schedule stays enabled=true while its retry
	 * sequence is still open — the finite-repeat/one-shot auto-disable is deferred.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
	 */
	public function testOnceScheduleStaysEnabledWhileRetryPending(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('once failed'));
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'once',
						'runAt' => '2000-01-01T00:00:00+00:00',
						'agentId' => 'agent-uuid',
						'prompt' => 'once with retry',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 3,
						'retryBackoffBaseSeconds' => 60,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'once-retry-sched'
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('retry_pending', $final['lastStatus']);
		$this->assertTrue($final['enabled'], 'A once schedule with a pending retry must stay enabled.');

	}//end testOnceScheduleStaysEnabledWhileRetryPending()

	/**
	 * run-reliability: a success — whether the first attempt or a later retry —
	 * clears retryState and resets consecutiveDeadLetters to 0.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
	 */
	public function testSuccessResetsRetryStateAndDeadLetterStreak(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 3,
						'retryBackoffBaseSeconds' => 60,
						'consecutiveDeadLetters' => 2,
						'nextRun' => '2030-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
						'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
					],
					'recovering-sched'
				),
			],
			[]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('ok', $final['lastStatus']);
		$this->assertNull($final['retryState']);
		$this->assertSame(0, $final['consecutiveDeadLetters']);

	}//end testSuccessResetsRetryStateAndDeadLetterStreak()

	/**
	 * run-reliability: once consecutiveDeadLetters reaches circuitBreakerThreshold,
	 * the schedule is auto-paused (enabled=false, lastStatus=paused_circuit_breaker)
	 * and the owner receives a DISTINCT circuit-breaker alert in addition to the
	 * dead-letter alert.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-consecutive-dead-letter-circuit-breaker-auto-pauses-a-schedule-mvp
	 */
	public function testCircuitBreakerTripsAndAlertsOwnerDistinctly(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->method('processMessage')->willThrowException(new \RuntimeException('chronic failure'));

		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->method('deliver')->willReturn(
			new DeliveryResult(delivered: true, channel: 'none', fellBack: false, warning: null)
		);
		$this->deliveryService->expects($this->once())->method('deliverFailureAlert')
			->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));
		$this->deliveryService->expects($this->once())->method('deliverCircuitBreakerAlert')
			->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));
		$this->service = $this->makeService();

		$this->objectService->method('findAll')->willReturn(
			[
				$this->schedule(
					[
						'kind' => 'interval',
						'intervalMinutes' => 60,
						'agentId' => 'agent-uuid',
						'prompt' => 'go',
						'deliver' => 'none',
						'enabled' => true,
						'retryEnabled' => true,
						'retryMaxAttempts' => 1,
						'retryBackoffBaseSeconds' => 60,
						'circuitBreakerThreshold' => 3,
						'consecutiveDeadLetters' => 2,
						'nextRun' => '2000-01-01T00:00:00+00:00',
						'repeat' => ['times' => 0, 'completed' => 0],
					],
					'breaker-sched'
				),
			]
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('paused_circuit_breaker', $final['lastStatus']);
		$this->assertFalse($final['enabled'], 'The circuit breaker must auto-pause the schedule.');
		$this->assertSame(3, $final['consecutiveDeadLetters']);

	}//end testCircuitBreakerTripsAndAlertsOwnerDistinctly()

	/**
	 * run-reliability (governance): a kill-switch-halted retry is skipped exactly
	 * like any other gated occurrence — the agent never runs and the skip does NOT
	 * count toward consecutiveDeadLetters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-a-retried-run-is-a-new-governed-dispatch-mvp
	 */
	public function testKillSwitchHaltsPendingRetryWithoutCountingAsDeadLetter(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->deliveryService->expects($this->never())->method('deliverFailureAlert');
		$this->deliveryService->expects($this->never())->method('deliverCircuitBreakerAlert');
		$this->service = $this->makeService();

		$gatedRetry = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'retryEnabled' => true,
				'retryMaxAttempts' => 2,
				'retryBackoffBaseSeconds' => 60,
				'consecutiveDeadLetters' => 1,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
				'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
			],
			'gated-retry-sched'
		);
		$gatedRetry->setOrganisation('org-x');

		$control = new ObjectEntity();
		$control->setUuid('ctrl-1');
		$control->setOrganisation('org-x');
		$control->setObject(['engaged' => true]);

		// call 1 = due schedules (selected via the pending retry); call 2 = engaged
		// kill-switches.
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$gatedRetry], [$control]);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('skipped_killswitch', $final['lastStatus']);
		$this->assertSame(1, $final['consecutiveDeadLetters'], 'A gated retry must not increment consecutiveDeadLetters.');

	}//end testKillSwitchHaltsPendingRetryWithoutCountingAsDeadLetter()

	/**
	 * run-reliability (governance): an approval-gated schedule's pending retry
	 * still requires approval — the agent is not invoked directly, a pending
	 * Approval is ensured, exactly like any other gated occurrence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-a-retried-run-is-a-new-governed-dispatch-mvp
	 */
	public function testApprovalGateStillAppliesToPendingRetry(): void {
		$this->chatService = $this->createMock(ChatService::class);
		$this->chatService->expects($this->never())->method('processMessage');

		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->approvalService->expects($this->once())->method('ensurePendingApproval');
		$this->service = $this->makeService();

		$gatedRetry = $this->schedule(
			[
				'kind' => 'interval',
				'intervalMinutes' => 60,
				'agentId' => 'agent-uuid',
				'prompt' => 'go',
				'deliver' => 'none',
				'enabled' => true,
				'requiresApproval' => true,
				'retryEnabled' => true,
				'retryMaxAttempts' => 3,
				'retryBackoffBaseSeconds' => 60,
				'nextRun' => '2030-01-01T00:00:00+00:00',
				'repeat' => ['times' => 0, 'completed' => 0],
				'retryState' => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
			],
			'approval-retry-sched'
		);

		// call 1 = due (selected via the pending retry); call 2 = engaged kill-switches (none).
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls([$gatedRetry], []);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->service->run();

		$final = end($saved);
		$this->assertSame('awaiting_approval', $final['lastStatus']);
		$this->assertSame('awaiting_approval', $this->auditCalls[0]['context']['status']);

	}//end testApprovalGateStillAppliesToPendingRetry()

	/**
	 * An agent object with the given actingUser (agent-lifecycle-governance offboarding).
	 *
	 * @param string $uuid The agent uuid.
	 * @param string|null $actingUser The agent's actingUser field, or null when unset.
	 *
	 * @return ObjectEntity
	 */
	private function agentWithActingUser(string $uuid, ?string $actingUser): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['name' => 'Agent', 'actingUser' => $actingUser]);
		return $entity;
	}//end agentWithActingUser()

	/**
	 * pauseForUser() disables an enabled schedule owned by the offboarded user,
	 * persists it, writes an audit entry, and flags its agent for reassignment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseForUserPausesScheduleOwnedByUser(): void {
		$schedule = $this->schedule(
			['agentId' => 'agent-1', 'enabled' => true, 'kind' => 'once'],
			'sched-1',
			'alice'
		);

		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->objectService->method('find')->willReturn($this->agentWithActingUser('agent-1', null));

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => (string)$schema];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'x');
				$entity->setObject($object);
				return $entity;
			}
		);

		$paused = $this->service->pauseForUser('alice');

		$this->assertSame(1, $paused);

		$scheduleSave = null;
		$agentSave = null;
		foreach ($saved as $call) {
			if ($call['schema'] === 'schedule') {
				$scheduleSave = $call['object'];
			}

			if ($call['schema'] === 'agent') {
				$agentSave = $call['object'];
			}
		}

		$this->assertNotNull($scheduleSave, 'The schedule must be persisted.');
		$this->assertFalse($scheduleSave['enabled']);
		$this->assertSame('paused_offboarding', $scheduleSave['lastStatus']);
		$this->assertSame('agent-1', $scheduleSave['agentId'], 'Unrelated fields must be preserved.');

		$this->assertNotNull($agentSave, 'The owning agent must be flagged for reassignment.');
		$this->assertTrue($agentSave['reassignmentFlag']);

		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('paused_offboarding', $this->auditCalls[0]['context']['status']);
		$this->assertSame('alice', $this->auditCalls[0]['context']['offboardedUser']);

	}//end testPauseForUserPausesScheduleOwnedByUser()

	/**
	 * pauseForUser() also pauses a schedule whose Agent's actingUser resolves to
	 * the offboarded user, even when the schedule's own owner differs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseForUserMatchesViaActingUser(): void {
		$schedule = $this->schedule(
			['agentId' => 'agent-2', 'enabled' => true, 'kind' => 'once'],
			'sched-2',
			'bob'
		);

		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->objectService->method('find')->willReturn($this->agentWithActingUser('agent-2', 'alice'));

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => (string)$schema];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'x');
				$entity->setObject($object);
				return $entity;
			}
		);

		$paused = $this->service->pauseForUser('alice');

		$this->assertSame(1, $paused);
		$scheduleSaves = array_filter($saved, static fn (array $c) => $c['schema'] === 'schedule');
		$this->assertNotEmpty($scheduleSaves);
		$this->assertFalse(reset($scheduleSaves)['object']['enabled']);

	}//end testPauseForUserMatchesViaActingUser()

	/**
	 * pauseForUser() leaves a schedule belonging to a different, unrelated user
	 * untouched — no persist, no audit entry, no agent flag.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseForUserLeavesUnrelatedScheduleUntouched(): void {
		$schedule = $this->schedule(
			['agentId' => 'agent-3', 'enabled' => true, 'kind' => 'once'],
			'sched-3',
			'carol'
		);

		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->objectService->method('find')->willReturn($this->agentWithActingUser('agent-3', 'dave'));
		$this->objectService->expects($this->never())->method('saveObject');

		$paused = $this->service->pauseForUser('alice');

		$this->assertSame(0, $paused);
		$this->assertCount(0, $this->auditCalls);

	}//end testPauseForUserLeavesUnrelatedScheduleUntouched()

	/**
	 * pauseForUser() does not persist/audit an already-disabled schedule again,
	 * but its agent is still flagged — an already-paused schedule's owning human
	 * is still gone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseForUserSkipsAlreadyDisabledScheduleButStillFlagsAgent(): void {
		$schedule = $this->schedule(
			['agentId' => 'agent-4', 'enabled' => false, 'kind' => 'once'],
			'sched-4',
			'alice'
		);

		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->objectService->method('find')->willReturn($this->agentWithActingUser('agent-4', null));

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => (string)$schema];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'x');
				$entity->setObject($object);
				return $entity;
			}
		);

		$paused = $this->service->pauseForUser('alice');

		$this->assertSame(0, $paused, 'An already-disabled schedule is not counted as newly paused.');
		$this->assertCount(0, $this->auditCalls, 'No redundant audit entry for an already-disabled schedule.');

		$schemas = array_column($saved, 'schema');
		$this->assertNotContains('schedule', $schemas, 'The already-disabled schedule must not be re-persisted.');
		$this->assertContains('agent', $schemas, 'The owning agent must still be flagged.');

	}//end testPauseForUserSkipsAlreadyDisabledScheduleButStillFlagsAgent()

	/**
	 * pauseForUser() only changes enabled/lastStatus — it never touches a
	 * currently in-progress run's own state (there is no "abort" primitive here;
	 * only future occurrences are prevented from firing, matching the gate-skip
	 * semantics already documented on recordGateSkip()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable
	 */
	public function testPauseForUserDoesNotAlterInProgressRunFields(): void {
		$schedule = $this->schedule(
			[
				'agentId' => 'agent-5',
				'enabled' => true,
				'kind' => 'once',
				'lastStatus' => 'running',
				'prompt' => 'do the thing',
			],
			'sched-5',
			'alice'
		);

		$this->objectService->method('findAll')->willReturn([$schedule]);
		$this->objectService->method('find')->willReturn($this->agentWithActingUser('agent-5', null));

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => (string)$schema];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'x');
				$entity->setObject($object);
				return $entity;
			}
		);

		$this->service->pauseForUser('alice');

		$scheduleSave = null;
		foreach ($saved as $call) {
			if ($call['schema'] === 'schedule') {
				$scheduleSave = $call['object'];
			}
		}

		$this->assertNotNull($scheduleSave);
		$this->assertFalse($scheduleSave['enabled']);
		$this->assertSame('paused_offboarding', $scheduleSave['lastStatus']);
		// The in-flight run's own prompt/other fields are preserved verbatim —
		// pauseForUser() never rewrites them.
		$this->assertSame('do the thing', $scheduleSave['prompt']);

	}//end testPauseForUserDoesNotAlterInProgressRunFields()

	/**
	 * A basic engine-enabled schedule fixture for the run-replay-and-dry-run
	 * tests below (dryRunNow()/replayRun() both require the engine flag on).
	 *
	 * @param array<string,mixed> $overrides Payload fields to override/add.
	 *
	 * @return ObjectEntity
	 */
	private function engineEnabledSchedule(array $overrides = []): ObjectEntity {
		return $this->schedule(
			array_merge(
				[
					'kind' => 'once',
					'agentId' => 'agent-uuid',
					'prompt' => 'current schedule prompt',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 1, 'completed' => 0],
				],
				$overrides
			),
			'dryrun-sched'
		);

	}//end engineEnabledSchedule()

	/**
	 * Wire the engine-flag-ON collaborators (agent resolves as an ObjectEntity,
	 * the in-app Engine mock returns a canned envelope, saveObject captures
	 * every write by schema) shared by the run-replay-and-dry-run tests below.
	 *
	 * @param array<string,mixed> $engineResult The Engine::processMessage() return envelope.
	 * @param array<int, ObjectEntity> $findAllReturn What `ObjectService::findAll()` returns
	 *                                                (kill-switch load + dry-run message
	 *                                                cleanup lookup) — defaults to none
	 *                                                engaged, no messages.
	 *
	 * @return array<int, array<string,mixed>> A reference array `$saved` populated by every
	 *                                         `saveObject()` call (`['object' => ..., 'schema' => ...]`).
	 */
	private function wireEngineEnabled(array $engineResult, array $findAllReturn = []): array {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('true');

		$this->engine = $this->createMock(Engine::class);
		$this->engine->method('processMessage')->willReturn($engineResult);

		$agentObject = new ObjectEntity();
		$agentObject->setUuid('agent-uuid');
		$agentObject->setObject(['name' => 'Scheduled agent']);
		$this->objectService->method('find')->willReturn($agentObject);
		$this->objectService->method('findAll')->willReturn($findAllReturn);
		$this->objectService->method('deleteObject')->willReturn(true);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => (string)$schema];
				$entity = new ObjectEntity();
				$entity->setUuid('conv-uuid-1');
				return $entity;
			}
		);

		$this->service = $this->makeService();

		return $saved;
	}//end wireEngineEnabled()

	/**
	 * Dry-run refuses with `EngineRequiredException` when `hermiq.engine.enabled`
	 * is off (the default) — the agent is never run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
	 */
	public function testDryRunNowThrowsWhenEngineDisabled(): void {
		$this->chatService->expects($this->never())->method('processMessage');

		$this->expectException(EngineRequiredException::class);
		$this->service->dryRunNow(schedule: $this->engineEnabledSchedule());

	}//end testDryRunNowThrowsWhenEngineDisabled()

	/**
	 * A kill-switch-engaged organisation blocks dryRunNow() with `skipped_killswitch`
	 * — identical to a real run's gate — and the agent never runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state
	 */
	public function testDryRunNowBlockedByKillSwitch(): void {
		$control = new ObjectEntity();
		$control->setObject(['engaged' => true]);
		$control->setOrganisation('org-halted');

		$this->wireEngineEnabled(['message' => 'unused'], [$control]);
		$this->engine->expects($this->never())->method('processMessage');

		$schedule = $this->engineEnabledSchedule();
		$schedule->setOrganisation('org-halted');

		$result = $this->service->dryRunNow(schedule: $schedule);

		$this->assertSame('blocked', $result['status']);
		$this->assertSame('skipped_killswitch', $result['gate']);

	}//end testDryRunNowBlockedByKillSwitch()

	/**
	 * A budget-exhausted organisation blocks dryRunNow() with `skipped_budget`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state
	 */
	public function testDryRunNowBlockedByBudget(): void {
		$this->wireEngineEnabled(['message' => 'unused']);
		$this->engine->expects($this->never())->method('processMessage');

		$this->budgetService = $this->createMock(BudgetService::class);
		$this->budgetService->method('isBlocked')->willReturn(true);
		$this->service = $this->makeService();

		$result = $this->service->dryRunNow(schedule: $this->engineEnabledSchedule());

		$this->assertSame('blocked', $result['status']);
		$this->assertSame('skipped_budget', $result['gate']);

	}//end testDryRunNowBlockedByBudget()

	/**
	 * A schedule with `requiresApproval=true` blocks dryRunNow() with
	 * `awaiting_approval` and, critically, never creates a new pending
	 * `Approval` object as a side effect of the preview attempt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state
	 */
	public function testDryRunNowBlockedByApprovalRequiredWithoutCreatingApproval(): void {
		$this->wireEngineEnabled(['message' => 'unused']);
		$this->engine->expects($this->never())->method('processMessage');
		$this->approvalService->expects($this->never())->method('ensurePendingApproval');

		$result = $this->service->dryRunNow(schedule: $this->engineEnabledSchedule(['requiresApproval' => true]));

		$this->assertSame('blocked', $result['status']);
		$this->assertSame('awaiting_approval', $result['gate']);

	}//end testDryRunNowBlockedByApprovalRequiredWithoutCreatingApproval()

	/**
	 * A passing dry-run writes an `action='run'` audit entry marked
	 * `dryRun: true` with the exact prompt used, and NEVER writes the
	 * schedule object itself — `nextRun`/`repeat`/`enabled` are untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state
	 */
	public function testDryRunNowWritesMarkedAuditEntryAndDoesNotMutateSchedule(): void {
		$saved = $this->wireEngineEnabled(['message' => 'preview output']);

		$result = $this->service->dryRunNow(schedule: $this->engineEnabledSchedule());

		$this->assertSame('ok', $result['status']);
		$this->assertCount(1, $this->auditCalls);
		$this->assertTrue($this->auditCalls[0]['context']['dryRun']);
		$this->assertNull($this->auditCalls[0]['context']['replayOf']);
		$this->assertSame('current schedule prompt', $this->auditCalls[0]['context']['prompt']);

		foreach ($saved as $call) {
			$this->assertNotSame('schedule', $call['schema'], 'dryRunNow() must never write the schedule object itself.');
		}

	}//end testDryRunNowWritesMarkedAuditEntryAndDoesNotMutateSchedule()

	/**
	 * A dry-run's scratch Conversation is deleted once the turn completes
	 * (run-replay-and-dry-run) — the ONE durable artifact of a dry-run is its
	 * audit entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 */
	public function testDryRunNowDeletesScratchConversation(): void {
		$this->wireEngineEnabled(['message' => 'preview output']);

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid, mixed $register = null, mixed $schema = null) use (&$deleted): bool {
				$deleted[] = ['uuid' => $uuid, 'schema' => (string)$schema];
				return true;
			}
		);
		$this->service = $this->makeService();

		$this->service->dryRunNow(schedule: $this->engineEnabledSchedule());

		$conversationDeletes = array_filter($deleted, static fn (array $d): bool => $d['schema'] === 'conversation');
		$this->assertCount(1, $conversationDeletes);

	}//end testDryRunNowDeletesScratchConversation()

	/**
	 * Replaying a run whose audit entry carries no persisted `prompt` (a run
	 * recorded before this change shipped) is refused cleanly, never a crash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
	 */
	public function testReplayRunRefusesWhenOriginalPromptMissing(): void {
		$this->wireEngineEnabled(['message' => 'unused']);
		$this->engine->expects($this->never())->method('processMessage');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not available for replay');
		$this->service->replayRun(
			schedule: $this->engineEnabledSchedule(),
			runId: 'run-1',
			originalTrace: ['status' => 'ok', 'steps' => [], 'summary' => 'x']
		);

	}//end testReplayRunRefusesWhenOriginalPromptMissing()

	/**
	 * Replay uses the ORIGINAL run's recorded prompt, not the schedule's
	 * current (possibly since-edited) `prompt` field, and marks the audit
	 * entry with `replayOf`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
	 */
	public function testReplayRunUsesOriginalPromptNotCurrentScheduleField(): void {
		$this->wireEngineEnabled(['message' => 'replay output']);

		$capturedPrompt = null;
		$this->engine = $this->createMock(Engine::class);
		$this->engine->method('processMessage')->willReturnCallback(
			function (string $conversationId, string $userId, string $userMessage) use (&$capturedPrompt): array {
				$capturedPrompt = $userMessage;
				return ['message' => 'replay output'];
			}
		);
		$this->service = $this->makeService();

		$result = $this->service->replayRun(
			schedule: $this->engineEnabledSchedule(['prompt' => 'EDITED schedule prompt']),
			runId: 'run-1',
			originalTrace: ['status' => 'ok', 'steps' => [], 'summary' => 'original output', 'prompt' => 'ORIGINAL prompt text']
		);

		$this->assertSame('ORIGINAL prompt text', $capturedPrompt);
		$this->assertSame('run-1', $result['replayOf']);
		$this->assertCount(1, $this->auditCalls);
		$this->assertSame('run-1', $this->auditCalls[0]['context']['replayOf']);
		$this->assertSame('ORIGINAL prompt text', $this->auditCalls[0]['context']['prompt']);

	}//end testReplayRunUsesOriginalPromptNotCurrentScheduleField()

	/**
	 * The replay diff reports a changed tool sequence position-by-position
	 * when the replay's tool calls differ from the original's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
	 */
	public function testReplayRunDiffDetectsChangedToolSequence(): void {
		$this->wireEngineEnabled(
			[
				'message' => 'replay output',
			]
		);
		$this->engine = $this->createMock(Engine::class);
		$this->engine->method('processMessage')->willReturnCallback(
			function (string $conversationId, string $userId, string $userMessage, array $selectedViews = [], array $selectedTools = [], array $ragSettings = [], array $context = [], $channel = null, $trace = null): array {
				$trace?->startStep(type: 'context', name: 'Context retrieval');
				$token = $trace?->startStep(type: 'tool', name: 'app.a');
				$trace?->endStep(token: $token, outcome: 'ok');
				$token = $trace?->startStep(type: 'tool', name: 'app.c');
				$trace?->endStep(token: $token, outcome: 'would-have-called');
				return ['message' => 'replay output'];
			}
		);
		$this->service = $this->makeService();

		$result = $this->service->replayRun(
			schedule: $this->engineEnabledSchedule(),
			runId: 'run-1',
			originalTrace: [
				'status' => 'ok',
				'steps' => [
					['seq' => 0, 'type' => 'tool', 'name' => 'app.a', 'outcome' => 'ok'],
					['seq' => 1, 'type' => 'tool', 'name' => 'app.b', 'outcome' => 'ok'],
				],
				'summary' => 'original output',
				'prompt' => 'ORIGINAL prompt text',
			]
		);

		$this->assertFalse($result['diff']['toolSequenceMatches']);
		$this->assertTrue($result['diff']['toolCalls'][0]['match']);
		$this->assertSame('app.a', $result['diff']['toolCalls'][0]['original']);
		$this->assertFalse($result['diff']['toolCalls'][1]['match']);
		$this->assertSame('app.b', $result['diff']['toolCalls'][1]['original']);
		$this->assertSame('app.c', $result['diff']['toolCalls'][1]['replay']);
		$this->assertTrue($result['diff']['outputChanged']);

	}//end testReplayRunDiffDetectsChangedToolSequence()

	/**
	 * A normal (real, non-dry-run) run's audit entry now also persists the
	 * exact prompt used — additive, so replay of a real run is possible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function testRealRunAuditEntryPersistsPromptAndDryRunFalse(): void {
		$due = [
			$this->schedule(
				[
					'kind' => 'once',
					'agentId' => 'agent-1',
					'prompt' => 'go do the thing',
					'deliver' => 'none',
					'enabled' => true,
					'nextRun' => '2020-01-01T00:00:00+00:00',
					'repeat' => ['times' => 0, 'completed' => 0],
				],
				'real-run-sched'
			),
		];
		$this->objectService->method('findAll')->willReturn($due);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'x');
				$entity->setObject($object);
				return $entity;
			}
		);

		$this->service->run();

		$this->assertCount(1, $this->auditCalls);
		$this->assertFalse($this->auditCalls[0]['context']['dryRun']);
		$this->assertNull($this->auditCalls[0]['context']['replayOf']);
		$this->assertSame('go do the thing', $this->auditCalls[0]['context']['prompt']);

	}//end testRealRunAuditEntryPersistsPromptAndDryRunFalse()
}//end class
