<?php

/**
 * Unit tests for MemoryService (agent-memory, agent-memory-tools).
 *
 * Covers the char-budget write path: appending under budget does not flag; appending
 * over budget flags `needsConsolidation` and NEVER drops older entries; an explicit
 * consolidation clears the flag; and recall passes the agent filter + search term to
 * OpenRegister's own search (tenant scoping is inherited from ObjectService).
 *
 * agent-memory-tools additionally covers: every appended entry is redacted
 * BEFORE persist and carries a freshly-generated unique id; `forgetEntry()`
 * soft-deletes (never hard-deletes) an entry, falls back to the acting user's
 * own UserProfile when no match exists in Memory, and returns a structured
 * not-found result for an unknown id; `recallEntries()` reuses the same
 * `findAll()` search substrate `recallSessions()` already uses; and a
 * soft-deleted entry is excluded from `needsConsolidation` character counting.
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
 * @spec openspec/changes/agent-memory-tools/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the agent-memory MemoryService.
 *
 * @spec openspec/changes/agent-memory/tasks.md#task-5-1
 */
class MemoryServiceTest extends TestCase
{

    /**
     * A Memory/UserProfile ObjectEntity with the given payload.
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
     * A real RedactionService (redact() always applies its patterns regardless of
     * the frozen toggle — MODE_FORCE — so the IConfig value fed here is
     * irrelevant to what these tests assert).
     *
     * @return RedactionService
     */
    private function redactionService(): RedactionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        return new RedactionService($config);

    }//end redactionService()

    /**
     * An IUserSession whose `getUser()` returns a user carrying the given uid, or
     * (when `$uid` is null) no user at all — used to exercise `listSessions()`'s
     * owner-scoping and its fail-closed no-session path.
     *
     * @param string|null $uid The uid `getUser()->getUID()` should resolve to, or null
     *                         to simulate no authenticated session.
     *
     * @return IUserSession
     */
    private function userSession(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end userSession()

    /**
     * An ObjectService whose findAll returns the given list and whose saveObject records
     * the last saved payload into $captured and echoes it back as an entity.
     *
     * @param array<int, ObjectEntity>  $findResult The findAll result.
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
     * An ObjectService that returns a DIFFERENT findAll result depending on the
     * schema most recently passed to setSchema() — needed to test forgetEntry()'s
     * Memory-then-UserProfile fallback, which reads two different schemas in one
     * call.
     *
     * @param array<int, ObjectEntity>  $memoryResult  findAll result when schema is 'memory'.
     * @param array<int, ObjectEntity>  $profileResult findAll result when schema is 'userprofile'.
     * @param array<string, mixed>|null $captured      Out-param: the last saved object payload.
     *
     * @return ObjectService
     */
    private function objectServiceBySchema(array $memoryResult, array $profileResult, ?array &$captured): ObjectService
    {
        $lastSchema = null;

        $service = $this->createMock(ObjectService::class);
        $service->method('setRegister')->willReturnSelf();
        $service->method('setSchema')->willReturnCallback(
            function (string $schema) use (&$lastSchema, $service): ObjectService {
                $lastSchema = $schema;
                return $service;
            }
        );
        $service->method('findAll')->willReturnCallback(
            function () use (&$lastSchema, $memoryResult, $profileResult): array {
                if ($lastSchema === 'userprofile') {
                    return $profileResult;
                }

                return $memoryResult;
            }
        );
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

    }//end objectServiceBySchema()

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
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
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
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
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
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
        $service->consolidateMemory(
            agentId: 'agent-1',
            entries: [['text' => 'consolidated summary', 'createdAt' => '2026-01-01T00:00:00+00:00']]
        );

        $this->assertNotNull($captured);
        $this->assertCount(1, $captured['entries']);
        $this->assertFalse($captured['needsConsolidation']);

    }//end testConsolidateClearsFlagWhenUnderBudget()

    /**
     * listSessions() scopes to the CALLER's own Sessions via OpenRegister's
     * `@self.owner` object-owner meta-filter, alongside `agentId` — Session has no
     * user/owner schema property, so this is the only guard against one user seeing
     * another user's chat sessions for the same agent. Without the fix, `filters`
     * carries only `agentId` and this assertion fails.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-4
     */
    public function testListSessionsScopesToCallerOwnedSessionsOnly(): void
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

        $memory = new MemoryService($service, $this->redactionService(), $this->userSession('alice'));
        $memory->listSessions(agentId: 'agent-1');

        $this->assertNotNull($capturedConfig);
        $this->assertSame('agent-1', $capturedConfig['filters']['agentId']);
        $this->assertArrayHasKey(
            '@self.owner',
            $capturedConfig['filters'],
            'listSessions() must scope by the OpenRegister object-owner meta-filter, never just agentId — '
            .'otherwise every authenticated user sees every other user\'s sessions for this agent.'
        );
        $this->assertSame('alice', $capturedConfig['filters']['@self.owner']);

    }//end testListSessionsScopesToCallerOwnedSessionsOnly()

    /**
     * `listSessions()` fails CLOSED when there is no authenticated user: it must return
     * an empty array rather than falling back to an unfiltered (everyone's-sessions)
     * query. `findAll()` must never even be called in this case.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-4
     */
    public function testListSessionsFailsClosedWithNoAuthenticatedUser(): void
    {
        $service = $this->createMock(ObjectService::class);
        // Both setRegister() and setSchema() must ALSO be stubbed to return the same
        // mock (fluent chain) — otherwise PHPUnit's auto-generated return value for an
        // unconfigured "static"-typed method hands back a DIFFERENT, unconfigured
        // double, and a findAll() call landing on THAT object would silently escape
        // the expectation below, making this assertion vacuous.
        $service->method('setRegister')->willReturnSelf();
        $service->method('setSchema')->willReturnSelf();
        $service->expects($this->never())->method('findAll');

        $memory = new MemoryService($service, $this->redactionService(), $this->userSession(null));
        $result = $memory->listSessions(agentId: 'agent-1');

        $this->assertSame([], $result);

    }//end testListSessionsFailsClosedWithNoAuthenticatedUser()

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

        $memory = new MemoryService($service, $this->redactionService(), $this->userSession('admin'));
        $memory->recallSessions(agentId: 'agent-9', query: 'budget report');

        $this->assertNotNull($capturedConfig);
        $this->assertSame('agent-9', $capturedConfig['filters']['agentId']);
        $this->assertSame('budget report', $capturedConfig['search']);

    }//end testRecallPassesAgentFilterAndSearch()

    /**
     * A memory entry containing a recognised credential pattern is redacted
     * BEFORE persist — the surrounding fact text is preserved unmasked
     * (agent-memory-tools, "Memory writes are redacted before persist").
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-writes-are-redacted-before-persist
     */
    public function testAppendRedactsSecretBeforePersist(): void
    {
        $existing = $this->memory(['agentId' => 'agent-1', 'entries' => [], 'charBudget' => 8000, 'needsConsolidation' => false]);

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
        $service->appendMemoryEntry(agentId: 'agent-1', text: 'the client\'s API key is sk-abcdefghijklmnop, keep it safe');

        $this->assertNotNull($captured);
        $storedText = $captured['entries'][0]['text'];
        $this->assertStringNotContainsString('sk-abcdefghijklmnop', $storedText, 'The credential substring must be masked.');
        $this->assertStringContainsString('API key is', $storedText, 'The surrounding fact text must be preserved unmasked.');

    }//end testAppendRedactsSecretBeforePersist()

    /**
     * Every appended entry carries a freshly-generated, non-empty id, and two
     * appends in the same call never collide.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/design.md#decision-2-entry-level-id--deletedat-not-a-separate-or-object-per-entry
     */
    public function testAppendGeneratesUniqueEntryId(): void
    {
        $existing = $this->memory(['agentId' => 'agent-1', 'entries' => [], 'charBudget' => 8000, 'needsConsolidation' => false]);

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
        $service->appendMemoryEntry(agentId: 'agent-1', text: 'fact one');

        $this->assertNotNull($captured);
        $firstId = $captured['entries'][0]['id'];
        $this->assertNotSame('', $firstId);
        $this->assertMatchesRegularExpression('~^[0-9a-f-]{36}$~', $firstId);

        // A second append (fresh state, simulating the next call) gets a DIFFERENT id.
        $existingWithOneEntry = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['id' => $firstId, 'text' => 'fact one', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 8000,
                'needsConsolidation' => false,
            ]
        );
        $captured2 = null;
        $service2  = new MemoryService($this->objectService([$existingWithOneEntry], $captured2), $this->redactionService(), $this->userSession('admin'));
        $service2->appendMemoryEntry(agentId: 'agent-1', text: 'fact two');

        $secondId = $captured2['entries'][1]['id'];
        $this->assertNotSame($firstId, $secondId);

    }//end testAppendGeneratesUniqueEntryId()

    /**
     * forgetEntry() soft-deletes a matching Memory entry: `deletedAt` is set and
     * the entry remains present in the stored `entries` array — never a hard
     * delete.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
     */
    public function testForgetEntrySoftDeletesButKeepsEntryInArray(): void
    {
        $existing = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['id' => 'entry-1', 'text' => 'a fact', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 8000,
                'needsConsolidation' => false,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
        $result   = $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'entry-1');

        $this->assertTrue($result['found']);
        $this->assertSame('memory', $result['scope']);
        $this->assertNotNull($captured);
        $this->assertCount(1, $captured['entries'], 'The entry must remain present — never a hard delete.');
        $this->assertSame('entry-1', $captured['entries'][0]['id']);
        $this->assertArrayHasKey('deletedAt', $captured['entries'][0]);
        $this->assertNotSame('', $captured['entries'][0]['deletedAt']);

    }//end testForgetEntrySoftDeletesButKeepsEntryInArray()

    /**
     * A soft-deleted entry is excluded from `needsConsolidation` character-budget
     * counting.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-3
     */
    public function testForgetEntryExcludesSoftDeletedFromCharacterCount(): void
    {
        $existing = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['id' => 'entry-1', 'text' => str_repeat('x', 20), 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 10,
                'needsConsolidation' => true,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectService([$existing], $captured), $this->redactionService(), $this->userSession('admin'));
        $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'entry-1');

        $this->assertNotNull($captured);
        $this->assertFalse($captured['needsConsolidation'], 'A forgotten entry must stop counting toward the character budget.');

    }//end testForgetEntryExcludesSoftDeletedFromCharacterCount()

    /**
     * forgetEntry() falls back to the acting user's own UserProfile when no
     * matching entry exists in the agent's Memory.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-3
     */
    public function testForgetEntryFallsBackToUserProfileWhenNotInMemory(): void
    {
        $memoryObject = $this->memory(
            [
                'agentId'            => 'agent-1',
                'entries'            => [['id' => 'other-entry', 'text' => 'unrelated', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 8000,
                'needsConsolidation' => false,
            ]
        );
        $profileObject = $this->memory(
            [
                'agentId'            => 'agent-1',
                'subjectUid'         => 'alice',
                'entries'            => [['id' => 'profile-entry', 'text' => 'likes tea', 'createdAt' => '2026-01-01T00:00:00+00:00']],
                'charBudget'         => 4000,
                'needsConsolidation' => false,
            ]
        );

        $captured = null;
        $service  = new MemoryService($this->objectServiceBySchema([$memoryObject], [$profileObject], $captured), $this->redactionService(), $this->userSession('admin'));
        $result   = $service->forgetEntry(agentId: 'agent-1', subjectUid: 'alice', entryId: 'profile-entry');

        $this->assertTrue($result['found']);
        $this->assertSame('userProfile', $result['scope']);
        $this->assertNotNull($captured);
        $this->assertSame('profile-entry', $captured['entries'][0]['id']);
        $this->assertArrayHasKey('deletedAt', $captured['entries'][0]);

    }//end testForgetEntryFallsBackToUserProfileWhenNotInMemory()

    /**
     * An id matching nothing in either the agent's Memory or the acting user's
     * UserProfile is a structured not-found result — never an exception.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
     */
    public function testForgetEntryUnknownIdReturnsNotFound(): void
    {
        $memoryObject  = $this->memory(['agentId' => 'agent-1', 'entries' => [], 'charBudget' => 8000, 'needsConsolidation' => false]);
        $profileObject = $this->memory(['agentId' => 'agent-1', 'subjectUid' => 'alice', 'entries' => [], 'charBudget' => 4000, 'needsConsolidation' => false]);

        $captured = null;
        $service  = new MemoryService($this->objectServiceBySchema([$memoryObject], [$profileObject], $captured), $this->redactionService(), $this->userSession('admin'));
        $result   = $service->forgetEntry(agentId: 'agent-1', subjectUid: 'alice', entryId: 'no-such-id');

        $this->assertFalse($result['found']);
        $this->assertNull($result['scope']);

    }//end testForgetEntryUnknownIdReturnsNotFound()

    /**
     * recallEntries() reuses the SAME findAll() search substrate recallSessions()
     * already uses (no second search index) and excludes soft-deleted entries.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-4
     */
    public function testRecallEntriesReusesSearchSubstrateAndExcludesSoftDeleted(): void
    {
        $memoryObject = $this->memory(
            [
                'agentId' => 'agent-1',
                'entries' => [
                    ['id' => 'e1', 'text' => 'the budget report is due Friday', 'createdAt' => '2026-01-01T00:00:00+00:00'],
                    ['id' => 'e2', 'text' => 'the budget was forgotten', 'createdAt' => '2026-01-01T00:00:00+00:00', 'deletedAt' => '2026-01-02T00:00:00+00:00'],
                ],
                'charBudget'         => 8000,
                'needsConsolidation' => false,
            ]
        );

        $capturedConfig = null;
        $service        = $this->createMock(ObjectService::class);
        $service->method('setRegister')->willReturnSelf();
        $service->method('setSchema')->willReturnSelf();
        $service->method('findAll')->willReturnCallback(
            function (array $config) use (&$capturedConfig, $memoryObject): array {
                $capturedConfig = $config;
                return [$memoryObject];
            }
        );

        $memory = new MemoryService($service, $this->redactionService(), $this->userSession('admin'));
        $result = $memory->recallEntries(agentId: 'agent-1', subjectUid: null, query: 'budget');

        $this->assertSame('agent-1', $capturedConfig['filters']['agentId']);
        $this->assertSame('budget', $capturedConfig['search']);
        $this->assertCount(1, $result['memoryEntries'], 'The soft-deleted entry must be excluded from the result.');
        $this->assertSame('e1', $result['memoryEntries'][0]['id']);
        $this->assertSame([], $result['userProfileEntries'], 'No subjectUid was supplied — userprofile is never searched.');

    }//end testRecallEntriesReusesSearchSubstrateAndExcludesSoftDeleted()
}//end class
