<?php

/**
 * Hermiq BackupFiles.
 *
 * Lists and reads the backup files in the backup folder of the administrator who
 * owns it (operations-backup-and-restore, design D3). Only a name in the shape
 * BackupWriter gives a file is read, so a request can never reach another file
 * or another folder.
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

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use RuntimeException;
use Throwable;

/**
 * The backup files an administrator can restore from.
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */
class BackupFiles {

	private const NAME_PATTERN = '/^hermiq-backup-[a-z0-9-]+-\d{4}-\d{2}-\d{2}-\d{4}\.json\.gz$/';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Nextcloud Files.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * Whether a name is one BackupWriter gives a backup file.
	 *
	 * @param string $name The name.
	 *
	 * @return bool Whether it is.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public static function isBackupName(string $name): bool {
		return preg_match(self::NAME_PATTERN, $name) === 1;
	}//end isBackupName()

	/**
	 * The backups in the folder, newest first, each with its manifest.
	 *
	 * A file that cannot be read is listed with an empty manifest, so the
	 * administrator sees it is there.
	 *
	 * @param BackupSettings $settings The settings.
	 *
	 * @return list<array{name: string, manifest: array<string, mixed>}> The backups.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function list(BackupSettings $settings): array {
		$folder = $this->folder(settings: $settings);
		if ($folder === null) {
			return [];
		}

		$files = array_values(
			array_filter(
				$folder->getDirectoryListing(),
				static fn (Node $node): bool => $node instanceof File && self::isBackupName(name: $node->getName()) === true
			)
		);
		// Newest first; backups of the same minute by name.
		usort(
			$files,
			static function (Node $a, Node $b): int {
				$byTime = strcmp(self::stamp(name: $b->getName()), self::stamp(name: $a->getName()));
				if ($byTime !== 0) {
					return $byTime;
				}

				return strcmp($a->getName(), $b->getName());
			}
		);

		$backups = [];
		foreach ($files as $file) {
			$manifest = [];
			try {
				$manifest = (array)($this->decode(file: $file)['manifest'] ?? []);
			} catch (Throwable) {
				$manifest = [];
			}

			$backups[] = ['name' => $file->getName(), 'manifest' => $manifest];
		}

		return $backups;
	}//end list()

	/**
	 * Read one backup file.
	 *
	 * @param BackupSettings $settings The settings.
	 * @param string         $name     The file name.
	 *
	 * @return array{manifest: array<string, mixed>, objects: array<string, list<array<string, mixed>>>} The backup.
	 *
	 * @throws RuntimeException When the name is not a backup's, the file is not there, or it cannot be read.
	 *
	 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
	 */
	public function read(BackupSettings $settings, string $name): array {
		if (self::isBackupName(name: $name) === false) {
			throw new RuntimeException('Not a backup file name.');
		}

		$folder = $this->folder(settings: $settings);
		if ($folder === null || $folder->nodeExists($name) === false) {
			throw new RuntimeException('The backup ' . $name . ' is not in the backup folder.');
		}

		$file = $folder->get($name);
		if (($file instanceof File) === false) {
			throw new RuntimeException('The backup ' . $name . ' is not a file.');
		}

		$document = $this->decode(file: $file);

		return [
			'manifest' => (array)($document['manifest'] ?? []),
			'objects'  => (array)($document['objects'] ?? []),
		];
	}//end read()

	/**
	 * The backup folder, or null when there is none yet.
	 *
	 * @param BackupSettings $settings The settings.
	 *
	 * @return Folder|null The folder.
	 */
	private function folder(BackupSettings $settings): ?Folder {
		if ($settings->ownerUid === '') {
			return null;
		}

		$home = $this->rootFolder->getUserFolder($settings->ownerUid);
		if ($home->nodeExists($settings->folder) === false) {
			return null;
		}

		$node = $home->get($settings->folder);
		if (($node instanceof Folder) === false) {
			return null;
		}

		return $node;
	}//end folder()

	/**
	 * The gzipped JSON of a file.
	 *
	 * @param File $file The file.
	 *
	 * @return array<string, mixed> The document.
	 *
	 * @throws RuntimeException When it is not a gzipped JSON object.
	 */
	private function decode(File $file): array {
		$raw = @gzdecode((string)$file->getContent());
		$document = json_decode((string)$raw, true);
		if ($raw === false || is_array($document) === false) {
			throw new RuntimeException('The backup ' . $file->getName() . ' cannot be read.');
		}

		return $document;
	}//end decode()

	/**
	 * The time part of a backup name, which sorts in creation order.
	 *
	 * @param string $name The name.
	 *
	 * @return string The `yyyy-mm-dd-hhmm` part.
	 */
	private static function stamp(string $name): string {
		return substr($name, -(strlen('yyyy-mm-dd-hhmm') + strlen(BackupWriter::FILE_SUFFIX)));
	}//end stamp()
}//end class
