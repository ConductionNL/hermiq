<?php

/**
 * Hermiq FolderPath.
 *
 * Walks a path of segments under a folder in Files, creating each missing
 * folder, and refuses when a segment is taken by a file.
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Files;

use OCP\Files\Folder;
use Throwable;

/**
 * The folder at a path under a parent, created where missing.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
final class FolderPath {

	/**
	 * The folder at a path under a parent, created where missing.
	 *
	 * @param Folder                 $parent   The starting folder.
	 * @param array<string>          $path     The segments, already cleaned by the caller.
	 * @param callable(): Throwable  $inTheWay The exception to throw when a segment exists as a file.
	 *
	 * @return Folder
	 *
	 * @throws Throwable The one $inTheWay gives, when a segment exists as a file.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/tasks.md
	 */
	public static function ensure(Folder $parent, array $path, callable $inTheWay): Folder {
		$folder = $parent;
		foreach ($path as $segment) {
			if ($folder->nodeExists($segment) === false) {
				$folder = $folder->newFolder($segment);
				continue;
			}

			$node = $folder->get($segment);
			if ($node instanceof Folder === false) {
				throw $inTheWay();
			}

			$folder = $node;
		}

		return $folder;
	}//end ensure()
}//end class
