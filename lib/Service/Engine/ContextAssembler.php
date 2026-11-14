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
 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
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
 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
 */
class ContextAssembler {

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
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param IRootFolder $rootFolder Files root (scoped per acting user).
	 * @param LoggerInterface $logger Logger.
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
	public function setTransientSkillContentOverride(?array $override): void {
		$this->skillContentOverride = $override;

	}//end setTransientSkillContentOverride()

	/**
	 * Assemble every Context an agent references into a single preamble string.
	 *
	 * Null agent or an empty `contextRefs` returns `''` (no-op — most agents have no
	 * attached context). A Context uuid that fails to resolve is skipped (logged), not
	 * fatal — one bad reference must not blank the whole preamble.
	 *
	 * @param ObjectEntity|null $agent Agent object (optional).
	 * @param string $actingUserId The acting user id, for reading `files` from
	 *                             their Nextcloud folder.
	 *
	 * @return string The concatenated preamble (each bundle under a `Context: {name}`
	 *                header), or `''` when the agent has no attached context.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
	 */
	public function assembleForAgent(?ObjectEntity $agent, string $actingUserId): string {
		$blocks = [];
		foreach ($this->contextRefsOf(agent: $agent) as $contextId) {
			$text = $this->contextText(contextId: $contextId, actingUserId: $actingUserId);
			if ($text !== '') {
				$blocks[] = $text;
			}
		}

		return implode("\n\n", $blocks);
	}//end assembleForAgent()

	/**
	 * The Context uuids an agent references; empty for no agent or a malformed value.
	 *
	 * @param ObjectEntity|null $agent Agent object (optional).
	 *
	 * @return array<mixed> The agent's `contextRefs` entries.
	 */
	private function contextRefsOf(?ObjectEntity $agent): array {
		if ($agent === null) {
			return [];
		}

		$contextRefs = ($agent->getObject()['contextRefs'] ?? []);
		if (is_array($contextRefs) === false) {
			return [];
		}

		return $contextRefs;

	}//end contextRefsOf()

	/**
	 * The assembled text of one referenced Context; empty for an invalid reference.
	 *
	 * @param mixed $contextId One `contextRefs` entry.
	 * @param string $actingUserId The acting user id.
	 *
	 * @return string The Context's preamble block, or `''`.
	 */
	private function contextText(mixed $contextId, string $actingUserId): string {
		if (is_string($contextId) === false || $contextId === '') {
			return '';
		}

		return $this->assemble(contextId: $contextId, actingUserId: $actingUserId)['text'];

	}//end contextText()

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
	 * @param ObjectEntity|null $agent Agent object (optional).
	 * @param array<int, string>|null $skillSetOverride Per-run effective-skill-set
	 *                                                  override (skill uuids); null =
	 *                                                  the agent's stored installs
	 *                                                  (every non-eval caller).
	 *
	 * @return array{text?: string, skillsUsed?: array<int, string>} The assembled skill
	 *                                                               block ('' when nothing is exposed) and the uuids actually exposed —
	 *                                                               recorded on the run's audit entry as `skillsUsed` for ALL runs
	 *                                                               (consumed later by skill-learnings). Keys are declared optional so
	 *                                                               the consuming seam (Engine) may defend against partial bundles from
	 *                                                               test doubles or a future assembler swap; this implementation always
	 *                                                               returns both keys.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One skip-and-log loop: each per-skill
	 *   guard (string uuid, resolvable, found, active state, content override) is a
	 *   single flat condition preserving the never-fatal contract.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same: the guards are independent
	 *   skips, multiplying paths without nesting.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-the-engine-run-loop-exposes-the-effective-skill-set-to-a-run
	 */
	public function assembleSkillsForRun(?ObjectEntity $agent, ?array $skillSetOverride = null): array {
		$blocks = [];
		$skillsUsed = [];
		foreach ($this->effectiveSkillIds(agent: $agent, skillSetOverride: $skillSetOverride) as $skillId) {
			if (is_string($skillId) === false || $skillId === '') {
				continue;
			}

			$skill = $this->resolveExposableSkill(skillId: $skillId);
			if ($skill === null) {
				continue;
			}

			$blocks[] = $this->renderSkillBlock(skill: $skill);
			$skillsUsed[] = (string)$skill->getUuid();
		}

		return [
			'text' => implode("\n\n", $blocks),
			'skillsUsed' => $skillsUsed,
		];

	}//end assembleSkillsForRun()

