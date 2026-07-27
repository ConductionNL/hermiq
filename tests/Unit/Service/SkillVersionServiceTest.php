<?php

/**
 * Unit tests for SkillVersionService (skill-self-improvement).
 *
 * Covers the agent-versioning mirror: version history = the Skill's AuditTrail
 * create/update entries (newest first, entry UUID = version id); diff limited to the
 * versioned content plane (`frontmatter`/`body`/`files` — a `state` change never
 * appears); rollback-as-a-new-version through the normal write path with
 * non-versioned fields (state, maturity, GitHub provenance) kept at their CURRENT
 * values and history never mutated; and the never-fatal version-pin lookups
 * (`currentVersionId()`/`pinsFor()`).
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SkillVersionService behaviour tests (skill-self-improvement).
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionServiceTest extends TestCase
{

    /**
     * The prepared AuditTrailMapper mock.
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * The prepared ObjectService mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Every saveObject() call captured as [schema, uuid, payload].
     *
     * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private array $savedObjects = [];

    /**
     * Build the service over fresh mocks.
     *
     * @return SkillVersionService
     */
    private function service(): SkillVersionService
    {
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->savedObjects     = [];

        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null): ObjectEntity {
                unset($extend, $register);
                $this->savedObjects[] = [
                    (string) $schema,
                    (string) $uuid,
                    $object,
                ];

                $entity = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        return new SkillVersionService(
            auditTrailMapper: $this->auditTrailMapper,
            objectService: $this->objectService
        );

    }//end service()

    /**
     * One AuditTrail version entry.
     *
     * @param string               $uuid    The entry UUID (version id).
     * @param string               $action  The audit action.
     * @param string               $created The creation timestamp.
     * @param array<string, mixed> $changed The recorded field changes.
     *
     * @return AuditTrail
     */
    private function entry(string $uuid, string $action, string $created, array $changed=[]): AuditTrail
    {
        $entry = new AuditTrail();
        $entry->setUuid($uuid);
        $entry->setObjectUuid('skill-1');
        $entry->setAction($action);
        $entry->setCreated(new DateTime($created));
        $entry->setChanged($changed);

        return $entry;

    }//end entry()

    /**
     * The live skill under test: three body versions plus lifecycle/provenance
     * churn that must never be rolled back.
     *
     * @return ObjectEntity
     */
    private function liveSkill(): ObjectEntity
    {
        $skill = new ObjectEntity();
        $skill->setUuid('skill-1');
        $skill->setObject(
            [
                'name'                  => 'tender-summary',
                'frontmatter'           => "name: tender-summary\n",
                'body'                  => 'BODY v3',
                'files'                 => [],
                'state'                 => 'stale',
                'maturityLevel'         => 4,
                'levelEvidence'         => ['l4' => ['attestedBy' => 'admin']],
                'githubOwner'           => 'YOUR_OWNER_HERE',
                'githubRepo'            => 'hermiq-skill-example',
                'publishedAt'           => '2026-07-01T00:00:00+00:00',
                'lastAcceptedVersionAt' => '2026-07-20T00:00:00+00:00',
            ]
        );

        return $skill;

    }//end liveSkill()

    /**
     * The three-version timeline: v1 (create), v2 (body v1→v2 + state change),
     * v3 (body v2→v3 + publishedAt change).
     *
     * @return array<int, AuditTrail>
     */
    private function timeline(): array
    {
        return [
            $this->entry(uuid: 'v1', action: 'create', created: '2026-07-01T10:00:00+00:00'),
            $this->entry(
                uuid: 'v2',
                action: 'update',
                created: '2026-07-10T10:00:00+00:00',
                changed: [
                    'body'  => [
                        'old' => 'BODY v1',
                        'new' => 'BODY v2',
                    ],
                    'state' => [
                        'old' => 'active',
                        'new' => 'stale',
                    ],
                ]
            ),
            $this->entry(
                uuid: 'v3',
                action: 'update',
                created: '2026-07-20T10:00:00+00:00',
                changed: [
                    'body'        => [
                        'old' => 'BODY v2',
                        'new' => 'BODY v3',
                    ],
                    'publishedAt' => [
                        'old' => null,
                        'new' => '2026-07-01T00:00:00+00:00',
                    ],
                ]
            ),
        ];

    }//end timeline()

    /**
     * History lists newest-first with the AuditTrail entry UUIDs as version ids.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testListVersionsNewestFirstWithEntryUuids(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willReturn($this->timeline());

        $versions = $service->listVersions(skillUuid: 'skill-1');

        $this->assertSame(['v3', 'v2', 'v1'], array_column($versions, 'id'));
        $this->assertSame(['body'], $versions[1]['changedFields'], 'Only versioned fields appear in changedFields — never state.');

    }//end testListVersionsNewestFirstWithEntryUuids()

    /**
     * The AuditTrail multi-action filter is passed as a comma-separated STRING —
     * OpenRegister's `AuditTrailMapper::findAll()` string-casts filter values, so an
     * ARRAY becomes the literal "Array" and matches zero rows (green-but-dead: the
     * live instance showed an empty version history while this suite stayed green
     * on a permissive mock).
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testVersionEntriesFilterUsesCommaSeparatedActionString(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(
                    function (array $filters): bool {
                        $this->assertSame('create,update', $filters['action'] ?? null);
                        $this->assertSame('skill-1', $filters['object_uuid'] ?? null);
                        return true;
                    }
                )
            )
            ->willReturn([]);

        $this->assertSame([], $service->listVersions(skillUuid: 'skill-1'));

    }//end testVersionEntriesFilterUsesCommaSeparatedActionString()

    /**
     * Diff covers ONLY the versioned field set: two versions differing in body AND
     * state yield a diff containing body — never state.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testDiffCoversOnlyVersionedFields(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willReturn($this->timeline());
        $this->objectService->method('find')->willReturn($this->liveSkill());

        $diff = $service->diff(skillUuid: 'skill-1', fromId: 'v2', toId: 'v3');

        $this->assertArrayHasKey('body', $diff);
        $this->assertSame('BODY v2', $diff['body']['old']);
        $this->assertSame('BODY v3', $diff['body']['new']);
        $this->assertArrayNotHasKey('state', $diff, 'state is not versioned-config and must never appear in a diff.');

    }//end testDiffCoversOnlyVersionedFields()

    /**
     * Rollback restores the versioned content as a brand-NEW version while every
     * non-versioned field (state, maturity, evidence, GitHub provenance) keeps its
     * CURRENT value — and existing history entries are only ever read.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testRollbackRestoresContentAsNewVersionKeepingNonVersionedFields(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willReturn($this->timeline());
        $this->objectService->method('find')->willReturn($this->liveSkill());

        $service->rollback(skillUuid: 'skill-1', versionId: 'v2');

        $this->assertCount(1, $this->savedObjects, 'Rollback is ONE new write through the normal path.');
        [$schema, $uuid, $payload] = $this->savedObjects[0];
        $this->assertSame('agentskill', $schema);
        $this->assertSame('skill-1', $uuid);
        // Versioned content restored to v2's value (v3's change undone).
        $this->assertSame('BODY v2', $payload['body']);
        // Non-versioned fields keep their CURRENT values.
        $this->assertSame('stale', $payload['state']);
        $this->assertSame(4, $payload['maturityLevel']);
        $this->assertSame(['l4' => ['attestedBy' => 'admin']], $payload['levelEvidence']);
        $this->assertSame('YOUR_OWNER_HERE', $payload['githubOwner']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $payload['publishedAt']);
        $this->assertSame('2026-07-20T00:00:00+00:00', $payload['lastAcceptedVersionAt']);

    }//end testRollbackRestoresContentAsNewVersionKeepingNonVersionedFields()

    /**
     * An unknown version id is a RuntimeException (the controller maps it to 404),
     * with nothing written.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testRollbackUnknownVersionThrowsWithoutWriting(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willReturn($this->timeline());
        $this->objectService->method('find')->willReturn($this->liveSkill());

        $this->expectException(RuntimeException::class);

        try {
            $service->rollback(skillUuid: 'skill-1', versionId: 'nope');
        } finally {
            $this->assertSame([], $this->savedObjects, 'An unknown version writes nothing.');
        }

    }//end testRollbackUnknownVersionThrowsWithoutWriting()

    /**
     * The version-pin lookups are NEVER fatal: a throwing mapper yields null /
     * an empty pin map, so a run's audit write can never fail on a pin.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-runs-pin-the-exact-skill-versions-that-executed
     */
    public function testVersionPinLookupsAreNeverFatal(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willThrowException(new RuntimeException('audit store down'));

        $this->assertNull($service->currentVersionId(skillUuid: 'skill-1'));
        $this->assertSame([], $service->pinsFor(skillUuids: ['skill-1', '']), 'Failed lookups are omitted, never thrown.');

    }//end testVersionPinLookupsAreNeverFatal()

    /**
     * pinsFor() maps each resolvable skill to its NEWEST version entry UUID.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-runs-pin-the-exact-skill-versions-that-executed
     */
    public function testPinsForResolvesNewestVersionPerSkill(): void
    {
        $service = $this->service();
        $this->auditTrailMapper->method('findAll')->willReturn($this->timeline());

        $this->assertSame(['skill-1' => 'v3'], $service->pinsFor(skillUuids: ['skill-1']));

    }//end testPinsForResolvesNewestVersionPerSkill()
}//end class
