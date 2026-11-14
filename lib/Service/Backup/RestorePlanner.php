<?php

/**
 * Hermiq RestorePlanner.
 *
 * Works out what a restore would do, without writing anything
 * (operations-backup-and-restore, design D3 and D5). Per schema it counts the
 * objects that will be put back (in the point, gone now), changed back (in
 * both, different now), left as they are (the same), created after the point,
 * missing from the point (here now, older than the point, but not in it), and,
 * for a moment in time, without history at that moment.
 *
 * A backup file is compared with the organisation's objects now. A moment is
 * rebuilt per object from its audit trail: each recorded change after the
 * moment is undone, newest first, the technique of the agent and skill
 * version rollback. An object whose history does not reach the moment is left
 * untouched and listed as such.
 *
 * Properties the current schema no longer has are dropped from what is
 * written and listed as not restorable. A backup written by a newer register
 * version than the installed one is refused.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Backup
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Backup;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The plan of a restore: counts per schema, and the writes the restore makes.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
class RestorePlanner {

	/**
	 * The counts every schema of a plan carries.
	 *
	 * @var list<string>
	 */
	public const COUNTS = ['putBack', 'changed', 'unchanged', 'createdAfter', 'missing', 'noHistory'];

	private const PAGE_SIZE = 500;

	private const MAX_PAGES = 1000;

	/**
	 * Audit actions that record a change of an object's content.
	 *
	 * @var list<string>
	 */
	private const CHANGE_ACTIONS = ['create', 'update'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService OpenRegister's object service.
	 * @param AuditTrailMapper $auditTrail    OpenRegister's audit trail, for a moment in time.
	 * @param BackupFiles      $files         Reads a backup file.
	 * @param BackupWriter     $writer        The register descriptor and the schema set.
	 * @param LoggerInterface  $logger        The logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrail,
		private readonly BackupFiles $files,
		private readonly BackupWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What the administrator sees before a restore: the plan without the objects.
	 *
	 * @param RestorePoint   $point    The point.
	 * @param BackupSettings $settings The backup settings (the folder).
	 *
	 * @return array<string, mixed> The preview.
	 *
	 * @throws \RuntimeException When the backup file cannot be read.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function preview(RestorePoint $point, BackupSettings $settings): array {
		$plan = $this->plan(point: $point, settings: $settings);
		$plan['writes'] = count($plan['writes']);
		$plan['createdAfter'] = count($plan['createdAfter']);

		return $plan;
	}//end preview()

	/**
	 * The plan of a restore. Nothing is written.
	 *
	 * @param RestorePoint   $point    The point.
	 * @param BackupSettings $settings The backup settings (the folder).
	 *
	 * @return array{
	 *     point: array<string, mixed>,
	 *     organisation: string,
	 *     registerVersion: string,
	 *     refused: string|null,
	 *     schemas: array<string, array<string, mixed>>,
	 *     writes: list<array{schema: string, uuid: string, object: array<string, mixed>}>,
	 *     createdAfter: list<array{schema: string, uuid: string}>
	 * } The plan.
	 *
	 * @throws \RuntimeException When the backup file cannot be read.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function plan(RestorePoint $point, BackupSettings $settings): array {
		$descriptor = $this->writer->descriptor();
		$installed = (string)($descriptor['info']['version'] ?? '');

		if ($point->kind === RestorePoint::KIND_BACKUP) {
			$backup = $this->files->read(settings: $settings, name: $point->file);
			$point = $point->withOrganisation(organisation: (string)($backup['manifest']['organisation'] ?? ''));
			$version = (string)($backup['manifest']['registerVersion'] ?? '');
			$plan = $this->emptyPlan(point: $point, registerVersion: $version);
			if ($version !== '' && $installed !== '' && version_compare($version, $installed, '>') === true) {
				$plan['refused'] = 'This backup was written by register version ' . $version
					. ', newer than the installed ' . $installed . '. Update hermiq before restoring it.';
				return $plan;
			}

			if ($point->organisation === '') {
				$plan['refused'] = 'This backup names no organisation.';
				return $plan;
			}

			return $this->planBackup(plan: $plan, point: $point, backup: $backup, descriptor: $descriptor);
		}

		$plan = $this->emptyPlan(point: $point, registerVersion: $installed);
		$slugs = $this->writer->schemaSlugs(descriptor: $descriptor, includeSessions: $settings->includeSessions);

		return $this->planMoment(plan: $plan, point: $point, slugs: $slugs, properties: $this->propertiesBySlug(descriptor: $descriptor));
	}//end plan()

	/**
	 * Compare a backup file with the organisation's objects now.
	 *
	 * @param array<string, mixed> $plan       The plan so far.
	 * @param RestorePoint         $point      The point.
	 * @param array<string, mixed> $backup     The backup.
	 * @param array<string, mixed> $descriptor The register descriptor.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private function planBackup(array $plan, RestorePoint $point, array $backup, array $descriptor): array {
		$pointTime = $this->time(value: ($backup['manifest']['createdAt'] ?? ''));
		$properties = $this->propertiesBySlug(descriptor: $descriptor);
		$declared = array_map('strval', (array)($backup['manifest']['schemas'] ?? []));
		$slugs = array_values(array_unique(array_merge($declared, array_map('strval', array_keys($backup['objects'])))));

		foreach ($slugs as $slug) {
			if (array_key_exists($slug, $properties) === false) {
				// A schema the installed register no longer declares: nothing to write it to.
				$plan['schemas'][$slug] = $this->emptyCounts();
				$plan['schemas'][$slug]['notRestorable'] = ['(schema)'];
				continue;
			}

			$counts = $this->emptyCounts();
			$dropped = [];
			$live = $this->liveObjects(slug: $slug, organisation: $point->organisation);
			$inBackup = [];
			foreach ((array)($backup['objects'][$slug] ?? []) as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$uuid = self::uuidOf(row: $row);
				if ($uuid === '') {
					continue;
				}

				$inBackup[$uuid] = true;
				$data = $this->restorable(data: self::content(row: $row), properties: $properties[$slug], dropped: $dropped);
				if (isset($live[$uuid]) === false) {
					$counts['putBack']++;
					$plan['writes'][] = ['schema' => $slug, 'uuid' => $uuid, 'object' => $data];
					continue;
				}

				$ignored = [];
				$now = $this->restorable(data: $live[$uuid]->getObject(), properties: $properties[$slug], dropped: $ignored);
				if (self::same(left: $data, right: $now) === true) {
					$counts['unchanged']++;
					continue;
				}

				$counts['changed']++;
				$plan['writes'][] = ['schema' => $slug, 'uuid' => $uuid, 'object' => $data];
			}//end foreach

			foreach ($live as $uuid => $object) {
				if (isset($inBackup[$uuid]) === true) {
					continue;
				}

				$created = $object->getCreated();
				if ($pointTime === null || $created === null || $created->getTimestamp() > $pointTime->getTimestamp()) {
					$counts['createdAfter']++;
					$plan['createdAfter'][] = ['schema' => $slug, 'uuid' => (string)$uuid];
					continue;
				}

				$counts['missing']++;
			}

			$counts['notRestorable'] = array_values(array_unique($dropped));
			$plan['schemas'][$slug] = $counts;
		}//end foreach

		return $plan;
	}//end planBackup()

	/**
	 * Rebuild every object of the organisation at a moment from its audit trail.
	 *
	 * @param array<string, mixed> $plan  The plan so far.
	 * @param RestorePoint         $point The point.
	 * @param list<string>         $slugs The schema slugs.
	 * @param array<string, list<string>> $properties Declared properties per slug.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private function planMoment(array $plan, RestorePoint $point, array $slugs, array $properties): array {
		$at = $point->at;
		foreach ($slugs as $slug) {
			$counts = $this->emptyCounts();
			$declared = ($properties[$slug] ?? []);
			foreach ($this->liveObjects(slug: $slug, organisation: $point->organisation) as $uuid => $object) {
				$ignored = [];
				$now = $this->restorable(data: $object->getObject(), properties: $declared, dropped: $ignored);
				$entries = $this->changeEntries(uuid: (string)$uuid);
				$state = $this->stateAt(live: $now, entries: $entries, at: $at);
				if ($state === 'createdAfter') {
					$counts['createdAfter']++;
					$plan['createdAfter'][] = ['schema' => $slug, 'uuid' => (string)$uuid];
					continue;
				}

				if ($state === null) {
					$counts['noHistory']++;
					continue;
				}

				if (self::same(left: $state, right: $now) === true) {
					$counts['unchanged']++;
					continue;
				}

				$counts['changed']++;
				$plan['writes'][] = ['schema' => $slug, 'uuid' => (string)$uuid, 'object' => $state];
			}//end foreach

			$counts['notRestorable'] = [];
			$plan['schemas'][$slug] = $counts;
		}//end foreach

		return $plan;
	}//end planMoment()

	/**
	 * An object's content at a moment, from its change entries.
	 *
	 * @param array<string, mixed>   $live    The object now.
	 * @param list<AuditTrail>       $entries Its create and update entries.
	 * @param DateTimeImmutable|null $at      The moment.
	 *
	 * @return array<string, mixed>|string|null The content; `createdAfter` when it was created after
	 *                                          the moment; null when its history does not reach it.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function stateAt(array $live, array $entries, ?DateTimeImmutable $at): array|string|null {
		if ($at === null || $entries === []) {
			return null;
		}

		usort(
			$entries,
			static fn (AuditTrail $a, AuditTrail $b): int => (($b->getCreated()?->getTimestamp() ?? 0) <=> ($a->getCreated()?->getTimestamp() ?? 0))
		);

		$moment = $at->getTimestamp();
		$reachesBack = false;
		$state = self::content(row: $live);
		foreach ($entries as $entry) {
			$when = ($entry->getCreated()?->getTimestamp() ?? 0);
			if ($when <= $moment) {
				$reachesBack = true;
				break;
			}

			if ($entry->getAction() === 'create') {
				return 'createdAfter';
			}

			foreach (($entry->getChanged() ?? []) as $field => $change) {
				if (is_string($field) === false || $field === '@self' || is_array($change) === false || array_key_exists('old', $change) === false) {
					continue;
				}

				if ($change['old'] === null) {
					unset($state[$field]);
					continue;
				}

				$state[$field] = $change['old'];
			}
		}//end foreach

		if ($reachesBack === false) {
			return null;
		}

		return $state;
	}//end stateAt()

	/**
	 * The create and update entries of one object.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return list<AuditTrail> The entries; none when the trail cannot be read.
	 */
	private function changeEntries(string $uuid): array {
		try {
			$logs = $this->auditTrail->findAll(filters: ['object_uuid' => $uuid, 'action' => implode(',', self::CHANGE_ACTIONS)]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RestorePlanner] The audit trail of an object could not be read',
				['object' => $uuid, 'exception' => $e->getMessage(), 'app' => AppIdentity::APP_ID]
			);
			return [];
		}

		return array_values(
			array_filter(
				$logs,
				static fn ($log): bool => $log instanceof AuditTrail && in_array($log->getAction(), self::CHANGE_ACTIONS, true) === true
			)
		);
	}//end changeEntries()

	/**
	 * The organisation's objects of one schema now, by uuid. Read system-wide, as
	 * the backup is, and kept to the organisation they carry.
	 *
	 * @param string $slug         The schema slug.
	 * @param string $organisation The organisation.
	 *
	 * @return array<string, ObjectEntity> The objects.
	 */
	private function liveObjects(string $slug, string $organisation): array {
		$objects = [];
		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->objectService
					->setRegister(BackupWriter::REGISTER_SLUG)
					->setSchema($slug)
					->findAll(config: ['limit' => self::PAGE_SIZE, 'offset' => ($page * self::PAGE_SIZE)], _rbac: false, _multitenancy: false);
				foreach ($rows as $row) {
					if ($row instanceof ObjectEntity && (string)($row->getOrganisation() ?? '') === $organisation && (string)$row->getUuid() !== '') {
						$objects[(string)$row->getUuid()] = $row;
					}
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[RestorePlanner] A schema could not be read for the restore',
				['schema' => $slug, 'exception' => $e->getMessage(), 'app' => AppIdentity::APP_ID]
			);
		}

		return $objects;
	}//end liveObjects()

	/**
	 * The declared property names per schema slug.
	 *
	 * @param array<string, mixed> $descriptor The register descriptor.
	 *
	 * @return array<string, list<string>> Slug => property names.
	 */
	private function propertiesBySlug(array $descriptor): array {
		$bySlug = [];
		foreach ((array)($descriptor['components']['schemas'] ?? []) as $key => $schema) {
			$slug = (string)($schema['slug'] ?? strtolower((string)$key));
			$bySlug[$slug] = array_map('strval', array_keys((array)($schema['properties'] ?? [])));
		}

		return $bySlug;
	}//end propertiesBySlug()

	/**
	 * The content that can be written to the schema as it is now; the rest is dropped and named.
	 *
	 * @param array<string, mixed> $data       The content.
	 * @param list<string>         $properties The schema's properties.
	 * @param list<string>         $dropped    Collects the dropped names.
	 *
	 * @return array<string, mixed> The content.
	 */
	private function restorable(array $data, array $properties, array &$dropped): array {
		$kept = [];
		foreach (self::content(row: $data) as $field => $value) {
			if ($properties !== [] && in_array((string)$field, $properties, true) === false) {
				// The uuid OpenRegister echoes into the content is metadata, not a property.
				if ($field !== 'id') {
					$dropped[] = (string)$field;
				}

				continue;
			}

			$kept[$field] = $value;
		}

		return $kept;
	}//end restorable()

	/**
	 * A plan with no schemas yet.
	 *
	 * @param RestorePoint $point           The point.
	 * @param string       $registerVersion The register version of the point.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private function emptyPlan(RestorePoint $point, string $registerVersion): array {
		return [
			'point'           => $point->toArray(),
			'organisation'    => $point->organisation,
			'registerVersion' => $registerVersion,
			'refused'         => null,
			'schemas'         => [],
			'writes'          => [],
			'createdAfter'    => [],
		];
	}//end emptyPlan()

	/**
	 * Every count at zero.
	 *
	 * @return array<string, int> The counts.
	 */
	private function emptyCounts(): array {
		return array_fill_keys(self::COUNTS, 0);
	}//end emptyCounts()

	/**
	 * An object's content, without the metadata OpenRegister keeps itself.
	 *
	 * @param array<string, mixed> $row The object as serialised.
	 *
	 * @return array<string, mixed> The content.
	 */
	private static function content(array $row): array {
		unset($row['@self']);
		return $row;
	}//end content()

	/**
	 * The uuid of a serialised object.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string The uuid, or '' when it has none.
	 */
	private static function uuidOf(array $row): string {
		return (string)($row['@self']['id'] ?? ($row['@self']['uuid'] ?? ($row['uuid'] ?? ($row['id'] ?? ''))));
	}//end uuidOf()

	/**
	 * Whether two contents are the same, whatever the key order.
	 *
	 * @param array<string, mixed> $left  One.
	 * @param array<string, mixed> $right The other.
	 *
	 * @return bool Whether they are.
	 */
	private static function same(array $left, array $right): bool {
		return self::canonical(value: $left) === self::canonical(value: $right);
	}//end same()

	/**
	 * A value with its maps sorted by key, as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The JSON.
	 */
	private static function canonical(mixed $value): string {
		$sort = static function (mixed $item) use (&$sort): mixed {
			if (is_array($item) === false) {
				return $item;
			}

			if (array_is_list($item) === false) {
				ksort($item);
			}

			return array_map($sort, $item);
		};

		return (string)json_encode($sort($value));
	}//end canonical()

	/**
	 * Parse a manifest time.
	 *
	 * @param mixed $value The time.
	 *
	 * @return DateTimeInterface|null The time, or null when there is none.
	 */
	private function time(mixed $value): ?DateTimeInterface {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}
	}//end time()
}//end class
