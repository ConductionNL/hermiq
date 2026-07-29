<?php

/**
 * Unit tests for ContextRetrievalHandler (agent-engine-port).
 *
 * Covers the keyword retrieval path against ObjectService::searchObjectsPaginated
 * (`_search` term, ambient register/schema explicitly nulled), the semantic/hybrid
 * degrade-gracefully adaptation (logged, keyword path used, no crash, no OR-internal
 * VectorEmbeddings), agent-driven source limits, and the never-throws error contract.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Exception;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the RAG context retrieval handler.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */
class ContextRetrievalHandlerTest extends TestCase
{

    /**
     * An Agent ObjectEntity with the given RAG settings.
     *
     * @param array<string, mixed> $ragFields The RAG-related agent fields.
     *
     * @return ObjectEntity
     */
    private function agent(array $ragFields): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-uuid');
        // A default view scope, because retrieval is GATED on one: an agent that
        // resolves to no views declares no data scope, and searching every
        // register on the instance is the bug that gate exists to prevent (see
        // ContextRetrievalHandler::searchScoped). Tests that want the gate
        // itself pass `views => []` explicitly.
        $entity->setObject(array_merge(['name' => 'RAG agent', 'views' => ['view-uuid-1']], $ragFields));
        return $entity;

    }//end agent()

    /**
     * An agent with no resolved views retrieves nothing — the search is never
     * issued, rather than fanning out across every register on the instance.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testNoViewsSkipsRetrievalEntirely(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(['ragSearchMode' => 'keyword', 'views' => []])
        );

        $this->assertSame([], $context['sources']);
        $this->assertSame('', $context['text']);

    }//end testNoViewsSkipsRetrievalEntirely()

    /**
     * Keyword mode issues a `_search` query with explicit `_register`/`_schema`
     * nulls and formats results into the {text, sources} context shape.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testKeywordModeSearchesAndFormatsSources(): void
    {
        $capturedQuery = null;
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query) use (&$capturedQuery): array {
                $capturedQuery = $query;
                return [
                    'results' => [
                        [
                            'id'     => 'obj-1',
                            'name'   => 'Leave policy',
                            '_score' => 0.9,
                        ],
                    ],
                    'total'   => 1,
                ];
            }
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(['ragSearchMode' => 'keyword', 'ragNumSources' => 5])
        );

        $this->assertSame('leave policy', $capturedQuery['_search']);
        // 5 sources * 2 fetch factor.
        $this->assertSame(10, $capturedQuery['_limit']);
        // Ambient register/schema context is explicitly disabled for RAG.
        $this->assertArrayHasKey('_register', $capturedQuery);
        $this->assertNull($capturedQuery['_register']);
        $this->assertNull($capturedQuery['_schema']);

        $this->assertCount(1, $context['sources']);
        $this->assertSame('obj-1', $context['sources'][0]['id']);
        $this->assertSame('object', $context['sources'][0]['type']);
        $this->assertSame('Leave policy', $context['sources'][0]['name']);
        $this->assertStringContainsString('Source: Leave policy', $context['text']);

    }//end testKeywordModeSearchesAndFormatsSources()

    /**
     * semantic/hybrid modes DEGRADE to the keyword path with an info log — no
     * crash, no OR-internal vector construction (the ground-truth adaptation).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testSemanticModeDegradesToKeywordWithLogNote(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())
            ->method('searchObjectsPaginated')
            ->willReturn(['results' => [], 'total' => 0]);

        $degradeLogged = false;
        $logger        = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(
            function (string|\Stringable $message) use (&$degradeLogged): void {
                if (str_contains((string) $message, 'degrading to keyword search') === true) {
                    $degradeLogged = true;
                }
            }
        );

        $handler = new ContextRetrievalHandler($objectService, $logger);
        $context = $handler->retrieveContext(
            query: 'anything',
            agent: $this->agent(['ragSearchMode' => 'semantic'])
        );

        $this->assertTrue($degradeLogged, 'The semantic→keyword degrade must be logged');
        $this->assertSame(['text' => '', 'sources' => []], $context);

    }//end testSemanticModeDegradesToKeywordWithLogNote()

    /**
     * The agent's ragNumSources caps how many object sources are returned.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testRagNumSourcesCapsResults(): void
    {
        $results = [];
        for ($i = 1; $i <= 6; $i++) {
            $results[] = [
                'id'   => 'obj-'.$i,
                'name' => 'Doc '.$i,
            ];
        }

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturn(
            [
                'results' => $results,
                'total'   => 6,
            ]
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'docs',
            agent: $this->agent(['ragNumSources' => 2])
        );

        $this->assertCount(2, $context['sources']);

    }//end testRagNumSourcesCapsResults()

    /**
     * includeObjects=false (ragSettings override) filters object sources out.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testIncludeObjectsOverrideFiltersObjectSources(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturn(
            [
                'results' => [
                    ['id' => 'obj-1', 'name' => 'Doc'],
                ],
                'total'   => 1,
            ]
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'docs',
            agent: null,
            ragSettings: ['includeObjects' => false]
        );

        $this->assertSame([], $context['sources']);
        $this->assertSame('', $context['text']);

    }//end testIncludeObjectsOverrideFiltersObjectSources()

    /**
     * Retrieval failure returns an empty context instead of throwing (the chat
     * turn must survive a broken search backend).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testSearchFailureYieldsEmptyContext(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willThrowException(new Exception('index down'));

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(query: 'anything', agent: null);

        $this->assertSame(['text' => '', 'sources' => []], $context);

    }//end testSearchFailureYieldsEmptyContext()

    /**
     * ObjectEntity results (the shape the real paginated search returns after
     * rendering) are unwrapped via uuid + payload.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */
    public function testObjectEntityResultsAreUnwrapped(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('uuid-9');
        $entity->setObject(['title' => 'Rendered doc', 'body' => 'text']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturn(
            [
                'results' => [$entity],
                'total'   => 1,
            ]
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(query: 'rendered', agent: null);

        $this->assertCount(1, $context['sources']);
        $this->assertSame('uuid-9', $context['sources'][0]['id']);

    }//end testObjectEntityResultsAreUnwrapped()
}//end class
