<?php

/**
 * Unit tests for AgentVersionService (agent-versioning).
 *
 * Exercises the read + rollback surface over a mocked OpenRegister AuditTrail:
 *   - listVersions() returns every create/update entry, newest-first;
 *   - diff() reconstructs two versions' VERSIONED_FIELDS values by replaying
 *     changed['old'] backward from the live object, and is scoped to the
 *     allowlist (an unrelated field never appears);
 *   - diffing a version against itself yields no changes;
 *   - rollback() merges the target version's VERSIONED_FIELDS onto the CURRENT
 *     payload and persists via ObjectService::saveObject() — non-allowlisted
 *     fields are untouched;
 *   - currentVersionId() never throws, returning null on any failure.
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
 * @spec openspec/changes/agent-versioning/tasks.md#task-1-agentversionservice-list-history-diff-rollback-current-version-lookup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentVersionService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the agent-versioning AgentVersionService.
 *
 * @spec openspec/changes/agent-versioning/tasks.md#task-1-agentversionservice-list-history-diff-rollback-current-version-lookup
 */
class AgentVersionServiceTest extends TestCase
{

    /**
     * Build an AuditTrail entry.
     *
     * @param string              $action  The audit action.
     * @param array<string,mixed> $changed The field-diff map.
     * @param string              $created A parseable created timestamp.
     * @param string              $uuid    The entry UUID.
     * @param string              $user    The acting user.
     *
     * @return AuditTrail
     */
    private function entry(string $action, array $changed, string $created, string $uuid, string $user='alice'): AuditTrail
    {
        $entry = new AuditTrail();
        $entry->setUuid($uuid);
        $entry->setAction($action);
        $entry->setUser($user);
        $entry->setChanged($changed);
        $entry->setCreated(new \DateTime($created));
        return $entry;

    }//end entry()

