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
     * Retrieval is opt-in (`Agent.enableRag`, schema default false), so this defaults
     * to an agent that has opted IN — otherwise every retrieval test would be asserting
     * against the disabled path by accident. Pass `enableRag` explicitly to override.
     *
     * @param array<string, mixed> $ragFields The RAG-related agent fields.
     *
     * @return ObjectEntity
     */
    private function agent(array $ragFields): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-uuid');
        $entity->setObject(array_merge(['name' => 'RAG agent', 'enableRag' => true], $ragFields));
        return $entity;

    }//end agent()

    /**
     * Keyword mode issues a `_search` query with explicit `_register`/`_schema`
     * nulls and formats results into the {text, sources} context shape.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
     */

    /**
     * Excluding BOTH source types skips the query entirely.
     *
     * The flags read like search inputs but are applied as a post-filter, so an
     * agent wanting neither files nor objects still paid for a full unscoped scan
     * (26–62s measured on an instance with ~2k magic tables) only to discard every
     * row. Asserts the OUTPUT as well as the absence of the query: the skip has to
     * be behaviour-preserving, not merely faster.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-skipped-when-its-results-would-all-be-discarded
     */
    public function testRetrievalIsSkippedWhenNeitherFilesNorObjectsAreIncluded(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        // The assertion that matters: the expensive call is never made.
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(
                [
                    'ragSearchMode' => 'keyword',
                    'ragNumSources' => 5,
                    'searchFiles'   => false,
                    'searchObjects' => false,
                ]
            )
        );

        // Byte-identical to what the pre-change code produced: it searched, then
        // `continue`d on every row, arriving at exactly this empty context.
        $this->assertSame([], $context['sources']);
        $this->assertSame('', $context['text']);

    }//end testRetrievalIsSkippedWhenNeitherFilesNorObjectsAreIncluded()

    /**
     * One included type is enough to keep the search — the skip must not
     * over-trigger and silently starve an agent of context.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-skipped-when-its-results-would-all-be-discarded
     */
    public function testRetrievalStillRunsWhenOnlyOneSourceTypeIsExcluded(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->once())->method('searchObjectsPaginated')->willReturn(
            [
                'results' => [
                    [
                        'id'   => 'obj-1',
                        'name' => 'Leave policy',
                    ],
                ],
                'total'   => 1,
            ]
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(
                [
                    'ragSearchMode' => 'keyword',
                    'ragNumSources' => 5,
                    'searchFiles'   => false,
                    'searchObjects' => true,
                ]
            )
        );

        $this->assertCount(1, $context['sources']);
        $this->assertSame('obj-1', $context['sources'][0]['id']);

    }//end testRetrievalStillRunsWhenOnlyOneSourceTypeIsExcluded()

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
     * An agent with RAG disabled does not search at all.
     *
     * `Agent.enableRag` (schema default FALSE, surfaced in AgentFormModal) is the
     * documented switch for "ground responses in context", and no engine code read it.
     * All 16 agents on the reference instance have it false, and every message still
     * paid a full unscoped keyword scan across ~2k magic tables — 26-62s of a ~65s
     * reply — for retrieval the agent was configured not to want.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-scoped-to-what-the-agent-may-actually-read
     */
    public function testRetrievalIsSkippedWhenTheAgentHasRagDisabled(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(['enableRag' => false, 'ragSearchMode' => 'keyword'])
        );

        // Behaviour-preserving, not merely faster: the disabled path still returns the
        // documented empty-context shape.
        $this->assertSame([], $context['sources']);
        $this->assertSame('', $context['text']);

    }//end testRetrievalIsSkippedWhenTheAgentHasRagDisabled()

    /**
     * An agent object with no `enableRag` field does not search either.
     *
     * Absent means false, matching the schema default — an object predating the field
     * never opted in.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-scoped-to-what-the-agent-may-actually-read
     */
    public function testRetrievalIsSkippedWhenEnableRagIsAbsent(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $agent = new ObjectEntity();
        $agent->setUuid('agent-uuid');
        $agent->setObject(['name' => 'Legacy agent', 'ragSearchMode' => 'keyword']);

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(query: 'leave policy', agent: $agent);

        $this->assertSame([], $context['sources']);

    }//end testRetrievalIsSkippedWhenEnableRagIsAbsent()

    /**
     * A conversation with no agent does not search.
     *
     * Engine passes a null agent when the conversation carries no agentId. That is the
     * worst case for an unscoped scan — no enableRag, no views, so ~2k magic tables get
     * searched on behalf of no configuration at all. Retrieval is opt-in per agent, so
     * no agent means nothing opted in.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-scoped-to-what-the-agent-may-actually-read
     */
    public function testAConversationWithoutAnAgentDoesNotSearch(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects($this->never())->method('searchObjectsPaginated');

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(query: 'leave policy', agent: null);

        $this->assertSame([], $context['sources']);
        $this->assertSame('', $context['text']);

    }//end testAConversationWithoutAnAgentDoesNotSearch()

    /**
     * The agent's views are passed to the search as its retrieval scope.
     *
     * `Agent.views` is "UUIDs of views that filter which data the agent can access" —
     * an access boundary. retrieveContext() resolved it and threw it away ("TODO: Apply
     * view filters here"), so an agent restricted to a view could retrieve from
     * everything. Asserts RECALL as well as the scope: a scoping change that silently
     * returned zero rows would read as a large speed-up while quietly breaking RAG,
     * which is exactly how the wrong identifier type fails against OpenRegister.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-scoped-to-what-the-agent-may-actually-read
     */
    public function testTheAgentsViewsAreAppliedAsTheRetrievalScope(): void
    {
        $capturedViews = 'not-called';
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query, bool $rbac=true, bool $mt=true, bool $deleted=false, ?array $ids=null, ?string $uses=null, ?array $views=null) use (&$capturedViews): array {
                $capturedViews = $views;
                return [
                    'results' => [['id' => 'obj-1', 'name' => 'Leave policy', '_score' => 0.9]],
                    'total'   => 1,
                ];
            }
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(
                [
                    'ragSearchMode' => 'keyword',
                    'views'         => ['view-uuid-1', 'view-uuid-2'],
                ]
            )
        );

        $this->assertSame(['view-uuid-1', 'view-uuid-2'], $capturedViews);
        // Recall: scoping must still return the rows inside the scope.
        $this->assertCount(1, $context['sources']);
        $this->assertSame('obj-1', $context['sources'][0]['id']);

    }//end testTheAgentsViewsAreAppliedAsTheRetrievalScope()

    /**
     * An agent with no views searches unscoped rather than searching nothing.
     *
     * The failure mode this guards against: passing an empty array as a scope reads as
     * "restricted to no views" and would return zero rows for every agent on the
     * instance — RAG silently dead, and fast enough to look like a win.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-scoped-to-what-the-agent-may-actually-read
     */
    public function testAnAgentWithoutViewsSearchesUnscoped(): void
    {
        $capturedViews = 'not-called';
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query, bool $rbac=true, bool $mt=true, bool $deleted=false, ?array $ids=null, ?string $uses=null, ?array $views=null) use (&$capturedViews): array {
                $capturedViews = $views;
                return [
                    'results' => [['id' => 'obj-1', 'name' => 'Leave policy', '_score' => 0.9]],
                    'total'   => 1,
                ];
            }
        );

        $handler = new ContextRetrievalHandler($objectService, new NullLogger());
        $context = $handler->retrieveContext(
            query: 'leave policy',
            agent: $this->agent(['ragSearchMode' => 'keyword', 'views' => []])
        );

        $this->assertNull($capturedViews, 'No views must mean unscoped, never "scoped to nothing".');
        $this->assertCount(1, $context['sources']);

    }//end testAnAgentWithoutViewsSearchesUnscoped()

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
        $context = $handler->retrieveContext(
            query: 'rendered',
            agent: $this->agent(['ragSearchMode' => 'keyword'])
        );

        $this->assertCount(1, $context['sources']);
        $this->assertSame('uuid-9', $context['sources'][0]['id']);

    }//end testObjectEntityResultsAreUnwrapped()
}//end class
