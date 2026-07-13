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
     * @param string|null                      $uid    The acting user id, or null for unauthenticated.
     * @param CourseRecommendationEngine|null $engine A specific engine double, or a plain mock.
     *
     * @return HermiqToolProvider
     */
    private function provider(?string $uid, ?CourseRecommendationEngine $engine=null): HermiqToolProvider
    {
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
        // progressive-disclosure meta-tool) + hermiq.recommendCourses (ai-course-recommendations),
        // all registered through this same provider.
        $this->assertCount(8, $tools);

        $ids = array_column($tools, 'id');
        $this->assertContains('hermiq.listFiles', $ids);
        $this->assertContains('hermiq.readFile', $ids);
        $this->assertContains('hermiq.searchContacts', $ids);
        $this->assertContains('hermiq.listCalendarEvents', $ids);
        $this->assertContains('hermiq.sendMail', $ids);
        $this->assertContains('hermiq.listDeckBoards', $ids);
        $this->assertContains('hermiq.searchTools', $ids);
        $this->assertContains('hermiq.recommendCourses', $ids);

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
        ];

        $tools = $this->provider('alice')->getTools();
        $this->assertCount(8, $tools, 'This test must be updated if a tool is added or removed.');

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
}//end class
