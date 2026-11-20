<?php

/**
 * Hermiq TaskAudioInput.
 *
 * Reads the audio of a TaskProcessing task's `input` slot, whatever shape
 * Nextcloud hands it in: an `OCP\Files\File` node, an `ISimpleFile`, a stream
 * resource or the bytes themselves.
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use RuntimeException;

/**
 * The audio bytes of a task input.
 *
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
 */
final class TaskAudioInput {

	/**
	 * The bytes of the audio in the task's `input` slot.
	 *
	 * All three shapes are accepted rather than the one a Nextcloud version happens
	 * to pass: the ISimpleFile/File split differs by input source.
	 *
	 * @param array<string, mixed> $input   The task input.
	 * @param string               $purpose What the audio is for, in the error: `transcribe`, `translate`.
	 *
	 * @return string The audio bytes, never empty.
	 *
	 * @throws RuntimeException When the input holds no audio.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public static function bytes(array $input, string $purpose): string {
		$audio = ($input['input'] ?? null);
		if ($audio instanceof File === true || $audio instanceof ISimpleFile === true) {
			$audio = $audio->getContent();
		}

		if (is_resource($audio) === true) {
			$audio = stream_get_contents($audio);
		}

		if (is_string($audio) === false || $audio === '') {
			throw new RuntimeException('No audio was supplied to ' . $purpose . '.');
		}

		return $audio;
	}//end bytes()
}//end class
