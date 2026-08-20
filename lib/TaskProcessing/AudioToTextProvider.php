<?php

/**
 * Hermiq AudioToText TaskProcessing provider (speech-services).
 *
 * Registers transcription as a Nextcloud TaskProcessing provider rather than as a
 * Hermiq-private API, so Assistant, Talk and every other TaskProcessing consumer
 * gets dictation without knowing Hermiq exists.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCA\Hermiq\Service\Speech\SpeechClient;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Speech-to-text via the runner sidecar.
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
 */
class AudioToTextProvider implements ISynchronousProvider {

	/**
	 * Constructor.
	 *
	 * @param SpeechClient $speech The sidecar transport.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly SpeechClient $speech,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'hermiq:audio2text';

	}//end getId()

	/**
	 * The human-readable name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Hermiq (local Whisper)';

	}//end getName()

	/**
	 * The task type served.
	 *
	 * @return string The task type id.
	 */
	public function getTaskTypeId(): string {
		return AudioToText::ID;

	}//end getTaskTypeId()

	/**
	 * A rough runtime hint, in seconds.
	 *
	 * Deliberately generous: CPU inference on a short clip can take minutes, and
	 * an optimistic figure here produces spurious timeouts rather than speed.
	 *
	 * @return int Seconds.
	 */
	public function getExpectedRuntime(): int {
		return 120;

	}//end getExpectedRuntime()

	/**
	 * Optional inputs.
	 *
	 * @return array<string, ShapeDescriptor> The shape.
	 */
	public function getOptionalInputShape(): array {
		return [
			'language' => new ShapeDescriptor(
				'Language',
				'ISO code of the spoken language. State it when known — automatic detection '
				. 'misfires on short utterances and on speech that mixes languages.',
				EShapeType::Text
			),
		];

	}//end getOptionalInputShape()

	/**
	 * Optional outputs — the language actually used, so a wrong-language
	 * transcript is diagnosable rather than invisible.
	 *
	 * @return array<string, ShapeDescriptor> The shape.
	 */
	public function getOptionalOutputShape(): array {
		return [
			'language' => new ShapeDescriptor('Language', 'The language the model actually used.', EShapeType::Text),
		];

	}//end getOptionalOutputShape()

	/**
	 * Input enum values.
	 *
	 * @return array<array-key, array<array-key, ShapeEnumValue>> Empty.
	 */
	public function getInputShapeEnumValues(): array {
		return [];

	}//end getInputShapeEnumValues()

	/**
	 * Input defaults.
	 *
	 * @return array<array-key, numeric|string> Empty.
	 */
	public function getInputShapeDefaults(): array {
		return [];

	}//end getInputShapeDefaults()

	/**
	 * Optional input enum values.
	 *
	 * @return array<array-key, array<array-key, ShapeEnumValue>> Empty.
	 */
	public function getOptionalInputShapeEnumValues(): array {
		return [];

	}//end getOptionalInputShapeEnumValues()

	/**
	 * Optional input defaults.
	 *
	 * @return array<array-key, numeric|string> Empty.
	 */
	public function getOptionalInputShapeDefaults(): array {
		return [];

	}//end getOptionalInputShapeDefaults()

	/**
	 * Output enum values.
	 *
	 * @return array<array-key, array<array-key, ShapeEnumValue>> Empty.
	 */
	public function getOutputShapeEnumValues(): array {
		return [];

	}//end getOutputShapeEnumValues()

	/**
	 * Optional output enum values.
	 *
	 * @return array<array-key, array<array-key, ShapeEnumValue>> Empty.
	 */
	public function getOptionalOutputShapeEnumValues(): array {
		return [];

	}//end getOptionalOutputShapeEnumValues()

	/**
	 * Transcribe.
	 *
	 * @param string|null $userId The acting user, or null.
	 * @param array<string, mixed> $input The task input.
	 * @param callable $reportProgress Progress callback.
	 *
	 * @return array<string, list<numeric|string>|numeric|string> The task output.
	 *
	 * @throws RuntimeException When the sidecar is unavailable.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
	 */
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$audio = ($input['input'] ?? null);

		// 🔴 NEXTCLOUD HANDS AN `OCP\Files\File` NODE, NOT A RESOURCE AND NOT BYTES.
		// This method used to accept only the latter two, so EVERY audio2text task
		// failed with the message below — measured 2026-08-20 on task 3, the first
		// such task ever scheduled on the dev instance:
		//
		//   RuntimeException: No audio was supplied to transcribe.
		//   Manager.php:1139 → AudioToTextProvider->process('admin',
		//     ['input' => OC\Files\Node\File], Closure)
		//
		// It read as a sidecar problem and was not: the request never left PHP.
		// Nothing caught it because the provider had no caller — the sidecar was
		// unreachable from this container until the network fix in the speech
		// compose, so the queue count for this task type was zero.
		//
		// All three shapes are accepted rather than the one this version happens
		// to pass: the ISimpleFile/File split differs by input source, and a
		// provider that guesses is a provider that breaks on the next caller.
		if ($audio instanceof File === true || $audio instanceof ISimpleFile === true) {
			$audio = $audio->getContent();
		}

		if (is_resource($audio) === true) {
			$audio = stream_get_contents($audio);
		}

		if (is_string($audio) === false || $audio === '') {
			throw new RuntimeException('No audio was supplied to transcribe.');
		}

		$reportProgress(0.1);

		$result = $this->speech->transcribe(
			audio: $audio,
			language: (string)($input['language'] ?? '')
		);

		$reportProgress(1.0);

		// The engine is logged, never the transcript: an oversight record that
		// quotes the audio becomes a second copy of the conversation in a place
		// with different access rules.
		$this->logger->info(
			'Hermiq: transcription completed',
			['engine' => $result['engine'], 'language' => $result['language'], 'user' => $userId]
		);

		return ['output' => $result['text'], 'language' => $result['language']];

	}//end process()

}//end class
