<?php

/**
 * Hermiq Context Assembler.
 *
 * Resolves a `Context` object (hermiq register, schema `context` — agent-context-system,
 * SPECTR-NEXTCLOUD-PLAN.md §6.4) into a budgeted text preamble at run start: runs each
 * `objectQueries` entry through `ObjectService` (the same public surface `MemoryService`/
 * `ContextRetrievalHandler` already use), reads each `files` entry from the acting user's
 * Nextcloud folder via `IRootFolder` (the same public OCP surface
 * `HermiqToolProvider::readFile()` already uses), and concatenates everything under a
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
 * skill-evals additionally lands the run-loop skill-exposure seam here
 * (`assembleSkillsForRun()`): the effective skill set — a per-run override when supplied
 * (paired eval halves), otherwise the agent's stored `skillInstalls` — is resolved and
 * each `state: active` skill's content (name/description + body) is injected into the
 * run's system context. Non-active skills (quarantined/stale/archived) are NEVER exposed,
 * preserving the marketplace approval gate. Context exposure only — no skill
 * tool-calling semantics.
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every resolver (skills, object
 *   queries, files) is a skip-and-log loop whose per-entry defensive guards each add a
 *   branch; the class-wide sum crosses the threshold without any deep nesting.
 *
 * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
     * Schema slug for Skill objects (namespaced to avoid a cross-app slug collision).
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * The ONLY skill lifecycle state the run loop may expose (skills-marketplace
     * approval gate: quarantined/stale/archived skills never reach a run context).
     *
     * @var string
     */
    private const SKILL_ACTIVE_STATE = 'active';

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
     * Per-run, IN-MEMORY skill CONTENT override (skill-self-improvement): map of
     * skill uuid → `{name, description, body}` used INSTEAD of the stored content
     * when that skill is assembled. The thin adapter the paired draft-vs-active
     * eval sets around its draft half — the stored Skill object is never written,
     * mirroring the skill-set override's in-memory-only contract. Always null for
     * every non-draft-eval caller.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $skillContentOverride = null;

    /**
     * Set (or clear, with null) the transient per-run skill content override.
     *
     * Halves run strictly sequentially (impersonation is not concurrency-safe
     * already), so a set→run→clear window can never leak into another run. The
     * caller MUST clear it in a `finally` block.
     *
     * @param array<string, array<string, mixed>>|null $override Map of skill uuid →
     *                                                           draft content, or null.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
     */
    public function setTransientSkillContentOverride(?array $override): void
    {
        $this->skillContentOverride = $override;

    }//end setTransientSkillContentOverride()

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
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
     * Resolve a run's effective skill set and assemble the exposed skills' content
     * into a system-context block (the run-loop skill-exposure seam, skill-evals).
     *
     * The effective set is the per-run override when supplied (a paired eval half:
     * with = installed ∪ linked, without = per the agent's evalBaselineMode),
     * otherwise the agent's stored `skillInstalls` — so every non-eval caller
     * (schedule tick, Run now, chat, webhook, flow) exposes the stored installs,
     * which is the run-loop consumption the skills-catalog spec reserved. ONLY
     * `state: active` skills are exposed; a quarantined/stale/archived skill
     * referenced by an install or an override is skipped (logged), preserving the
     * marketplace approval gate. A skill uuid that fails to resolve is skipped
     * (logged), never fatal. Context exposure only — no tool-calling semantics.
     *
     * @param ObjectEntity|null       $agent            Agent object (optional).
     * @param array<int, string>|null $skillSetOverride Per-run effective-skill-set
     *                                                  override (skill uuids); null =
     *                                                  the agent's stored installs
     *                                                  (every non-eval caller).
     *
     * @return array{text?: string, skillsUsed?: array<int, string>} The assembled skill
     *         block ('' when nothing is exposed) and the uuids actually exposed —
     *         recorded on the run's audit entry as `skillsUsed` for ALL runs
     *         (consumed later by skill-learnings). Keys are declared optional so
     *         the consuming seam (Engine) may defend against partial bundles from
     *         test doubles or a future assembler swap; this implementation always
     *         returns both keys.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One skip-and-log loop: each per-skill
     *   guard (string uuid, resolvable, found, active state, content override) is a
     *   single flat condition preserving the never-fatal contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: the guards are independent
     *   skips, multiplying paths without nesting.
     *
     * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
     */
    public function assembleSkillsForRun(?ObjectEntity $agent, ?array $skillSetOverride=null): array
    {
        $effective = $skillSetOverride;
        if ($effective === null) {
            $effective = [];
            if ($agent !== null) {
                $stored = ($agent->getObject()['skillInstalls'] ?? []);
                if (is_array($stored) === true) {
                    $effective = $stored;
                }
            }
        }

        $blocks     = [];
        $skillsUsed = [];
        foreach ($effective as $skillId) {
            if (is_string($skillId) === false || $skillId === '') {
                continue;
            }

            try {
                $skill = $this->objectService->find(
                    id: $skillId,
                    register: self::REGISTER_SLUG,
                    schema: self::SKILL_SCHEMA,
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf('Hermiq ContextAssembler could not resolve skill %s: %s', $skillId, $e->getMessage()),
                    ['exception' => $e]
                );
                continue;
            }

            if ($skill === null) {
                $this->logger->info(sprintf('Hermiq ContextAssembler: skill not found, skipping: %s', $skillId));
                continue;
            }

            $data  = $skill->getObject();
            $state = (string) ($data['state'] ?? self::SKILL_ACTIVE_STATE);
            if ($state !== self::SKILL_ACTIVE_STATE) {
                // Marketplace approval gate: an agent MUST NOT use an unapproved skill —
                // neither via install nor via a dataset's skillRefs reaching an override.
                $this->logger->info(
                    sprintf('Hermiq ContextAssembler: skill %s is %s, not exposed to the run.', $skillId, $state)
                );
                continue;
            }

            $name        = (string) ($data['name'] ?? 'skill');
            $description = (string) ($data['description'] ?? '');
            $body        = (string) ($data['body'] ?? '');

            // Skill-self-improvement: the paired draft-vs-active eval's draft half
            // swaps in the DRAFT's content in memory — the stored object above was
            // still consulted for existence and the marketplace state gate.
            $override = ($this->skillContentOverride[(string) $skill->getUuid()] ?? null);
            if (is_array($override) === true) {
                $name        = (string) ($override['name'] ?? $name);
                $description = (string) ($override['description'] ?? $description);
                $body        = (string) ($override['body'] ?? $body);
            }

            $block = "Skill: {$name}";
            if ($description !== '') {
                $block .= "\n".$description;
            }

            if ($body !== '') {
                $block .= "\n\n".$body;
            }

            $blocks[]     = $block;
            $skillsUsed[] = (string) $skill->getUuid();
        }//end foreach

        return [
            'text'       => implode("\n\n", $blocks),
            'skillsUsed' => $skillsUsed,
        ];

    }//end assembleSkillsForRun()

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
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One skip-and-log loop: each per-query
     *   guard (array shape, register/schema present, optional filters/search, read
     *   failure) is a single flat condition preserving the never-fatal contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: independent skips multiply
     *   paths without nesting.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
     * Read each `files` entry from the acting user's Nextcloud folder. A missing file,
     * a path resolving to a folder, or a read failure is skipped (logged) — it never
     * aborts the whole assembly.
     *
     * @param mixed  $files        The Context's `files` value.
     * @param string $actingUserId The acting user id.
     *
     * @return array<int, string> One formatted block per resolved file.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One skip-and-log loop: each per-file
     *   guard (array shape, non-empty path, exists, is-a-file, size cap, read failure)
     *   is a single flat condition preserving the never-fatal contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: independent skips multiply
     *   paths without nesting.
     *
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
     * @spec openspec/changes/agent-context-system/tasks.md#2-contextassembler
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
