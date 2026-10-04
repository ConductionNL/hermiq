<?php

/**
 * Hermiq GeneratedImageFiles.
 *
 * The Files side of image creation: reads a text-to-image task's output (bytes or
 * the id of the file Nextcloud stored it under) and writes the PNG into the
 * person's `Hermiq/Generated images` folder (chat-attachments-and-images D7).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Reads the task output and writes the created PNG to the person's Files.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */
class GeneratedImageFiles {

	/**
	 * Where created images land, relative to the person's Files root.
	 *
	 * @var string
	 */
	public const FOLDER = 'Hermiq/Generated images';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder The person's Files and the task's output file.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The bytes of one task output: a file id Nextcloud stored it under, or the bytes.
	 *
	 * @param mixed $output The output value.
	 *
	 * @return string|null The bytes.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
	 */
	public function outputBytes(mixed $output): ?string {
		if (is_string($output) === true) {
			return $output;
		}

		if (is_int($output) === false) {
			return null;
		}

		$node = $this->rootFolder->getFirstNodeById($output);
		if ($node instanceof File === false) {
			return null;
		}

		return $node->getContent();
	}//end outputBytes()

	/**
	 * Write the PNG into the person's `Hermiq/Generated images` folder.
	 *
	 * @param string $uid   The person.
	 * @param string $bytes The PNG bytes.
	 *
	 * @return File The new file.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
	 */
	public function save(string $uid, string $bytes): File {
		$folder = $this->rootFolder->getUserFolder($uid);
		foreach (explode('/', self::FOLDER) as $segment) {
			$next = null;
			if ($folder->nodeExists($segment) === true) {
				$next = $folder->get($segment);
			}

			if ($next instanceof Folder === false) {
				$next = $folder->newFolder($segment);
			}

			$folder = $next;
		}

		$name = $folder->getNonExistingName('image-' . gmdate('Y-m-d-His') . '.png');
		return $folder->newFile($name, $bytes);
	}//end save()
}//end class
