<?php

/**
 * Hermiq AudioToAudioTranslateProvider.
 *
 * Offers the translate-a-recording chain to other apps as a TaskProcessing
 * provider for `core:audio2audio:translate`. That task type exists from
 * Nextcloud 35; ProviderRegistrar registers this provider only when its class
 * is present, so on Nextcloud 32 to 34 it is absent without error. Nothing in
 * this file references the task type class, so loading it never fails either.
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

use OCA\Hermiq\Service\Speech\SpeechTranslationException;
use OCA\Hermiq\Service\Speech\TranslationChain;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\TaskProcessing\ISynchronousProvider;
use RuntimeException;

/**
 * Transcribe, translate and speak one recording for a TaskProcessing task.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-other-apps-can-use-the-chain-through-taskprocessing-where-nextcloud-defines-it-req-strn-004
 */
class AudioToAudioTranslateProvider implements ISynchronousProvider {
	use EmptyOptionalShapesTrait;

	/**
	 * The task type id, as a string so this class loads where the type does not exist.
	 *
	 * @var string
	 */
	public const TASK_TYPE_ID = 'core:audio2audio:translate';

	/**
	 * The task type class, checked before registration.
	 *
	 * @var string
	 */
	public const TASK_TYPE_CLASS = 'OCP\\TaskProcessing\\TaskTypes\\AudioToAudioTranslate';

	/**
	 * The origin-language value Nextcloud uses for "detect it".
	 *
	 * @var string
	 */
	private const DETECT = 'detect_language';

	/**
	 * Constructor.
	 *
	 * @param TranslationChain $chain The translate-a-recording chain.
	 */
	public function __construct(
		private readonly TranslationChain $chain,
	) {
	}//end __construct()

	/**
	 * Whether Nextcloud defines the task type on this instance.
	 *
	 * @return bool True from Nextcloud 35.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-nextcloud-34-boots-without-the-provider
	 */
	public static function isSupported(): bool {
		return class_exists(self::TASK_TYPE_CLASS);
	}//end isSupported()

	/**
	 * The provider id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getId(): string {
		return 'hermiq:audio2audio:translate';
	}//end getId()

	/**
	 * The provider name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getName(): string {
		return 'Hermiq (local Whisper, governed translation, local voice)';
	}//end getName()

	/**
	 * The task type this provider runs.
	 *
	 * @return string The task type id.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getTaskTypeId(): string {
		return self::TASK_TYPE_ID;
	}//end getTaskTypeId()

	/**
	 * The expected runtime in seconds.
	 *
	 * @return int Seconds.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getExpectedRuntime(): int {
		return 240;
	}//end getExpectedRuntime()

	/**
	 * No optional inputs beyond the task type's own.
	 *
	 * @return array The shapes.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getOptionalInputShape(): array {
		return [];
	}//end getOptionalInputShape()

	/**
	 * No optional outputs beyond the task type's own.
	 *
	 * @return array The shapes.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function getOptionalOutputShape(): array {
		return [];
	}//end getOptionalOutputShape()

	/**
	 * Translate the task's recording and return the spoken translation.
	 *
	 * The `nextcloud` chat provider is refused for the translation step: it would
	 * hand the work back to TaskProcessing, which could pick this provider again.
	 *
	 * @param string|null $userId         The task's user.
	 * @param array       $input          `input` (the recording), `origin_language`, `target_language`.
	 * @param callable    $reportProgress Progress callback.
	 *
	 * @return array `['audio_output' => <WAV bytes>]`.
	 *
	 * @throws RuntimeException When the recording is missing, a step is refused, or the
	 *                          target language has no voice, so nothing can be spoken.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-other-apps-can-use-the-chain-through-taskprocessing-where-nextcloud-defines-it-req-strn-004
	 */
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$audio = ($input['input'] ?? null);
		if ($audio instanceof File === true || $audio instanceof ISimpleFile === true) {
			$audio = $audio->getContent();
		}

		if (is_resource($audio) === true) {
			$audio = stream_get_contents($audio);
		}

		if (is_string($audio) === false || $audio === '') {
			throw new RuntimeException('No audio was supplied to translate.');
		}

		$origin = (string)($input['origin_language'] ?? '');
		if ($origin === self::DETECT) {
			$origin = '';
		}

		$reportProgress(0.1);

		try {
			$result = $this->chain->translate(
				audio: $audio,
				targetLanguage: (string)($input['target_language'] ?? ''),
				sourceLanguage: $origin,
				uid: $userId,
				allowNextcloud: false
			);
		} catch (SpeechTranslationException $e) {
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		if ($result['audio'] === null) {
			throw new RuntimeException(
				'There is no voice for "' . (string)($input['target_language'] ?? '') . '" on this server, so the translation cannot be spoken.'
			);
		}

		$reportProgress(1.0);

		return ['audio_output' => $result['audio']];
	}//end process()
}//end class
