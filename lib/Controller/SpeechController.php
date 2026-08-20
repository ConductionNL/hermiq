<?php

/**
 * Hermiq SpeechController.
 *
 * The composer-facing half of speech-services: one synchronous transcription
 * endpoint and one synthesis endpoint, both thin wrappers over SpeechClient.
 *
 * ⚠️ WHY NOT JUST USE `core:audio2text` FROM THE BROWSER. Hermiq registers
 * AudioToText and TextToSpeech as TaskProcessing providers, and those stay —
 * they are how Assistant, Talk and every other consumer reach this without
 * knowing Hermiq exists. They are the wrong shape for a chat composer:
 *
 *   - `core:audio2text` takes a FILE ID, not bytes. A dictated sentence would
 *     have to be written into the user's Files first, which leaves a trail of
 *     three-second audio clips in somebody's Documents folder and makes the
 *     retention story worse than the feature is worth.
 *   - The task API is asynchronous — schedule, then poll — which for a
 *     five-second utterance is latency spent on plumbing rather than inference.
 *
 * So the composer gets a direct call, and nothing here duplicates model logic:
 * both paths meet at the same SpeechClient.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Speech\SpeechClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronous speech-to-text and text-to-speech for the AI composer.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
 */
class SpeechController extends Controller {

	/**
	 * Largest clip accepted, in bytes.
	 *
	 * A dictated chat message is seconds long. This is a bound on what an
	 * authenticated caller can push through the sidecar in one request, not a
	 * feature limit: transcription is CPU-bound and minutes of audio is minutes
	 * of a core, so an unbounded endpoint is a denial-of-service surface with a
	 * microphone icon on it.
	 *
	 * @var int
	 */
	private const MAX_AUDIO_BYTES = (12 * 1024 * 1024);

	/**
	 * Longest text accepted for synthesis, in characters.
	 *
	 * Same reasoning in the other direction — Kokoro runs at roughly 3x realtime
	 * on CPU, so a novel submitted here is an hour of pegged core.
	 *
	 * @var int
	 */
	private const MAX_SPEAK_CHARS = 4000;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SpeechClient $speech The sidecar transport.
	 * @param IUserSession $userSession The acting session.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly SpeechClient $speech,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Transcribe an uploaded clip.
	 *
	 * ⚠️ NO `$id` OF ANY KIND IS ACCEPTED HERE, which is why `NoAdminRequired`
	 * carries no per-object guard beside it: the caller supplies the audio and
	 * receives its transcript, so there is no other user's object to reach. The
	 * only thing worth bounding is volume, and the rate limit does that.
	 *
	 * @return JSONResponse `{text, language, engine}`, or `{error}` with 400/502.
	 *
	 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function transcribe(): JSONResponse {
		$upload = $this->request->getUploadedFile(key: 'audio');

		if (is_array($upload) === false || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'No audio was uploaded.'], statusCode: 400);
		}

		if ((int)($upload['size'] ?? 0) > self::MAX_AUDIO_BYTES) {
			return new JSONResponse(data: ['error' => 'Audio clip is too large.'], statusCode: 400);
		}

		$bytes = file_get_contents(filename: (string)($upload['tmp_name'] ?? ''));

		if ($bytes === false || $bytes === '') {
			return new JSONResponse(data: ['error' => 'No audio was uploaded.'], statusCode: 400);
		}

		try {
			$result = $this->speech->transcribe(
				audio: $bytes,
				language: (string)$this->request->getParam(key: 'language', default: '')
			);
		} catch (Throwable $e) {
			// The engine and the failure, never the audio.
			$this->logger->warning(
				message: '[SpeechController] Transcription failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return new JSONResponse(data: ['error' => 'The speech service is unavailable.'], statusCode: 502);
		}

		// ⚠️ THE TRANSCRIPT IS NEVER LOGGED. An oversight record that quotes the
		// dictated text becomes a second copy of the conversation somewhere with
		// different access rules — the exact thing routing speech locally was
		// meant to avoid.
		$this->logger->info(
			message: '[SpeechController] Transcription completed',
			context: [
				'engine' => ($result['engine'] ?? ''),
				'language' => ($result['language'] ?? ''),
				'bytes' => strlen(string: $bytes),
				'user' => $this->currentUserId(),
			]
		);

		return new JSONResponse(data: $result, statusCode: 200);

	}//end transcribe()

	/**
	 * Speak a reply.
	 *
	 * Returns audio bytes rather than a URL: the alternative is writing a file
	 * per spoken sentence and then owning its lifetime.
	 *
	 * @return DataDownloadResponse|JSONResponse WAV audio, or `{error}`.
	 *
	 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function synthesise(): JSONResponse|DataDownloadResponse {
		$text = trim(string: (string)$this->request->getParam(key: 'text', default: ''));

		if ($text === '') {
			return new JSONResponse(data: ['error' => 'No text was supplied.'], statusCode: 400);
		}

		if (mb_strlen(string: $text) > self::MAX_SPEAK_CHARS) {
			return new JSONResponse(data: ['error' => 'Text is too long to speak.'], statusCode: 400);
		}

		try {
			$result = $this->speech->synthesise(
				text: $text,
				voice: (string)$this->request->getParam(key: 'voice', default: 'af_heart')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SpeechController] Synthesis failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return new JSONResponse(data: ['error' => 'The speech service is unavailable.'], statusCode: 502);
		}

		$this->logger->info(
			message: '[SpeechController] Synthesis completed',
			context: [
				'engine' => ($result['engine'] ?? ''),
				'chars' => mb_strlen(string: $text),
				'user' => $this->currentUserId(),
			]
		);

		return new DataDownloadResponse(
			data: (string)($result['audio'] ?? ''),
			filename: 'speech.wav',
			contentType: 'audio/wav'
		);

	}//end synthesise()

	/**
	 * Whether local speech can actually be performed here.
	 *
	 * 🔴 THIS EXISTS BECAUSE "THE PROVIDER IS REGISTERED" IS NOT "SPEECH WORKS".
	 * Nextcloud advertised `core:audio2text` on an instance where the sidecar
	 * was unreachable for months, so every consumer believed transcription was
	 * available and nobody found out until one was attempted. A composer that
	 * offers a private-speech option it cannot honour would repeat that, and
	 * worse: the fallback is a cloud engine, so failing to know would send audio
	 * off-instance for an agent whose whole point was that it must not.
	 *
	 * So this REACHES the sidecar rather than reading configuration.
	 *
	 * @return JSONResponse `{available: bool, reason: string}`.
	 *
	 * @spec openspec/specs/speech-services/spec.md#requirement-no-audio-leaves-the-instance
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function capabilities(): JSONResponse {
		try {
			$reachable = $this->speech->isReachable();
		} catch (Throwable $e) {
			$reachable = false;
		}

		$reason = 'speech_service_unreachable';

		if ($reachable === true) {
			$reason = '';
		}

		return new JSONResponse(
			data: [
				'available' => $reachable,
				'reason' => $reason,
			],
			statusCode: 200
		);

	}//end capabilities()

	/**
	 * The acting user id, or ''.
	 *
	 * @return string The user id.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return '';
		}

		return $user->getUID();

	}//end currentUserId()

}//end class
