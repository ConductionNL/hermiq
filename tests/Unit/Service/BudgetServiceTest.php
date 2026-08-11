<?php

/**
 * Unit tests for BudgetService (cost-guardrails).
 *
 * Exercises the hard-cap gate check, the soft-threshold one-per-period warning, the
 * pre-run estimate, and the tenant/system-wide read postures — all without a live
 * Nextcloud/OpenRegister. Usage is windowed from a fixed set of `action='run'` audit
 * entries (never a stored counter), mirroring AnalyticsServiceTest's fixture style.
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Hermiq\Service\AnalyticsService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the cost-guardrails BudgetService.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class BudgetServiceTest extends TestCase
{

    /**
     * A stateful ObjectService test double: setSchema() records the active schema so
     * findAll()/find()/saveObject()/deleteObject() can behave differently per schema
     * (budget vs schedule) within a single call chain — a plain PHPUnit mock cannot
     * express this without brittle consecutive-call ordering.
     *
     * @param array<string, array<int, ObjectEntity>> $bySchema  Schema slug → objects findAll() returns.
     * @param array<int, array<string, mixed>>        &$saved    Captures every saveObject() payload.
     *
     * @return ObjectService
     */
    private function objectService(array $bySchema, array &$saved=[]): ObjectService
    {
        return new class ($bySchema, $saved) extends ObjectService {
            private ?string $schema = null;

            /**
             * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
             * @param array<int, array<string, mixed>>        $saved    Captured saveObject() payloads.
             */
            public function __construct(private array $bySchema, private array &$saved)
            {
            }

            public function setRegister(mixed $register): static
            {
                return $this;
            }

            public function setSchema(mixed $schema): static
            {
                $this->schema = (string) $schema;
                return $this;
            }

            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return ($this->bySchema[$this->schema] ?? []);
            }

            public function find(
                int | string $id,
                ?array $_extend=[],
                bool $files=false,
                mixed $register=null,
                mixed $schema=null,
                bool $_rbac=true,
                bool $_multitenancy=true,
                bool $_render=true
            ): ?ObjectEntity {
                foreach (($this->bySchema[(string) $schema] ?? []) as $object) {
                    if ((string) $object->getUuid() === (string) $id) {
                        return $object;
                    }
                }

                return null;
            }

            public function saveObject(
                array | ObjectEntity $object,
                ?array $extend=[],
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true,
                bool $silent=false,
                ?array $uploadedFiles=null,
                ?\OCP\IUser $currentUser=null,
                // openregister#2211 (insert-only saves) added this. A double that
                // drifts from the real signature is a FATAL, not a failed
                // assertion: PHP refuses to declare the class and the whole
                // suite dies before it runs.
                bool $failIfExists=false
            ): ObjectEntity {
                $this->saved[] = is_array($object) ? $object : $object->getObject();
                $entity        = new ObjectEntity();
                $entity->setUuid($uuid ?? 'new-budget');
                $entity->setObject(is_array($object) ? $object : $object->getObject());
                return $entity;
            }
        };

    }//end objectService()

    /**
     * A Schedule ObjectEntity bound to an organisation + agent.
     *
     * @param string $uuid         The schedule uuid.
     * @param string $organisation The owning organisation.
     * @param string $agentId      The bound agent uuid.
     *
     * @return ObjectEntity
     */
    private function schedule(string $uuid, string $organisation, string $agentId): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setOrganisation($organisation);
        $e->setObject(['agentId' => $agentId]);
        return $e;

    }//end schedule()

    /**
     * A Budget ObjectEntity.
     *
     * @param string              $uuid         The budget uuid.
     * @param string              $organisation The owning organisation.
     * @param array<string,mixed> $payload      The budget body (scope/period/limits/...).
     *
     * @return ObjectEntity
     */
    private function budget(string $uuid, string $organisation, array $payload): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setOrganisation($organisation);
        $e->setObject(
            array_merge(
                [
                    'scope'                => 'organisation',
                    'agentId'              => '',
                    'period'               => 'monthly',
                    'tokenLimit'           => null,
                    'eurLimit'             => null,
                    'softThresholdPercent' => 80,
                    'enabled'              => true,
                    'warnedPeriodKey'      => '',
                    'lastHardBlockAt'      => null,
                ],
                $payload
            )
        );
        return $e;

    }//end budget()

    /**
     * A run AuditTrail entry created "now" carrying token usage.
     *
     * @param string $objectUuid       The owning schedule uuid.
     * @param int    $promptTokens     Prompt tokens recorded.
     * @param int    $completionTokens Completion tokens recorded.
     *
     * @return AuditTrail
     */
    private function runEntry(string $objectUuid, int $promptTokens, int $completionTokens): AuditTrail
    {
        $a = new AuditTrail();
        $a->setAction('run');
        $a->setObjectUuid($objectUuid);
        $a->setCreated(new DateTime('now'));
        $a->setChanged(['usage' => ['promptTokens' => $promptTokens, 'completionTokens' => $completionTokens]]);
        return $a;

    }//end runEntry()

    /**
     * An OrganisationMapper resolving every organisation to the given owner.
     *
     * @param string $owner The owner uid to resolve to.
     *
     * @return OrganisationMapper
     */
    private function orgMapper(string $owner): OrganisationMapper
    {
        $mapper = $this->createMock(OrganisationMapper::class);
        // Real entity, not a mock: the real Organisation resolves getOwner()
        // via Entity magic, unmockable under a server tree.
        $org = new Organisation();
        $org->setOwner($owner);
        $mapper->method('findByUuid')->willReturn($org);
        return $mapper;

    }//end orgMapper()

    /**
     * Build a BudgetService wired to the given fixtures.
     *
     * @param array<string, array<int, ObjectEntity>> $bySchema        Schema slug → objects.
     * @param array<int, array<string, mixed>>        &$saved          Captures saveObject() payloads.
     * @param string                                   $eurRate         The IAppConfig EUR rate ('' = unset).
     * @param OrganisationMapper|null                  $orgMapper       Optional custom org mapper.
     * @param AnalyticsService|null                    $analyticsService Optional custom AnalyticsService.
     * @param DeliveryService|null                     $deliveryService  Optional custom DeliveryService mock.
     *
     * @return BudgetService
     */
    private function service(
        array $bySchema,
        array &$saved=[],
        string $eurRate='',
        ?OrganisationMapper $orgMapper=null,
        ?AnalyticsService $analyticsService=null,
        ?DeliveryService $deliveryService=null
    ): BudgetService {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($eurRate);

        $auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $auditTrailMapper->method('findAll')->willReturn($bySchema['__runs__'] ?? []);

        return new BudgetService(
            objectService: $this->objectService(bySchema: $bySchema, saved: $saved),
            auditTrailMapper: $auditTrailMapper,
            appConfig: $appConfig,
            organisationMapper: $orgMapper ?? $this->orgMapper('org-owner'),
            deliveryService: $deliveryService ?? $this->createMock(DeliveryService::class),
            analyticsService: $analyticsService ?? $this->createMock(AnalyticsService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end service()

    /**
     * isBlocked() returns true once current-period usage reaches an organisation
     * budget's tokenLimit, computed from action='run' AuditTrail entries windowed to
     * the period — never a stored counter.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testIsBlockedTrueAtOrAboveTokenLimit(): void
    {
        $budget    = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000]);
        $schedule  = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema  = [
            'agentbudget'    => [$budget],
            'schedule'  => [$schedule],
            '__runs__'  => [$this->runEntry('s1', 60000, 40000)],
        ];

        $service = $this->service(bySchema: $bySchema);

        $this->assertTrue($service->isBlocked(organisation: 'org-a', agentId: null));

    }//end testIsBlockedTrueAtOrAboveTokenLimit()

    /**
     * isBlocked() returns false while current-period usage stays under the limit.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testIsBlockedFalseBelowLimit(): void
    {
        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            '__runs__' => [$this->runEntry('s1', 1000, 500)],
        ];

        $service = $this->service(bySchema: $bySchema);

        $this->assertFalse($service->isBlocked(organisation: 'org-a', agentId: null));

    }//end testIsBlockedFalseBelowLimit()

    /**
     * An organisation-scoped budget blocks all of that organisation's schedules; a
     * DIFFERENT organisation with its own due schedule is unaffected (its usage is
     * never mixed into org-a's window).
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testIsBlockedScopesUsageToOwnOrganisationOnly(): void
    {
        $budget = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 1000]);
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [
                $this->schedule('s1', 'org-a', 'agent-1'),
                $this->schedule('s2', 'org-b', 'agent-2'),
            ],
            // org-b's usage alone would exceed org-a's limit if it leaked in.
            '__runs__' => [
                $this->runEntry('s1', 100, 100),
                $this->runEntry('s2', 5000, 5000),
            ],
        ];

        $service = $this->service(bySchema: $bySchema);

        $this->assertFalse($service->isBlocked(organisation: 'org-a', agentId: null), 'org-b usage must never count toward org-a.');
        $this->assertFalse($service->isBlocked(organisation: 'org-b', agentId: null), 'org-b has no budget of its own.');

    }//end testIsBlockedScopesUsageToOwnOrganisationOnly()

    /**
     * An agent-scoped budget counts only that agent's schedules, regardless of which
     * organisation they belong to.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testIsBlockedAgentScopeCountsOnlyThatAgent(): void
    {
        $budget = $this->budget('b1', 'org-a', ['scope' => 'agent', 'agentId' => 'agent-1', 'tokenLimit' => 1000]);
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [
                $this->schedule('s1', 'org-a', 'agent-1'),
                $this->schedule('s2', 'org-a', 'agent-2'),
            ],
            '__runs__' => [
                $this->runEntry('s1', 900, 200),
                // Belongs to a DIFFERENT agent — must not count toward agent-1's budget.
                $this->runEntry('s2', 9000, 9000),
            ],
        ];

        $service = $this->service(bySchema: $bySchema);

        $this->assertTrue($service->isBlocked(organisation: 'org-a', agentId: 'agent-1'));
        $this->assertFalse($service->isBlocked(organisation: 'org-a', agentId: 'agent-2'));

    }//end testIsBlockedAgentScopeCountsOnlyThatAgent()

    /**
     * A budget read failure fails OPEN — isBlocked() returns false rather than halting
     * every tenant's runs, exactly like ScheduleService::loadEngagedOrganisations().
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testIsBlockedFailsOpenOnReadFailure(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willThrowException(new RuntimeException('OR down'));

        $service = new BudgetService(
            objectService: $objectService,
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            appConfig: $this->createMock(IAppConfig::class),
            organisationMapper: $this->orgMapper('org-owner'),
            deliveryService: $this->createMock(DeliveryService::class),
            analyticsService: $this->createMock(AnalyticsService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->assertFalse($service->isBlocked(organisation: 'org-a', agentId: null));

    }//end testIsBlockedFailsOpenOnReadFailure()

    /**
     * isBlocked() never consults AnalyticsService (the estimate) — only actual
     * recorded usage vs. limit decides the gate.
     *
     * @return void
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
     */
    public function testIsBlockedNeverReadsTheEstimate(): void
    {
        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->expects($this->never())->method('computeAnalytics');

        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 1000]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            '__runs__' => [$this->runEntry('s1', 100, 100)],
        ];

        $service = $this->service(bySchema: $bySchema, analyticsService: $analytics);
        $service->isBlocked(organisation: 'org-a', agentId: null);

        // Assertion is the mock expectation above (never called).
        $this->addToAssertionCount(1);

    }//end testIsBlockedNeverReadsTheEstimate()

    /**
     * recordWarningIfDue() returns the organisation owner exactly once per period when
     * the soft threshold is first crossed, and null on a subsequent check within the
     * SAME period (warnedPeriodKey persisted).
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testRecordWarningIfDueFiresOncePerPeriod(): void
    {
        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000, 'softThresholdPercent' => 80]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            // 85% of 100000 — crosses the 80% soft threshold, below the hard cap.
            '__runs__' => [$this->runEntry('s1', 50000, 35000)],
        ];

        $saved   = [];
        $service = $this->service(bySchema: $bySchema, saved: $saved, orgMapper: $this->orgMapper('alice'));

        $recipient = $service->recordWarningIfDue(budget: $budget);
        $this->assertSame('alice', $recipient, 'The first crossing in a period must return the org owner.');
        $this->assertNotEmpty($saved, 'warnedPeriodKey must be persisted.');
        $this->assertNotSame('', $saved[0]['warnedPeriodKey']);

        // Simulate the persisted warnedPeriodKey being read back on the NEXT check
        // within the same period: recordWarningIfDue must not re-fire.
        $warnedBudget = $this->budget(
            'b1',
            'org-a',
            [
                'scope'                => 'organisation',
                'tokenLimit'           => 100000,
                'softThresholdPercent' => 80,
                'warnedPeriodKey'      => $saved[0]['warnedPeriodKey'],
            ]
        );
        $again = $service->recordWarningIfDue(budget: $warnedBudget);
        $this->assertNull($again, 'A second check in the SAME period must not re-fire.');

    }//end testRecordWarningIfDueFiresOncePerPeriod()

    /**
     * recordWarningIfDue() returns null while usage stays below the soft threshold.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testRecordWarningIfDueReturnsNullBelowThreshold(): void
    {
        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000, 'softThresholdPercent' => 80]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            '__runs__' => [$this->runEntry('s1', 1000, 500)],
        ];

        $service = $this->service(bySchema: $bySchema);

        $this->assertNull($service->recordWarningIfDue(budget: $budget));

    }//end testRecordWarningIfDueReturnsNullBelowThreshold()

    /**
     * checkAndDeliverWarnings() delivers exactly one Talk/Notification message to the
     * organisation owner when a matching budget crosses its soft threshold.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testCheckAndDeliverWarningsDeliversOnceWhenDue(): void
    {
        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000, 'softThresholdPercent' => 80]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            '__runs__' => [$this->runEntry('s1', 50000, 35000)],
        ];

        $delivery = $this->createMock(DeliveryService::class);
        $delivery->expects($this->once())
            ->method('deliverBudgetWarning')
            ->with($this->anything(), ['alice'])
            ->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));

        $service = $this->service(bySchema: $bySchema, orgMapper: $this->orgMapper('alice'), deliveryService: $delivery);

        $service->checkAndDeliverWarnings(organisation: 'org-a', agentId: null);

    }//end testCheckAndDeliverWarningsDeliversOnceWhenDue()

    /**
     * checkAndDeliverWarnings() never throws when delivery fails — non-fatal by
     * contract, matching every other dispatch-path gate.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testCheckAndDeliverWarningsNeverThrowsOnDeliveryFailure(): void
    {
        $budget   = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 100000, 'softThresholdPercent' => 80]);
        $schedule = $this->schedule('s1', 'org-a', 'agent-1');
        $bySchema = [
            'agentbudget'   => [$budget],
            'schedule' => [$schedule],
            '__runs__' => [$this->runEntry('s1', 50000, 35000)],
        ];

        $delivery = $this->createMock(DeliveryService::class);
        $delivery->method('deliverBudgetWarning')->willThrowException(new RuntimeException('notification backend down'));

        $service = $this->service(bySchema: $bySchema, orgMapper: $this->orgMapper('alice'), deliveryService: $delivery);

        // Must not throw.
        $service->checkAndDeliverWarnings(organisation: 'org-a', agentId: null);
        $this->addToAssertionCount(1);

    }//end testCheckAndDeliverWarningsNeverThrowsOnDeliveryFailure()

    /**
     * estimateNextRun() returns the trailing-average prompt/completion/total tokens
     * from AnalyticsService::computeAnalytics() for an agent with prior runs.
     *
     * @return void
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
     */
    public function testEstimateNextRunReturnsTrailingAverage(): void
    {
        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->method('computeAnalytics')->with('agent-1')->willReturn(
            [
                'totalRuns' => 4,
                'tokens'    => ['available' => true, 'prompt' => 4000, 'completion' => 800, 'total' => 4800],
            ]
        );

        $service  = $this->service(bySchema: [], analyticsService: $analytics);
        $estimate = $service->estimateNextRun(agentId: 'agent-1');

        $this->assertTrue($estimate['available']);
        $this->assertSame(4, $estimate['sampleSize']);
        $this->assertSame(1000, $estimate['avgPromptTokens']);
        $this->assertSame(200, $estimate['avgCompletionTokens']);
        $this->assertSame(1200, $estimate['avgTotalTokens']);
        $this->assertNull($estimate['avgCostEur'], 'No EUR rate configured — must not fabricate a conversion.');

    }//end testEstimateNextRunReturnsTrailingAverage()

    /**
     * estimateNextRun() reports unavailable (never a fabricated zero) for an agent
     * with no recorded runs yet.
     *
     * @return void
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
     */
    public function testEstimateNextRunUnavailableWhenNoHistory(): void
    {
        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->method('computeAnalytics')->willReturn(
            ['totalRuns' => 0, 'tokens' => ['available' => false, 'prompt' => 0, 'completion' => 0, 'total' => 0]]
        );

        $service  = $this->service(bySchema: [], analyticsService: $analytics);
        $estimate = $service->estimateNextRun(agentId: 'agent-y');

        $this->assertFalse($estimate['available']);
        $this->assertNull($estimate['avgTotalTokens']);
        $this->assertSame(0, $estimate['sampleSize']);

    }//end testEstimateNextRunUnavailableWhenNoHistory()

    /**
     * estimateNextRun() derives a EUR figure only when the instance-wide EUR rate is
     * configured.
     *
     * @return void
     *
     * @spec openspec/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
     */
    public function testEstimateNextRunDerivesEurWhenRateConfigured(): void
    {
        $analytics = $this->createMock(AnalyticsService::class);
        $analytics->method('computeAnalytics')->willReturn(
            ['totalRuns' => 2, 'tokens' => ['available' => true, 'prompt' => 2000, 'completion' => 0, 'total' => 2000]]
        );

        $service  = $this->service(bySchema: [], eurRate: '2', analyticsService: $analytics);
        $estimate = $service->estimateNextRun(agentId: 'agent-1');

        // avgTotalTokens = 1000; 1000 tokens * 2 EUR/1k tokens / 1000 = 2.0 EUR.
        $this->assertSame(2.0, $estimate['avgCostEur']);

    }//end testEstimateNextRunDerivesEurWhenRateConfigured()

    /**
     * create() rejects scope=agent without an agentId (cross-field validation the
     * schema tooling cannot express).
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testCreateRejectsAgentScopeWithoutAgentId(): void
    {
        $service = $this->service(bySchema: []);

        $this->expectException(InvalidArgumentException::class);
        $service->create(payload: ['scope' => 'agent', 'period' => 'monthly', 'tokenLimit' => 1000], organisation: 'org-a');

    }//end testCreateRejectsAgentScopeWithoutAgentId()

    /**
     * create() rejects a payload with neither tokenLimit nor eurLimit set.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testCreateRejectsMissingBothLimits(): void
    {
        $service = $this->service(bySchema: []);

        $this->expectException(InvalidArgumentException::class);
        $service->create(payload: ['scope' => 'organisation', 'period' => 'monthly'], organisation: 'org-a');

    }//end testCreateRejectsMissingBothLimits()

    /**
     * create() persists a valid organisation-scoped budget pinned to the target
     * organisation (not the actor's active org).
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testCreatePersistsBudgetPinnedToTargetOrganisation(): void
    {
        $saved   = [];
        $service = $this->service(bySchema: [], saved: $saved);

        $created = $service->create(
            payload: ['scope' => 'organisation', 'period' => 'monthly', 'tokenLimit' => 2000000, 'softThresholdPercent' => 80],
            organisation: 'org-a'
        );

        $this->assertSame('organisation', $created['scope']);
        $this->assertSame(2000000, $created['tokenLimit']);
        $this->assertNotEmpty($saved);
        $this->assertSame('org-a', $saved[0]['@self']['organisation']);

    }//end testCreatePersistsBudgetPinnedToTargetOrganisation()

    /**
     * statusForScope() reports "not configured" (never an error) when no budget
     * matches the caller's organisation/agent.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testStatusForScopeReportsNotConfiguredWhenNoBudget(): void
    {
        $service = $this->service(bySchema: ['agentbudget' => []]);

        $status = $service->statusForScope(organisation: 'org-a', agentId: null);

        $this->assertFalse($status['configured']);
        $this->assertFalse($status['hardCapReached']);

    }//end testStatusForScopeReportsNotConfiguredWhenNoBudget()

    /**
     * listForCaller() shapes every visible budget and can be filtered to one
     * organisation.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testListForCallerFiltersByOrganisation(): void
    {
        $bySchema = [
            'agentbudget' => [
                $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 1000]),
                $this->budget('b2', 'org-b', ['scope' => 'organisation', 'tokenLimit' => 2000]),
            ],
        ];

        $service = $this->service(bySchema: $bySchema);

        $all    = $service->listForCaller();
        $orgA   = $service->listForCaller(organisation: 'org-a');

        $this->assertCount(2, $all);
        $this->assertCount(1, $orgA);
        $this->assertSame('b1', $orgA[0]['id']);

    }//end testListForCallerFiltersByOrganisation()

    /**
     * update() merges the requested fields onto the existing budget and persists them.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testUpdateMergesFieldsAndPersists(): void
    {
        $existing = $this->budget('b1', 'org-a', ['scope' => 'organisation', 'tokenLimit' => 1000]);
        $saved    = [];
        $service  = $this->service(bySchema: ['agentbudget' => [$existing]], saved: $saved);

        $updated = $service->update(budgetId: 'b1', payload: ['tokenLimit' => 5000, 'softThresholdPercent' => 90]);

        $this->assertSame(5000, $updated['tokenLimit']);
        $this->assertSame(90, $updated['softThresholdPercent']);
        $this->assertNotEmpty($saved);

    }//end testUpdateMergesFieldsAndPersists()

    /**
     * update() throws when the budget cannot be found (never silently no-ops).
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testUpdateThrowsWhenBudgetMissing(): void
    {
        $service = $this->service(bySchema: ['agentbudget' => []]);

        $this->expectException(RuntimeException::class);
        $service->update(budgetId: 'missing', payload: ['tokenLimit' => 5000]);

    }//end testUpdateThrowsWhenBudgetMissing()

    /**
     * delete() calls through to ObjectService::deleteObject() for the budget schema.
     *
     * @return void
     *
     * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
     */
    public function testDeleteRemovesTheBudget(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('deleteObject')
            ->with('b1', 'hermiq', 'agentbudget', false, false)
            ->willReturn(true);

        $service = new BudgetService(
            objectService: $objectService,
            auditTrailMapper: $this->createMock(AuditTrailMapper::class),
            appConfig: $this->createMock(IAppConfig::class),
            organisationMapper: $this->orgMapper('org-owner'),
            deliveryService: $this->createMock(DeliveryService::class),
            analyticsService: $this->createMock(AnalyticsService::class),
            logger: $this->createMock(LoggerInterface::class),
        );

        $service->delete(budgetId: 'b1');

    }//end testDeleteRemovesTheBudget()
}//end class
