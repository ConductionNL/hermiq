<?php

/**
 * Hermiq AttachmentStore.
 *
 * Keeps a chat attachment in the person's own Files, under
 * Hermiq/Attachments/<yyyy-mm>/, with Nextcloud's normal name-conflict suffix.
 * The file is theirs: they see it, share rules apply to it, and deleting it
 * removes it everywhere. A refused type or a file over the admin's size cap is
 * refused before anything is written (chat-attachments-and-images, D1).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use RuntimeException;

/**
 * Stores chat attachments in the person's Files.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */
class AttachmentStore {

	/**
	 * App config key of the size cap, in MB.
	 */
	public const CAP_KEY = 'chat.attachmentMaxMb';

	/**
	 * The size cap when the admin set none.
	 */
	public const DEFAULT_CAP_MB = 20;

	/**
	 * The folder under the person's Files root.
	 */
	private const FOLDER = ['Hermiq', 'Attachments'];

	/**
	 * Exact types allowed besides text/*: JSON, images a model can read, PDF and office documents (text fallback).
	 */
	private const ALLOWED_TYPES = [
		'application/json',
		'image/png',
		'image/jpeg',
		'image/webp',
		'image/gif',
		'application/pdf',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
	];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder       $rootFolder       The person's Files.
	 * @param IAppConfig        $appConfig        The admin's size cap.
	 * @param IMimeTypeDetector $mimeTypeDetector The type, by the file name, as Nextcloud classifies it.
	 * @param IL10N             $l10n             Refusals in the person's language.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $appConfig,
		private readonly IMimeTypeDetector $mimeTypeDetector,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Keep one upload in the person's Files.
	 *
	 * @param string               $uid    The person.
	 * @param array<string, mixed> $upload The PHP upload slot (name, tmp_name, size).
	 *
	 * @return array{path: string, name: string, fileId: int, mimeType: string, size: int}
	 *
	 * @throws AttachmentRefusedException For a refused type or a file over the cap; nothing is written.
	 * @throws RuntimeException           When the file could not be read or written.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
	 */
	public function store(string $uid, array $upload): array {
		$name = $this->cleanName(name: (string)($upload['name'] ?? ''));
		$size = (int)($upload['size'] ?? 0);
		$capMb = max(1, $this->appConfig->getValueInt('hermiq', self::CAP_KEY, self::DEFAULT_CAP_MB));
		if ($size > ($capMb * 1024 * 1024)) {
			throw new AttachmentRefusedException($this->l10n->t('This file is larger than %s MB.', [$capMb]));
		}

		$mimeType = $this->mimeTypeDetector->detectPath($name);
		if (self::isAllowedType(mimeType: $mimeType) === false) {
			throw new AttachmentRefusedException($this->l10n->t('Hermiq cannot read files of this type.'));
		}

		$bytes = file_get_contents((string)($upload['tmp_name'] ?? ''));
		if ($bytes === false) {
			throw new RuntimeException('The upload could not be read.');
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		$folder     = $this->folderIn(parent: $userFolder, path: [...self::FOLDER, gmdate('Y-m')]);
		$file       = $folder->newFile($folder->getNonExistingName($name), $bytes);

		return [
			'path' => $userFolder->getRelativePath($file->getPath()) ?? '',
			'name' => $file->getName(),
			'fileId' => (int)$file->getId(),
			'mimeType' => $mimeType,
			'size' => strlen($bytes),
		];
	}//end store()

	/**
	 * Whether Hermiq keeps a file of this type: text, the four image types a model can read, PDF, office documents.
	 *
	 * @param string $mimeType The type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
	 */
	public static function isAllowedType(string $mimeType): bool {
		$mimeType = strtolower(trim($mimeType));

		return str_starts_with($mimeType, 'text/') || in_array($mimeType, self::ALLOWED_TYPES, true);
	}//end isAllowedType()

	/**
	 * The folder at a path under a parent, created where missing.
	 *
	 * @param Folder        $parent The starting folder.
	 * @param array<string> $path   The segments.
	 *
	 * @return Folder
	 *
	 * @throws RuntimeException When a segment exists as a file.
	 */
	private function folderIn(Folder $parent, array $path): Folder {
		$folder = $parent;
		foreach ($path as $segment) {
			if ($folder->nodeExists($segment) === false) {
				$folder = $folder->newFolder($segment);
				continue;
			}

			$node = $folder->get($segment);
			if ($node instanceof Folder === false) {
				throw new RuntimeException('A file is in the way of the attachments folder.');
			}

			$folder = $node;
		}

		return $folder;
	}//end folderIn()

	/**
	 * The file name without any path, and never empty.
	 *
	 * @param string $name The uploaded name.
	 *
	 * @return string
	 */
	private function cleanName(string $name): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = str_replace(["\0", "\n", "\r"], '', $name);
		if ($name === '' || $name === '.' || $name === '..') {
			return 'attachment';
		}

		return mb_substr($name, 0, 200);
	}//end cleanName()
}//end class
