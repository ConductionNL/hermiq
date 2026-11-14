<?php

/**
 * Hermiq TranslatedAudioFiles.
 *
 * Reads a recording from the person's own Files and writes the spoken
 * translation back into their Files under `Hermiq/Translations`.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Speech
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

namespace OCA\Hermiq\Service\Speech;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * The recording's bytes in, the spoken translation's file out.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
class TranslatedAudioFiles {

	/**
	 * Where spoken translations land, relative to the person's Files root.
	 *
	 * @var string
	 */
	public const FOLDER = 'Hermiq/Translations';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder The person's Files.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * A recording in the person's own Files, read as them.
	 *
	 * Resolved in the person's user folder only, so a file id they cannot open
	 * reads as absent, exactly like a file id that does not exist.
	 *
	 * @param string $uid    The person.
	 * @param int    $fileId The file id.
	 *
	 * @return array{bytes: string, name: string, size: int}|null The recording, or null when they cannot read it.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function readRecording(string $uid, int $fileId): ?array {
		if ($uid === '' || $fileId < 1) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
			if ($node instanceof File === false || $node->isReadable() === false) {
				return null;
			}

			return ['bytes' => $node->getContent(), 'name' => $node->getName(), 'size' => (int)$node->getSize()];
		} catch (Throwable $e) {
			return null;
		}
	}//end readRecording()

	/**
	 * Write the spoken translation into the person's `Hermiq/Translations` folder.
	 *
	 * @param string $uid            The person.
	 * @param string $bytes          The WAV bytes.
	 * @param string $sourceLanguage The language the recording was in.
	 * @param string $targetLanguage The language it was translated into.
	 *
	 * @return File The new file.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function save(string $uid, string $bytes, string $sourceLanguage, string $targetLanguage): File {
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

		$name = $folder->getNonExistingName($this->fileName(sourceLanguage: $sourceLanguage, targetLanguage: $targetLanguage));
		return $folder->newFile($name, $bytes);
	}//end save()

	/**
	 * The file name: the source and the target language and the time, e.g. `ar-to-nl-2026-11-13-101500.wav`.
	 *
	 * @param string $sourceLanguage The source language, or '' when unknown.
	 * @param string $targetLanguage The target language.
	 *
	 * @return string The name.
	 */
	private function fileName(string $sourceLanguage, string $targetLanguage): string {
		$source = $this->slug(value: $sourceLanguage);
		if ($source === '') {
			$source = 'recording';
		}

		return $source . '-to-' . $this->slug(value: $targetLanguage) . '-' . gmdate('Y-m-d-His') . '.wav';
	}//end fileName()

	/**
	 * A language code reduced to characters safe in a file name.
	 *
	 * @param string $value The code.
	 *
	 * @return string The safe code.
	 */
	private function slug(string $value): string {
		return (string)preg_replace('/[^A-Za-z0-9\-]/', '', $value);
	}//end slug()
}//end class