	/**
	 * The skill uuids a run sees: the per-run override when given (even an empty
	 * one), else the agent's stored skillInstalls when they are a list, else none.
	 *
	 * @param ObjectEntity|null       $agent            Agent object (optional).
	 * @param array<int, string>|null $skillSetOverride Per-run override, or null.
	 *
	 * @return array<int, mixed> The candidate skill ids, unvalidated.
	 */
	private function effectiveSkillIds(?ObjectEntity $agent, ?array $skillSetOverride): array {
		if ($skillSetOverride !== null) {
			return $skillSetOverride;
		}

		if ($agent === null) {
			return [];
		}

		$stored = ($agent->getObject()['skillInstalls'] ?? []);
		if (is_array($stored) === true) {
			return $stored;
		}

		return [];

	}//end effectiveSkillIds()

	/**
	 * Load a skill and return it only when it may be exposed to a run. A lookup
	 * that throws is logged as a warning, a missing skill and a skill that is
	 * not active are logged as info; each is skipped, never fatal (marketplace
	 * approval gate: an agent MUST NOT use an unapproved skill).
	 *
	 * @param string $skillId The skill uuid.
	 *
	 * @return ObjectEntity|null The active skill, or null when it is skipped.
	 */
	private function resolveExposableSkill(string $skillId): ?ObjectEntity {
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
			return null;
		}

		if ($skill === null) {
			$this->logger->info(sprintf('Hermiq ContextAssembler: skill not found, skipping: %s', $skillId));
			return null;
		}

		$state = (string)($skill->getObject()['state'] ?? self::SKILL_ACTIVE_STATE);
		if ($state !== self::SKILL_ACTIVE_STATE) {
			$this->logger->info(
				sprintf('Hermiq ContextAssembler: skill %s is %s, not exposed to the run.', $skillId, $state)
			);
			return null;
		}

