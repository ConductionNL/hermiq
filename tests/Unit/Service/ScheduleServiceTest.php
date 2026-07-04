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

use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ChatService;
use OCA\OpenRegister\Service\ObjectService;
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

        $this->service = new ScheduleService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            conversationMapper: $this->conversationMapper,
            chatService: $this->chatService,
            userSession: $this->userSession,
            userManager: $this->userManager,
            config: $this->config,
            logger: $this->createMock(LoggerInterface::class),
            deliveryService: $this->deliveryService,
        );

    }//end setUp()

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
        $this->service = new ScheduleService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            conversationMapper: $this->conversationMapper,
            chatService: $this->chatService,
            userSession: $this->userSession,
            userManager: $this->userManager,
            config: $this->config,
            logger: $this->createMock(LoggerInterface::class),
            deliveryService: $this->deliveryService,
        );

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
}//end class
