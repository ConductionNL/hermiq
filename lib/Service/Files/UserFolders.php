<?php

/**
 * Hermiq UserFolders.
 *
 * Opens a nested folder under a person's Files root, making every missing
 * segment on the way. Shared by the writers that keep generated output in a
 * fixed `Hermiq/...` folder.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Files
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Files;

use OCP\Files\Folder;

/**
 * Walks a slash-separated path below a folder, creating what is missing.
 *
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
 */
final class UserFolders {

	/**
	 * The folder at `$path` below `$folder`; a missing segment, or one that is a
	 * file rather than a folder, is made as a new folder.
	 *
	 * @param Folder $folder The folder to start from (a person's Files root).
	 * @param string $path   The path below it, e.g. `Hermiq/Translations`.
	 *
	 * @return Folder The innermost folder.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public static function open(Folder $folder, string $path): Folder {
		foreach (explode('/', $path) as $segment) {
			$next = null;
			if ($folder->nodeExists($segment) === true) {
				$next = $folder->get($segment);
			}

			if ($next instanceof Folder === false) {
				$next = $folder->newFolder($segment);
			}

			$folder = $next;
		}

		return $folder;
	}//end open()
}//end class
