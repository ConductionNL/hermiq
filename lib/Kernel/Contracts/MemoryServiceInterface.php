<?php

/**
 * Contract of MemoryService
 *
 * The public surface of MemoryService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of MemoryService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MemoryServiceInterface {

	/**
	 * Append an entry to an agent's Memory, flagging a consolidation nudge over budget.
	 *
	 * The entry is ALWAYS persisted; when the total character count exceeds the object's
	 * `charBudget` the object is flagged `needsConsolidation=true` (a nudge to summarise)
	 * — older entries are never dropped (the spec forbids silent truncation).
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $text The memory text to append.
	 *
	 * @return ObjectEntity The persisted Memory object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-2
	 */
	public function appendMemoryEntry(string $agentId, string $text): ObjectEntity;

	/**
	 * Append an entry to an agent's UserProfile, flagging a consolidation nudge over budget.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $subjectUid The subject user id.
	 * @param string $text The profile text to append.
	 *
	 * @return ObjectEntity The persisted UserProfile object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-2
	 */
	public function appendUserProfileEntry(string $agentId, string $subjectUid, string $text): ObjectEntity;

	/**
	 * Replace an agent's Memory entries with a consolidated set and clear the nudge.
	 *
	 * The consolidation STRATEGY (summarise vs. prune) is the caller's: an operator or an
	 * OpenRegister agent turn supplies the consolidated entries. This method applies them
	 * and recomputes the flag — it does not itself summarise (that is the OR run-loop seam).
	 *
	 * @param string $agentId The agent UUID.
	 * @param array<int, array<string>> $entries The consolidated entries ([{text, createdAt}]).
	 *
	 * @return ObjectEntity The persisted Memory object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-3
	 */
	public function consolidateMemory(string $agentId, array $entries): ObjectEntity;

	/**
	 * Correct one Memory entry: the old entry is soft-deleted and the new text is
	 * appended as a new entry, in one save, so the history keeps both wordings.
	 *
	 * The new text goes through the same redaction as every other memory write.
	 * An entry that does not exist, or that is already forgotten, is not found.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $entryId The id of the entry to correct.
	 * @param string $text The corrected text (non-empty; the caller checks).
	 *
	 * @return ObjectEntity|null The persisted Memory object, or null when no live
	 *                           entry carries `$entryId`.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001
	 */
	public function correctMemoryEntry(string $agentId, string $entryId, string $text): ?ObjectEntity;

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
	 * @param string $agentId The agent UUID.
	 * @param string|null $subjectUid The acting user id whose own UserProfile may also
	 *                                be searched, or null to search only the agent's Memory.
	 * @param string $entryId The entry id to soft-delete.
	 *
	 * @return array{found: bool, scope: (string|null)} Whether a match was found, and
	 *                                                  in which object ('memory'|'userProfile') when it was.
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-memory-tools/tasks.md#task-3
	 */
	public function forgetEntry(string $agentId, ?string $subjectUid, string $entryId): array;

	/**
	 * Get (or create) the Memory object for an agent, scoped to the caller's tenant.
	 *
	 * @param string      $agentId    The agent UUID.
	 * @param string|null $projectKey The project, or null for the agent's shared memory.
	 *
	 * @return ObjectEntity The agent's Memory object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-1
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
	 */
	public function getMemory(string $agentId, ?string $projectKey = null): ObjectEntity;

	/**
	 * List an agent's Sessions, scoped to the caller's tenant AND to the caller's OWN
	 * Sessions — the `Session` schema carries no user/owner property, so `agentId` alone
	 * would return every user's chat sessions for that agent (a cross-user disclosure).
	 * `@self.owner` is OpenRegister's object-owner meta-filter (NEVER `_owner`, which is
	 * silently ignored and returns unfiltered results — see MemoryServiceTest).
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, ObjectEntity> The CALLER's own Session objects for this agent.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-4
	 */
	public function listSessions(string $agentId): array;

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
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-1
	 */
	public function listUserProfiles(string $agentId): array;

	/**
	 * Recall matching, non-soft-deleted Memory/UserProfile entries for an agent via the
	 * SAME OpenRegister search substrate `recallSessions()` already uses — no second
	 * search index, no vector store. Object-level `ObjectService` search resolves
	 * candidate Memory/UserProfile objects; entry-level filtering (query substring,
	 * excluding soft-deleted entries) narrows the result to the matching entries within
	 * them. `hermiq.recallMemory` merges this with `recallSessions()`'s turn matches into
	 * one combined tool result (design.md Decision 5).
	 *
	 * The returned entries are tenant-scoped for free (unchanged ObjectService
	 * caller-context RBAC).
	 *
	 * @param string $agentId The agent UUID.
	 * @param string|null $subjectUid The acting user id whose own UserProfile is also
	 *                                searched, or null to search only the agent's Memory.
	 * @param string $query The recall query.
	 * @param int $limit Maximum objects to search per schema.
	 * @param string|null $projectKey The session's project, searched beside the shared memory; null for shared only.
	 *
	 * @return array{memoryEntries: array<int, array<string, string>>, userProfileEntries: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-memory-tools/tasks.md#task-4
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-recall-in-a-project-reads-the-project-and-the-shared-memory-only-req-pmem-002
	 */
	public function recallEntries(string $agentId, ?string $subjectUid, string $query, int $limit = 20, ?string $projectKey = null): array;

	/**
	 * Recall relevant SessionTurns for an agent via OpenRegister's own search substrate.
	 *
	 * Reuses ObjectService search (the same substrate VectorizationService builds on) — NO
	 * bespoke SQLite/FTS5 index. Scoped to the CALLER's own turns via `@self.owner`
	 * (SessionTurn has no owner/subject property) so one user never recalls another's
	 * conversation content; fails CLOSED (no user ⇒ no recall). See the body comment for
	 * the agent-run-loop trade-off.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $query The recall query.
	 * @param int $limit Maximum turns to return.
	 *
	 * @return array<int, ObjectEntity> The matching SessionTurn objects (tenant-scoped).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-memory/tasks.md#task-2-5
	 */
	public function recallSessions(string $agentId, string $query, int $limit = 20): array;
}//end interface
