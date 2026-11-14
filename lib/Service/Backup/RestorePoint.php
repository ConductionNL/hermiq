<?php

/**
 * Hermiq RestorePoint.
 *
 * What an administrator restores an organisation to (operations-backup-and-restore,
 * design D3): a backup file from the backup folder, or a moment in time that the
 * audit trail reaches back to. It also carries the choice for objects created
 * after the point: keep them (the default) or move them to OpenRegister's
 * deleted objects, where they can still be recovered.
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
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * A backup file or a moment in time, for one organisation.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
final class RestorePoint {

	public const KIND_BACKUP = 'backup';

	public const KIND_MOMENT = 'moment';

	/**
	 * Constructor.
	 *
	 * @param string                 $kind               `backup` or `moment`.
	 * @param string                 $organisation       The organisation (for a backup: the one its manifest names).
	 * @param string                 $file               The backup file name, for a backup.
	 * @param DateTimeImmutable|null $at                 The moment, for a moment.
	 * @param bool                   $removeCreatedAfter Whether objects created after the point move to the deleted objects.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $organisation='',
		public readonly string $file='',
		public readonly ?DateTimeImmutable $at=null,
		public readonly bool $removeCreatedAfter=false,
	) {
	}//end __construct()

	/**
	 * Read a restore point from request parameters or a queued job's argument.
	 *
	 * A backup needs `file`; a moment needs `organisation` and `at` (ISO 8601).
	 *
	 * @param array<string, mixed> $values The values.
	 *
	 * @return self The point.
	 *
	 * @throws InvalidArgumentException When a value is missing or not understood.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public static function fromArray(array $values): self {
		$kind = (string)($values['kind'] ?? '');
		$remove = filter_var(($values['removeCreatedAfter'] ?? false), FILTER_VALIDATE_BOOLEAN);
		$organisation = trim((string)($values['organisation'] ?? ''));

		if ($kind === self::KIND_BACKUP) {
			$file = (string)($values['file'] ?? '');
			if (BackupFiles::isBackupName(name: $file) === false) {
				throw new InvalidArgumentException('Pick a backup file from the backup folder.');
			}

			return new self(kind: $kind, organisation: $organisation, file: $file, removeCreatedAfter: $remove);
		}

		if ($kind === self::KIND_MOMENT) {
			if ($organisation === '') {
				throw new InvalidArgumentException('Name the organisation to restore.');
			}

			return new self(kind: $kind, organisation: $organisation, at: self::moment(value: ($values['at'] ?? '')), removeCreatedAfter: $remove);
		}

		throw new InvalidArgumentException('A restore point is a backup file or a moment in time.');
	}//end fromArray()

	/**
	 * The same point for the organisation a backup file belongs to.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return self The point.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function withOrganisation(string $organisation): self {
		return new self(kind: $this->kind, organisation: $organisation, file: $this->file, at: $this->at, removeCreatedAfter: $this->removeCreatedAfter);
	}//end withOrganisation()

	/**
	 * The point as values a queued job can carry and fromArray() reads back.
	 *
	 * @return array<string, mixed> The values.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function toArray(): array {
		return [
			'kind'               => $this->kind,
			'organisation'       => $this->organisation,
			'file'               => $this->file,
			'at'                 => $this->at?->format(DateTimeInterface::ATOM),
			'removeCreatedAfter' => $this->removeCreatedAfter,
		];
	}//end toArray()

	/**
	 * Parse the moment, in UTC.
	 *
	 * @param mixed $value The ISO 8601 moment.
	 *
	 * @return DateTimeImmutable The moment.
	 *
	 * @throws InvalidArgumentException When it is not a date and time.
	 */
	private static function moment(mixed $value): DateTimeImmutable {
		$text = '';
		if (is_scalar($value) === true) {
			$text = trim((string)$value);
		}

		if ($text === '') {
			throw new InvalidArgumentException('Choose the moment to restore to.');
		}

		try {
			return (new DateTimeImmutable($text))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable) {
			throw new InvalidArgumentException('The moment to restore to is not a date and time.');
		}
	}//end moment()
}//end class
