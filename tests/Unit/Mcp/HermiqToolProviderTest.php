<?php

/**
 * Unit tests for HermiqToolProvider (nc-native-tools, ai-course-recommendations,
 * hermiq-prefer-tool-hints).
 *
 * Covers the tool catalogue (six pre-existing + `recommendCourses`, namespaced
 * hermiq.* descriptors) and the never-throws contract: invokeTool returns a
 * structured error for an unauthenticated caller and for an unknown tool id, and
 * `recommendCourses` delegates to the shared `CourseRecommendationEngine` with the
 * acting user's own uid (no separate authorization path).
 *
 * Also covers the hermiq-prefer-tool-hints regression fix: every descriptor now
 * carries honest `readOnlyHint`/`destructiveHint`/`idempotentHint`/`scope` keys
 * so `ToolGrantResolver::isWriteOrDestructive()` classifies these hand-written,
 * 2-segment ids from their OWN declared hints instead of failing closed on their
 * (unclassifiable-by-shape) id text — see ToolGrantResolverTest for the
 * end-to-end grant-resolution proof.
 *
 * Also covers the three agent-memory-tools (`rememberMemory`/`recallMemory`/
 * `forgetMemory`): IDOR scoping to the acting user (never a caller-supplied
 * `subjectUid`), the `no_agent_context` error when `FacadeToolInvoker` has not
 * injected an `agentId` (agent-less chat), the not-found path, and the
 * never-throws contract.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-4-1
 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCA\Hermiq\Service\DelegationService;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the nc-native-tools HermiqToolProvider.
 *
 * @spec openspec/changes/nc-native-tools/tasks.md#task-4-1
 */
class HermiqToolProviderTest extends TestCase
{

    /**
     * Build the provider with a session that resolves to $uid (or null for anonymous).
     *
     * @param string|null                      $uid           The acting user id, or null for unauthenticated.
     * @param CourseRecommendationEngine|null $engine        A specific engine double, or a plain mock.
     * @param MemoryService|null              $memoryService A specific MemoryService double, or a plain mock.
     * @param WebSearchClient|null            $webSearchClient A specific WebSearchClient double, or a plain mock.
     * @param WebFetchService|null            $webFetchService A specific WebFetchService double, or a plain mock.
     * @param DelegationService|null          $delegationService A specific DelegationService double, or a plain mock.
     *
     * @return HermiqToolProvider
     */
    private function provider(
        ?string $uid,
        ?CourseRecommendationEngine $engine=null,
        ?MemoryService $memoryService=null,
        ?WebSearchClient $webSearchClient=null,
        ?WebFetchService $webFetchService=null,
        ?DelegationService $delegationService=null
    ): HermiqToolProvider {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $session->method('getUser')->willReturn($user);
        }

