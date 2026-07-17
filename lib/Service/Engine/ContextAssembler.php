<?php

/**
 * Hermiq Context Assembler.
 *
 * Resolves a `Context` object (hermiq register, schema `context` — agent-context-system,
 * SPECTR-NEXTCLOUD-PLAN.md §6.4) into a budgeted text preamble at run start: runs each
 * `objectQueries` entry through `ObjectService` (the same public surface `MemoryService`/
 * `ContextRetrievalHandler` already use), reads each `files` entry from the acting user's
 * Nextcloud folder via `IRootFolder` (the same public OCP surface
 * `HermiqToolProvider::readFile()` already uses), renders each inline `documents` entry
 * (ADR-024 — a `design.md`-style document authored directly on the Context, distinct from
 * a `files` pointer at a user's Nextcloud file), and concatenates everything under a
 * character budget — mirroring `MemoryService`'s `charBudget`/`needsConsolidation`
 * contract: the assembled text is NEVER truncated to fit the budget; exceeding it only
 * flags (and persists) a `needsConsolidation` nudge.
 *
 * `viewRefs` resolution is deferred: `Agent.views` has the identical, already-documented
 * gap at HEAD (`ContextRetrievalHandler::resolveViewFilters()` computes the effective set
 * but does not yet apply it — see that class's docblock). Wiring a DIFFERENT, working
 * view-filter mechanism just for `Context` would create two inconsistent behaviors in the
 * same codebase, so `viewRefs` is collected and logged (count only), not applied.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves Context objects into a budgeted text preamble for the Engine's system prompt.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) hermiq-chat-attachments' assembleAttachments()
 * pushes the class over the threshold by ONE more (trivial, one-line) method. The alternative — a
 * separate AttachmentAssembler class — was explicitly rejected (design.md Decision 3): an
 * attachment is Context-kind material with a Message lifecycle, not a fourth concept, so its
 * resolution reuses resolveFiles() verbatim rather than becoming a second seam by another name.
 */