		return $skill;

	}//end resolveExposableSkill()

	/**
	 * The text block for one exposed skill: `Skill: {name}`, then the description
	 * and the body when present. The paired draft-vs-active eval's draft half
	 * swaps in the DRAFT's content in memory; the stored object was already used
	 * for existence and the marketplace state gate.
	 *
	 * @param ObjectEntity $skill The exposed skill.
	 *
	 * @return string The block.
	 */
	private function renderSkillBlock(ObjectEntity $skill): string {
		$data = $skill->getObject();
		$name = (string)($data['name'] ?? 'skill');
		$description = (string)($data['description'] ?? '');
		$body = (string)($data['body'] ?? '');

		$override = ($this->skillContentOverride[(string)$skill->getUuid()] ?? null);
		if (is_array($override) === true) {
			$name = (string)($override['name'] ?? $name);
			$description = (string)($override['description'] ?? $description);
			$body = (string)($override['body'] ?? $body);
		}

		$block = "Skill: {$name}";
		if ($description !== '') {
			$block .= "\n" . $description;
		}

		if ($body !== '') {
			$block .= "\n\n" . $body;
		}

		return $block;

	}//end renderSkillBlock()

	/**
	 * Resolve ONE Context object into its budgeted preamble text.
	 *
	 * @param string $contextId The Context object uuid.
	 * @param string $actingUserId The acting user id, for reading `files`.
	 *
	 * @return array{text: string, needsConsolidation: bool} The assembled text (headed
	 *                                                       by `Context: {name}`; `''` when the Context cannot be resolved) and
	 *                                                       whether it exceeded the object's charBudget.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
	 */
	public function assemble(string $contextId, string $actingUserId): array {
		$context = $this->objectService->find(
			id: $contextId,
			register: self::REGISTER_SLUG,
			schema: self::CONTEXT_SCHEMA
		);
		if ($context === null) {
			return ['text' => '', 'needsConsolidation' => false];
		}

		$data = $context->getObject();
		$name = (string)($data['name'] ?? 'Context');

		$sections = [];
		$sections = array_merge($sections, $this->resolveObjectQueries(queries: ($data['objectQueries'] ?? [])));
		$sections = array_merge($sections, $this->resolveFiles(files: ($data['files'] ?? []), actingUserId: $actingUserId));
		$sections = array_merge($sections, $this->resolveDocuments(documents: ($data['documents'] ?? [])));

		$this->logViewRefs(contextId: $contextId, viewRefs: ($data['viewRefs'] ?? []));

		$body = implode("\n\n", $sections);
		$text = "Context: {$name}\n" . $body;
		if ($body === '') {
			$text = "Context: {$name}";
		}

		$budget = (int)($data['charBudget'] ?? self::DEFAULT_CHAR_BUDGET);
		$needsConsolidation = (mb_strlen($body) > $budget);

		$this->persistFlagIfChanged(context: $context, data: $data, needsConsolidation: $needsConsolidation);

		return [
			'text' => $text,
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
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
	 */
	private function resolveObjectQueries(mixed $queries): array {
		if (is_array($queries) === false) {
			return [];
		}

		$blocks = [];
		foreach ($queries as $query) {
			if (is_array($query) === true) {
				$blocks = array_merge($blocks, $this->resolveObjectQuery(query: $query));
			}
		}

		return $blocks;
	}//end resolveObjectQueries()

	/**
	 * Resolve one object query into `Source:` blocks; an entry without a register and
	 * schema, or whose read fails, yields none.
	 *
	 * @param array<mixed> $query One `objectQueries` entry.
	 *
	 * @return array<int, string> One formatted block per object found.
	 */
	private function resolveObjectQuery(array $query): array {
		$register = $this->queryPart(query: $query, key: 'register');
		$schema = $this->queryPart(query: $query, key: 'schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		$objects = $this->findQueryObjects(query: $query);
		if ($objects === null) {
			return [];
		}

		return $this->formatQueryObjects(source: $register.'/'.$schema, objects: $objects);

	}//end resolveObjectQuery()

	/**
	 * One string part (`register` or `schema`) of an object query.
	 *
	 * @param array<mixed> $query One `objectQueries` entry.
	 * @param string $key The part to read.
	 *
	 * @return string The part, or `''` when absent.
	 */
	private function queryPart(array $query, string $key): string {
		return (string)($query[$key] ?? '');

	}//end queryPart()

	/**
	 * Read the objects an object query selects; a failed read is logged.
	 *
	 * @param array<mixed> $query One `objectQueries` entry with a register and schema.
	 *
	 * @return array<mixed>|null The objects found, or null when the read failed.
	 */
	private function findQueryObjects(array $query): ?array {
		$register = $this->queryPart(query: $query, key: 'register');
		$schema = $this->queryPart(query: $query, key: 'schema');
		try {
			return $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(config: $this->queryConfig(query: $query));
		} catch (Throwable $e) {
			$this->logQueryFailure(source: $register.'/'.$schema, error: $e);
			return null;
		}

	}//end findQueryObjects()

	/**
	 * The read configuration of an object query: its limit, plus its filters and
	 * search term when given.
	 *
	 * @param array<mixed> $query One `objectQueries` entry.
	 *
	 * @return array<string, mixed> The `findAll` configuration.
	 */
	private function queryConfig(array $query): array {
		$config = ['limit' => (int)($query['limit'] ?? self::DEFAULT_QUERY_LIMIT)];

		$filters = ($query['filters'] ?? []);
		if (is_array($filters) === true && $filters !== []) {
			$config['filters'] = $filters;
		}

		$search = ($query['search'] ?? '');
		if (is_string($search) === true && $search !== '') {
			$config['search'] = $search;
		}

		return $config;

	}//end queryConfig()

	/**
	 * Log a failed object query read.
	 *
	 * @param string $source The query's `register/schema`.
	 * @param Throwable $error The read failure.
	 *
	 * @return void
	 */
	private function logQueryFailure(string $source, Throwable $error): void {
		$this->logger->warning(
			sprintf('Hermiq ContextAssembler query %s failed: %s', $source, $error->getMessage()),
			['exception' => $error]
		);

	}//end logQueryFailure()

	/**
	 * Format each found object as a `Source: {register}/{schema}` block, skipping
	 * anything that is not an object entity.
	 *
	 * @param string $source The query's `register/schema`.
	 * @param array<mixed> $objects The objects found.
	 *
	 * @return array<int, string> One formatted block per object.
	 */
	private function formatQueryObjects(string $source, array $objects): array {
		$blocks = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === true) {
				$blocks[] = sprintf(
					"Source: %s\n%s",
					$source,
					(string)json_encode($object->getObject(), JSON_UNESCAPED_SLASHES)
				);
			}
		}

		return $blocks;

	}//end formatQueryObjects()

	/**
	 * Render each inline `documents` entry as a `Document: {name}` block. An entry that
	 * is not an object, or lacks a non-empty string `name` or `body`, is skipped
	 * (logged); it never aborts the whole assembly. Reads nothing beyond the Context
	 * object itself, so it adds no authorization path.
	 *
	 * @param mixed $documents The Context's `documents` value.
	 *
	 * @return array<int, string> One formatted block per valid document.
	 *
	 * @spec openspec/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble
	 */
	private function resolveDocuments(mixed $documents): array {
		if (is_array($documents) === false) {
			return [];
		}

		$blocks = [];
		foreach ($documents as $index => $document) {
			$block = $this->documentBlock(document: $document);
			if ($block === null) {
				$this->logger->info(sprintf('Hermiq ContextAssembler: document %s has no name or body, skipping', (string)$index));
				continue;
			}

			$blocks[] = $block;
		}

		return $blocks;
	}//end resolveDocuments()

	/**
	 * Render one inline document as a `Document: {name}` block.
	 *
	 * @param mixed $document One `documents` entry.
	 *
	 * @return string|null The block, or null when the name or body is missing or blank.
	 */
	private function documentBlock(mixed $document): ?string {
		$name = trim($this->documentField(document: $document, key: 'name'));
		$body = $this->documentField(document: $document, key: 'body');
		if ($name === '' || trim($body) === '') {
			return null;
		}

		return sprintf("Document: %s\n%s", $name, $body);

	}//end documentBlock()

	/**
	 * One string field of an inline document.
	 *
	 * @param mixed $document One `documents` entry.
	 * @param string $key The field to read.
	 *
	 * @return string The field, or `''` when the entry is not an object or the field is not a string.
	 */
	private function documentField(mixed $document, string $key): string {
		if (is_array($document) === true && is_string($document[$key] ?? null) === true) {
			return $document[$key];
		}

		return '';

	}//end documentField()

	/**
	 * Read each `files` entry from the acting user's Nextcloud folder. A missing file,
	 * a path resolving to a folder, or a read failure is skipped (logged) — it never
	 * aborts the whole assembly.
	 *
	 * @param mixed $files The Context's `files` value.
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
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
	 */
	private function resolveFiles(mixed $files, string $actingUserId): array {
		if (is_array($files) === false || $files === [] || $actingUserId === '') {
			return [];
		}

		$userFolder = $this->openUserFolder(actingUserId: $actingUserId);
		if ($userFolder === null) {
			return [];
		}

		return $this->readFiles(userFolder: $userFolder, files: $files);
	}//end resolveFiles()

	/**
	 * Open the acting user's Nextcloud folder; a failure is logged.
	 *
	 * @param string $actingUserId The acting user id.
	 *
	 * @return Folder|null The user's folder, or null when it cannot be opened.
	 */
	private function openUserFolder(string $actingUserId): ?Folder {
		try {
			return $this->rootFolder->getUserFolder($actingUserId);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq ContextAssembler could not open the folder for %s: %s', $actingUserId, $e->getMessage()),
				['exception' => $e]
			);
			return null;
		}

	}//end openUserFolder()

	/**
	 * Read every `files` entry from the user's folder, skipping the ones that yield nothing.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param array<mixed> $files The Context's `files` entries.
	 *
	 * @return array<int, string> One formatted block per resolved file.
	 */
	private function readFiles(Folder $userFolder, array $files): array {
		$blocks = [];
		foreach ($files as $file) {
			$block = $this->resolveFile(userFolder: $userFolder, file: $file);
			if ($block !== null) {
				$blocks[] = $block;
			}
		}

		return $blocks;

	}//end readFiles()

	/**
	 * Resolve one `files` entry; an entry without a usable path yields nothing.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param mixed $file One `files` entry.
	 *
	 * @return string|null The `Source:` block, or null when skipped.
	 */
	private function resolveFile(Folder $userFolder, mixed $file): ?string {
		if (is_array($file) === false) {
			return null;
		}

		$path = (string)($file['path'] ?? '');
		if (trim($path, '/') === '') {
			return null;
		}

		return $this->readFileBlock(userFolder: $userFolder, path: $path);

	}//end resolveFile()

	/**
	 * Read one file into a `Source: {path}` block; a read failure is logged.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param string $path The file path inside the folder.
	 *
	 * @return string|null The block, or null when the file is missing, not a file or unreadable.
	 */
	private function readFileBlock(Folder $userFolder, string $path): ?string {
		try {
			$content = $this->readFileContent(userFolder: $userFolder, path: $path);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq ContextAssembler could not read file %s: %s', $path, $e->getMessage()),
				['exception' => $e]
			);
			return null;
		}

		if ($content === null) {
			return null;
		}

		return sprintf("Source: %s\n%s", $path, $content);

	}//end readFileBlock()

	/**
	 * The content of one file, capped at the per-file read limit; a missing path or a
	 * path that is not a file is logged and yields null.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param string $path The file path inside the folder.
	 *
	 * @return string|null The (capped) content, or null when skipped.
	 */
	private function readFileContent(Folder $userFolder, string $path): ?string {
		if ($userFolder->nodeExists($path) === false) {
			$this->logger->info(sprintf('Hermiq ContextAssembler: file not found, skipping: %s', $path));
			return null;
		}

		$node = $userFolder->get($path);
		if (($node instanceof File) === false) {
			$this->logger->info(sprintf('Hermiq ContextAssembler: path is not a file, skipping: %s', $path));
			return null;
		}

		return $this->capFileContent(content: (string)$node->getContent(), path: $path);

	}//end readFileContent()

	/**
	 * Cap file content at the per-file read limit, logging when it was cut.
	 *
	 * @param string $content The file content.
	 * @param string $path The file path (for the log line).
	 *
	 * @return string The content, at most MAX_FILE_BYTES long.
	 */
	private function capFileContent(string $content, string $path): string {
		if (strlen($content) <= self::MAX_FILE_BYTES) {
			return $content;
		}

		$capped = substr($content, 0, self::MAX_FILE_BYTES);
		$this->logger->info(sprintf('Hermiq ContextAssembler: file capped at %d bytes: %s', self::MAX_FILE_BYTES, $path));
		return $capped;

	}//end capFileContent()

	/**
	 * Log the count of declared `viewRefs` — resolution is deferred (see class docblock).
	 *
	 * @param string $contextId The Context uuid (for the log line).
	 * @param mixed $viewRefs The Context's `viewRefs` value.
	 *
	 * @return void
	 */
	private function logViewRefs(string $contextId, mixed $viewRefs): void {
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
	 * @param ObjectEntity $context The Context object.
	 * @param array<string,mixed> $data The Context's current payload.
	 * @param bool $needsConsolidation The freshly computed flag value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-context-system/tasks.md#2-contextassembler
	 */
	private function persistFlagIfChanged(ObjectEntity $context, array $data, bool $needsConsolidation): void {
		$stored = (bool)($data['needsConsolidation'] ?? false);
		if ($stored === $needsConsolidation) {
			return;
		}

		$data['needsConsolidation'] = $needsConsolidation;

		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::CONTEXT_SCHEMA,
				uuid: (string)$context->getUuid()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Hermiq ContextAssembler could not persist needsConsolidation for %s: %s', (string)$context->getUuid(), $e->getMessage()),
				['exception' => $e]
			);
		}

	}//end persistFlagIfChanged()
}//end class
