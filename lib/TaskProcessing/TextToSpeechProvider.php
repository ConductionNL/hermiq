<?php

/**
 * Hermiq TextToSpeech TaskProcessing provider (speech-services).
 *
 * ⚠️ SYNTHESIS COVERAGE IS FAR NARROWER THAN TRANSCRIPTION COVERAGE. Whisper is
 * measured across the 24 official EU languages; Kokoro covers roughly nine. A
 * single "supported languages" figure would be false in one direction whichever
 * number was quoted, so support is declared PER DIRECTION — and a user who can
 * dictate in a language cannot necessarily be answered in it.
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
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Text-to-speech via the runner sidecar.
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
 */
class TextToSpeechProvider implements ISynchronousProvider {

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
		return 'hermiq:text2speech';

	}//end getId()

	/**
	 * The human-readable name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Hermiq (local Kokoro)';

	}//end getName()

	/**
	 * The task type served.
	 *
	 * @return string The task type id.
	 */
	public function getTaskTypeId(): string {
		return TextToSpeech::ID;

	}//end getTaskTypeId()

	/**
	 * A rough runtime hint, in seconds.
	 *
	 * @return int Seconds.
	 */
	public function getExpectedRuntime(): int {
		return 30;

	}//end getExpectedRuntime()

	/**
	 * Optional inputs.
	 *
	 * @return array<string, ShapeDescriptor> The shape.
	 */
	public function getOptionalInputShape(): array {
		return [
			'voice' => new ShapeDescriptor(
				'Voice',
				'Voice id. Voices are language-specific, and synthesis covers far fewer '
				. 'languages than transcription does.',
				EShapeType::Text
			),
		];

	}//end getOptionalInputShape()

	/**
	 * Optional outputs.
	 *
	 * @return array<string, ShapeDescriptor> Empty.
	 */
	public function getOptionalOutputShape(): array {
		return [];

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
	 * @return array<array-key, numeric|string> The defaults.
	 */
	public function getOptionalInputShapeDefaults(): array {
		return ['voice' => 'af_heart'];

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
	 * Synthesise.
	 *
	 * @param string|null $userId The acting user, or null.
	 * @param array<string, mixed> $input The task input.
	 * @param callable $reportProgress Progress callback.
	 *
	 * @return array<string, list<numeric|string>|numeric|string> The task output.
	 *
	 * @throws RuntimeException When no text was supplied.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
	 */
	public function process(?string $userId, array $input, callable $reportProgress): array {
		$text = trim((string)($input['input'] ?? ''));
		if ($text === '') {
			throw new RuntimeException('No text was supplied to speak.');
		}

		$reportProgress(0.1);

		$result = $this->speech->synthesise(
			text: $text,
			voice: (string)($input['voice'] ?? 'af_heart')
		);

		$reportProgress(1.0);

		// Engine only — never the text that was spoken.
		$this->logger->info(
			'Hermiq: synthesis completed',
			['engine' => $result['engine'], 'user' => $userId]
		);

		return ['speech' => $result['audio']];

	}//end process()

}//end class