    /**
     * Build an Agent ObjectEntity with the given payload.
     *
     * @param array<string,mixed> $payload The object body.
     * @param string               $uuid    The object UUID.
     *
     * @return ObjectEntity
     */
    private function agent(array $payload, string $uuid='agent-1'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end agent()

    /**
     * A 4-entry history: one create + three updates that each touch `prompt`
     * (and the CREATE + newest UPDATE additionally touch `name`, an
     * out-of-allowlist field, to prove it never leaks into a diff).
     *
     * @return array<int, AuditTrail> Newest-first.
     */
    private function fourEntryHistory(): array
    {
        return [
            $this->entry(
                'update',
                [
                    'prompt' => ['old' => 'v3', 'new' => 'v4'],
                    'name'   => ['old' => 'N3', 'new' => 'N4'],
                ],
                '2026-01-04T10:00:00+00:00',
                'e4'
            ),
            $this->entry('update', ['prompt' => ['old' => 'v2', 'new' => 'v3']], '2026-01-03T10:00:00+00:00', 'e3'),
            $this->entry('update', ['prompt' => ['old' => 'v1', 'new' => 'v2']], '2026-01-02T10:00:00+00:00', 'e2'),
            $this->entry(
                'create',
                [
                    'prompt' => ['old' => null, 'new' => 'v1'],
                    'name'   => ['old' => null, 'new' => 'N1'],
                ],
                '2026-01-01T10:00:00+00:00',
                'e1'
            ),
        ];

    }//end fourEntryHistory()

    /**
     * listVersions() returns every create/update entry, newest-first, each
     * with a version id, timestamp, user, and action.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function testListVersionsReturnsNewestFirst(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);

        $service  = new AgentVersionService($mapper, $objectService);
        $versions = $service->listVersions('agent-1');

        $this->assertCount(4, $versions);
        $this->assertSame(['e4', 'e3', 'e2', 'e1'], array_column($versions, 'id'), 'Must be newest-first.');
        $this->assertSame('alice', $versions[0]['user']);
        $this->assertSame('update', $versions[0]['action']);
        $this->assertSame('create', $versions[3]['action']);
        $this->assertContains('prompt', $versions[0]['changedFields']);
        $this->assertNotContains('name', $versions[0]['changedFields'], 'Only VERSIONED_FIELDS ever appear in changedFields.');

    }//end testListVersionsReturnsNewestFirst()

    /**
     * A newly created agent (a single `create` entry) has exactly one version.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function testSingleCreateEntryYieldsOneVersion(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn(
            [$this->entry('create', ['prompt' => ['old' => null, 'new' => 'v1']], '2026-01-01T10:00:00+00:00', 'e1')]
        );
        $objectService = $this->createMock(ObjectService::class);

        $service  = new AgentVersionService($mapper, $objectService);
        $versions = $service->listVersions('agent-1');

        $this->assertCount(1, $versions);
        $this->assertSame('e1', $versions[0]['id']);

    }//end testSingleCreateEntryYieldsOneVersion()

    /**
     * Non-create/update AuditTrail entries (e.g. a future agent-scoped audit
     * action) never appear in the version timeline.
     *
     * @return void
     */
    public function testNonVersionActionsAreExcluded(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn(
            [
                $this->entry('create', ['prompt' => ['old' => null, 'new' => 'v1']], '2026-01-01T10:00:00+00:00', 'e1'),
                $this->entry('agent-run', ['status' => 'ok'], '2026-01-02T10:00:00+00:00', 'r1'),
            ]
        );
        $objectService = $this->createMock(ObjectService::class);

        $service  = new AgentVersionService($mapper, $objectService);
        $versions = $service->listVersions('agent-1');

        $this->assertCount(1, $versions);
        $this->assertSame('e1', $versions[0]['id']);

    }//end testNonVersionActionsAreExcluded()

    /**
     * Diffing two versions where only `prompt` changed between them returns
     * exactly that field, old/new — and never an out-of-allowlist field.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    public function testDiffReturnsOnlyChangedAllowlistedFields(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->agent(['prompt' => 'v4', 'name' => 'Agent Four']));

        $service = new AgentVersionService($mapper, $objectService);
        $diff    = $service->diff('agent-1', 'e2', 'e3');

        $this->assertArrayHasKey('prompt', $diff);
        $this->assertSame('v2', $diff['prompt']['old'], "e2's own reconstructed state is its 'new' value, v2.");
        $this->assertSame('v3', $diff['prompt']['new'], "e3's own reconstructed state is its 'new' value, v3.");
        $this->assertArrayNotHasKey('name', $diff, 'name is not in VERSIONED_FIELDS.');

    }//end testDiffReturnsOnlyChangedAllowlistedFields()

    /**
     * Diffing the oldest (create) version against the newest reconstructs the
     * full span of changes.
     *
     * @return void
     */
    public function testDiffAcrossFullHistorySpan(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->agent(['prompt' => 'v4', 'name' => 'Agent Four']));

        $service = new AgentVersionService($mapper, $objectService);
        $diff    = $service->diff('agent-1', 'e1', 'e4');

        $this->assertSame('v1', $diff['prompt']['old']);
        $this->assertSame('v4', $diff['prompt']['new']);

    }//end testDiffAcrossFullHistorySpan()

    /**
     * Diffing a version id against itself yields no changes.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    public function testDiffAgainstSelfIsEmpty(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->agent(['prompt' => 'v4', 'name' => 'Agent Four']));

        $service = new AgentVersionService($mapper, $objectService);
        $diff    = $service->diff('agent-1', 'e3', 'e3');

        $this->assertSame([], $diff);

    }//end testDiffAgainstSelfIsEmpty()

    /**
     * Diffing two versions that also differ in `tools`/`skillInstalls` surfaces
     * both fields.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    public function testDiffSurfacesToolsAndSkillInstalls(): void
    {
        $entries = [
            $this->entry(
                'update',
                [
                    'tools'         => ['old' => ['opencatalogi.search'], 'new' => ['opencatalogi.search', 'openconnector.fetch']],
                    'skillInstalls' => ['old' => [], 'new' => ['skill-uuid-1']],
                ],
                '2026-01-02T10:00:00+00:00',
                'e2'
            ),
            $this->entry('create', [], '2026-01-01T10:00:00+00:00', 'e1'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($entries);
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->agent(['tools' => ['opencatalogi.search', 'openconnector.fetch'], 'skillInstalls' => ['skill-uuid-1']])
        );

        $service = new AgentVersionService($mapper, $objectService);
        $diff    = $service->diff('agent-1', 'e1', 'e2');

        $this->assertSame(['opencatalogi.search'], $diff['tools']['old']);
        $this->assertSame(['opencatalogi.search', 'openconnector.fetch'], $diff['tools']['new']);
        $this->assertSame([], $diff['skillInstalls']['old']);
        $this->assertSame(['skill-uuid-1'], $diff['skillInstalls']['new']);

    }//end testDiffSurfacesToolsAndSkillInstalls()

    /**
     * An unknown version id in a diff request throws (the controller maps
     * this to a 400).
     *
     * @return void
     */
    public function testDiffThrowsForUnknownVersionId(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);

