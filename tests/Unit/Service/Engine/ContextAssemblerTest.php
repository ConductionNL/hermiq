<?php

/**
 * Unit tests for ContextAssembler (agent-context-system).
 *
 * Covers objectQueries resolution (including a degraded single bad entry),
 * files resolution (including a missing/non-file path skip, never fatal), the
 * charBudget/needsConsolidation contract (flip to true over budget, flip back to
 * false under budget, persisted ONLY when the flag actually changes), and
 * assembleForAgent's multi-context concatenation / null-agent and empty-refs no-ops.
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
 * @spec openspec/changes/agent-context-system/tasks.md#4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ContextAssembler.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#4-1
 */
class ContextAssemblerTest extends TestCase
{

    /**
     * A Context ObjectEntity with the given payload.
     *
     * @param array<string, mixed> $payload The object data.
     * @param string                $uuid    The object uuid.
     *
     * @return ObjectEntity
     */
    private function context(array $payload, string $uuid='ctx-uuid'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end context()

    /**
     * An Agent ObjectEntity with the given payload.
     *
     * @param array<string, mixed> $payload The object data.
     *
     * @return ObjectEntity
     */
    private function agent(array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-uuid');
        $entity->setObject($payload);
        return $entity;

    }//end agent()

    /**
     * An IRootFolder mock whose getUserFolder() returns a Folder mock wired to
     * resolve the given path → content map; any path not in the map "does not exist".
     *
     * @param array<string, string|null> $files Path => content (null = not a file / missing).
     *
     * @return IRootFolder
     */
    private function rootFolderWithFiles(array $files): IRootFolder
    {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('nodeExists')->willReturnCallback(
            static fn (string $path): bool => array_key_exists($path, $files) === true
        );
        $userFolder->method('get')->willReturnCallback(
            function (string $path) use ($files) {
                if (($files[$path] ?? null) === null) {
                    // Simulate "not a file" via a Folder node (any non-File instance works).
                    return $this->createMock(Folder::class);
                }

                $file = $this->createMock(File::class);
                $file->method('getContent')->willReturn($files[$path]);
                return $file;
            }
        );

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);
        return $rootFolder;

    }//end rootFolderWithFiles()

