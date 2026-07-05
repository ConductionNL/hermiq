<?php

/**
 * Unit tests for MemoryService (agent-memory).
 *
 * Covers the char-budget write path: appending under budget does not flag; appending
 * over budget flags `needsConsolidation` and NEVER drops older entries; an explicit
 * consolidation clears the flag; and recall passes the agent filter + search term to
 * OpenRegister's own search (tenant scoping is inherited from ObjectService).
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
 * @spec openspec/changes/agent-memory/tasks.md#task-5-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the agent-memory MemoryService.
 *
 * @spec openspec/changes/agent-memory/tasks.md#task-5-1
 */
class MemoryServiceTest extends TestCase
{

    /**
     * A Memory ObjectEntity with the given payload.
     *
     * @param array<string, mixed> $payload The object data.
     *
     * @return ObjectEntity
     */
    private function memory(array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('mem-uuid');
        $entity->setObject($payload);
        return $entity;

    }//end memory()

    /**
     * An ObjectService whose findAll returns the given list and whose saveObject records
     * the last saved payload into $captured and echoes it back as an entity.
     *
     * @param array<int, ObjectEntity> $findResult The findAll result.
     * @param array<string, mixed>|null $captured   Out-param: the last saved object payload.
     *
     * @return ObjectService
     */
    private function objectService(array $findResult, ?array &$captured): ObjectService
    {
        $service = $this->createMock(ObjectService::class);
        $service->method('setRegister')->willReturnSelf();
        $service->method('setSchema')->willReturnSelf();
        $service->method('findAll')->willReturn($findResult);
        $service->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured): ObjectEntity {
                $captured = $object;
                $entity   = new ObjectEntity();
                $entity->setUuid('mem-uuid');
                $entity->setObject($object);
                return $entity;
            }
        );
        return $service;

    }//end objectService()

    /**
     * Appending under budget persists the entry and does NOT flag consolidation.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-2
     */
    public function testAppendUnderBudgetDoesNotFlag(): void
    {
        $existing = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['text' => 'short', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 8000,
                'needsConsolidation' => false,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured));
        $service->appendMemoryEntry(agentId: 'agent-1', text: 'another fact');

        $this->assertNotNull($captured);
        $this->assertCount(2, $captured['entries']);
        $this->assertFalse($captured['needsConsolidation']);

    }//end testAppendUnderBudgetDoesNotFlag()

    /**
     * Appending over budget flags consolidation and keeps EVERY entry (no truncation).
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-2
     */
    public function testAppendOverBudgetFlagsAndKeepsEntries(): void
    {
        $existing = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['text' => '12345678', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 10,
                'needsConsolidation' => false,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured));
        $service->appendMemoryEntry(agentId: 'agent-1', text: 'over the limit now');

        $this->assertNotNull($captured);
        // Nothing dropped — the old entry AND the new one are present.
        $this->assertCount(2, $captured['entries']);
        $this->assertSame('12345678', $captured['entries'][0]['text']);
        // Total characters (8 + 18) exceed the budget of 10 → flagged.
        $this->assertTrue($captured['needsConsolidation']);

    }//end testAppendOverBudgetFlagsAndKeepsEntries()

    /**
     * An explicit consolidation with a reduced entry set clears the flag.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-3
     */
    public function testConsolidateClearsFlagWhenUnderBudget(): void
    {
        $existing = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [
                    ['text' => str_repeat('a', 60), 'createdAt' => '2026-01-01T00:00:00+00:00'],
                    ['text' => str_repeat('b', 60), 'createdAt' => '2026-01-01T00:00:00+00:00'],
                ],
                'charBudget'         => 100,
                'needsConsolidation' => true,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured));
        $service->consolidateMemory(
            agentId: 'agent-1',
            entries: [['text' => 'consolidated summary', 'createdAt' => '2026-01-01T00:00:00+00:00']]
        );

        $this->assertNotNull($captured);
        $this->assertCount(1, $captured['entries']);
        $this->assertFalse($captured['needsConsolidation']);

    }//end testConsolidateClearsFlagWhenUnderBudget()

    /**
     * Recall passes the agent filter + search term through to ObjectService search.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-5
     */
    public function testRecallPassesAgentFilterAndSearch(): void
    {
        $capturedConfig = null;
        $service        = $this->createMock(ObjectService::class);
        $service->method('setRegister')->willReturnSelf();
        $service->method('setSchema')->willReturnSelf();
        $service->method('findAll')->willReturnCallback(
            function (array $config) use (&$capturedConfig): array {
                $capturedConfig = $config;
                return [];
            }
        );

        $memory = new MemoryService($service);
        $memory->recallSessions(agentId: 'agent-9', query: 'budget report');

        $this->assertNotNull($capturedConfig);
        $this->assertSame('agent-9', $capturedConfig['filters']['agentId']);
        $this->assertSame('budget report', $capturedConfig['search']);

    }//end testRecallPassesAgentFilterAndSearch()
}//end class
