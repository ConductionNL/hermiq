<?php

/**
 * Hermiq BackupSettings.
 *
 * What an administrator chose for the scheduled backup (operations-backup-and-
 * restore, design D1): on or off, the Files folder, the hour, how many backups
 * to keep, whether chat sessions are included, and whose Files the folder is
 * in. Defaults: off, "Hermiq backups", 02:00, 14, sessions left out.
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Backup;

use InvalidArgumentException;

/**
 * The backup settings, validated.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */
final class BackupSettings {

	public const DEFAULT_FOLDER = 'Hermiq backups';

	public const DEFAULT_HOUR = 2;

	public const DEFAULT_KEEP = 14;

	public const MAXIMUM_KEEP = 365;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled         Whether the nightly backup runs.
	 * @param string $folder          The folder in the owner's Files.
	 * @param int    $hour            The hour of the day it runs, 0 to 23.
	 * @param int    $keep            How many backups per organisation to keep.
	 * @param bool   $includeSessions Whether chat sessions and turns are in the backup.
	 * @param string $ownerUid        The administrator in whose Files the folder is.
	 */
	public function __construct(
		public readonly bool $enabled=false,
		public readonly string $folder=self::DEFAULT_FOLDER,
		public readonly int $hour=self::DEFAULT_HOUR,
		public readonly int $keep=self::DEFAULT_KEEP,
		public readonly bool $includeSessions=false,
		public readonly string $ownerUid='',
	) {
	}//end __construct()

	/**
	 * Settings from stored or posted values, refusing any value out of range.
	 *
	 * @param array<string, mixed> $values The values.
	 *
	 * @return self The settings.
	 *
	 * @throws InvalidArgumentException When a value is out of range.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public static function fromArray(array $values): self {
		$folder = trim((string)($values['folder'] ?? self::DEFAULT_FOLDER));
		if ($folder === '' || str_contains($folder, '..') === true || str_starts_with($folder, '/') === true) {
			throw new InvalidArgumentException('The backup folder is a folder name in your Files, without .. or a leading /.');
		}

		$hour = self::integer(value: ($values['hour'] ?? self::DEFAULT_HOUR), name: 'hour');
		if ($hour < 0 || $hour > 23) {
			throw new InvalidArgumentException('The backup hour is between 0 and 23.');
		}

		$keep = self::integer(value: ($values['keep'] ?? self::DEFAULT_KEEP), name: 'keep');
		if ($keep < 1 || $keep > self::MAXIMUM_KEEP) {
			throw new InvalidArgumentException('The number of backups to keep is between 1 and ' . self::MAXIMUM_KEEP . '.');
		}

		return new self(
			enabled: self::boolean(value: ($values['enabled'] ?? false)),
			folder: $folder,
			hour: $hour,
			keep: $keep,
			includeSessions: self::boolean(value: ($values['includeSessions'] ?? false)),
			ownerUid: (string)($values['ownerUid'] ?? ''),
		);
	}//end fromArray()

	/**
	 * The settings as stored and answered.
	 *
	 * @return array{enabled: bool, folder: string, hour: int, keep: int, includeSessions: bool, ownerUid: string}
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function toArray(): array {
		return [
			'enabled'         => $this->enabled,
			'folder'          => $this->folder,
			'hour'            => $this->hour,
			'keep'            => $this->keep,
			'includeSessions' => $this->includeSessions,
			'ownerUid'        => $this->ownerUid,
		];
	}//end toArray()

	/**
	 * The same settings with another owner.
	 *
	 * @param string $ownerUid The administrator in whose Files the folder is.
	 *
	 * @return self The settings.
	 *
	* @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
	 */
	public function withOwner(string $ownerUid): self {
		return new self($this->enabled, $this->folder, $this->hour, $this->keep, $this->includeSessions, $ownerUid);
	}//end withOwner()

	/**
	 * A whole number, or a refusal.
	 *
	 * @param mixed  $value The value.
	 * @param string $name  Its name, for the message.
	 *
	 * @return int The number.
	 *
	 * @throws InvalidArgumentException When it is not a whole number.
	 */
	private static function integer(mixed $value, string $name): int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && preg_match('/^-?\d+$/', trim($value)) === 1) {
			return (int)trim($value);
		}

		throw new InvalidArgumentException('The backup ' . $name . ' is a whole number.');
	}//end integer()

	/**
	 * A boolean from a JSON or form value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool The boolean.
	 */
	private static function boolean(mixed $value): bool {
		if (is_string($value) === true) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}

		return (bool)$value;
	}//end boolean()
}//end class
