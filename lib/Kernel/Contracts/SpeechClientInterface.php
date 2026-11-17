<?php

/**
 * Contract of SpeechClient
 *
 * The public surface of SpeechClient that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of SpeechClient.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SpeechClientInterface {

	/**
	 * Whether the sidecar answers at all.
	 *
	 * 🔴 REACHES IT. A configuration read would have reported "available" for the
	 * entire period during which `speech_base_url` pointed at a hostname that
	 * resolved from nowhere — the sidecar sat on a jailed network with nothing
	 * else attached, and Nextcloud advertised transcription to every consumer
	 * regardless. Configuration is not reachability.
	 *
	 * Short timeout on purpose: this answers a UI question — "may I offer the
	 * private engine?" — and a caller that waits fifteen minutes has already
	 * lost. A slow inference call is a different question with a different
	 * budget (`TIMEOUT_SECONDS`).
	 *
	 * @return bool True when `/health` answered 200.
	 *
	 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
	 */
	public function isReachable(): bool;

	/**
	 * Synthesise speech from text.
	 *
	 * @param string $text The text to speak.
	 * @param string $voice The voice id.
	 *
	 * @return array<string, mixed> `['audio' => string, 'engine' => string]`.
	 *
	 * @throws RuntimeException When the sidecar is unreachable or refuses.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
	 */
	public function synthesise(string $text, string $voice = 'af_heart'): array;

	/**
	 * Transcribe audio bytes to text.
	 *
	 * @param string $audio The raw audio bytes.
	 * @param string $language Optional ISO language code; '' lets the model detect.
	 *
	 * @return array<string, mixed> `['text' => string, 'language' => string, 'engine' => string]`.
	 *
	 * @throws RuntimeException When the sidecar is unreachable or refuses.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
	 */
	public function transcribe(string $audio, string $language = ''): array;
}//end interface
