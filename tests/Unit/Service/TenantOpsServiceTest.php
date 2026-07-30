<?php

/**
 * Unit tests for TenantOpsService (multi-tenant-ops + agent-lifecycle-governance).
 *
 * Covers the quota math (schedule count, distinct agents-in-use, configured limits +
 * atLimit), the AI Act audit export scoping (records come only from the caller's own
 * loaded objects, now including incident records), the periodic access-review list +
 * reviewed attestation + flagged-agent reassignment, incident create/list, and the
 * per-organisation retention-period get/set (>= 6 months).
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
 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-4-1
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-4-tenantopsservice-access-review-reassignment
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-6-tenantopsservice-incidents-audit-export-extension
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\TenantOpsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the multi-tenant-ops / agent-lifecycle-governance TenantOpsService.
 *
 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-4-1
 */
class TenantOpsServiceTest extends TestCase
{

    /**
     * A schedule ObjectEntity bound to an agent.
     *
     * @param string $uuid    The schedule uuid.
     * @param string $agentId The bound agent uuid.
     *
     * @return ObjectEntity
     */
    private function schedule(string $uuid, string $agentId): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setObject(['agentId' => $agentId]);
        return $e;

    }//end schedule()

    /**
     * An IAppConfig returning the given integer quota limits.
     *
     * @param int $scheduleQuota The schedule limit.
     * @param int $agentQuota    The agent limit.
     *
     * @return IAppConfig
     */
    private function appConfig(int $scheduleQuota, int $agentQuota): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueInt')->willReturnCallback(
            static function (string $app, string $key, int $default=0) use ($scheduleQuota, $agentQuota): int {
                if ($key === 'scheduleQuota') {
                    return $scheduleQuota;
                }
                if ($key === 'agentQuota') {
                    return $agentQuota;
                }
                return $default;
            }
        );
        return $cfg;

    }//end appConfig()

    /**
     * An IUserManager resolving the given active uids to an enabled IUser and
     * everything else to null (unknown user).
     *
     * @param array<int, string> $activeUids Uids that resolve to an active user.
     * @param array<int, string> $disabledUids Uids that resolve to a DISABLED user.
     *
     * @return IUserManager
     */
    private function userManager(array $activeUids=[], array $disabledUids=[]): IUserManager
    {
        $manager = $this->createMock(IUserManager::class);
        $manager->method('get')->willReturnCallback(
            function (string $uid) use ($activeUids, $disabledUids): ?IUser {
                if (in_array($uid, $disabledUids, true) === true) {
                    $user = $this->createMock(IUser::class);
                    $user->method('isEnabled')->willReturn(false);
                    return $user;
                }

                if (in_array($uid, $activeUids, true) === true) {
                    $user = $this->createMock(IUser::class);
                    $user->method('isEnabled')->willReturn(true);
                    return $user;
                }

                return null;
            }
        );
        return $manager;

    }//end userManager()

    /**
     * Build a stateful ObjectService test double keyed by schema.
     *
     * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
     *
     * @return ObjectService
     */
    private function objectService(array $bySchema): ObjectService
    {
        return new class ($bySchema) extends ObjectService {
            private ?string $schema = null;

            /**
             * @var array<int, array{object: array|ObjectEntity, register: mixed, schema: mixed, uuid: string|null}>
             */
            public array $saved = [];

            /**
             * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
             */
            public function __construct(private array $bySchema)
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
                    if ($object->getUuid() === $id) {
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
                $this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];

                $entity = new ObjectEntity();
                $entity->setUuid($uuid ?? 'new-uuid');
                $entity->setObject(is_array($object) ? $object : $object->getObject());
                return $entity;
            }
        };

    }//end objectService()

    /**
     * quotaStatus counts schedules, de-duplicates agents, and flags the limits.
     *
     * @return void
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-1
     */
    public function testQuotaStatus(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn(
            [
                $this->schedule('s1', 'agentA'),
                $this->schedule('s2', 'agentA'),
                $this->schedule('s3', 'agentB'),
            ]
        );

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(scheduleQuota: 3, agentQuota: 50),
            $this->userManager()
        );

        $q = $service->quotaStatus();

        $this->assertSame(3, $q['schedules']['count']);
        $this->assertSame(3, $q['schedules']['limit']);
        $this->assertTrue($q['schedules']['atLimit']);

        // agentA + agentB = 2 distinct agents in use.
        $this->assertSame(2, $q['agents']['count']);
        $this->assertSame(50, $q['agents']['limit']);
        $this->assertFalse($q['agents']['atLimit']);

    }//end testQuotaStatus()

    /**
     * The audit export records come only from the caller's own loaded objects.
     *
     * @return void
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-2
     */
    public function testAuditExportScopedToCallerObjects(): void
    {
        // The service loads schedules then approvals; return one object each.
        $schemaState   = ['schema' => ''];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnCallback(
            static function (string $schema) use (&$schemaState, $objectService) {
                $schemaState['schema'] = $schema;
                return $objectService;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            function () use (&$schemaState): array {
                if ($schemaState['schema'] === 'schedule') {
                    $s = new ObjectEntity();
                    $s->setUuid('sched-1');
                    $s->setObject(['agentId' => 'agentA']);
                    return [$s];
                }
                if ($schemaState['schema'] === 'incident') {
                    return [];
                }
                $a = new ObjectEntity();
                $a->setUuid('appr-1');
                $a->setObject([]);
                return [$a];
            }
        );

        $entry = new AuditTrail();
        $entry->setAction('run');
        $entry->setUser('alice');
        $entry->setChanged(['status' => 'ok']);

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn([$entry]);

        $service = new TenantOpsService($objectService, $mapper, $this->appConfig(100, 50), $this->userManager());

        $export = $service->exportAuditTrail();

        $this->assertSame('eu-ai-act-audit', $export['export']);
        // One schedule + one approval, each with one audit entry → 2 records.
        $this->assertSame(2, $export['recordCount']);
        $types = array_column($export['records'], 'objectType');
        $this->assertContains('schedule', $types);
        $this->assertContains('approval', $types);

    }//end testAuditExportScopedToCallerObjects()

    /**
     * The audit export includes each incident's own fields (description/impact/
     * actionsTaken/linked refs), not just a generic AuditTrail log line.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-are-included-in-the-art-12-audit-export
     */
    public function testExportAuditTrailIncludesIncidentRecords(): void
    {
        $incident = new ObjectEntity();
        $incident->setUuid('incident-1');
        $incident->setObject(
            [
                'description'   => 'Agent posted duplicate replies.',
                'impact'        => 'Minor.',
                'actionsTaken'  => 'Paused and fixed.',
                'linkedAgentId' => 'agent-1',
                'linkedRunIds'  => ['run-1'],
                'createdAt'     => '2026-07-01T00:00:00+00:00',
                'createdBy'     => 'org.admin',
            ]
        );

        $objectService = $this->objectService(
            [
                'schedule' => [],
                'approval' => [],
                'incident' => [$incident],
            ]
        );

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn([]);

        $service = new TenantOpsService($objectService, $mapper, $this->appConfig(100, 50), $this->userManager());

        $export = $service->exportAuditTrail();

        $this->assertSame(1, $export['recordCount']);
        $record = $export['records'][0];
        $this->assertSame('incident', $record['objectType']);
        $this->assertSame('Agent posted duplicate replies.', $record['description']);
        $this->assertSame('Minor.', $record['impact']);
        $this->assertSame('Paused and fixed.', $record['actionsTaken']);
        $this->assertSame('agent-1', $record['linkedAgentId']);
        $this->assertSame(['run-1'], $record['linkedRunIds']);

    }//end testExportAuditTrailIncludesIncidentRecords()

    /**
     * accessReviewList() shapes each agent with its owner/actingUser/capability
     * summary/reassignment+review state and its most recent run timestamp.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary
     */
    public function testAccessReviewListShapesAgentsWithLastRun(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setOwner('j.doe');
        $agent->setObject(
            [
                'name'             => 'Permit drafting assistant',
                'actingUser'       => null,
                'tools'            => ['openregister.searchObjects'],
                'reassignmentFlag' => false,
                'reviewedAt'       => null,
                'reviewedBy'       => null,
            ]
        );

        $schedule = $this->schedule('sched-1', 'agent-1');

        $objectService = $this->objectService(
            [
                'agent'    => [$agent],
                'schedule' => [$schedule],
            ]
        );

        $older = new AuditTrail();
        $older->setCreated(new \DateTime('2026-07-01 08:00:00'));
        $newer = new AuditTrail();
        $newer->setCreated(new \DateTime('2026-07-10 08:00:00'));

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn([$older, $newer]);

        $service = new TenantOpsService($objectService, $mapper, $this->appConfig(100, 50), $this->userManager());

        $result = $service->accessReviewList();

        $this->assertCount(1, $result['agents']);
        $row = $result['agents'][0];
        $this->assertSame('agent-1', $row['uuid']);
        $this->assertSame('j.doe', $row['owner']);
        $this->assertNull($row['actingUser']);
        $this->assertSame(['openregister.searchObjects'], $row['tools']);
        $this->assertFalse($row['reassignmentFlag']);
        $this->assertNotNull($row['lastRunAt']);
        $this->assertStringContainsString('2026-07-10', $row['lastRunAt']);

    }//end testAccessReviewListShapesAgentsWithLastRun()

    /**
     * attestAgentReviewed() sets reviewedAt/reviewedBy on the agent.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-reviewed-attestation-is-recorded-and-auditable
     */
    public function testAttestAgentReviewedSetsReviewedAtAndReviewedBy(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setObject(['name' => 'Agent one']);

        $objectService = $this->objectService(['agent' => [$agent]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $row = $service->attestAgentReviewed(uuid: 'agent-1', reviewerUid: 'org.admin');

        $this->assertSame('org.admin', $row['reviewedBy']);
        $this->assertNotNull($row['reviewedAt']);
        $this->assertCount(1, $objectService->saved);
        $this->assertSame('org.admin', $objectService->saved[0]['object']['reviewedBy']);

    }//end testAttestAgentReviewedSetsReviewedAtAndReviewedBy()

    /**
     * attestAgentReviewed() throws when the agent does not exist.
     *
     * @return void
     */
    public function testAttestAgentReviewedThrowsWhenAgentMissing(): void
    {
        $objectService = $this->objectService(['agent' => []]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $this->expectException(RuntimeException::class);
        $service->attestAgentReviewed(uuid: 'missing', reviewerUid: 'org.admin');

    }//end testAttestAgentReviewedThrowsWhenAgentMissing()

    /**
     * reassignAgent() updates actingUser and clears the reassignment flag when
     * the target user exists and is active.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents
     */
    public function testReassignAgentUpdatesActingUserAndClearsFlag(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setObject(['name' => 'Agent one', 'reassignmentFlag' => true]);

        $objectService = $this->objectService(['agent' => [$agent]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager(activeUids: ['new.owner'])
        );

        $row = $service->reassignAgent(uuid: 'agent-1', newActingUser: 'new.owner');

        $this->assertFalse($row['reassignmentFlag']);
        $this->assertSame('new.owner', $objectService->saved[0]['object']['actingUser']);
        $this->assertFalse($objectService->saved[0]['object']['reassignmentFlag']);

    }//end testReassignAgentUpdatesActingUserAndClearsFlag()

    /**
     * reassignAgent() rejects a non-existent target user, leaving the agent flagged.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents
     */
    public function testReassignAgentRejectsNonexistentUser(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setObject(['reassignmentFlag' => true]);

        $objectService = $this->objectService(['agent' => [$agent]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $this->expectException(InvalidArgumentException::class);
        $service->reassignAgent(uuid: 'agent-1', newActingUser: 'ghost');

    }//end testReassignAgentRejectsNonexistentUser()

    /**
     * reassignAgent() rejects a disabled target user.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents
     */
    public function testReassignAgentRejectsDisabledUser(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setObject(['reassignmentFlag' => true]);

        $objectService = $this->objectService(['agent' => [$agent]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager(disabledUids: ['disabled.user'])
        );

        $this->expectException(InvalidArgumentException::class);
        $service->reassignAgent(uuid: 'agent-1', newActingUser: 'disabled.user');

    }//end testReassignAgentRejectsDisabledUser()

    /**
     * listIncidents() returns incidents newest first.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
     */
    public function testListIncidentsNewestFirst(): void
    {
        $older = new ObjectEntity();
        $older->setUuid('older');
        $older->setObject(['description' => 'older', 'impact' => '', 'actionsTaken' => '', 'createdAt' => '2026-01-01T00:00:00+00:00']);

        $newer = new ObjectEntity();
        $newer->setUuid('newer');
        $newer->setObject(['description' => 'newer', 'impact' => '', 'actionsTaken' => '', 'createdAt' => '2026-07-01T00:00:00+00:00']);

        $objectService = $this->objectService(['incident' => [$older, $newer]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $list = $service->listIncidents();

        $this->assertSame('newer', $list['incidents'][0]['uuid']);
        $this->assertSame('older', $list['incidents'][1]['uuid']);

    }//end testListIncidentsNewestFirst()

    /**
     * createIncident() persists all fields including linked agent/runs and createdBy.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
     */
    public function testCreateIncidentPersistsFields(): void
    {
        $objectService = $this->objectService(['incident' => []]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $incident = $service->createIncident(
            description: 'Duplicate replies.',
            impact: 'Minor.',
            actionsTaken: 'Paused and fixed.',
            linkedAgentId: 'agent-1',
            linkedRunIds: ['run-1', 'run-2'],
            createdBy: 'org.admin'
        );

        $this->assertSame('Duplicate replies.', $incident['description']);
        $this->assertSame('agent-1', $incident['linkedAgentId']);
        $this->assertSame(['run-1', 'run-2'], $incident['linkedRunIds']);
        $this->assertSame('org.admin', $incident['createdBy']);
        $this->assertCount(1, $objectService->saved);

    }//end testCreateIncidentPersistsFields()

    /**
     * getRetentionMonths() defaults to 6 when no TenantControl exists yet.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
     */
    public function testGetRetentionMonthsDefaultsToSix(): void
    {
        $objectService = $this->objectService(['tenantcontrol' => []]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $this->assertSame(6, $service->getRetentionMonths());

    }//end testGetRetentionMonthsDefaultsToSix()

    /**
     * setRetentionMonths() rejects a value below 6, leaving the stored value unchanged.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
     */
    public function testSetRetentionMonthsRejectsBelowSix(): void
    {
        $objectService = $this->objectService(['tenantcontrol' => []]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $this->expectException(InvalidArgumentException::class);
        $service->setRetentionMonths(3);

    }//end testSetRetentionMonthsRejectsBelowSix()

    /**
     * setRetentionMonths() persists a valid value and getRetentionMonths() then reflects it.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
     */
    public function testSetRetentionMonthsPersistsValue(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('control-1');
        $existing->setObject(['engaged' => false, 'retentionMonths' => 6]);

        $objectService = $this->objectService(['tenantcontrol' => [$existing]]);

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(100, 50),
            $this->userManager()
        );

        $result = $service->setRetentionMonths(12);

        $this->assertSame(12, $result);
        $this->assertSame(12, $objectService->saved[0]['object']['retentionMonths']);
        $this->assertSame('control-1', $objectService->saved[0]['uuid']);

    }//end testSetRetentionMonthsPersistsValue()
}//end class
