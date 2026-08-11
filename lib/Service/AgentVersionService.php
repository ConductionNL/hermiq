<?php

/**
 * Hermiq AgentVersionService.
 *
 * The read + rollback surface for agent-versioning. Every save of an Agent's
 * configuration already lands in OpenRegister's hash-chained AuditTrail
 * (`SaveObject::createAuditTrail()` diffs old/new state on every save) — this
 * service adds NO new storage. It reads that same AuditTrail directly via an
 * injected `AuditTrailMapper` (the identical in-process pattern
 * `RunHistoryService`/`BudgetService`/`TenantOpsService` already use), because
 * OpenRegister's own `GET .../audit-trails` HTTP endpoint hard-gates on NC
 * admin (`AuditTrailController::requireAdmin()`), which would lock out the
 * non-admin agent owners who are this feature's actual audience.
 *
 * A "version" is one `create`/`update` AuditTrail entry for the Agent object;
 * its identifier is the entry's own UUID (the Agent's generic `version` semver
 * field is never bumped by `SaveObject` on update, so it cannot serve as a
 * version id — see design.md Decision 2). Diffing/reconstructing a historical
 * field value replays each entry's `changed['old']` backward from the live
 * object, the same technique `AuditTrailMapper::revertObject()`/
 * `revertChanges()` already use in production, but scoped to the fixed
 * `VERSIONED_FIELDS` allowlist (the agent's "config"/"capability profile"
 * fields) so identity/visibility/quota/lifecycle-governance fields are never
 * touched by a rollback.
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
 * @spec openspec/changes/agent-versioning/tasks.md#task-1-agentversionservice-list-history-diff-rollback-current-version-lookup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;
use Throwable;

/**
 * Reads an Agent's OpenRegister AuditTrail as a version timeline, diffs any two
 * versions across a fixed field allowlist, and rolls an Agent back to a prior
 * version's values via the normal `ObjectService::saveObject()` write-path.
 *
 * @spec openspec/changes/agent-versioning/tasks.md#task-1-agentversionservice-list-history-diff-rollback-current-version-lookup
 */
class AgentVersionService
{

    /**
     * OpenRegister register slug that holds Hermiq agent-engine objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * AuditTrail actions that represent a version (create + every update);
     * every OTHER action Hermiq writes against an Agent object (e.g. a future
     * agent-scoped audit action) is deliberately excluded from the timeline.
     *
     * @var array<int, string>
     */
    private const VERSION_ACTIONS = ['create', 'update'];

