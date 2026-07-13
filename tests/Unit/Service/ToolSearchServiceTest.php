<?php

/**
 * Unit tests for ToolSearchService (agent-tool-governance-and-disclosure).
 *
 * Covers the `hermiq.searchTools` meta-tool's backing logic: matches are drawn
 * ONLY from the registered resolved set (never widen), matching is
 * case-insensitive substring over id/name/description, an empty query returns
 * no matches, and `isGranted()`/`descriptor()` reflect the currently-registered
 * set (a later `registerResolved()` call replaces the previous one).
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ToolSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-run resolved-set registry and `searchTools` ranking.
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 */
class ToolSearchServiceTest extends TestCase
{
    /**
     * A representative resolved descriptor set.
     *
     * @return array<int, array<string,mixed>>
     */
    private function descriptors(): array
    {
        return [
            ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads by name or status'],
            ['name' => 'pipelinq_contactmoment_search', 'mcpId' => 'pipelinq.contactmoment.search', 'description' => 'Search contact moments'],
            ['name' => 'openregister_schemas_get', 'mcpId' => 'openregister.schemas.get', 'description' => 'Get a schema definition'],
        ];

    }//end descriptors()

    /**
     * search() returns only matching descriptors from the registered set, and
     * never a descriptor outside it.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
     */
    public function testSearchReturnsOnlyMatchingRegisteredDescriptors(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());

        $matches = $service->search(query: 'lead');

        $this->assertCount(1, $matches);
        $this->assertSame('pipelinq.lead.search', $matches[0]['mcpId']);

    }//end testSearchReturnsOnlyMatchingRegisteredDescriptors()

    /**
     * A query matching by description text (not just the id/name) also matches.
     *
     * @return void
     */
    public function testSearchMatchesByDescription(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());

        $matches = $service->search(query: 'schema definition');

        $this->assertCount(1, $matches);
        $this->assertSame('openregister.schemas.get', $matches[0]['mcpId']);

    }//end testSearchMatchesByDescription()

    /**
     * An empty query returns no matches (never "everything").
     *
     * @return void
     */
    public function testEmptyQueryReturnsNoMatches(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());

        $this->assertSame([], $service->search(query: ''));
        $this->assertSame([], $service->search(query: '   '));

    }//end testEmptyQueryReturnsNoMatches()

    /**
     * A query matching nothing in the registered set returns no matches —
     * NEVER a tool outside the resolved set, however broad the query.
     *
     * @return void
     */
    public function testUnmatchedQueryReturnsNoMatches(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());

        $this->assertSame([], $service->search(query: 'delete-everything-nuke'));

    }//end testUnmatchedQueryReturnsNoMatches()

    /**
     * isGranted()/descriptor() reflect exactly the registered set.
     *
     * @return void
     */
    public function testIsGrantedAndDescriptorReflectRegisteredSet(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());

        $this->assertTrue($service->isGranted(id: 'pipelinq.lead.search'));
        $this->assertFalse($service->isGranted(id: 'pipelinq.lead.delete'));
        $this->assertSame('pipelinq.lead.search', $service->descriptor(id: 'pipelinq.lead.search')['mcpId']);
        $this->assertNull($service->descriptor(id: 'pipelinq.lead.delete'));

    }//end testIsGrantedAndDescriptorReflectRegisteredSet()

    /**
     * A later registerResolved() call REPLACES the previous set, not merges it.
     *
     * @return void
     */
    public function testRegisterResolvedReplacesPreviousSet(): void
    {
        $service = new ToolSearchService();
        $service->registerResolved(descriptors: $this->descriptors());
        $this->assertTrue($service->isGranted(id: 'pipelinq.lead.search'));

        $service->registerResolved(descriptors: [['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail']]);

        $this->assertFalse($service->isGranted(id: 'pipelinq.lead.search'));
        $this->assertTrue($service->isGranted(id: 'hermiq.sendMail'));

    }//end testRegisterResolvedReplacesPreviousSet()
}//end class
