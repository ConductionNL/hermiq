<?php

/**
 * Hermiq AuditTrailVersionHistory.
 *
 * The AuditTrail-as-version-store reading shared by `AgentVersionService` and
 * `SkillVersionService`: a "version" is one `create`/`update` AuditTrail entry
 * for an object (its identifier is the entry's own UUID), listed newest-first,
 * mapped into a version record, and diffed across the owner's fixed
 * versioned field set. Each owner builds its own instance with its own field
 * set; the history itself never writes.
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
 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Reads an object's `create`/`update` AuditTrail entries as a newest-first
 * version timeline and maps or diffs them across one versioned field set.
 *
 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
 */
final class AuditTrailVersionHistory {

	/**
	 * AuditTrail actions that represent a version.
	 *
	 * @var array<int, string>
	 */
	private const VERSION_ACTIONS = ['create', 'update'];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper   $auditTrailMapper OpenRegister's AuditTrail mapper.
	 * @param array<int, string> $versionedFields  The owner's versioned field set.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly array $versionedFields,
	) {
	}//end __construct()

	/**
	 * Fetch an object's `create`/`update` AuditTrail entries, newest-first.
	 *
	 * WHY re-filter/re-sort defensively after the mapper call: mirrors
	 * `RunHistoryService::getRunHistory()`'s identical defensive pattern —
	 * the action filter is applied server-side already, but the result is
	 * re-checked and re-sorted here rather than trusting the mapper's
	 * default ordering.
	 *
	 * @param string $objectUuid The versioned object's UUID.
	 *
	 * @return array<int, AuditTrail> The version entries, newest-first.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	public function entriesFor(string $objectUuid): array {
		// NOTE: AuditTrailMapper::findAll() string-casts every filter value — an
		// ARRAY value becomes the literal string "Array" and matches ZERO rows
		// (green-but-dead). Its multi-value contract is a comma-separated STRING
		// ("create,update"), which it explodes into an IN() itself.
		$logs = $this->auditTrailMapper->findAll(
			filters: [
				'object_uuid' => $objectUuid,
				'action' => implode(',', self::VERSION_ACTIONS),
			]
		);

		return $this->newestFirst(entries: $this->versionEntriesOnly(logs: $logs));
	}//end entriesFor()

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
	public function indexOf(array $entries, string $versionId): ?int {
		foreach ($entries as $index => $entry) {
			if ($entry->getUuid() === $versionId) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The versioned fields whose value differs between two reconstructed states.
	 *
	 * @param array<string, mixed> $fromState The state at the "from" version.
	 * @param array<string, mixed> $toState   The state at the "to" version.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> The changed fields.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
	 */
	public function changedBetween(array $fromState, array $toState): array {
		$changed = [];
		foreach ($this->versionedFields as $field) {
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
	}//end changedBetween()

	/**
	 * Map one AuditTrail entry into a version record.
	 *
	 * @param AuditTrail $entry The version's AuditTrail entry.
	 *
	 * @return array<string, mixed> The version record.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	public function toVersionRecord(AuditTrail $entry): array {
		$changed = ($entry->getChanged() ?? []);
		$changedFields = array_values(array_intersect(array_keys($changed), $this->versionedFields));
		$created = $entry->getCreated();

		return [
			'id' => $entry->getUuid(),
			'timestamp' => $created?->format('c'),
			'user' => $entry->getUser(),
			'action' => $entry->getAction(),
			'changedFields' => $changedFields,
		];

	}//end toVersionRecord()

	/**
	 * Keep only the version actions (defensive: findAll() filters by action
	 * already, but guard anyway — mirrors RunHistoryService::getRunHistory()).
	 *
	 * @param array<int, AuditTrail> $logs The mapper's AuditTrail entries.
	 *
	 * @return array<int, AuditTrail> The version entries, in mapper order.
	 */
	private function versionEntriesOnly(array $logs): array {
		$entries = [];
		foreach ($logs as $log) {
			if (in_array($log->getAction(), self::VERSION_ACTIONS, true) === false) {
				continue;
			}

			$entries[] = $log;
		}

		return $entries;
	}//end versionEntriesOnly()

	/**
	 * Sort version entries newest-first by their creation time.
	 *
	 * @param array<int, AuditTrail> $entries The version entries.
	 *
	 * @return array<int, AuditTrail> The version entries, newest-first.
	 */
	private function newestFirst(array $entries): array {
		usort(
			$entries,
			static function (AuditTrail $entryA, AuditTrail $entryB): int {
				$timeA = ($entryA->getCreated()?->getTimestamp() ?? 0);
				$timeB = ($entryB->getCreated()?->getTimestamp() ?? 0);
				return ($timeB <=> $timeA);
			}
		);

		return $entries;
	}//end newestFirst()
}//end class
