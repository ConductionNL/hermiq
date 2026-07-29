<?php

/**
 * Hermiq SkillVersionService.
 *
 * The read + rollback surface for skill versioning (skill-self-improvement),
 * mirroring `AgentVersionService` exactly: a skill "version" is one
 * `create`/`update` AuditTrail entry for the Skill object (its identifier is the
 * entry's own UUID), diff/reconstruction replays each entry's `changed['old']`
 * backward from the live object, and rollback writes a prior version's values as
 * a brand-new version through the normal `ObjectService::saveObject()` write
 * path — history is never mutated. The versioned field set is exactly the
 * agentskills.io content plane (`frontmatter`, `body`, `files`); identity,
 * lifecycle, provenance, maturity and evidence fields keep their CURRENT values
 * on rollback (design.md Decision 3). No `SkillVersion` schema exists — the
 * AuditTrail IS the version store.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
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
 * Reads a Skill's OpenRegister AuditTrail as a version timeline, diffs any two
 * versions across the fixed content field set, rolls a Skill back to a prior
 * version's content as a NEW version, and resolves the never-fatal version pins
 * run-audit writers record.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for skill objects.
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * AuditTrail actions that represent a version (create + every update) —
     * every other action written against a Skill object (draft transitions,
     * rollback/republish markers) is deliberately excluded from the timeline.
     *
     * @var array<int, string>
     */
    private const VERSION_ACTIONS = ['create', 'update'];

    /**
     * The versioned field set: exactly the agentskills.io content plane. Identity
     * (`name`), lifecycle (`state`), provenance (`githubOwner`/`githubRepo`/
     * `publishedAt`/`lastAcceptedVersionAt`), maturity and evidence fields are NOT
     * versioned-config — a rollback leaves them at their CURRENT values (design.md
     * Decision 3, mirroring agent-versioning's identity/visibility/quota rule).
     *
     * @var array<int, string>
     */
    public const VERSIONED_FIELDS = [
        'frontmatter',
        'body',
        'files',
    ];

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (by object_uuid), the
     *                                           SAME in-process pattern AgentVersionService/
     *                                           RunHistoryService already use.
     * @param ObjectService    $objectService    OpenRegister object read/write: resolves the
     *                                           live Skill and persists a rollback via the
     *                                           single write-path.
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * List a Skill's version history, newest-first.
     *
     * @param string $skillUuid The Skill object UUID.
     *
     * @return array<int, array<string, mixed>> The versions (id, timestamp, user,
     *         action, changedFields), newest-first.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function listVersions(string $skillUuid): array
    {
        $entries = $this->fetchVersionEntries(skillUuid: $skillUuid);

        return array_map(
            fn (AuditTrail $entry): array => $this->toVersionRecord(entry: $entry),
            $entries
        );

    }//end listVersions()

    /**
     * Diff two versions across the fixed `VERSIONED_FIELDS` set — `frontmatter`,
     * `body`, `files` only; a `state`/provenance/maturity change never appears.
     *
     * @param string $skillUuid The Skill object UUID.
     * @param string $fromId    The "old" version's AuditTrail entry UUID.
     * @param string $toId      The "new" version's AuditTrail entry UUID.
     *
     * @return array<string, array{old: mixed, new: mixed}> Only the versioned fields
     *         that differ between the two versions.
     *
     * @throws RuntimeException When either version id is unknown for this skill.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function diff(string $skillUuid, string $fromId, string $toId): array
    {
        $entries = $this->fetchVersionEntries(skillUuid: $skillUuid);

        $fromIndex = $this->findEntryIndex(entries: $entries, versionId: $fromId);
        $toIndex   = $this->findEntryIndex(entries: $entries, versionId: $toId);
        if ($fromIndex === null || $toIndex === null) {
            throw new RuntimeException('Unknown skill version id.');
        }

        $live = $this->liveSkillData(skillUuid: $skillUuid);

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
     * Roll a Skill back to a previous version's content (`frontmatter`/`body`/
     * `files`) as a brand-NEW version. Non-versioned fields (identity, lifecycle
     * `state`, GitHub provenance, maturity/evidence, `installedOn`, …) keep their
     * CURRENT values because the rollback payload starts from the live object and
     * replaces ONLY the versioned fields; existing AuditTrail entries are only
     * ever read.
     *
     * @param string $skillUuid The Skill object UUID.
     * @param string $versionId The target version's AuditTrail entry UUID.
     *
     * @return ObjectEntity The updated skill (a new version, equal in content to
     *         the target for every versioned field).
     *
     * @throws RuntimeException When the version id is unknown, or the skill cannot
     *                          be resolved.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function rollback(string $skillUuid, string $versionId): ObjectEntity
    {
        $entries     = $this->fetchVersionEntries(skillUuid: $skillUuid);
        $targetIndex = $this->findEntryIndex(entries: $entries, versionId: $versionId);
        if ($targetIndex === null) {
            throw new RuntimeException('Unknown skill version id.');
        }

        $skill = $this->objectService->find(
            id: $skillUuid,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA
        );
        if (($skill instanceof ObjectEntity) === false) {
            throw new RuntimeException('Skill not found.');
        }

        $live        = $skill->getObject();
        $targetState = $this->reconstructAsOf(entries: $entries, targetIndex: $targetIndex, liveData: $live);

        $payload = $live;
        foreach (self::VERSIONED_FIELDS as $field) {
            $payload[$field] = ($targetState[$field] ?? null);
        }

        return $this->objectService->saveObject(
            object: $payload,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: $skillUuid
        );

    }//end rollback()

    /**
     * The current (newest) version identifier for a Skill — pinned onto drafts as
     * `baseVersionId` and onto run audit entries. Never fatal: any failure
     * (including "no versions found") returns null rather than propagating, so a
     * version-pin lookup can never break a run (spec: "a pin failure is never
     * fatal").
     *
     * @param string $skillUuid The Skill object UUID.
     *
     * @return string|null The newest version's AuditTrail entry UUID, or null.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-runs-pin-the-exact-skill-versions-that-executed
     */
    public function currentVersionId(string $skillUuid): ?string
    {
        try {
            $entries = $this->fetchVersionEntries(skillUuid: $skillUuid);
            if (count($entries) === 0) {
                return null;
            }

            return $entries[0]->getUuid();
        } catch (Throwable $e) {
            return null;
        }

    }//end currentVersionId()

    /**
     * Resolve the version pins for a set of exercised skills — the map run-audit
     * writers record as `skillVersions` alongside the existing `agentVersion` pin.
     * Never fatal: an unresolvable skill is simply absent from the map (the audit
     * entry is written without that pin, the run unaffected).
     *
     * @param array<int, mixed> $skillUuids The exercised skill UUIDs. Deliberately
     *                                      typed loose: entries come from stored
     *                                      run metadata, so non-string or empty
     *                                      junk is filtered here (never fatal).
     *
     * @return array<string, string> Map of skill UUID → version id (AuditTrail
     *         entry UUID); unresolvable skills omitted.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-runs-pin-the-exact-skill-versions-that-executed
     */
    public function pinsFor(array $skillUuids): array
    {
        $pins = [];
        foreach ($skillUuids as $skillUuid) {
            if (is_string($skillUuid) === false || $skillUuid === '') {
                continue;
            }

            $versionId = $this->currentVersionId(skillUuid: $skillUuid);
            if ($versionId === null) {
                continue;
            }

            $pins[$skillUuid] = $versionId;
        }

        return $pins;

    }//end pinsFor()

    /**
     * Fetch a Skill's `create`/`update` AuditTrail entries, newest-first
     * (defensive re-filter/re-sort mirroring `AgentVersionService`).
     *
     * @param string $skillUuid The Skill object UUID.
     *
     * @return array<int, AuditTrail> The version entries, newest-first.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    private function fetchVersionEntries(string $skillUuid): array
    {
        // NOTE: AuditTrailMapper::findAll() string-casts every filter value — an
        // ARRAY value becomes the literal string "Array" and matches ZERO rows
        // (green-but-dead). Its multi-value contract is a comma-separated STRING
        // ("create,update"), which it explodes into an IN() itself.
        $logs = $this->auditTrailMapper->findAll(
            filters: [
                'object_uuid' => $skillUuid,
                'action'      => implode(',', self::VERSION_ACTIONS),
            ]
        );

        $entries = [];
        foreach ($logs as $log) {
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
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
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
     * Reconstruct the `VERSIONED_FIELDS` values as of a target version by undoing
     * every NEWER entry's recorded change (backward replay of `changed[field]['old']`,
     * the `AuditTrailMapper::revertObject()` technique, scoped to the fixed set).
     *
     * @param array<int, AuditTrail> $entries     The version entries, newest-first.
     * @param int                    $targetIndex The target version's index.
     * @param array<string, mixed>   $liveData    The skill's current object payload.
     *
     * @return array<string, mixed> The reconstructed versioned field values.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
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
     * Resolve the Skill's current object payload (diff()'s reconstruction baseline).
     *
     * @param string $skillUuid The Skill object UUID.
     *
     * @return array<string, mixed> The skill's current object payload.
     *
     * @throws RuntimeException When the skill cannot be resolved.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    private function liveSkillData(string $skillUuid): array
    {
        $skill = $this->objectService->find(
            id: $skillUuid,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA
        );
        if (($skill instanceof ObjectEntity) === false) {
            throw new RuntimeException('Skill not found.');
        }

        return $skill->getObject();

    }//end liveSkillData()

    /**
     * Map one AuditTrail entry into a version record.
     *
     * @param AuditTrail $entry The version's AuditTrail entry.
     *
     * @return array<string, mixed> The version record.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
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
