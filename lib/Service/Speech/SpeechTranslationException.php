<?php

/**
 * Hermiq SpeechTranslationException.
 *
 * Why a recording was not translated, with a stable code for the route and the
 * chat action, and the HTTP status that code answers with.
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
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Speech;

use RuntimeException;

/**
 * A refused or failed translation of a recording.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
class SpeechTranslationException extends RuntimeException {

	/**
	 * The HTTP status per code; anything else is 502.
	 *
	 * @var array<string, int>
	 */
	private const STATUS_FOR = [
		'invalid_request' => 400,
		'not_found' => 404,
		'feature_not_enabled' => 409,
		'provider_refused' => 422,
		'nothing_heard' => 422,
	];

	/**
	 * Constructor.
	 *
	 * @param string $errorCode The stable code (invalid_request, not_found, feature_not_enabled,
	 *                          provider_refused, nothing_heard, speech_unavailable,
	 *                          translation_failed, marking_failed).
	 * @param string $message   The message for the person.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
	 */
	public function __construct(public readonly string $errorCode, string $message) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status this refusal answers with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
	 */
	public function status(): int {
		return (self::STATUS_FOR[$this->errorCode] ?? 502);
	}//end status()
}//end class
