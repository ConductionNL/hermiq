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

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
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
class ScheduleServiceTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService      = $this->createMock(ObjectService::class);
        $this->agentMapper        = $this->createMock(AgentMapper::class);
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->chatService        = $this->createMock(ChatService::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->config             = $this->createMock(IConfig::class);

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
        $this->auditCalls       = [];
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
            function (ObjectEntity $object, string $action, array $context=[]): AuditTrail {
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

        $this->service = $this->makeService();

    }//end setUp()

    /**
     * Build a ScheduleService wired to the current mocks.
     *
     * @return ScheduleService
     */
    private function makeService(): ScheduleService
    {
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
        );

    }//end makeService()

    /**
     * Build a schedule ObjectEntity with the given payload.
     *
     * @param array<string,mixed> $payload The schedule object body.
     * @param string             $uuid    The object UUID.
     * @param string             $owner   The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(array $payload, string $uuid='sched-1', string $owner='alice'): ObjectEntity
    {
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
    public function testNextRunPerKind(): void
    {
        $due = [
            $this->schedule(
                [
                    'kind'     => 'cron',
                    'cronExpr' => '0 8 * * *',
                    'agentId'  => 'agent-uuid',
                    'prompt'   => 'daily brief',
                    'deliver'  => 'none',
                    'enabled'  => true,
                    'nextRun'  => '2000-01-01T00:00:00+00:00',
                    'repeat'   => ['times' => 0, 'completed' => 0],
                ],
                'cron-sched'
            ),
            $this->schedule(
                [
                    'kind'            => 'interval',
                    'intervalMinutes' => 90,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'poll',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2000-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
                ],
                'interval-sched'
            ),
            $this->schedule(
                [
                    'kind'    => 'once',
                    'runAt'   => '2000-01-01T00:00:00+00:00',
                    'agentId' => 'agent-uuid',
                    'prompt'  => 'one shot',
                    'deliver' => 'none',
                    'enabled' => true,
                    'nextRun' => '2000-01-01T00:00:00+00:00',
                    'repeat'  => ['times' => 0, 'completed' => 0],
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
        $cronCommit     = $saved[0];
        $intervalCommit = null;
        $onceCommit     = null;
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
    public function testCommitBeforeRun(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2000-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
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
        $agentIndex  = array_search('agent', $order, true);
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
    public function testPerScheduleErrorIsolation(): void
    {
        $bad = $this->schedule(
            [
                'kind'    => 'once',
                'runAt'   => '2000-01-01T00:00:00+00:00',
                'agentId' => 'agent-uuid',
                'prompt'  => 'boom',
                'deliver' => 'none',
                'enabled' => true,
                'nextRun' => '2000-01-01T00:00:00+00:00',
                'repeat'  => ['times' => 0, 'completed' => 0],
            ],
            'bad-sched',
            'ghost'
        );
        $good = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 30,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'ok',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2000-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
            ],
            'good-sched',
            'alice'
        );

        $this->objectService->method('findAll')->willReturn([$bad, $good]);

        // Owner "ghost" does not resolve (see setUp) → the bad schedule throws
        // inside dispatch and is isolated.
        $errorStatuses = [];
        $okStatuses    = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) use (&$errorStatuses, &$okStatuses): ObjectEntity {
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
    public function testFailureDoesNotRevertCommittedRunState(): void
    {
        $originalNextRun = '2000-01-01T00:00:00+00:00';

        // A once schedule owned by a resolvable user, but whose agent turn throws.
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'    => 'once',
                        'runAt'   => $originalNextRun,
                        'agentId' => 'agent-uuid',
                        'prompt'  => 'will fail',
                        'deliver' => 'none',
                        'enabled' => true,
                        'nextRun' => $originalNextRun,
                        'repeat'  => ['times' => 0, 'completed' => 0],
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
    public function testFiniteRepeatDeletesAtLimit(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 1440,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'nightly',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2000-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 3, 'completed' => 2],
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
    public function testDateFieldsAreIsoNormalisedBeforeSave(): void
    {
        // Space-format (Y-m-d H:i:s) date-times as OR's getObject() would return them.
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'    => 'once',
                        'runAt'   => '2020-01-01 00:00:00',
                        'agentId' => 'agent-uuid',
                        'prompt'  => 'one shot',
                        'deliver' => 'none',
                        'enabled' => true,
                        'nextRun' => '2020-01-01 00:00:00',
                        'repeat'  => ['times' => 0, 'completed' => 0],
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
                    (string) $object['runAt'],
                    'runAt must be ISO-8601 (with T) before save.'
                );
                $this->assertStringNotContainsString(
                    '2020-01-01 00:00:00',
                    (string) $object['runAt'],
                    'runAt must not remain in space-separated form.'
                );
            }

            // nextRun, whether recomputed or carried through, must be ISO too.
            if (array_key_exists('nextRun', $object) === true && $object['nextRun'] !== null && $object['nextRun'] !== '') {
                $this->assertStringContainsString(
                    'T',
                    (string) $object['nextRun'],
                    'nextRun must be ISO-8601 (with T) before save.'
                );
            }
        }

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
    public function testInfiniteScheduleSerialisesRepeatAsNull(): void
    {
        // OR returns the nullable repeat as {times:0, completed:0} on read.
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'forever',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
                    ],
                    'infinite-sched'
                ),
            ]
        );

        $saved   = [];
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
    public function testFiniteRepeatIsPreservedOnSave(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'finite',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 5, 'completed' => 1],
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
    public function testDeliveryFailurePersistsLastDeliveryErrorAndKeepsRunOk(): void
    {
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
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'talk',
                        'deliverTarget'   => 'room-x',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testSuccessfulDeliveryClearsLastDeliveryError(): void
    {
        // Default deliveryService (setUp) returns a clean result (warning null).
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'              => 'interval',
                        'intervalMinutes'   => 60,
                        'agentId'           => 'agent-uuid',
                        'prompt'            => 'go',
                        'deliver'           => 'talk',
                        'enabled'           => true,
                        'nextRun'           => '2020-01-01T00:00:00+00:00',
                        'repeat'            => ['times' => 0, 'completed' => 0],
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
    public function testSuccessfulRunWritesRunAuditEntry(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
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

    }//end testSuccessfulRunWritesRunAuditEntry()

    /**
     * A failed run still writes an audit entry with status=error.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-2-4
     */
    public function testFailedRunStillWritesRunAuditEntry(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'    => 'once',
                        'runAt'   => '2000-01-01T00:00:00+00:00',
                        'agentId' => 'agent-uuid',
                        'prompt'  => 'will fail',
                        'deliver' => 'none',
                        'enabled' => true,
                        'nextRun' => '2000-01-01T00:00:00+00:00',
                        'repeat'  => ['times' => 0, 'completed' => 0],
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
    public function testAuditWriteFailureDoesNotFailTheTick(): void
    {
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
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testRunAuditSummaryIsRedactedBeforeWrite(): void
    {
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
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'nextRun'         => '2020-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
                    ]
                ),
            ]
        );
        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());

        $this->service->run();

        $this->assertCount(1, $this->auditCalls);
        $summary = (string) $this->auditCalls[0]['context']['summary'];
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
    public function testRunNowDrivesDispatchPath(): void
    {
        $schedule = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 60,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'run me now',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2030-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testKillSwitchSkipsRun(): void
    {
        // The agent must never be invoked for a halted organisation.
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');
        $this->service = $this->makeService();

        $killed = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 60,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'go',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2000-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testIsOrganisationEngagedReflectsTenantControl(): void
    {
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
    public function testApprovalGateCreatesPendingAndSkipsRun(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');

        // The gate must ask ApprovalService for exactly one pending Approval.
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->approvalService->expects($this->once())->method('ensurePendingApproval');
        $this->service = $this->makeService();

        $gated = $this->schedule(
            [
                'kind'             => 'interval',
                'intervalMinutes'  => 60,
                'agentId'          => 'agent-uuid',
                'prompt'           => 'sensitive',
                'deliver'          => 'none',
                'enabled'          => true,
                'requiresApproval' => true,
                'nextRun'          => '2000-01-01T00:00:00+00:00',
                'repeat'           => ['times' => 0, 'completed' => 0],
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
    public function testRunNowGatesApprovalWhenNotBypassed(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');

        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->approvalService->expects($this->once())->method('ensurePendingApproval');
        $this->service = $this->makeService();

        $gated = $this->schedule(
            [
                'kind'             => 'interval',
                'intervalMinutes'  => 60,
                'agentId'          => 'agent-uuid',
                'prompt'           => 'sensitive',
                'deliver'          => 'none',
                'enabled'          => true,
                'requiresApproval' => true,
                'nextRun'          => '2030-01-01T00:00:00+00:00',
                'repeat'           => ['times' => 0, 'completed' => 0],
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
    public function testApprovalBypassRunsAgentWithoutGating(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->once())->method('processMessage')->willReturn(['message' => 'ran']);

        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->approvalService->expects($this->never())->method('ensurePendingApproval');
        $this->service = $this->makeService();

        $gated = $this->schedule(
            [
                'kind'             => 'interval',
                'intervalMinutes'  => 60,
                'agentId'          => 'agent-uuid',
                'prompt'           => 'authorised',
                'deliver'          => 'none',
                'enabled'          => true,
                'requiresApproval' => true,
                'nextRun'          => '2030-01-01T00:00:00+00:00',
                'repeat'           => ['times' => 0, 'completed' => 0],
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
    public function testKillSwitchOverridesApprovalBypass(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');
        $this->service = $this->makeService();

        $gated = $this->schedule(
            [
                'kind'             => 'interval',
                'intervalMinutes'  => 60,
                'agentId'          => 'agent-uuid',
                'prompt'           => 'authorised but halted',
                'deliver'          => 'none',
                'enabled'          => true,
                'requiresApproval' => true,
                'nextRun'          => '2030-01-01T00:00:00+00:00',
                'repeat'           => ['times' => 0, 'completed' => 0],
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
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetHardCapSkipsRun(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');

        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->method('isBlocked')->willReturn(true);
        $this->service = $this->makeService();

        $budgeted = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 60,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'go',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2000-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
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
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetHardCapOverridesApprovalBypass(): void
    {
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
                'kind'             => 'interval',
                'intervalMinutes'  => 60,
                'agentId'          => 'agent-uuid',
                'prompt'           => 'authorised but budget-exhausted',
                'deliver'          => 'none',
                'enabled'          => true,
                'requiresApproval' => true,
                'nextRun'          => '2030-01-01T00:00:00+00:00',
                'repeat'           => ['times' => 0, 'completed' => 0],
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
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetSoftThresholdCheckRunsEveryDispatch(): void
    {
        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->method('isBlocked')->willReturn(false);
        $this->budgetService->expects($this->once())
            ->method('checkAndDeliverWarnings')
            ->with('org-y', 'agent-uuid');
        $this->service = $this->makeService();

        $schedule = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 60,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'go',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2000-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testEngineFlagOffUsesOrChatService(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->once())->method('processMessage')->willReturn(
            [
                'message' => 'or output',
                'usage'   => [
                    'promptTokens'     => 3,
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
                    'kind'            => 'interval',
                    'intervalMinutes' => 60,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'go',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2020-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
                ],
                'flag-off-sched'
            )
        );

        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('ok', $this->auditCalls[0]['context']['status']);
        $this->assertSame(
            [
                'promptTokens'     => 3,
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
    public function testEngineFlagOnUsesInAppEngine(): void
    {
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
                'usage'   => [
                    'promptTokens'     => 11,
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
            function (mixed $object, ?array $extend=null, mixed $register=null, mixed $schema=null) use (&$savedConversations): ObjectEntity {
                $entity = new ObjectEntity();
                $entity->setUuid('saved-'.count($savedConversations));
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
                    'kind'            => 'interval',
                    'intervalMinutes' => 60,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'go',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2020-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
                ],
                'flag-on-sched'
            )
        );

        // The hermiq conversation object carries the ported title/owner/agent shape.
        $this->assertCount(1, $savedConversations);
        $this->assertSame(
            [
                'title'   => 'Hermiq scheduled run',
                'userId'  => 'alice',
                'agentId' => 'agent-uuid',
            ],
            $savedConversations[0]
        );

        // The run finalised ok and the engine usage shape survived into the audit.
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('ok', $this->auditCalls[0]['context']['status']);
        $this->assertSame(
            [
                'promptTokens'     => 11,
                'completionTokens' => 22,
            ],
            $this->auditCalls[0]['context']['usage'],
            'The flag-on path must capture the Engine usage shape identically.'
        );

    }//end testEngineFlagOnUsesInAppEngine()

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
    public function testActingUserOverridesOwnerImpersonation(): void
    {
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
            function (mixed $object, ?array $extend=null, mixed $register=null, mixed $schema=null) use (&$savedConversations): ObjectEntity {
                $entity = new ObjectEntity();
                $entity->setUuid('saved-'.count($savedConversations));
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
                    'kind'            => 'interval',
                    'intervalMinutes' => 60,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'go',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2020-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testActingUserFallsBackToOwnerWhenNonexistent(): void
    {
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
                    'kind'            => 'interval',
                    'intervalMinutes' => 60,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'go',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2020-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testActingUserIgnoredOnFlagOffPath(): void
    {
        $this->objectService->expects($this->never())->method('find');
        $this->chatService->method('processMessage')->willReturn(['message' => 'or output', 'usage' => []]);
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());

        $this->service->runNow(
            $this->schedule(
                [
                    'kind'            => 'interval',
                    'intervalMinutes' => 60,
                    'agentId'         => 'agent-uuid',
                    'prompt'          => 'go',
                    'deliver'         => 'none',
                    'enabled'         => true,
                    'nextRun'         => '2020-01-01T00:00:00+00:00',
                    'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testFindDueSchedulesSelectsRetryDueEvenWithFutureNextRun(): void
    {
        $retryDue = $this->schedule(
            [
                'kind'                    => 'interval',
                'intervalMinutes'         => 60,
                'agentId'                 => 'agent-uuid',
                'prompt'                  => 'go',
                'deliver'                 => 'none',
                'enabled'                 => true,
                'nextRun'                 => '2030-01-01T00:00:00+00:00',
                'repeat'                  => ['times' => 0, 'completed' => 0],
                'retryEnabled'            => true,
                'retryMaxAttempts'        => 3,
                'retryBackoffBaseSeconds' => 60,
                'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
    public function testFindDueSchedulesSkipsFutureNextRunWithoutRetryState(): void
    {
        $notDue = $this->schedule(
            [
                'kind'            => 'interval',
                'intervalMinutes' => 60,
                'agentId'         => 'agent-uuid',
                'prompt'          => 'go',
                'deliver'         => 'none',
                'enabled'         => true,
                'nextRun'         => '2030-01-01T00:00:00+00:00',
                'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testRetryDisabledLeavesNoRetryState(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->method('processMessage')->willThrowException(new \RuntimeException('boom'));
        $this->service = $this->makeService();

        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'            => 'interval',
                        'intervalMinutes' => 60,
                        'agentId'         => 'agent-uuid',
                        'prompt'          => 'go',
                        'deliver'         => 'none',
                        'enabled'         => true,
                        'retryEnabled'    => false,
                        'nextRun'         => '2000-01-01T00:00:00+00:00',
                        'repeat'          => ['times' => 0, 'completed' => 0],
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
    public function testFirstFailureSchedulesRetryWithBaseBackoff(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->method('processMessage')->willThrowException(new \RuntimeException('transient'));
        $this->service = $this->makeService();

        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'                    => 'interval',
                        'intervalMinutes'         => 60,
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'go',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 3,
                        'retryBackoffBaseSeconds' => 60,
                        'nextRun'                 => '2000-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
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
    public function testSecondFailureDoublesBackoff(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->method('processMessage')->willThrowException(new \RuntimeException('still failing'));
        $this->service = $this->makeService();

        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
            [
                $this->schedule(
                    [
                        'kind'                    => 'interval',
                        'intervalMinutes'         => 60,
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'go',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 3,
                        'retryBackoffBaseSeconds' => 60,
                        'nextRun'                 => '2030-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
                        'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
    public function testRetryBudgetExhaustedMarksDeadLetterAndAlertsOwner(): void
    {
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
                        'kind'                    => 'interval',
                        'intervalMinutes'         => 60,
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'go',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 2,
                        'retryBackoffBaseSeconds' => 60,
                        'circuitBreakerThreshold' => 3,
                        'consecutiveDeadLetters'  => 0,
                        'nextRun'                 => '2030-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
                        'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
    public function testOnceScheduleStaysEnabledWhileRetryPending(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->method('processMessage')->willThrowException(new \RuntimeException('once failed'));
        $this->service = $this->makeService();

        $this->objectService->method('findAll')->willReturn(
            [
                $this->schedule(
                    [
                        'kind'                    => 'once',
                        'runAt'                   => '2000-01-01T00:00:00+00:00',
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'once with retry',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 3,
                        'retryBackoffBaseSeconds' => 60,
                        'nextRun'                 => '2000-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
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
    public function testSuccessResetsRetryStateAndDeadLetterStreak(): void
    {
        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
            [
                $this->schedule(
                    [
                        'kind'                    => 'interval',
                        'intervalMinutes'         => 60,
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'go',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 3,
                        'retryBackoffBaseSeconds' => 60,
                        'consecutiveDeadLetters'  => 2,
                        'nextRun'                 => '2030-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
                        'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
    public function testCircuitBreakerTripsAndAlertsOwnerDistinctly(): void
    {
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
                        'kind'                    => 'interval',
                        'intervalMinutes'         => 60,
                        'agentId'                 => 'agent-uuid',
                        'prompt'                  => 'go',
                        'deliver'                 => 'none',
                        'enabled'                 => true,
                        'retryEnabled'            => true,
                        'retryMaxAttempts'        => 1,
                        'retryBackoffBaseSeconds' => 60,
                        'circuitBreakerThreshold' => 3,
                        'consecutiveDeadLetters'  => 2,
                        'nextRun'                 => '2000-01-01T00:00:00+00:00',
                        'repeat'                  => ['times' => 0, 'completed' => 0],
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
    public function testKillSwitchHaltsPendingRetryWithoutCountingAsDeadLetter(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');
        $this->deliveryService = $this->createMock(DeliveryService::class);
        $this->deliveryService->expects($this->never())->method('deliverFailureAlert');
        $this->deliveryService->expects($this->never())->method('deliverCircuitBreakerAlert');
        $this->service = $this->makeService();

        $gatedRetry = $this->schedule(
            [
                'kind'                    => 'interval',
                'intervalMinutes'         => 60,
                'agentId'                 => 'agent-uuid',
                'prompt'                  => 'go',
                'deliver'                 => 'none',
                'enabled'                 => true,
                'retryEnabled'            => true,
                'retryMaxAttempts'        => 2,
                'retryBackoffBaseSeconds' => 60,
                'consecutiveDeadLetters'  => 1,
                'nextRun'                 => '2030-01-01T00:00:00+00:00',
                'repeat'                  => ['times' => 0, 'completed' => 0],
                'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
    public function testApprovalGateStillAppliesToPendingRetry(): void
    {
        $this->chatService = $this->createMock(ChatService::class);
        $this->chatService->expects($this->never())->method('processMessage');

        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->approvalService->expects($this->once())->method('ensurePendingApproval');
        $this->service = $this->makeService();

        $gatedRetry = $this->schedule(
            [
                'kind'                    => 'interval',
                'intervalMinutes'         => 60,
                'agentId'                 => 'agent-uuid',
                'prompt'                  => 'go',
                'deliver'                 => 'none',
                'enabled'                 => true,
                'requiresApproval'        => true,
                'retryEnabled'            => true,
                'retryMaxAttempts'        => 3,
                'retryBackoffBaseSeconds' => 60,
                'nextRun'                 => '2030-01-01T00:00:00+00:00',
                'repeat'                  => ['times' => 0, 'completed' => 0],
                'retryState'              => ['attempt' => 1, 'nextAttemptAt' => '2000-01-01T00:00:00+00:00'],
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
}//end class
