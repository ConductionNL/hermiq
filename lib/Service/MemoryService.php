<?php

/**
 * Hermiq MemoryService.
 *
 * The Hermiq-owned management surface for agent memory (the Hermes MEMORY.md / USER.md
 * + FTS5 session-search port), stored as OpenRegister objects. It provides a
 * char-budget-aware write path that flags a consolidation nudge instead of silently
 * truncating history, tenant-scoped reads, and cross-session recall that reuses
 * OpenRegister's own search substrate (no bespoke SQLite/FTS5 index).
 *
 * Tenant scoping is native: every read and write runs in the caller's session context
 * through OpenRegister ObjectService (single write-path, ADR-001/ADR-004), so
 * owner/organisation/groups are inherited and RBAC denies cross-tenant access. The
 * agent run loop that CONSUMES recall/appends turns is an OpenRegister seam (ADR-001
 * Option C+), not implemented here.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-memory/tasks.md#2-memoryservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads and writes agent memory (Memory/UserProfile/Session/SessionTurn) via OpenRegister.
 *
 * @spec openspec/changes/agent-memory/tasks.md#2-memoryservice
 */
class MemoryService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for agent long-term memory objects.
     *
     * @var string
     */
    private const MEMORY_SCHEMA = 'memory';

    /**
     * Schema slug for user-profile objects.
     *
     * @var string
     */
    private const USER_PROFILE_SCHEMA = 'userprofile';

    /**
     * Schema slug for conversation-session objects (namespaced to avoid a cross-app slug
     * collision — a bare `session` slug resolves to another register's schema).
     *
     * @var string
     */
    private const SESSION_SCHEMA = 'agentsession';

    /**
     * Schema slug for session-turn objects.
     *
     * @var string
     */
    private const SESSION_TURN_SCHEMA = 'agentsessionturn';

    /**
     * Default character budget for a Memory object when none is stored.
     *
     * @var int
     */
    private const DEFAULT_MEMORY_BUDGET = 8000;

    /**
     * Default character budget for a UserProfile object when none is stored.
     *
     * @var int
     */
    private const DEFAULT_PROFILE_BUDGET = 4000;

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OpenRegister object read/write (single write-path).
     */
    public function __construct(
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Get (or create) the Memory object for an agent, scoped to the caller's tenant.
     *
     * @param string $agentId The agent UUID.
     *
     * @return ObjectEntity The agent's Memory object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-1
     */
    public function getMemory(string $agentId): ObjectEntity
    {
        $existing = $this->findOne(schema: self::MEMORY_SCHEMA, filters: ['agentId' => $agentId]);
        if ($existing !== null) {
            return $existing;
        }

        return $this->objectService->saveObject(
            object: [
                'agentId'            => $agentId,
                'entries'            => [],
                'charBudget'         => self::DEFAULT_MEMORY_BUDGET,
                'needsConsolidation' => false,
            ],
            register: self::REGISTER_SLUG,
            schema: self::MEMORY_SCHEMA
        );

    }//end getMemory()

    /**
     * Get (or create) the UserProfile object for an agent + subject user.
     *
     * @param string $agentId    The agent UUID.
     * @param string $subjectUid The user id the profile describes.
     *
     * @return ObjectEntity The UserProfile object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-1
     */
    public function getUserProfile(string $agentId, string $subjectUid): ObjectEntity
    {
        $existing = $this->findOne(
            schema: self::USER_PROFILE_SCHEMA,
            filters: [
                'agentId'    => $agentId,
                'subjectUid' => $subjectUid,
            ]
        );
        if ($existing !== null) {
            return $existing;
        }

        return $this->objectService->saveObject(
            object: [
                'agentId'            => $agentId,
                'subjectUid'         => $subjectUid,
                'entries'            => [],
                'charBudget'         => self::DEFAULT_PROFILE_BUDGET,
                'needsConsolidation' => false,
            ],
            register: self::REGISTER_SLUG,
            schema: self::USER_PROFILE_SCHEMA
        );

    }//end getUserProfile()

    /**
     * Append an entry to an agent's Memory, flagging a consolidation nudge over budget.
     *
     * The entry is ALWAYS persisted; when the total character count exceeds the object's
     * `charBudget` the object is flagged `needsConsolidation=true` (a nudge to summarise)
     * — older entries are never dropped (the spec forbids silent truncation).
     *
     * @param string $agentId The agent UUID.
     * @param string $text    The memory text to append.
     *
     * @return ObjectEntity The persisted Memory object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-2
     */
    public function appendMemoryEntry(string $agentId, string $text): ObjectEntity
    {
        $memory = $this->getMemory(agentId: $agentId);
        return $this->appendEntry(object: $memory, schema: self::MEMORY_SCHEMA, text: $text, defaultBudget: self::DEFAULT_MEMORY_BUDGET);

    }//end appendMemoryEntry()

    /**
     * Append an entry to an agent's UserProfile, flagging a consolidation nudge over budget.
     *
     * @param string $agentId    The agent UUID.
     * @param string $subjectUid The subject user id.
     * @param string $text       The profile text to append.
     *
     * @return ObjectEntity The persisted UserProfile object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-2
     */
    public function appendUserProfileEntry(string $agentId, string $subjectUid, string $text): ObjectEntity
    {
        $profile = $this->getUserProfile(agentId: $agentId, subjectUid: $subjectUid);
        return $this->appendEntry(object: $profile, schema: self::USER_PROFILE_SCHEMA, text: $text, defaultBudget: self::DEFAULT_PROFILE_BUDGET);

    }//end appendUserProfileEntry()

    /**
     * Replace an agent's Memory entries with a consolidated set and clear the nudge.
     *
     * The consolidation STRATEGY (summarise vs. prune) is the caller's: an operator or an
     * OpenRegister agent turn supplies the consolidated entries. This method applies them
     * and recomputes the flag — it does not itself summarise (that is the OR run-loop seam).
     *
     * @param string                    $agentId The agent UUID.
     * @param array<int, array<string>> $entries The consolidated entries ([{text, createdAt}]).
     *
     * @return ObjectEntity The persisted Memory object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-3
     */
    public function consolidateMemory(string $agentId, array $entries): ObjectEntity
    {
        $memory = $this->getMemory(agentId: $agentId);
        $data   = $memory->getObject();

        $budget          = (int) ($data['charBudget'] ?? self::DEFAULT_MEMORY_BUDGET);
        $normalised      = $this->normaliseEntries(entries: $entries);
        $data['entries'] = $normalised;
        // After an explicit consolidation the nudge is cleared unless the new set is
        // STILL over budget (a no-op consolidation should not falsely clear it).
        $data['needsConsolidation'] = ($this->countCharacters(entries: $normalised) > $budget);

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::MEMORY_SCHEMA,
            uuid: (string) $memory->getUuid()
        );

    }//end consolidateMemory()

    /**
     * Start a conversation Session for an agent.
     *
     * @param string $agentId The agent UUID.
     * @param string $title   The session title.
     *
     * @return ObjectEntity The persisted Session object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-4
     */
    public function startSession(string $agentId, string $title): ObjectEntity
    {
        $now = $this->now();
        return $this->objectService->saveObject(
            object: [
                'agentId'        => $agentId,
                'title'          => $title,
                'startedAt'      => $now,
                'lastActivityAt' => $now,
            ],
            register: self::REGISTER_SLUG,
            schema: self::SESSION_SCHEMA
        );

    }//end startSession()

    /**
     * Record a SessionTurn and touch the parent Session's last-activity timestamp.
     *
     * @param string $sessionId The parent Session UUID.
     * @param string $agentId   The agent UUID (denormalised for recall filtering).
     * @param string $role      The turn role (user|assistant|system|tool).
     * @param string $content   The turn content.
     *
     * @return ObjectEntity The persisted SessionTurn object.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-4
     */
    public function recordTurn(string $sessionId, string $agentId, string $role, string $content): ObjectEntity
    {
        $now  = $this->now();
        $turn = $this->objectService->saveObject(
            object: [
                'sessionId' => $sessionId,
                'agentId'   => $agentId,
                'role'      => $role,
                'content'   => $content,
                'createdAt' => $now,
            ],
            register: self::REGISTER_SLUG,
            schema: self::SESSION_TURN_SCHEMA
        );

        $session = $this->findOne(schema: self::SESSION_SCHEMA, filters: ['uuid' => $sessionId]);
        if ($session !== null) {
            $data = $session->getObject();
            $data['lastActivityAt'] = $now;
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::SESSION_SCHEMA,
                uuid: (string) $session->getUuid()
            );
        }

        return $turn;

    }//end recordTurn()

    /**
     * List an agent's Sessions, scoped to the caller's tenant.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, ObjectEntity> The agent's Session objects.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-4
     */
    public function listSessions(string $agentId): array
    {
        return $this->findMany(schema: self::SESSION_SCHEMA, filters: ['agentId' => $agentId]);

    }//end listSessions()

    /**
     * List an agent's UserProfiles, scoped to the caller's tenant.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, ObjectEntity> The agent's UserProfile objects.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-1
     */
    public function listUserProfiles(string $agentId): array
    {
        return $this->findMany(schema: self::USER_PROFILE_SCHEMA, filters: ['agentId' => $agentId]);

    }//end listUserProfiles()

    /**
     * Recall relevant SessionTurns for an agent via OpenRegister's own search substrate.
     *
     * Reuses ObjectService search (the same substrate VectorizationService builds on) — NO
     * bespoke SQLite/FTS5 index. The query runs in the caller's session context (RBAC +
     * multitenancy ON), so turns from another organisation are never returned.
     *
     * @param string $agentId The agent UUID.
     * @param string $query   The recall query.
     * @param int    $limit   Maximum turns to return.
     *
     * @return array<int, ObjectEntity> The matching SessionTurn objects (tenant-scoped).
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-5
     */
    public function recallSessions(string $agentId, string $query, int $limit=20): array
    {
        return $this->findMany(
            schema: self::SESSION_TURN_SCHEMA,
            filters: ['agentId' => $agentId],
            search: $query,
            limit: $limit
        );

    }//end recallSessions()

    /**
     * Append an entry to a Memory/UserProfile object and recompute the consolidation flag.
     *
     * @param ObjectEntity $object        The Memory/UserProfile object.
     * @param string       $schema        The schema slug to save under.
     * @param string       $text          The entry text.
     * @param int          $defaultBudget The budget to assume when the object stores none.
     *
     * @return ObjectEntity The persisted object.
     */
    private function appendEntry(ObjectEntity $object, string $schema, string $text, int $defaultBudget): ObjectEntity
    {
        $data    = $object->getObject();
        $entries = $this->normaliseEntries(entries: ($data['entries'] ?? []));

        $entries[] = [
            'text'      => $text,
            'createdAt' => $this->now(),
        ];

        $budget          = (int) ($data['charBudget'] ?? $defaultBudget);
        $data['entries'] = $entries;
        $data['needsConsolidation'] = ($this->countCharacters(entries: $entries) > $budget);

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: $schema,
            uuid: (string) $object->getUuid()
        );

    }//end appendEntry()

    /**
     * Total character count across all entry texts.
     *
     * @param array<int, mixed> $entries The entries.
     *
     * @return int The summed character length of the entry texts.
     */
    private function countCharacters(array $entries): int
    {
        $total = 0;
        foreach ($entries as $entry) {
            if (is_array($entry) === true && isset($entry['text']) === true) {
                $total += mb_strlen((string) $entry['text']);
            }
        }

        return $total;

    }//end countCharacters()

    /**
     * Coerce a raw entries value into a list of {text, createdAt} maps.
     *
     * @param mixed $entries The stored/supplied entries value.
     *
     * @return array<int, array<string, string>> The normalised entries.
     */
    private function normaliseEntries(mixed $entries): array
    {
        if (is_array($entries) === false) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (is_array($entry) === false || isset($entry['text']) === false) {
                continue;
            }

            $out[] = [
                'text'      => (string) $entry['text'],
                'createdAt' => (string) ($entry['createdAt'] ?? $this->now()),
            ];
        }

        return $out;

    }//end normaliseEntries()

    /**
     * Find the first matching object, or null.
     *
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters The equality filters.
     *
     * @return ObjectEntity|null The first match, or null.
     */
    private function findOne(string $schema, array $filters): ?ObjectEntity
    {
        $matches = $this->findMany(schema: $schema, filters: $filters, limit: 1);
        if ($matches === []) {
            return null;
        }

        return $matches[0];

    }//end findOne()

    /**
     * Find matching objects in the caller's tenant context (RBAC + multitenancy ON).
     *
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters The equality filters.
     * @param string|null         $search  Optional full-text search term.
     * @param int                 $limit   Maximum objects to return.
     *
     * @return array<int, ObjectEntity> The matching objects.
     */
    private function findMany(string $schema, array $filters, ?string $search=null, int $limit=100): array
    {
        $config = [
            'filters' => $filters,
            'limit'   => $limit,
        ];
        if ($search !== null && $search !== '') {
            $config['search'] = $search;
        }

        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema($schema)
            ->findAll(config: $config);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end findMany()

    /**
     * The current UTC timestamp in ISO-8601.
     *
     * @return string The ISO-8601 timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    }//end now()
}//end class
