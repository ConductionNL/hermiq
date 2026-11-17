<?php

/**
 * Hermiq KnowledgeBaseService.
 *
 * The owner's writes on a knowledge base that are not plain object edits:
 * uploading documents into its folder in Files and adding each as a `file`
 * source. Documents stay in Files; nothing is copied out of them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\KnowledgeBase;

use OCA\Hermiq\Kernel\Contracts\KnowledgeBaseServiceInterface;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Files\FolderPath;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Uploads into a knowledge base's folder, as its owner.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
class KnowledgeBaseService implements KnowledgeBaseServiceInterface {
	use SanitizesForSaveTrait;

	/**
	 * The register the knowledge bases live in.
	 */
	private const REGISTER = 'hermiq';

	/**
	 * The most documents one request uploads.
	 */
	public const MAX_UPLOADS = 20;

	/**
	 * The largest document accepted, in bytes (100 MiB).
	 */
	public const MAX_BYTES = 104857600;

	/**
	 * The folder uploads land in when the knowledge base names none, before its name.
	 */
	public const DEFAULT_FOLDER = 'Hermiq/Knowledge bases';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objects    OpenRegister's objects, for the knowledge base.
	 * @param IRootFolder     $rootFolder The owner's Files.
	 * @param LoggerInterface $logger     Where a failed write is noted.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the uploads into the knowledge base's folder and add each as a `file` source.
	 *
	 * @param string                           $id        The knowledge base uuid.
	 * @param string                           $callerUid The person uploading; must own the knowledge base.
	 * @param array<int, array<string, mixed>> $uploads   PHP upload slots (name, tmp_name, size, error).
	 *
	 * @return array<int, array<string, mixed>> The knowledge base's sources after the upload.
	 *
	 * @throws KnowledgeBaseException 404 when the caller does not own it, 400 for an unusable upload.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function upload(string $id, string $callerUid, array $uploads): array {
		$entity  = $this->owned(id: $id, callerUid: $callerUid);
		$uploads = self::usable(uploads: $uploads);
		$payload = $entity->getObject();
		$sources = self::sourcesOf(knowledgeBase: $payload);

		$userFolder = $this->rootFolder->getUserFolder($callerUid);
		$path       = self::folderPathOf(knowledgeBase: $payload);
		try {
			$folder = self::folderIn(parent: $userFolder, path: $path);
			foreach ($uploads as $upload) {
				$sources[] = $this->write(folder: $folder, userFolder: $userFolder, upload: $upload);
			}
		} catch (KnowledgeBaseException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning('[KnowledgeBaseService] A document could not be saved: '.$e->getMessage(), ['knowledgeBase' => $id]);
			throw new KnowledgeBaseException('The document could not be saved.', 500);
		}

		unset($payload['@self'], $payload['id']);
		$payload['sources']    = $sources;
		$payload['folderPath'] = implode('/', $path);
		$this->objects->saveObject(
			object: $this->sanitizeForSave(data: $payload),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: KnowledgeBaseRetrieval::SCHEMA),
				uuid: $id
			)
		);

		return $sources;
	}//end upload()

	/**
	 * The knowledge base when the caller owns it.
	 *
	 * @param string $id        The knowledge base uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws KnowledgeBaseException 404 when it is missing or someone else's.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function owned(string $id, string $callerUid): ObjectEntity {
		$entity = null;
		if ($callerUid !== '' && trim($id) !== '') {
			try {
				$entity = $this->objects->find(
					id: $id,
					options: new ObjectFindOptions(register: self::REGISTER, schema: KnowledgeBaseRetrieval::SCHEMA),
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable) {
				$entity = null;
			}
		}

		if ($entity instanceof ObjectEntity === false || (string)$entity->getOwner() !== $callerUid) {
			throw new KnowledgeBaseException('Knowledge base not found.', 404);
		}

		return $entity;
	}//end owned()

	/**
	 * The folder path segments of a knowledge base, cleaned of empty, `.` and `..` parts.
	 *
	 * @param array<string, mixed> $knowledgeBase The knowledge base.
	 *
	 * @return array<int, string> At least one segment.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public static function folderPathOf(array $knowledgeBase): array {
		$path = self::segments(path: (string)($knowledgeBase['folderPath'] ?? ''));
		if ($path !== []) {
			return $path;
		}

		$name = self::segments(path: (string)preg_replace('/\s+/', ' ', str_replace('/', ' ', (string)($knowledgeBase['name'] ?? ''))));

		return [...self::segments(path: self::DEFAULT_FOLDER), ($name[0] ?? 'Knowledge base')];
	}//end folderPathOf()

	/**
	 * The clean segments of a path.
	 *
	 * @param string $path The path.
	 *
	 * @return array<int, string>
	 */
	private static function segments(string $path): array {
		$parts = explode('/', str_replace(['\\', "\0", "\n", "\r"], ['/', '', '', ''], $path));
		$parts = array_map('trim', $parts);

		return array_values(array_filter($parts, static fn (string $part): bool => in_array($part, ['', '.', '..'], true) === false));
	}//end segments()

	/**
	 * The uploads that arrived whole, refusing the request when none did or too many came.
	 *
	 * @param array<int, array<string, mixed>> $uploads The upload slots.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws KnowledgeBaseException 400 for no usable upload, too many or one too large.
	 */
	private static function usable(array $uploads): array {
		$usable = [];
		foreach ($uploads as $upload) {
			if (is_array($upload) === true && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
				$usable[] = $upload;
			}
		}

		if ($usable === []) {
			throw new KnowledgeBaseException('No document was uploaded.', 400);
		}

		if (count($usable) > self::MAX_UPLOADS) {
			throw new KnowledgeBaseException('Upload at most '.self::MAX_UPLOADS.' documents at a time.', 400);
		}

		foreach ($usable as $upload) {
			if ((int)($upload['size'] ?? 0) > self::MAX_BYTES) {
				throw new KnowledgeBaseException('A document is larger than 100 MB.', 400);
			}
		}

		return $usable;
	}//end usable()

	/**
	 * Write one upload and describe it as a `file` source.
	 *
	 * @param Folder               $folder     The knowledge base folder.
	 * @param Folder               $userFolder The owner's Files root, for the display path.
	 * @param array<string, mixed> $upload     The upload slot.
	 *
	 * @return array{type: string, fileId: int, path: string}
	 */
	private function write(Folder $folder, Folder $userFolder, array $upload): array {
		$bytes = file_get_contents((string)($upload['tmp_name'] ?? ''));
		if ($bytes === false) {
			throw new KnowledgeBaseException('A document could not be read.', 400);
		}

		$file = $folder->newFile($folder->getNonExistingName(self::cleanName(name: (string)($upload['name'] ?? ''))), $bytes);

		return [
			'type' => 'file',
			'fileId' => (int)$file->getId(),
			'path' => (string)($userFolder->getRelativePath($file->getPath()) ?? $file->getName()),
		];
	}//end write()

	/**
	 * A safe file name from an upload's name.
	 *
	 * @param string $name The name the browser sent.
	 *
	 * @return string
	 */
	private static function cleanName(string $name): string {
		$name = trim(basename(str_replace('\\', '/', $name)));
		$name = str_replace(["\0", "\n", "\r"], '', $name);
		if ($name === '' || $name === '.' || $name === '..') {
			return 'document';
		}

		return mb_substr($name, 0, 200);
	}//end cleanName()

	/**
	 * Open or create a folder path under a parent.
	 *
	 * @param Folder             $parent The parent.
	 * @param array<int, string> $path   The segments.
	 *
	 * @return Folder
	 *
	 * @throws KnowledgeBaseException 400 when a file is in the way.
	 */
	private static function folderIn(Folder $parent, array $path): Folder {
		return FolderPath::ensure(
			parent: $parent,
			path: $path,
			inTheWay: static fn (): Throwable => new KnowledgeBaseException('A file is in the way of the knowledge base folder.', 400)
		);
	}//end folderIn()

	/**
	 * The knowledge base's sources that are arrays.
	 *
	 * @param array<string, mixed> $knowledgeBase The knowledge base.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sourcesOf(array $knowledgeBase): array {
		$sources = ($knowledgeBase['sources'] ?? []);
		if (is_array($sources) === false) {
			return [];
		}

		return array_values(array_filter($sources, 'is_array'));
	}//end sourcesOf()
}//end class
