<?php

/**
 * Unit tests for SkillService (skills-catalog + agent-capability-profile).
 *
 * Covers `installOnAgent()`'s bidirectional join: the skill-side `installedOn` append
 * (existing behavior) AND the new agent-side `skillInstalls` sync — including idempotency
 * and the best-effort guarantee that a missing/unreadable agent never fails the
 * skill-side install.
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
 * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillSerializer;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SkillService.
 *
 * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
 */
class SkillServiceTest extends TestCase
{

    /**
     * A Skill ObjectEntity with the given payload.
     *
     * @param array<string, mixed> $payload The object data.
     * @param string                $uuid    The object uuid.
     *
     * @return ObjectEntity
     */
    private function skill(array $payload, string $uuid='skill-uuid'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end skill()

    /**
     * An Agent ObjectEntity with the given payload.
     *
     * @param array<string, mixed>|null $payload The object data (null = agent not found).
     * @param string                     $uuid    The object uuid.
     *
     * @return ObjectEntity|null
     */
    private function agent(?array $payload, string $uuid='agent-uuid'): ?ObjectEntity
    {
        if ($payload === null) {
            return null;
        }

        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end agent()

    /**
     * installOnAgent appends the agent uuid to Skill.installedOn AND the skill uuid to
     * Agent.skillInstalls — a genuine bidirectional join.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
     */
    public function testInstallOnAgentSyncsBothDirections(): void
    {
        $skill = $this->skill(['name' => 'Gap report', 'installedOn' => []]);
        $agent = $this->agent(['name' => 'Analyst', 'skillInstalls' => []]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id) use ($skill, $agent): ?ObjectEntity {
                if ($id === 'skill-uuid') {
                    return $skill;
                }

                if ($id === 'agent-uuid') {
                    return $agent;
                }

                return null;
            }
        );

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=null, mixed $register=null, mixed $schema=null, mixed $uuid=null) use (&$saved): ObjectEntity {
                $saved[] = ['schema' => $schema, 'uuid' => $uuid, 'data' => $object];
                $entity  = new ObjectEntity();
                $entity->setUuid((string) $uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $service->installOnAgent(skillId: 'skill-uuid', agentId: 'agent-uuid');

        $this->assertCount(2, $saved, 'Both the skill and the agent must be saved.');

        $skillSave = current(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'agentskill'));
        $this->assertSame(['agent-uuid'], $skillSave['data']['installedOn']);

        $agentSave = current(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'agent'));
        $this->assertSame(['skill-uuid'], $agentSave['data']['skillInstalls']);

    }//end testInstallOnAgentSyncsBothDirections()