        $service = new AgentVersionService($mapper, $objectService);

        $this->expectException(RuntimeException::class);
        $service->diff('agent-1', 'does-not-exist', 'e4');

    }//end testDiffThrowsForUnknownVersionId()

    /**
     * Rollback reconstructs the target version's VERSIONED_FIELDS and persists
     * them via ObjectService::saveObject(), leaving every non-allowlisted
     * field (name, isPrivate, tokenQuota) at its CURRENT value.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
     */
    public function testRollbackMergesTargetFieldsOntoCurrentPayload(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());

        $currentAgent = $this->agent(
            [
                'prompt'     => 'v4',
                'name'       => 'Agent Four',
                'isPrivate'  => true,
                'tokenQuota' => 5000,
            ]
        );

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($currentAgent);

        $savedPayload = null;
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $rbac=true, bool $multitenancy=true) use (&$savedPayload): ObjectEntity {
                    $savedPayload = $object;
                    $this->assertSame('hermiq', $register);
                    $this->assertSame('agent', $schema);
                    $this->assertSame('agent-1', $uuid);
                    return $this->agent($object);
                }
            );

        $service = new AgentVersionService($mapper, $objectService);
        $updated = $service->rollback('agent-1', 'e2');

        $this->assertSame('v2', $savedPayload['prompt'], 'Rolled back to version e2\'s recorded prompt.');
        $this->assertSame('Agent Four', $savedPayload['name'], 'Non-allowlisted name must keep its CURRENT value.');
        $this->assertTrue($savedPayload['isPrivate'], 'Non-allowlisted isPrivate must keep its CURRENT value.');
        $this->assertSame(5000, $savedPayload['tokenQuota'], 'Non-allowlisted tokenQuota must keep its CURRENT value.');
        $this->assertInstanceOf(ObjectEntity::class, $updated);

    }//end testRollbackMergesTargetFieldsOntoCurrentPayload()

    /**
     * An unknown version id on rollback throws rather than silently no-op-ing.
     *
     * @return void
     */
    public function testRollbackThrowsForUnknownVersionId(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('saveObject');

        $service = new AgentVersionService($mapper, $objectService);

        $this->expectException(RuntimeException::class);
        $service->rollback('agent-1', 'does-not-exist');

    }//end testRollbackThrowsForUnknownVersionId()

    /**
     * currentVersionId() returns the newest version's id.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it
     */
    public function testCurrentVersionIdReturnsNewestEntry(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($this->fourEntryHistory());
        $objectService = $this->createMock(ObjectService::class);

        $service = new AgentVersionService($mapper, $objectService);

        $this->assertSame('e4', $service->currentVersionId('agent-1'));

    }//end testCurrentVersionIdReturnsNewestEntry()

    /**
     * currentVersionId() returns null (never throws) when the AuditTrail
     * lookup itself throws.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it
     */
    public function testCurrentVersionIdReturnsNullOnFailure(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willThrowException(new RuntimeException('db unavailable'));
        $objectService = $this->createMock(ObjectService::class);

        $service = new AgentVersionService($mapper, $objectService);

        $this->assertNull($service->currentVersionId('agent-1'));

    }//end testCurrentVersionIdReturnsNullOnFailure()

    /**
     * currentVersionId() returns null when the agent has no version entries
     * at all.
     *
     * @return void
     */
    public function testCurrentVersionIdReturnsNullWhenNoEntries(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn([]);
        $objectService = $this->createMock(ObjectService::class);

        $service = new AgentVersionService($mapper, $objectService);

        $this->assertNull($service->currentVersionId('agent-1'));

    }//end testCurrentVersionIdReturnsNullWhenNoEntries()
}//end class