class ContextAssembler
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for context objects.
     *
     * @var string
     */
    private const CONTEXT_SCHEMA = 'context';

    /**
     * Default character budget for a Context object when none is stored.
     *
     * @var int
     */
    private const DEFAULT_CHAR_BUDGET = 8000;

    /**
     * Per-object-query result limit when the query itself declares none.
     *
     * @var int
     */
    private const DEFAULT_QUERY_LIMIT = 20;

    /**
     * Per-file read cap (bytes) — a defensive bound against a pathologically large
     * file blowing memory, distinct from the charBudget nudge contract: this is
     * logged, not silently applied (mirrors `HermiqToolProvider::readFile()`).
     *
     * @var int
     */
    private const MAX_FILE_BYTES = 20000;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read/write (single write-path).
     * @param IRootFolder     $rootFolder    Files root (scoped per acting user).
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assemble every Context an agent references into a single preamble string.
     *
     * Null agent or an empty `contextRefs` returns `''` (no-op — most agents have no
     * attached context). A Context uuid that fails to resolve is skipped (logged), not
     * fatal — one bad reference must not blank the whole preamble.
     *
     * @param ObjectEntity|null $agent        Agent object (optional).
     * @param string            $actingUserId The acting user id, for reading `files` from
     *                                        their Nextcloud folder.
     *
     * @return string The concatenated preamble (each bundle under a `Context: {name}`
     *                header), or `''` when the agent has no attached context.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-5
     */
    public function assembleForAgent(?ObjectEntity $agent, string $actingUserId): string
    {
        if ($agent === null) {
            return '';
        }

        $contextRefs = ($agent->getObject()['contextRefs'] ?? []);
        if (is_array($contextRefs) === false || $contextRefs === []) {
            return '';
        }

        $blocks = [];
        foreach ($contextRefs as $contextId) {
            if (is_string($contextId) === false || $contextId === '') {
                continue;
            }

            $resolved = $this->assemble(contextId: $contextId, actingUserId: $actingUserId);
            if ($resolved['text'] === '') {
                continue;
            }

            $blocks[] = $resolved['text'];
        }

        return implode("\n\n", $blocks);

    }//end assembleForAgent()

    /**
     * Resolve ONE Context object into its budgeted preamble text.
     *
     * @param string $contextId    The Context object uuid.
     * @param string $actingUserId The acting user id, for reading `files`.
     *
     * @return array{text: string, needsConsolidation: bool} The assembled text (headed
     *         by `Context: {name}`; `''` when the Context cannot be resolved) and
     *         whether it exceeded the object's charBudget.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-1
     */
    public function assemble(string $contextId, string $actingUserId): array
    {
        $context = $this->objectService->find(
            id: $contextId,
            register: self::REGISTER_SLUG,
            schema: self::CONTEXT_SCHEMA
        );
        if ($context === null) {
            return ['text' => '', 'needsConsolidation' => false];
        }

        $data = $context->getObject();
        $name = (string) ($data['name'] ?? 'Context');

        $sections = [];
        $sections = array_merge($sections, $this->resolveObjectQueries(queries: ($data['objectQueries'] ?? [])));
        $sections = array_merge($sections, $this->resolveFiles(files: ($data['files'] ?? []), actingUserId: $actingUserId));
        $sections = array_merge($sections, $this->resolveDocuments(documents: ($data['documents'] ?? [])));

        $this->logViewRefs(contextId: $contextId, viewRefs: ($data['viewRefs'] ?? []));

        $body = implode("\n\n", $sections);
        $text = "Context: {$name}\n".$body;
        if ($body === '') {
            $text = "Context: {$name}";
        }

        $budget = (int) ($data['charBudget'] ?? self::DEFAULT_CHAR_BUDGET);
        $needsConsolidation = (mb_strlen($body) > $budget);

        $this->persistFlagIfChanged(context: $context, data: $data, needsConsolidation: $needsConsolidation);

        return [
            'text'               => $text,
            'needsConsolidation' => $needsConsolidation,
        ];

    }//end assemble()

    /**
     * Resolve each `objectQueries` entry via ObjectService, formatting the results as
     * `Source:` text blocks. A single unresolvable entry (unknown register/schema, a
     * read failure) is skipped (logged) — it never aborts the whole assembly.
     *
     * @param mixed $queries The Context's `objectQueries` value.
     *
     * @return array<int, string> One formatted block per resolved query.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-2
     */
    private function resolveObjectQueries(mixed $queries): array
    {
        if (is_array($queries) === false) {
            return [];
        }

        $blocks = [];
        foreach ($queries as $query) {
            if (is_array($query) === false) {
                continue;
            }

            $register = (string) ($query['register'] ?? '');
            $schema   = (string) ($query['schema'] ?? '');
            if ($register === '' || $schema === '') {
                continue;
            }

            try {
                $config = ['limit' => (int) ($query['limit'] ?? self::DEFAULT_QUERY_LIMIT)];

                $filters = ($query['filters'] ?? []);
                if (is_array($filters) === true && $filters !== []) {
                    $config['filters'] = $filters;
                }

                $search = ($query['search'] ?? '');
                if (is_string($search) === true && $search !== '') {
                    $config['search'] = $search;
                }

                $objects = $this->objectService
                    ->setRegister($register)
                    ->setSchema($schema)
                    ->findAll(config: $config);
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Hermiq ContextAssembler query %s/%s failed: %s', $register, $schema, $e->getMessage()),
                    ['exception' => $e]
                );
                continue;
            }//end try

            foreach ($objects as $object) {
                if (($object instanceof ObjectEntity) === false) {
                    continue;
                }

                $blocks[] = sprintf(
                    "Source: %s/%s\n%s",
                    $register,
                    $schema,
                    (string) json_encode($object->getObject(), JSON_UNESCAPED_SLASHES)
                );
            }
        }//end foreach

        return $blocks;

    }//end resolveObjectQueries()

    /**
     * Resolve a chat turn's per-turn `attachments` references into `Source:` blocks,
     * reusing `resolveFiles()` VERBATIM — the SAME `IRootFolder` read, the SAME
     * `MAX_FILE_BYTES` cap, and the SAME skip-and-log tolerance for a missing/folder/
     * unreadable entry `Context.files` already gets. An attachment is Context-kind
     * material with a Message lifecycle, not a fourth concept (hermiq-chat-attachments
     * design.md Decision 3): it introduces no second read path. The caller (Engine)
     * folds the returned text into the SAME preamble `assembleForAgent()` produces,
     * so attachment text inherits that preamble's guardrail filtering and budget
     * accounting rather than needing either of its own.
     *
     * `{path, name, description}` attachment entries are read here purely by their
     * `path` key — `resolveFiles()` never looks at `name`/`description` — so no
     * shape adaptation is needed between the two.
     *
     * @param mixed  $attachments  The turn's `attachments` value.
     * @param string $actingUserId The acting user id, for reading files from their
     *                             Nextcloud folder.
     *
     * @return string The concatenated attachment blocks ('' when there are none, or
     *                none resolve).
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-attachment-content-is-resolved-into-the-turn-preamble-via-the-acting-users-folder
     */
    public function assembleAttachments(mixed $attachments, string $actingUserId): string
    {
        return implode("\n\n", $this->resolveFiles(files: $attachments, actingUserId: $actingUserId));

    }//end assembleAttachments()

    /**
     * Read each `files` entry from the acting user's Nextcloud folder. A missing file,
     * a path resolving to a folder, or a read failure is skipped (logged) — it never
     * aborts the whole assembly.
     *
     * @param mixed  $files        The Context's `files` value.
     * @param string $actingUserId The acting user id.
     *
     * @return array<int, string> One formatted block per resolved file.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-3
     */
    private function resolveFiles(mixed $files, string $actingUserId): array
    {
        if (is_array($files) === false || $files === [] || $actingUserId === '') {
            return [];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($actingUserId);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq ContextAssembler could not open the folder for %s: %s', $actingUserId, $e->getMessage()),
                ['exception' => $e]
            );
            return [];
        }

        $blocks = [];
        foreach ($files as $file) {
            if (is_array($file) === false) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            if (trim($path, '/') === '') {
                continue;
            }

            try {
                if ($userFolder->nodeExists($path) === false) {
                    $this->logger->info(sprintf('Hermiq ContextAssembler: file not found, skipping: %s', $path));
                    continue;
                }

                $node = $userFolder->get($path);
                if (($node instanceof File) === false) {
                    $this->logger->info(sprintf('Hermiq ContextAssembler: path is not a file, skipping: %s', $path));
                    continue;
                }

                $content = (string) $node->getContent();
                if (strlen($content) > self::MAX_FILE_BYTES) {
                    $content = substr($content, 0, self::MAX_FILE_BYTES);
                    $this->logger->info(sprintf('Hermiq ContextAssembler: file capped at %d bytes: %s', self::MAX_FILE_BYTES, $path));
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Hermiq ContextAssembler could not read file %s: %s', $path, $e->getMessage()),
                    ['exception' => $e]
                );
                continue;
            }//end try

            $blocks[] = sprintf("Source: %s\n%s", $path, $content);
        }//end foreach

        return $blocks;

    }//end resolveFiles()

    /**
     * Render each inline `documents` entry (ADR-024) as a titled section identified by
     * its `name`, formatted with the SAME `Source: {identifier}` prefix convention
     * `resolveFiles()` uses for its blocks — so the model sees a uniform section shape
     * across all three source kinds. An entry that is not a valid object, or that lacks
     * a non-empty `name` or `body`, is skipped (logged) — it never aborts the whole
     * assembly, mirroring `resolveFiles()`/`resolveObjectQueries()`. Rendered documents
     * feed the SAME `$sections` collection `assemble()` merges, so they inherit the
     * existing `charBudget`/`needsConsolidation` accounting with no new budget contract
     * and no per-document byte cap. `format` is carried on the schema for future use;
     * every body is currently rendered as plain text (no branching by format).
     *
     * @param mixed $documents The Context's `documents` value.
     *
     * @return array<int, string> One formatted block per valid entry.
     *
     * @spec openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
     */
    private function resolveDocuments(mixed $documents): array
    {
        if (is_array($documents) === false) {
            return [];
        }

        $blocks = [];
        foreach ($documents as $document) {
            if (is_array($document) === false) {
                continue;
            }

            $name = (string) ($document['name'] ?? '');
            $body = (string) ($document['body'] ?? '');
            if ($name === '' || $body === '') {
                $this->logger->info('Hermiq ContextAssembler: document entry missing name/body, skipping.');
                continue;
            }

            $blocks[] = sprintf("Source: %s\n%s", $name, $body);
        }//end foreach

        return $blocks;

    }//end resolveDocuments()

    /**
     * Log the count of declared `viewRefs` — resolution is deferred (see class docblock).
     *
     * @param string $contextId The Context uuid (for the log line).
     * @param mixed  $viewRefs  The Context's `viewRefs` value.
     *
     * @return void
     */
    private function logViewRefs(string $contextId, mixed $viewRefs): void
    {
        if (is_array($viewRefs) === false || $viewRefs === []) {
            return;
        }

        $this->logger->info(
            sprintf(
                'Hermiq ContextAssembler: Context %s declares %d viewRefs — resolution deferred (mirrors Agent.views).',
                $contextId,
                count($viewRefs)
            )
        );

    }//end logViewRefs()

    /**
     * Persist `needsConsolidation` only when the computed value differs from the
     * stored one — avoids an extra OpenRegister write on every single assembly.
     *
     * @param ObjectEntity        $context            The Context object.
     * @param array<string,mixed> $data               The Context's current payload.
     * @param bool                $needsConsolidation The freshly computed flag value.
     *
     * @return void
     *
     * @spec openspec/changes/agent-context-system/tasks.md#task-2-4
     */
    private function persistFlagIfChanged(ObjectEntity $context, array $data, bool $needsConsolidation): void
    {
        $stored = (bool) ($data['needsConsolidation'] ?? false);
        if ($stored === $needsConsolidation) {
            return;
        }

        $data['needsConsolidation'] = $needsConsolidation;

        try {
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::CONTEXT_SCHEMA,
                uuid: (string) $context->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Hermiq ContextAssembler could not persist needsConsolidation for %s: %s', (string) $context->getUuid(), $e->getMessage()),
                ['exception' => $e]
            );
        }

    }//end persistFlagIfChanged()
}//end class