        return new HermiqToolProvider(
            $session,
            $this->createMock(IGroupManager::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IContactsManager::class),
            $this->createMock(ICalendarManager::class),
            $this->createMock(IMailer::class),
            $this->createMock(IAppManager::class),
            $this->createMock(ContainerInterface::class),
            $engine ?? $this->createMock(CourseRecommendationEngine::class),
            $memoryService ?? $this->createMock(MemoryService::class),
            $webSearchClient ?? $this->createMock(WebSearchClient::class),
            $webFetchService ?? $this->createMock(WebFetchService::class),
            $delegationService ?? $this->createMock(DelegationService::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end provider()

    /**
     * getAppId is the hermiq app slug and every tool id is namespaced by it.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-1
     */
    public function testToolCatalogueIsNamespaced(): void
    {
        $provider = $this->provider('alice');

        $this->assertSame('hermiq', $provider->getAppId());

        $tools = $provider->getTools();
        // 6 nc-native-tools + hermiq.searchTools (agent-tool-governance-and-disclosure's
        // progressive-disclosure meta-tool) + hermiq.recommendCourses (ai-course-recommendations)
        // + hermiq.rememberMemory/recallMemory/forgetMemory (agent-memory-tools)
        // + hermiq.webSearch/webFetch (web-research-tool)
        // + hermiq.delegateAgent (sub-agent-delegation),
        // all registered through this same provider.
        $this->assertCount(14, $tools);

        $ids = array_column($tools, 'id');
        $this->assertContains('hermiq.listFiles', $ids);
        $this->assertContains('hermiq.readFile', $ids);
        $this->assertContains('hermiq.searchContacts', $ids);
        $this->assertContains('hermiq.listCalendarEvents', $ids);
        $this->assertContains('hermiq.sendMail', $ids);
        $this->assertContains('hermiq.listDeckBoards', $ids);
        $this->assertContains('hermiq.searchTools', $ids);
        $this->assertContains('hermiq.recommendCourses', $ids);
        $this->assertContains('hermiq.rememberMemory', $ids);
        $this->assertContains('hermiq.recallMemory', $ids);
        $this->assertContains('hermiq.forgetMemory', $ids);
        $this->assertContains('hermiq.webSearch', $ids);
        $this->assertContains('hermiq.webFetch', $ids);
        $this->assertContains('hermiq.delegateAgent', $ids);

        foreach ($ids as $id) {
            $this->assertStringStartsWith('hermiq.', $id);
        }

    }//end testToolCatalogueIsNamespaced()

    /**
     * Every descriptor carries the honest `readOnlyHint`/`destructiveHint`/
     * `idempotentHint`/`scope` hint keys (hermiq-prefer-tool-hints) — before this
     * fix these 2-segment ids carried NO hints at all and were fail-closed
     * classified write/destructive by `ToolGrantResolver::isWriteOrDestructive()`,
     * stripping all seven read-shaped tools from any default/wildcard grant.
     *
     * `recommendCourses` really persists a cached recommendation on staleness
     * (`CourseRecommendationEngine::getOrRegenerate()` → `saveObject()`), so it is
     * annotated as a genuine, non-idempotent `update`, not read-only. `sendMail`
     * sends externally-visible, irreversible email, so it is annotated
     * destructive + `create`, not read-only.
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-declared-hint-overrides-a-conflicting-verb-suffix
     */
    public function testDescriptorsCarryHonestHintsAndScope(): void
    {
        $expected = [
            'hermiq.listFiles'          => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.readFile'           => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.searchContacts'     => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.listCalendarEvents' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.sendMail'           => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'create'],
            'hermiq.listDeckBoards'     => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.searchTools'        => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.recommendCourses'   => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'update'],
            'hermiq.rememberMemory'     => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'scope' => 'create'],
            'hermiq.recallMemory'       => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.forgetMemory'       => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'scope' => 'delete'],
            'hermiq.webSearch'          => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.webFetch'           => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
            'hermiq.delegateAgent'      => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'create'],
        ];

        $tools = $this->provider('alice')->getTools();
        $this->assertCount(14, $tools, 'This test must be updated if a tool is added or removed.');

        $seen = [];
        foreach ($tools as $tool) {
            $id        = $tool['id'];
            $seen[$id] = true;

            $this->assertArrayHasKey($id, $expected, "Unexpected tool id '{$id}' has no hint expectation.");
            $this->assertSame($expected[$id]['readOnlyHint'], $tool['readOnlyHint'] ?? null, "{$id}: readOnlyHint mismatch.");
            $this->assertSame($expected[$id]['destructiveHint'], $tool['destructiveHint'] ?? null, "{$id}: destructiveHint mismatch.");
            $this->assertSame($expected[$id]['idempotentHint'], $tool['idempotentHint'] ?? null, "{$id}: idempotentHint mismatch.");
            $this->assertSame($expected[$id]['scope'], $tool['scope'] ?? null, "{$id}: scope mismatch.");
        }

        $this->assertSame(array_keys($expected), array_keys($seen), 'Every expected tool id must be present exactly once.');

    }//end testDescriptorsCarryHonestHintsAndScope()

    /**
     * An unauthenticated caller gets a structured error, never an exception.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
     */
    public function testUnauthenticatedReturnsError(): void
    {
        $result = $this->provider(null)->invokeTool('hermiq.listFiles', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unauthenticated', $result['error']['code']);

    }//end testUnauthenticatedReturnsError()

    /**
     * An unknown tool id returns a structured error, never an exception.
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-7
     */
    public function testUnknownToolReturnsError(): void
    {
        $result = $this->provider('alice')->invokeTool('hermiq.doesNotExist', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unknown_tool', $result['error']['code']);

    }//end testUnknownToolReturnsError()

    /**
     * sendMail with missing arguments returns an invalid_argument error (no throw).
     *
     * @return void
     *
     * @spec openspec/changes/nc-native-tools/tasks.md#task-1-5
     */
    public function testSendMailWithoutArgumentsReturnsError(): void
    {
        $result = $this->provider('alice')->invokeTool('hermiq.sendMail', ['to' => '']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testSendMailWithoutArgumentsReturnsError()

    /**
     * recommendCourses delegates to the shared CourseRecommendationEngine with the
     * ACTING user's own uid — no separate authorization path, no request-supplied
     * learnerId (spec.md "Recommendation access is self-scoped").
     *
     * @return void
     *
     * @spec openspec/changes/ai-course-recommendations/tasks.md#task-4-1
     * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranked-recommendations-are-chat-companion-reachable-via-a-domain-mcp-tool
     */
    public function testRecommendCoursesDelegatesToEngineWithActingUid(): void
    {
        $engine = $this->createMock(CourseRecommendationEngine::class);
        $engine->expects($this->once())
            ->method('getOrRegenerate')
            ->with($this->equalTo('alice'))
            ->willReturn(['learnerId' => 'alice', 'status' => 'fresh', 'recommendations' => []]);

        $result = $this->provider('alice', $engine)->invokeTool('hermiq.recommendCourses', []);

        $this->assertSame('fresh', $result['status']);
        $this->assertArrayNotHasKey('error', $result);

    }//end testRecommendCoursesDelegatesToEngineWithActingUid()

    /**
     * A failure inside the engine (e.g. Scholiq absent, or any other Throwable) never
     * crosses the MCP boundary as an exception — invokeTool()'s own outer catch turns
     * it into the same structured `{error: {code, message}}` envelope every other
     * tool failure uses (spec.md "A tool failure never crosses the MCP boundary as
     * an exception").
     *
     * @return void
     *
     * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-ranked-recommendations-are-chat-companion-reachable-via-a-domain-mcp-tool
     */
    public function testRecommendCoursesNeverThrowsAcrossTheMcpBoundary(): void
    {
        $engine = $this->createMock(CourseRecommendationEngine::class);
        $engine->method('getOrRegenerate')->willThrowException(new RuntimeException('scholiq unreachable'));

        $result = $this->provider('alice', $engine)->invokeTool('hermiq.recommendCourses', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('tool_failed', $result['error']['code']);

    }//end testRecommendCoursesNeverThrowsAcrossTheMcpBoundary()

    /**
     * A Memory/UserProfile ObjectEntity with the given payload (mirrors
     * MemoryServiceTest's helper).
     *
     * @param array<string, mixed> $payload The object data.
     *
     * @return ObjectEntity
     */
    private function memoryObject(array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('mem-uuid');
        $entity->setObject($payload);
        return $entity;

    }//end memoryObject()

    /**
     * Without an `agentId` in arguments (agent-less chat — `FacadeToolInvoker`
     * never injects one when the run has none), every memory tool returns the
     * structured `no_agent_context` error rather than guessing which agent's
     * Memory to touch.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testMemoryToolsWithoutAgentContextReturnError(): void
    {
        $provider = $this->provider('alice');

        foreach (['hermiq.rememberMemory', 'hermiq.recallMemory', 'hermiq.forgetMemory'] as $toolId) {
            $result = $provider->invokeTool($toolId, []);
            $this->assertArrayHasKey('error', $result, "{$toolId} must error without an agentId.");
            $this->assertSame('no_agent_context', $result['error']['code'], "{$toolId} must report no_agent_context.");
        }

    }//end testMemoryToolsWithoutAgentContextReturnError()

    /**
     * rememberMemory(scope: agent) delegates to appendMemoryEntry() with the
     * run-injected agentId, and surfaces the newly-appended entry's id.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
     */
    public function testRememberMemoryAgentScopeDelegatesToAppendMemoryEntry(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->once())
            ->method('appendMemoryEntry')
            ->with($this->equalTo('agent-1'), $this->equalTo('the sky is blue'))
            ->willReturn(
                $this->memoryObject(
                    [
                        'entries'            => [['id' => 'entry-1', 'text' => 'the sky is blue', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                        'needsConsolidation' => false,
                    ]
                )
            );

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.rememberMemory',
            ['agentId' => 'agent-1', 'content' => 'the sky is blue', 'scope' => 'agent']
        );

        $this->assertTrue($result['remembered']);
        $this->assertSame('agent', $result['scope']);
        $this->assertSame('entry-1', $result['entryId']);
        $this->assertFalse($result['needsConsolidation']);

    }//end testRememberMemoryAgentScopeDelegatesToAppendMemoryEntry()

    /**
     * rememberMemory(scope: user) delegates to appendUserProfileEntry() with the
     * ACTING user's own uid — a caller-supplied `subjectUid` argument (should the
     * LLM ever pass one, though the declared inputSchema has no such property) is
     * never consulted, matching every other `HermiqToolProvider` tool's IDOR
     * posture.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testRememberMemoryUserScopeUsesActingUidNeverCallerSupplied(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->once())
            ->method('appendUserProfileEntry')
            ->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('likes tea'))
            ->willReturn($this->memoryObject(['entries' => [['id' => 'entry-2', 'text' => 'likes tea', 'createdAt' => '2026-01-01T00:00:00+00:00']]]));

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.rememberMemory',
            ['agentId' => 'agent-1', 'content' => 'likes tea', 'scope' => 'user', 'subjectUid' => 'mallory']
        );

        $this->assertTrue($result['remembered']);
        $this->assertSame('user', $result['scope']);

    }//end testRememberMemoryUserScopeUsesActingUidNeverCallerSupplied()

    /**
     * rememberMemory rejects an empty content or an invalid scope value without
     * calling MemoryService at all.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testRememberMemoryInvalidArgumentsReturnError(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->never())->method('appendMemoryEntry');
        $memoryService->expects($this->never())->method('appendUserProfileEntry');

        $provider = $this->provider('alice', null, $memoryService);

        $missingContent = $provider->invokeTool('hermiq.rememberMemory', ['agentId' => 'agent-1', 'content' => '  ', 'scope' => 'agent']);
        $this->assertSame('invalid_argument', $missingContent['error']['code']);

        $badScope = $provider->invokeTool('hermiq.rememberMemory', ['agentId' => 'agent-1', 'content' => 'x', 'scope' => 'nope']);
        $this->assertSame('invalid_argument', $badScope['error']['code']);

    }//end testRememberMemoryInvalidArgumentsReturnError()

    /**
     * recallMemory merges MemoryService::recallEntries() (Memory/UserProfile
     * matches) with the existing recallSessions() (SessionTurn matches) into one
     * combined result — no second search index.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool
     */
    public function testRecallMemoryMergesEntriesAndSessionTurns(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->once())
            ->method('recallEntries')
            ->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('budget'))
            ->willReturn(
                [
                    'memoryEntries'      => [['id' => 'e1', 'text' => 'budget is 8000 chars', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                    'userProfileEntries' => [],
                ]
            );

        $turn = $this->memoryObject(['role' => 'user', 'content' => 'what is the budget?', 'createdAt' => '2026-01-02T00:00:00+00:00']);
        $memoryService->expects($this->once())
            ->method('recallSessions')
            ->with($this->equalTo('agent-1'), $this->equalTo('budget'))
            ->willReturn([$turn]);

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.recallMemory',
            ['agentId' => 'agent-1', 'query' => 'budget']
        );

        $this->assertSame('budget', $result['query']);
        $this->assertCount(1, $result['memoryEntries']);
        $this->assertSame('e1', $result['memoryEntries'][0]['id']);
        $this->assertCount(0, $result['userProfileEntries']);
        $this->assertCount(1, $result['sessionTurns']);
        $this->assertSame('user', $result['sessionTurns'][0]['role']);

    }//end testRecallMemoryMergesEntriesAndSessionTurns()

    /**
     * recallMemory rejects an empty query without calling MemoryService at all.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testRecallMemoryMissingQueryReturnsError(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->never())->method('recallEntries');

        $result = $this->provider('alice', null, $memoryService)->invokeTool('hermiq.recallMemory', ['agentId' => 'agent-1', 'query' => ' ']);

        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testRecallMemoryMissingQueryReturnsError()

    /**
     * forgetMemory delegates to MemoryService::forgetEntry() with the ACTING
     * user's own uid (never a caller-supplied `subjectUid`) and surfaces a found
     * result.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
     */
    public function testForgetMemoryDelegatesWithActingUidAndReturnsFound(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->once())
            ->method('forgetEntry')
            ->with($this->equalTo('agent-1'), $this->equalTo('alice'), $this->equalTo('entry-1'))
            ->willReturn(['found' => true, 'scope' => 'memory']);

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.forgetMemory',
            ['agentId' => 'agent-1', 'id' => 'entry-1', 'subjectUid' => 'mallory']
        );

        $this->assertTrue($result['found']);
        $this->assertSame('memory', $result['scope']);

    }//end testForgetMemoryDelegatesWithActingUidAndReturnsFound()

    /**
     * forgetMemory returns a structured not-found result — never an exception —
     * when MemoryService reports no match in either object.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
     */
    public function testForgetMemoryNotFoundReturnsStructuredResult(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->method('forgetEntry')->willReturn(['found' => false, 'scope' => null]);

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.forgetMemory',
            ['agentId' => 'agent-1', 'id' => 'no-such-entry']
        );

        $this->assertFalse($result['found']);
        $this->assertArrayNotHasKey('error', $result);

    }//end testForgetMemoryNotFoundReturnsStructuredResult()

    /**
     * forgetMemory rejects an empty id without calling MemoryService at all.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testForgetMemoryMissingIdReturnsError(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->expects($this->never())->method('forgetEntry');

        $result = $this->provider('alice', null, $memoryService)->invokeTool('hermiq.forgetMemory', ['agentId' => 'agent-1', 'id' => '']);

        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testForgetMemoryMissingIdReturnsError()

    /**
     * A failure inside MemoryService never crosses the MCP boundary as an
     * exception for any of the three memory tools — invokeTool()'s outer catch
     * turns it into the same structured error envelope every other tool uses.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary(): void
    {
        $memoryService = $this->createMock(MemoryService::class);
        $memoryService->method('appendMemoryEntry')->willThrowException(new RuntimeException('object store unreachable'));

        $result = $this->provider('alice', null, $memoryService)->invokeTool(
            'hermiq.rememberMemory',
            ['agentId' => 'agent-1', 'content' => 'x', 'scope' => 'agent']
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('tool_failed', $result['error']['code']);

    }//end testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary()

    /**
     * webSearch delegates to WebSearchClient with the acting uid (for the broker's
     * sessionless-caller path) and the query argument.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
     */
    public function testWebSearchDelegatesToClientWithActingUid(): void
    {
        $client = $this->createMock(WebSearchClient::class);
        $client->expects($this->once())
            ->method('search')
            ->with(query: 'nextcloud news', actingUserId: 'alice')
            ->willReturn(['query' => 'nextcloud news', 'results' => []]);

        $result = $this->provider('alice', null, null, $client)->invokeTool(
            'hermiq.webSearch',
            ['query' => 'nextcloud news']
        );

        $this->assertSame(['query' => 'nextcloud news', 'results' => []], $result);

    }//end testWebSearchDelegatesToClientWithActingUid()

    /**
     * webSearch rejects an empty/missing query before ever reaching the client.
     *
     * @return void
     */
    public function testWebSearchMissingQueryReturnsError(): void
    {
        $client = $this->createMock(WebSearchClient::class);
        $client->expects($this->never())->method('search');

        $result = $this->provider('alice', null, null, $client)->invokeTool('hermiq.webSearch', []);

        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testWebSearchMissingQueryReturnsError()

    /**
     * webFetch delegates to WebFetchService with the url argument.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate
     */
    public function testWebFetchDelegatesToService(): void
    {
        $service = $this->createMock(WebFetchService::class);
        $service->expects($this->once())
            ->method('fetch')
            ->with(url: 'https://example.org/page')
            ->willReturn(['url' => 'https://example.org/page', 'truncated' => false, 'content' => 'hello']);

        $result = $this->provider('alice', null, null, null, $service)->invokeTool(
            'hermiq.webFetch',
            ['url' => 'https://example.org/page']
        );

        $this->assertSame('https://example.org/page', $result['url']);

    }//end testWebFetchDelegatesToService()

    /**
     * webFetch rejects an empty/missing url before ever reaching the service.
     *
     * @return void
     */
    public function testWebFetchMissingUrlReturnsError(): void
    {
        $service = $this->createMock(WebFetchService::class);
        $service->expects($this->never())->method('fetch');

        $result = $this->provider('alice', null, null, null, $service)->invokeTool('hermiq.webFetch', []);

        $this->assertSame('invalid_argument', $result['error']['code']);

    }//end testWebFetchMissingUrlReturnsError()

    /**
     * A WebSearchClient/WebFetchService exception never crosses the MCP boundary —
     * invokeTool()'s outer catch turns it into the same structured error envelope
     * every other tool uses (mirrors testMemoryToolFailureNeverThrowsAcrossTheMcpBoundary()).
     *
     * @return void
     */
    public function testWebResearchToolFailureNeverThrowsAcrossTheMcpBoundary(): void
    {
        $client = $this->createMock(WebSearchClient::class);
        $client->method('search')->willThrowException(new RuntimeException('unexpected'));

        $result = $this->provider('alice', null, null, $client)->invokeTool('hermiq.webSearch', ['query' => 'x']);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('tool_failed', $result['error']['code']);

    }//end testWebResearchToolFailureNeverThrowsAcrossTheMcpBoundary()
}//end class
