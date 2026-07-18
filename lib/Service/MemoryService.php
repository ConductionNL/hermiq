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
use OCP\IUserSession;

/**
 * Reads and writes agent memory (Memory/UserProfile/Session/SessionTurn) via OpenRegister.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     One class owns the full read/write/
 *   recall/forget surface for four related OpenRegister schemas (Memory, UserProfile,
 *   Session, SessionTurn) so callers (HermiqToolProvider, MemoryController) have a
 *   single collaborator rather than four near-identical services.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is the sum of many
 *   small, single-purpose read/write/recall methods over those four schemas, each
 *   independently simple and unit-tested; agent-memory-tools' additions
 *   (forgetEntry/recallEntries/soft-delete helpers) follow the same shape.
 *
 * @spec openspec/changes/agent-memory/tasks.md#2-memoryservice
 * @spec openspec/changes/agent-memory-tools/tasks.md#task-2
 * @spec openspec/changes/agent-memory-tools/tasks.md#task-3
 * @spec openspec/changes/agent-memory-tools/tasks.md#task-4
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
     * Schema slug for conversation objects — the LIVE session store.
     *
     * "Session" is the user-facing word for what the engine persists as a
     * `Conversation`. There is no second store: the `agentsession`/`agentsessionturn`
     * schemas ADR-003 mandated were created, read by `listSessions()`/`recallSessions()`,
     * and never written by anything — `startSession()`/`recordTurn()` had zero callers,
     * so both tables held 0 rows on the reference instance while `conversation` held 184
     * and `message` held 297. Everything reading the session store therefore returned
     * empty, silently and by construction.
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * Schema slug for message objects — the LIVE turn store.
     *
     * @var string
     */
    private const MESSAGE_SCHEMA = 'message';

    /**
     * OpenRegister's hard cap on expressions in a single `IN ()` list.
     *
     * Exceeding it is not a slow query, it is a broken one, so the recall join chunks its
     * conversation-id filter rather than truncating it.
     *
     * @var int
     */
    private const OR_IN_LIST_CAP = 1000;

    /**
     * How many of the caller's conversations a single recall will consider.
     *
     * Bounds the first of recall's two queries. Stated rather than left to `findMany()`'s
     * default so that a heavy user degrades to "recall over your most recent
     * conversations" instead of an unbounded scan.
     *
     * @var int
     */
    private const RECALL_CONVERSATION_SCAN_LIMIT = 500;

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
     * @param ObjectService    $objectService    OpenRegister object read/write (single write-path).
     * @param RedactionService $redactionService Applied to every entry BEFORE persist inside
     *                                           `appendEntry()` (agent-memory-tools) — closes the
     *                                           gap where operator-seeded memory previously bypassed
     *                                           redaction entirely.
     * @param IUserSession     $userSession      Resolves the requesting user so `listSessions()`/
     *                                           `recallSessions()` can scope to the caller's OWN
     *                                           Session/SessionTurn objects (`@self.owner`) — those
     *                                           schemas have no user/owner property, so this is the
     *                                           only guard — and `listUserProfiles()` can scope to
     *                                           the caller's own `subjectUid`.
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-2
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RedactionService $redactionService,
        private readonly IUserSession $userSession,
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
     * List an agent's sessions — the caller's own `Conversation` objects for that agent.
     *
     * Reads the live store. This used to read `agentsession`, which nothing ever wrote,
     * so it always returned an empty list.
     *
     * Scoped on the `userId` PROPERTY, deliberately, not on the `@self.owner` meta-filter
     * the sibling lookups use. `Session` carried no user property, which is why owner
     * scoping was the only option there; `Conversation` carries `userId`, and it is the
     * accurate one. On the reference instance all 184 conversations have `userId = admin`
     * while only 49 have `_owner = admin` — the other 135 are owned by `__system__`,
     * because the engine writes them from paths with no session user. Scoping this on
     * `@self.owner` would therefore hide 73% of a user's own sessions, and hide them
     * silently, which is the failure this whole change exists to stop.
     *
     * Still cross-user safe: `userId` is filtered server-side against the resolved session
     * UID and is written by the engine from that same session — never from request input.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, ObjectEntity> The CALLER's own Conversation objects for this agent.
     *
     * @spec openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-session-listing-reads-the-live-conversation-store
     */
    public function listSessions(string $agentId): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // Fail CLOSED: no session context means there is no safe user to scope to,
            // so this returns nothing rather than every tenant's sessions. The caller
            // (MemoryController::sessions()) already 401s before reaching here — this is
            // a belt-and-braces guard on the service seam itself.
            return [];
        }

        return $this->findMany(
            schema: self::CONVERSATION_SCHEMA,
            filters: [
                'agentId' => $agentId,
                'userId'  => $user->getUID(),
            ]
        );

    }//end listSessions()

    /**
     * List an agent's UserProfiles, scoped to the caller's tenant AND to the caller's
     * OWN UserProfile — `agentId` alone would return every user's UserProfile for
     * that agent (a cross-user disclosure). `UserProfile` DOES carry a `subjectUid`
     * property (unlike Session/SessionTurn), so this follows the same idiom as the
     * sibling `getUserProfile()` lookup rather than needing `@self.owner`.
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, ObjectEntity> The CALLER's own UserProfile object(s) for this agent.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-2-1
     */
    public function listUserProfiles(string $agentId): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // Fail CLOSED: no session context means there is no safe subjectUid to scope
            // to, so this returns nothing rather than every user's UserProfile. Mirrors
            // `listSessions()`'s belt-and-braces guard on this service seam.
            return [];
        }

        return $this->findMany(
            schema: self::USER_PROFILE_SCHEMA,
            filters: [
                'agentId'    => $agentId,
                'subjectUid' => $user->getUID(),
            ]
        );

    }//end listUserProfiles()

    /**
     * Recall relevant SessionTurns for an agent via OpenRegister's own search substrate.
     *
     * Reuses ObjectService search (the same substrate VectorizationService builds on) — NO
     * bespoke SQLite/FTS5 index. Reads the live store: this used to search
     * `agentsessionturn`, which nothing ever wrote, so `hermiq.recallMemory` has never
     * returned a turn in its life — it failed by returning nothing, which reads exactly
     * like "no match".
     *
     * Two queries, because `Message` carries neither `agentId` nor `userId` — only
     * `conversationId`. The agent binding and the ownership scope both live on
     * `Conversation`, so the caller's conversations for this agent are resolved first and
     * the message search is restricted to their ids. Fails CLOSED (no user ⇒ no recall).
     *
     * Behavioural trade-off (unchanged from the version this replaces): recallSessions has
     * two callers — MemoryController::recall() (user-facing) and HermiqToolProvider (the
     * AGENT calls it as an MCP tool during a run). Scoping to the actor means an agent
     * recalls only the run actor's own history ("identity, never stale authority"). A
     * scheduled/background run with no resolvable user therefore recalls NOTHING rather
     * than leaking across users — an intentional loss of recall, not a bug.
     *
     * @param string $agentId The agent UUID.
     * @param string $query   The recall query.
     * @param int    $limit   Maximum turns to return.
     *
     * @return array<int, ObjectEntity> The matching Message objects (tenant-scoped).
     *
     * @spec openspec/changes/session-store-consolidation/specs/agent-memory/spec.md#requirement-cross-session-recall-via-or-search
     */
    public function recallSessions(string $agentId, string $query, int $limit=20): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $conversations = $this->findMany(
            schema: self::CONVERSATION_SCHEMA,
            filters: [
                'agentId' => $agentId,
                'userId'  => $user->getUID(),
            ],
            limit: self::RECALL_CONVERSATION_SCAN_LIMIT
        );

        $conversationIds = [];
        foreach ($conversations as $conversation) {
            $uuid = (string) $conversation->getUuid();
            if ($uuid !== '') {
                $conversationIds[] = $uuid;
            }
        }

        if (empty($conversationIds) === true) {
            // No conversations ⇒ no turns. Returning here is not just an optimisation: an
            // empty `conversationId` filter list is an UNSCOPED message query, which would
            // recall every user's turns.
            return [];
        }

        // OpenRegister caps an `IN ()` list at 1000 expressions, so a caller with more
        // conversations than that must be chunked rather than silently truncated.
        $turns = [];
        foreach (array_chunk($conversationIds, self::OR_IN_LIST_CAP) as $chunk) {
            $turns = array_merge(
                $turns,
                $this->findMany(
                    schema: self::MESSAGE_SCHEMA,
                    filters: ['conversationId' => $chunk],
                    search: $query,
                    limit: $limit
                )
            );

            if (count($turns) >= $limit) {
                break;
            }
        }

        return array_slice($turns, 0, $limit);

    }//end recallSessions()

    /**
     * Recall matching, non-soft-deleted Memory/UserProfile entries for an agent via the
     * SAME OpenRegister search substrate `recallSessions()` already uses — no second
     * search index, no vector store. Object-level `ObjectService` search resolves
     * candidate Memory/UserProfile objects; entry-level filtering (query substring,
     * excluding soft-deleted entries) narrows the result to the matching entries within
     * them. `hermiq.recallMemory` merges this with `recallSessions()`'s turn matches into
     * one combined tool result (design.md Decision 5).
     *
     * @param string      $agentId    The agent UUID.
     * @param string|null $subjectUid The acting user id whose own UserProfile is also
     *                                searched, or null to search only the agent's Memory.
     * @param string      $query      The recall query.
     * @param int         $limit      Maximum objects to search per schema.
     *
     * @return array{memoryEntries: array<int, array<string, string>>, userProfileEntries: array<int, array<string, string>>}
     *         Matching entries, tenant-scoped for free (unchanged ObjectService caller-context RBAC).
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-4
     */
    public function recallEntries(string $agentId, ?string $subjectUid, string $query, int $limit=20): array
    {
        $memoryEntries = [];
        foreach ($this->findMany(schema: self::MEMORY_SCHEMA, filters: ['agentId' => $agentId], search: $query, limit: $limit) as $object) {
            $memoryEntries = array_merge($memoryEntries, $this->matchingEntries(object: $object, query: $query));
        }

        $userProfileEntries = [];
        if ($subjectUid !== null && $subjectUid !== '') {
            $profileFilters = [
                'agentId'    => $agentId,
                'subjectUid' => $subjectUid,
            ];
            foreach ($this->findMany(schema: self::USER_PROFILE_SCHEMA, filters: $profileFilters, search: $query, limit: $limit) as $object) {
                $userProfileEntries = array_merge($userProfileEntries, $this->matchingEntries(object: $object, query: $query));
            }
        }

        return [
            'memoryEntries'      => $memoryEntries,
            'userProfileEntries' => $userProfileEntries,
        ];

    }//end recallEntries()

    /**
     * Soft-delete one memory entry by id (never a hard delete): sets `deletedAt`,
     * leaving the entry present in the stored `entries` array (and therefore in
     * OpenRegister's AuditTrail history) for audit purposes.
     *
     * Scoped to the agent's own Memory object and, when `$subjectUid` is supplied,
     * the ACTING user's own UserProfile object for that agent — never any other
     * subject user's UserProfile (design.md Decision 3, matching every other
     * `HermiqToolProvider` tool's IDOR posture). An id matching nothing in either
     * object is a soft failure (not-found), never an exception — mirrors
     * `ContextAssembler`'s "one bad reference is skipped, not fatal" posture.
     *
     * @param string      $agentId    The agent UUID.
     * @param string|null $subjectUid The acting user id whose own UserProfile may also
     *                                be searched, or null to search only the agent's Memory.
     * @param string      $entryId    The entry id to soft-delete.
     *
     * @return array{found: bool, scope: (string|null)} Whether a match was found, and
     *         in which object ('memory'|'userProfile') when it was.
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-3
     */
    public function forgetEntry(string $agentId, ?string $subjectUid, string $entryId): array
    {
        if (trim($entryId) === '') {
            return ['found' => false, 'scope' => null];
        }

        $memory      = $this->getMemory(agentId: $agentId);
        $memoryFound = $this->softDeleteEntry(
            object: $memory,
            schema: self::MEMORY_SCHEMA,
            entryId: $entryId,
            defaultBudget: self::DEFAULT_MEMORY_BUDGET
        );
        if ($memoryFound === true) {
            return ['found' => true, 'scope' => 'memory'];
        }

        if ($subjectUid !== null && $subjectUid !== '') {
            $profile      = $this->getUserProfile(agentId: $agentId, subjectUid: $subjectUid);
            $profileFound = $this->softDeleteEntry(
                object: $profile,
                schema: self::USER_PROFILE_SCHEMA,
                entryId: $entryId,
                defaultBudget: self::DEFAULT_PROFILE_BUDGET
            );
            if ($profileFound === true) {
                return ['found' => true, 'scope' => 'userProfile'];
            }
        }

        return ['found' => false, 'scope' => null];

    }//end forgetEntry()

    /**
     * Append an entry to a Memory/UserProfile object and recompute the consolidation flag.
     *
     * Redacts the entry text BEFORE persist (`RedactionService::redact()`, ADR-004's
     * redaction-before-persist invariant) so EVERY caller of this single funnel method —
     * the `hermiq.rememberMemory` tool and the existing operator-facing
     * `MemoryController::addMemory()` endpoint alike — gets the same guarantee, closing a
     * gap that existed before agent-memory-tools (operator-seeded memory was not
     * redacted). Each new entry also gets a freshly-generated, stable `id` so it can
     * later be addressed by `hermiq.forgetMemory`.
     *
     * @param ObjectEntity $object        The Memory/UserProfile object.
     * @param string       $schema        The schema slug to save under.
     * @param string       $text          The entry text.
     * @param int          $defaultBudget The budget to assume when the object stores none.
     *
     * @return ObjectEntity The persisted object.
     *
     * @spec openspec/changes/agent-memory-tools/specs/agent-memory/spec.md#requirement-memory-writes-are-redacted-before-persist
     */
    private function appendEntry(ObjectEntity $object, string $schema, string $text, int $defaultBudget): ObjectEntity
    {
        $data    = $object->getObject();
        $entries = $this->normaliseEntries(entries: ($data['entries'] ?? []));

        $entries[] = [
            'id'        => $this->generateEntryId(),
            'text'      => $this->redactionService->redact(text: $text),
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
     * Soft-delete the entry matching `$entryId` inside one Memory/UserProfile object.
     *
     * @param ObjectEntity $object        The Memory/UserProfile object to search.
     * @param string       $schema        The schema slug to save under.
     * @param string       $entryId       The entry id to soft-delete.
     * @param int          $defaultBudget The budget to assume when the object stores none.
     *
     * @return bool True when a matching entry was found (and soft-deleted, or was
     *              already soft-deleted — idempotent); false when no entry in this
     *              object carries `$entryId`.
     */
    private function softDeleteEntry(ObjectEntity $object, string $schema, string $entryId, int $defaultBudget): bool
    {
        $data    = $object->getObject();
        $entries = $this->normaliseEntries(entries: ($data['entries'] ?? []));

        $matchIndex = null;
        foreach ($entries as $index => $entry) {
            if (($entry['id'] ?? '') === $entryId) {
                $matchIndex = $index;
                break;
            }
        }

        if ($matchIndex === null) {
            return false;
        }

        if ($this->isDeleted(entry: $entries[$matchIndex]) === true) {
            // Already forgotten — idempotent no-op, still a "found" result.
            return true;
        }

        $entries[$matchIndex]['deletedAt'] = $this->now();

        $budget          = (int) ($data['charBudget'] ?? $defaultBudget);
        $data['entries'] = $entries;
        $data['needsConsolidation'] = ($this->countCharacters(entries: $entries) > $budget);

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: $schema,
            uuid: (string) $object->getUuid()
        );

        return true;

    }//end softDeleteEntry()

    /**
     * Non-soft-deleted entries within one Memory/UserProfile object whose text
     * contains `$query` (case-insensitive substring match) — the object-level
     * `ObjectService` search already narrowed the candidate objects; this narrows to
     * the matching entries inside them.
     *
     * @param ObjectEntity $object The Memory/UserProfile object.
     * @param string       $query  The recall query.
     *
     * @return array<int, array<string, string>> The matching, non-soft-deleted entries.
     */
    private function matchingEntries(ObjectEntity $object, string $query): array
    {
        $entries = $this->normaliseEntries(entries: ($object->getObject()['entries'] ?? []));

        $out = [];
        foreach ($entries as $entry) {
            if ($this->isDeleted(entry: $entry) === true) {
                continue;
            }

            if ($query !== '' && stripos($entry['text'], $query) === false) {
                continue;
            }

            $out[] = $entry;
        }

        return $out;

    }//end matchingEntries()

    /**
     * Total character count across all NON-SOFT-DELETED entry texts.
     *
     * Soft-deleted entries (`deletedAt` set) are excluded so a forgotten fact never
     * keeps counting toward `needsConsolidation` (agent-memory-tools).
     *
     * @param array<int, mixed> $entries The entries.
     *
     * @return int The summed character length of the non-soft-deleted entry texts.
     */
    private function countCharacters(array $entries): int
    {
        $total = 0;
        foreach ($entries as $entry) {
            if (is_array($entry) === true && isset($entry['text']) === true && $this->isDeleted(entry: $entry) === false) {
                $total += mb_strlen((string) $entry['text']);
            }
        }

        return $total;

    }//end countCharacters()

    /**
     * Coerce a raw entries value into a list of {id?, text, createdAt, deletedAt?} maps.
     *
     * `id`/`deletedAt` are preserved when present (agent-memory-tools) but stay
     * optional so entries appended before this change — which carry neither — remain
     * valid and simply unforgettable-by-id until they are naturally re-appended
     * (proposal.md Risk 2).
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

            $normalised = [
                'text'      => (string) $entry['text'],
                'createdAt' => (string) ($entry['createdAt'] ?? $this->now()),
            ];

            if (isset($entry['id']) === true && $entry['id'] !== '') {
                $normalised['id'] = (string) $entry['id'];
            }

            if (isset($entry['deletedAt']) === true && $entry['deletedAt'] !== '') {
                $normalised['deletedAt'] = (string) $entry['deletedAt'];
            }

            $out[] = $normalised;
        }

        return $out;

    }//end normaliseEntries()

    /**
     * Whether a normalised entry has been soft-deleted (`deletedAt` set and non-empty).
     *
     * @param array<string, mixed> $entry A normalised entry (see `normaliseEntries()`).
     *
     * @return bool True when the entry is soft-deleted.
     */
    private function isDeleted(array $entry): bool
    {
        return isset($entry['deletedAt']) === true && $entry['deletedAt'] !== '';

    }//end isDeleted()

    /**
     * Generate a random UUID v4 (pure PHP — no Symfony/Ramsey uuid dependency exists in
     * Hermiq's own composer.json, matching `WebhookTriggerController::generateCorrelationId()`)
     * for a freshly-appended memory entry's stable `id`.
     *
     * @return string The generated UUID v4.
     *
     * @spec openspec/changes/agent-memory-tools/design.md#decision-2-entry-level-id--deletedat-not-a-separate-or-object-per-entry
     */
    private function generateEntryId(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end generateEntryId()

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