    /**
     * The fixed "agent config"/"capability profile" field allowlist this
     * capability diffs and rolls back — matches `lib/Settings/hermiq_register.json`'s
     * `agent` schema. Deliberately excludes identity/visibility/quota/
     * lifecycle-governance fields (`name`, `description`, `type`, `active`,
     * `isPrivate`, `invitedUsers`, `groups`, `requestQuota`, `tokenQuota`,
     * `actingUser`, `user`, `reassignmentFlag`, `reviewedAt`, `reviewedBy`) —
     * see proposal.md Out of Scope.
     *
     * @var array<int, string>
     */
    public const VERSIONED_FIELDS = [
        'prompt',
        'model',
        'provider',
        'temperature',
        'maxTokens',
        'configuration',
        'tools',
        'skillInstalls',
        'contextRefs',
        'enableRag',
        'ragSearchMode',
        'ragNumSources',
        'ragIncludeFiles',
        'ragIncludeObjects',
        'views',
        'searchFiles',
        'searchObjects',
    ];

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (by object_uuid — see
     *                                           fetchVersionEntries()), the SAME in-process
     *                                           pattern RunHistoryService/BudgetService/
     *                                           TenantOpsService already use.
     * @param ObjectService    $objectService    OpenRegister object read/write: resolves the
     *                                           live Agent (diff's reconstruction baseline,
     *                                           rollback's read) and persists a rollback via
     *                                           the single write-path.
     *
     * @spec openspec/specs/agent-versioning/spec.md
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * List an Agent's version history, newest-first.
     *
     * @param string $agentUuid The Agent object UUID.
     *
     * @return array<int, array<string, mixed>> The versions (id, timestamp, user,
     *         action, changedFields), newest-first.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function listVersions(string $agentUuid): array
    {
        $entries = $this->fetchVersionEntries(agentUuid: $agentUuid);

        return array_map(
            fn (AuditTrail $entry): array => $this->toVersionRecord(entry: $entry),
            $entries
        );

    }//end listVersions()

    /**
     * Diff two versions across the fixed `VERSIONED_FIELDS` allowlist.
     *
     * @param string $agentUuid The Agent object UUID.
     * @param string $fromId    The "old" version's AuditTrail entry UUID.
     * @param string $toId      The "new" version's AuditTrail entry UUID.
     *
     * @return array<string, array{old: mixed, new: mixed}> Only the fields that
     *         differ between the two versions (empty when $fromId === $toId or
     *         nothing in the allowlist changed).
     *
     * @throws RuntimeException When either version id is unknown for this agent.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    public function diff(string $agentUuid, string $fromId, string $toId): array
    {
        $entries = $this->fetchVersionEntries(agentUuid: $agentUuid);

        $fromIndex = $this->findEntryIndex(entries: $entries, versionId: $fromId);
        $toIndex   = $this->findEntryIndex(entries: $entries, versionId: $toId);
        if ($fromIndex === null || $toIndex === null) {
            throw new RuntimeException('Unknown agent version id.');
        }

        $live = $this->liveAgentData(agentUuid: $agentUuid);

        $fromState = $this->reconstructAsOf(entries: $entries, targetIndex: $fromIndex, liveData: $live);
        $toState   = $this->reconstructAsOf(entries: $entries, targetIndex: $toIndex, liveData: $live);

        $changed = [];
        foreach (self::VERSIONED_FIELDS as $field) {
            $oldValue = ($fromState[$field] ?? null);
            $newValue = ($toState[$field] ?? null);
            if ($oldValue === $newValue) {
                continue;
            }

            $changed[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changed;

    }//end diff()

    /**
     * Roll an Agent back to a previous version's `VERSIONED_FIELDS` values.
     *
     * Reconstructs the target version's allowlisted field values and merges
     * them onto the agent's CURRENT payload (every non-allowlisted field is
     * left untouched), then persists via `ObjectService::saveObject()` — the
     * exact call `AgentsController::update()` already makes — which itself
     * writes a brand-new AuditTrail entry. History is never mutated: the
     * target version's own entry is only ever read here.
     *
     * @param string $agentUuid The Agent object UUID.
     * @param string $versionId The target version's AuditTrail entry UUID.
     *
     * @return ObjectEntity The updated agent (a new version, equal in content
     *         to the target for every allowlisted field).
     *
     * @throws RuntimeException When the version id is unknown, or the agent
     *                          cannot be resolved.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
     */
    public function rollback(string $agentUuid, string $versionId): ObjectEntity
    {
        $entries     = $this->fetchVersionEntries(agentUuid: $agentUuid);
        $targetIndex = $this->findEntryIndex(entries: $entries, versionId: $versionId);
        if ($targetIndex === null) {
            throw new RuntimeException('Unknown agent version id.');
        }

        $agent = $this->objectService->find(
            id: $agentUuid,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
        if (($agent instanceof ObjectEntity) === false) {
            throw new RuntimeException('Agent not found.');
        }

        $live        = $agent->getObject();
        $targetState = $this->reconstructAsOf(entries: $entries, targetIndex: $targetIndex, liveData: $live);

        $payload = $live;
        foreach (self::VERSIONED_FIELDS as $field) {
            $payload[$field] = ($targetState[$field] ?? null);
        }

        return $this->objectService->saveObject(
            object: $payload,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA,
            uuid: $agentUuid
        );

    }//end rollback()

    /**
     * The current (newest) version identifier for an Agent — the version id
     * pinned onto a run's audit entry (run-audit writers). Never fatal: any
     * failure (including "no versions found") returns null rather than
     * propagating, so a version-pin lookup can never break a run.
     *
     * @param string $agentUuid The Agent object UUID.
     *
     * @return string|null The newest version's AuditTrail entry UUID, or null.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-a-runs-audit-entry-pins-the-exact-agent-version-that-executed-it
     */
    public function currentVersionId(string $agentUuid): ?string
    {
        try {
            $entries = $this->fetchVersionEntries(agentUuid: $agentUuid);
            if (count($entries) === 0) {
                return null;
            }

            return $entries[0]->getUuid();
        } catch (Throwable $e) {
            return null;
        }

    }//end currentVersionId()

    /**
     * Fetch an Agent's `create`/`update` AuditTrail entries, newest-first.
     *
     * WHY re-filter/re-sort defensively after the mapper call: mirrors
     * `RunHistoryService::getRunHistory()`'s identical defensive pattern —
     * the action filter is applied server-side already, but the result is
     * re-checked and re-sorted here rather than trusting the mapper's
     * default ordering.
     *
     * @param string $agentUuid The Agent object UUID.
     *
     * @return array<int, AuditTrail> The version entries, newest-first.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    private function fetchVersionEntries(string $agentUuid): array
    {
        // NOTE: AuditTrailMapper::findAll() string-casts every filter value — an
        // ARRAY value becomes the literal string "Array" and matches ZERO rows
        // (green-but-dead). Its multi-value contract is a comma-separated STRING
        // ("create,update"), which it explodes into an IN() itself.
        $logs = $this->auditTrailMapper->findAll(
            filters: [
                'object_uuid' => $agentUuid,
                'action'      => implode(',', self::VERSION_ACTIONS),
            ]
        );

        $entries = [];
        foreach ($logs as $log) {
            // Defensive: findAll() filters by action already, but guard anyway
            // (mirrors RunHistoryService::getRunHistory()).
            if (in_array($log->getAction(), self::VERSION_ACTIONS, true) === false) {
                continue;
            }

            $entries[] = $log;
        }

        usort(
            $entries,
            static function (AuditTrail $entryA, AuditTrail $entryB): int {
                $timeA = ($entryA->getCreated()?->getTimestamp() ?? 0);
                $timeB = ($entryB->getCreated()?->getTimestamp() ?? 0);
                return ($timeB <=> $timeA);
            }
        );

        return $entries;

    }//end fetchVersionEntries()

    /**
     * Find a version's index in a newest-first entry list.
     *
     * @param array<int, AuditTrail> $entries   The version entries, newest-first.
     * @param string                 $versionId The target AuditTrail entry UUID.
     *
     * @return int|null The entry's index, or null when not found.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    private function findEntryIndex(array $entries, string $versionId): ?int
    {
        foreach ($entries as $index => $entry) {
            if ($entry->getUuid() === $versionId) {
                return $index;
            }
        }

        return null;

    }//end findEntryIndex()

    /**
     * Reconstruct the `VERSIONED_FIELDS` values as of a target version.
     *
     * Starts from the live object's current allowlisted values and undoes
     * every NEWER entry's recorded change (entries at index 0..$targetIndex-1
     * in the newest-first list) by replacing each touched field with that
     * entry's own `changed[field]['old']` — the same backward-replay
     * `AuditTrailMapper::revertObject()`/`revertChanges()` already performs
     * in production, scoped here to the fixed allowlist (design.md Decision 4).
     *
     * @param array<int, AuditTrail> $entries     The version entries, newest-first.
     * @param int                    $targetIndex The target version's index.
     * @param array<string, mixed>   $liveData    The agent's current object payload.
     *
     * @return array<string, mixed> The reconstructed allowlisted field values.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    private function reconstructAsOf(array $entries, int $targetIndex, array $liveData): array
    {
        $state = [];
        foreach (self::VERSIONED_FIELDS as $field) {
            $state[$field] = ($liveData[$field] ?? null);
        }

        for ($index = 0; $index < $targetIndex; $index++) {
            $changed = ($entries[$index]->getChanged() ?? []);
            foreach (self::VERSIONED_FIELDS as $field) {
                $fieldChange = ($changed[$field] ?? null);
                if (is_array($fieldChange) === false) {
                    continue;
                }

                $state[$field] = ($fieldChange['old'] ?? null);
            }
        }

        return $state;

    }//end reconstructAsOf()

    /**
     * Resolve the Agent's current object payload (diff()'s reconstruction baseline).
     *
     * @param string $agentUuid The Agent object UUID.
     *
     * @return array<string, mixed> The agent's current object payload.
     *
     * @throws RuntimeException When the agent cannot be resolved.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    private function liveAgentData(string $agentUuid): array
    {
        $agent = $this->objectService->find(
            id: $agentUuid,
            register: self::REGISTER_SLUG,
            schema: self::AGENT_SCHEMA
        );
        if (($agent instanceof ObjectEntity) === false) {
            throw new RuntimeException('Agent not found.');
        }

        return $agent->getObject();

    }//end liveAgentData()

    /**
     * Map one AuditTrail entry into a version record.
     *
     * @param AuditTrail $entry The version's AuditTrail entry.
     *
     * @return array<string, mixed> The version record.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    private function toVersionRecord(AuditTrail $entry): array
    {
        $changed       = ($entry->getChanged() ?? []);
        $changedFields = array_values(array_intersect(array_keys($changed), self::VERSIONED_FIELDS));
        $created       = $entry->getCreated();

        return [
            'id'            => $entry->getUuid(),
            'timestamp'     => $created?->format('c'),
            'user'          => $entry->getUser(),
            'action'        => $entry->getAction(),
            'changedFields' => $changedFields,
        ];

    }//end toVersionRecord()
}//end class