    /**
     * Installing the same skill twice on the same agent is idempotent on BOTH sides —
     * no duplicate uuids, and the agent-side write is skipped entirely when already synced.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
     */
    public function testInstallOnAgentIsIdempotent(): void
    {
        $skill = $this->skill(['name' => 'Gap report', 'installedOn' => ['agent-uuid']]);
        $agent = $this->agent(['name' => 'Analyst', 'skillInstalls' => ['skill-uuid']]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            fn (string $id): ?ObjectEntity => ($id === 'skill-uuid') ? $skill : $agent
        );

        $agentSaveCount = 0;
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=null, mixed $register=null, mixed $schema=null, mixed $uuid=null) use (&$agentSaveCount): ObjectEntity {
                if ($schema === 'agent') {
                    $agentSaveCount++;
                }

                $entity = new ObjectEntity();
                $entity->setUuid((string) $uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $service->installOnAgent(skillId: 'skill-uuid', agentId: 'agent-uuid');

        $this->assertSame(0, $agentSaveCount, 'Already-synced skillInstalls must not trigger a redundant save.');

    }//end testInstallOnAgentIsIdempotent()

    /**
     * A missing/unreadable agent does NOT fail the skill-side install — Skill.installedOn
     * remains the authoritative "installed somewhere" record.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
     */
    public function testMissingAgentDoesNotFailSkillSideInstall(): void
    {
        $skill = $this->skill(['name' => 'Gap report', 'installedOn' => []]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            fn (string $id): ?ObjectEntity => ($id === 'skill-uuid') ? $skill : null
        );

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=null, mixed $register=null, mixed $schema=null, mixed $uuid=null) use (&$saved): ObjectEntity {
                $saved[] = ['schema' => $schema, 'data' => $object];
                $entity  = new ObjectEntity();
                $entity->setUuid((string) $uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $result  = $service->installOnAgent(skillId: 'skill-uuid', agentId: 'ghost-agent');

        $this->assertNotNull($result, 'The skill-side install must still succeed.');
        $this->assertCount(1, $saved, 'Only the skill is saved when the agent cannot be found.');
        $this->assertSame(['ghost-agent'], $saved[0]['data']['installedOn']);

    }//end testMissingAgentDoesNotFailSkillSideInstall()

    /**
     * A skill that does not exist returns null and never touches the agent.
     *
     * @return void
     */
    public function testInstallOnAgentReturnsNullForMissingSkill(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);
        $objectService->expects($this->never())->method('saveObject');

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $result  = $service->installOnAgent(skillId: 'ghost-skill', agentId: 'agent-uuid');

        $this->assertNull($result);

    }//end testInstallOnAgentReturnsNullForMissingSkill()

    /**
     * uninstallFromAgent removes the agent uuid from Skill.installedOn AND the skill uuid
     * from Agent.skillInstalls — the bidirectional mirror of installOnAgent.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
     */
    public function testUninstallFromAgentDesyncsBothDirections(): void
    {
        $skill = $this->skill(['name' => 'Gap report', 'installedOn' => ['agent-uuid', 'other-agent']]);
        $agent = $this->agent(['name' => 'Analyst', 'skillInstalls' => ['skill-uuid', 'other-skill']]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function (string $id) use ($skill, $agent): ?ObjectEntity {
                if ($id === 'skill-uuid') {
                    return $skill;
                }

                if ($id === 'agent-uuid') {
                    return $agent;
                }

                return null;
            }
        );

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=null, mixed $register=null, mixed $schema=null, mixed $uuid=null) use (&$saved): ObjectEntity {
                $saved[] = ['schema' => $schema, 'uuid' => $uuid, 'data' => $object];
                $entity  = new ObjectEntity();
                $entity->setUuid((string) $uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $service->uninstallFromAgent(skillId: 'skill-uuid', agentId: 'agent-uuid');

        $this->assertCount(2, $saved, 'Both the skill and the agent must be saved.');

        $skillSave = current(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'agentskill'));
        $this->assertSame(['other-agent'], $skillSave['data']['installedOn'], 'Only the target agent is removed from installedOn.');

        $agentSave = current(array_filter($saved, static fn (array $s): bool => $s['schema'] === 'agent'));
        $this->assertSame(['other-skill'], $agentSave['data']['skillInstalls'], 'Only the target skill is removed from skillInstalls.');

    }//end testUninstallFromAgentDesyncsBothDirections()

    /**
     * Detaching a skill that is not associated with the agent is idempotent — the skill-side
     * save is a no-op filter and the agent-side write is skipped entirely when already absent.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
     */
    public function testUninstallFromAgentIsIdempotent(): void
    {
        $skill = $this->skill(['name' => 'Gap report', 'installedOn' => []]);
        $agent = $this->agent(['name' => 'Analyst', 'skillInstalls' => []]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            fn (string $id): ?ObjectEntity => ($id === 'skill-uuid') ? $skill : $agent
        );

        $agentSaveCount = 0;
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=null, mixed $register=null, mixed $schema=null, mixed $uuid=null) use (&$agentSaveCount): ObjectEntity {
                if ($schema === 'agent') {
                    $agentSaveCount++;
                }

                $entity = new ObjectEntity();
                $entity->setUuid((string) $uuid);
                $entity->setObject($object);
                return $entity;
            }
        );

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $result  = $service->uninstallFromAgent(skillId: 'skill-uuid', agentId: 'agent-uuid');

        $this->assertNotNull($result, 'The skill-side detach still succeeds.');
        $this->assertSame(0, $agentSaveCount, 'An already-absent skillInstalls entry must not trigger a redundant save.');

    }//end testUninstallFromAgentIsIdempotent()

    /**
     * A skill that does not exist returns null and never touches the agent.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
     */
    public function testUninstallFromAgentReturnsNullForMissingSkill(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);
        $objectService->expects($this->never())->method('saveObject');

        $service = new SkillService($objectService, $this->createMock(SkillSerializer::class), new NullLogger());
        $result  = $service->uninstallFromAgent(skillId: 'ghost-skill', agentId: 'agent-uuid');

        $this->assertNull($result);

    }//end testUninstallFromAgentReturnsNullForMissingSkill()
}//end class