    /**
     * objectQueries resolve via ObjectService and format as Source: blocks; a second,
     * unresolvable entry (missing register/schema) is skipped without aborting assembly.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-2
     */
    public function testObjectQueriesResolveAndDegradeGracefully(): void
    {
        $found = new ObjectEntity();
        $found->setUuid('obj-1');
        $found->setObject(['title' => 'Permit 123']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn([$found]);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'          => 'Permit case law',
                    'objectQueries' => [
                        ['register' => 'opencatalogi', 'schema' => 'publication', 'limit' => 5],
                        ['register' => '', 'schema' => ''],
                        // Missing register/schema — must be skipped, not fatal.
                    ],
                    'charBudget' => 8000,
                ]
            )
        );

        $assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertStringContainsString('Context: Permit case law', $result['text']);
        $this->assertStringContainsString('Permit 123', $result['text']);
        $this->assertFalse($result['needsConsolidation']);

    }//end testObjectQueriesResolveAndDegradeGracefully()

    /**
     * files resolve from the acting user's folder; a missing file is skipped, not fatal.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-3
     */
    public function testFilesResolveAndMissingFileIsSkipped(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'  => 'Reference docs',
                    'files' => [
                        ['path' => 'notes.md'],
                        ['path' => 'missing.md'],
                    ],
                    'charBudget' => 8000,
                ]
            )
        );

        $rootFolder = $this->rootFolderWithFiles(['notes.md' => 'Some reference text.']);

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertStringContainsString('Some reference text.', $result['text']);
        $this->assertStringNotContainsString('missing.md', $result['text']);

    }//end testFilesResolveAndMissingFileIsSkipped()

    /**
     * Content exceeding charBudget flips needsConsolidation to true AND persists it —
     * the text itself is never truncated.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-4
     */
    public function testOverBudgetFlipsAndPersistsNeedsConsolidation(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'              => 'Big bundle',
                    'files'             => [['path' => 'big.md']],
                    'charBudget'        => 5,
                    'needsConsolidation' => false,
                ]
            )
        );

        $rootFolder = $this->rootFolderWithFiles(['big.md' => 'This is way more than five characters.']);

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertTrue($result['needsConsolidation']);
        $this->assertStringContainsString('This is way more than five characters.', $result['text'], 'The text must never be truncated.');
        $this->assertCount(1, $saved, 'The flag flip must be persisted.');
        $this->assertTrue($saved[0]['needsConsolidation']);

    }//end testOverBudgetFlipsAndPersistsNeedsConsolidation()

    /**
     * A stored needsConsolidation=true flips back to false (and persists) once the
     * content is back under budget.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-4
     */
    public function testUnderBudgetClearsStaleNeedsConsolidation(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'               => 'Small bundle',
                    'files'              => [['path' => 'small.md']],
                    'charBudget'         => 8000,
                    'needsConsolidation' => true,
                ]
            )
        );

        $rootFolder = $this->rootFolderWithFiles(['small.md' => 'tiny']);

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertFalse($result['needsConsolidation']);
        $this->assertCount(1, $saved, 'The flag flip back to false must also be persisted.');
        $this->assertFalse($saved[0]['needsConsolidation']);

    }//end testUnderBudgetClearsStaleNeedsConsolidation()

    /**
     * No save occurs when the computed flag matches the stored one — avoids a
     * write on every single assembly.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-4
     */
    public function testNoExtraSaveWhenFlagUnchanged(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'               => 'Small bundle',
                    'files'              => [['path' => 'small.md']],
                    'charBudget'         => 8000,
                    'needsConsolidation' => false,
                ]
            )
        );
        $objectService->method('findAll')->willReturn([]);
        $objectService->expects($this->never())->method('saveObject');

        $rootFolder = $this->rootFolderWithFiles(['small.md' => 'tiny']);

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

    }//end testNoExtraSaveWhenFlagUnchanged()

    /**
     * A valid `documents` entry renders as a titled section (its `name`) alongside
     * files/object-queries, and a malformed entry (missing `body`) is skipped without
     * aborting the rest of the assembly (hermiq-context-documents).
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
     */
    public function testDocumentsRenderAndMalformedEntryIsSkipped(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'      => 'Project reference',
                    'documents' => [
                        ['name' => 'design.md', 'body' => "# Design\nSome design content.", 'format' => 'markdown'],
                        ['name' => 'incomplete'],
                        // Missing `body` — must be skipped, not fatal.
                        'not-an-object',
                        // Non-array entry — must be skipped, not fatal.
                    ],
                    'files'      => [['path' => 'notes.md']],
                    'charBudget' => 8000,
                ]
            )
        );

        $rootFolder = $this->rootFolderWithFiles(['notes.md' => 'Some reference text.']);

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertStringContainsString('Context: Project reference', $result['text']);
        $this->assertStringContainsString('Source: design.md', $result['text']);
        $this->assertStringContainsString('Some design content.', $result['text']);
        $this->assertStringNotContainsString('incomplete', $result['text']);
        $this->assertStringContainsString('Some reference text.', $result['text']);
        $this->assertFalse($result['needsConsolidation']);

    }//end testDocumentsRenderAndMalformedEntryIsSkipped()

    /**
     * A Context with no `documents` value (absent) assembles exactly as it did before
     * this change — files/object-queries only, no extra section.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
     */
    public function testNoDocumentsAssemblesIdenticallyToPreChange(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'       => 'No documents',
                    'files'      => [['path' => 'notes.md']],
                    'charBudget' => 8000,
                ]
            )
        );

        $rootFolder = $this->rootFolderWithFiles(['notes.md' => 'Some reference text.']);

        $assembler = new ContextAssembler($objectService, $rootFolder, new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertSame("Context: No documents\nSource: notes.md\nSome reference text.", $result['text']);
        $this->assertFalse($result['needsConsolidation']);

    }//end testNoDocumentsAssemblesIdenticallyToPreChange()

    /**
     * A `documents` body pushing the assembled bundle over `charBudget` flags
     * `needsConsolidation` inside the EXISTING budget contract — no new/separate budget,
     * and the text is never truncated.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-documents-share-the-existing-budget-contract
     */
    public function testDocumentPushesBundleOverExistingBudget(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->context(
                [
                    'name'       => 'Big document bundle',
                    'documents'  => [
                        ['name' => 'design.md', 'body' => 'This document body is way more than five characters.'],
                    ],
                    'charBudget' => 5,
                ]
            )
        );

        $saved = [];
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), new NullLogger());
        $result    = $assembler->assemble(contextId: 'ctx-uuid', actingUserId: 'alice');

        $this->assertTrue($result['needsConsolidation']);
        $this->assertStringContainsString('This document body is way more than five characters.', $result['text'], 'The text must never be truncated.');
        $this->assertCount(1, $saved, 'The flag flip must be persisted.');
        $this->assertTrue($saved[0]['needsConsolidation']);

    }//end testDocumentPushesBundleOverExistingBudget()

    /**
     * assembleForAgent concatenates every referenced Context and returns '' for a null
     * agent or an agent with no contextRefs.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-5
     */
    public function testAssembleForAgentConcatenatesAndNoOps(): void
    {
        $ctxA = $this->context(['name' => 'A', 'files' => [], 'objectQueries' => []], 'ctx-a');
        $ctxB = $this->context(['name' => 'B', 'files' => [], 'objectQueries' => []], 'ctx-b');

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            fn (string $id): ?ObjectEntity => match ($id) {
                'ctx-a' => $ctxA,
                'ctx-b' => $ctxB,
                default => null,
            }
        );

        $assembler = new ContextAssembler($objectService, $this->createMock(IRootFolder::class), new NullLogger());

        // Null agent → no-op.
        $this->assertSame('', $assembler->assembleForAgent(agent: null, actingUserId: 'alice'));

        // Empty contextRefs → no-op.
        $this->assertSame(
            '',
            $assembler->assembleForAgent(agent: $this->agent(['contextRefs' => []]), actingUserId: 'alice')
        );

        // Two refs → both concatenated.
        $combined = $assembler->assembleForAgent(
            agent: $this->agent(['contextRefs' => ['ctx-a', 'ctx-b']]),
            actingUserId: 'alice'
        );
        $this->assertStringContainsString('Context: A', $combined);
        $this->assertStringContainsString('Context: B', $combined);

    }//end testAssembleForAgentConcatenatesAndNoOps()
}//end class
