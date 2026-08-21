<?php

/**
 * Hermiq Speech Client (speech-services).
 *
 * The HTTP transport to the runner sidecar's speech half, which serves
 * faster-whisper and Kokoro behind ONE OpenAI-compatible API. Everything
 * model-shaped lives in that container; this class only speaks HTTP.
 *
 * ⚠️ THIS IS NOT AN OpenConnector EXCEPTION. `nc-native-tools` requires remote
 * calls to route through OpenConnector's `CallService`, whose `Source` model is a
 * fixed, admin-registered base URL. That is exactly what this is: an
 * admin-configured, instance-local sidecar address, not a per-call destination
 * the model chooses. It is the same shape as reaching Ollama — a local inference
 * backend, not a third-party integration.
 *
 * The sidecar is reachable on the loopback address only and, once its models are
 * primed, runs with no route off the host at all (see docs/exapp-runner.md).
 *
 * ADR-031: a legitimate imperative external-integration service — side-effecting
 * HTTP, owning no schema, no derived value, no lifecycle.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Speech;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * HTTP client for the speech sidecar.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Speech
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
 */
class SpeechClient {

	/**
	 * The app-config key holding the sidecar's base URL.
	 *
	 * @var string
	 */
	public const CONFIG_BASE_URL = 'speech_base_url';

	/**
	 * The app-config key holding the transcription model id.
	 *
	 * @var string
	 */
	public const CONFIG_STT_MODEL = 'speech_stt_model';

	/**
	 * The app-config key holding the synthesis model id.
	 *
	 * @var string
	 */
	public const CONFIG_TTS_MODEL = 'speech_tts_model';

	/**
	 * Default sidecar address — loopback only.
	 *
	 * @var string
	 */
	private const DEFAULT_BASE_URL = 'http://127.0.0.1:8000';

	/**
	 * Default transcription model.
	 *
	 * 🔴 A CPU MODEL, DELIBERATELY. This used to default to
	 * `deepdml/faster-whisper-large-v3-turbo-ct2`, which is a GPU model: measured
	 * 2026-08-20 on a CPU-only host, warm, through the Nextcloud path, a 4.6s
	 * clip took **81s** with it and **3.1s** with this one. A default nobody can
	 * use is not a quality choice — the caller times out long before the
	 * transcript exists, and the feature reads as broken rather than as
	 * misconfigured.
	 *
	 * An instance with a GPU should raise this deliberately:
	 * `occ config:app:set hermiq speech_stt_model --value="deepdml/faster-whisper-large-v3-turbo-ct2"`.
	 *
	 * ⚠️ THE VENDOR PREFIX IS NOT INTERCHANGEABLE. Systran publishes small/base
	 * conversions but no large-v3 TURBO one, and `deepdml` publishes the turbo
	 * one. HuggingFace answers a non-existent repo with **401, not 404**, so a
	 * wrong id reads as an auth failure while the sidecar crash-loops.
	 *
	 * @var string
	 */
	private const DEFAULT_STT_MODEL = 'Systran/faster-whisper-base';

	/**
	 * Default synthesis model.
	 *
	 * @var string
	 */
	private const DEFAULT_TTS_MODEL = 'speaches-ai/Kokoro-82M-v1.0-ONNX';

	/**
	 * Inference on CPU is slow; a short clip can take minutes. This is a bound
	 * against a hung sidecar, not a performance target.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 900;

	/**
	 * Bound for the reachability probe.
	 *
	 * Deliberately nothing like `TIMEOUT_SECONDS`: this one answers a question
	 * the UI is waiting on, and an unreachable sidecar must read as unreachable
	 * within a second or two rather than hanging the control that asked.
	 *
	 * @var int
	 */
	private const HEALTH_TIMEOUT_SECONDS = 3;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
	public function transcribe(string $audio, string $language = ''): array {
		$model = $this->config(key: self::CONFIG_STT_MODEL, fallback: self::DEFAULT_STT_MODEL);

		$parts = [
			['name' => 'model', 'contents' => $model],
			['name' => 'file', 'contents' => $audio, 'filename' => 'audio.wav'],
		];

		// An explicitly stated language beats detection: auto-detect misfires on
		// short utterances and on code-switched speech, which is the normal case
		// here rather than an edge case.
		if (trim($language) !== '') {
			$parts[] = ['name' => 'language', 'contents' => trim($language)];
		}

		$decoded = $this->post(path: '/v1/audio/transcriptions', options: ['multipart' => $parts]);

		return [
			'text' => (string)($decoded['text'] ?? ''),
			// Record the language ACTUALLY used, so a wrong-language transcript is
			// diagnosable rather than invisible.
			'language' => (string)($decoded['language'] ?? $language),
			'engine' => $model,
		];

	}//end transcribe()

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
	public function synthesise(string $text, string $voice = 'af_heart'): array {
		$model = $this->config(key: self::CONFIG_TTS_MODEL, fallback: self::DEFAULT_TTS_MODEL);

		$body = [
			'model' => $model,
			'input' => $text,
			'voice' => $voice,
			'response_format' => 'wav',
		];

		return [
			'audio' => $this->postForBytes(path: '/v1/audio/speech', options: ['json' => $body]),
			'engine' => $model,
		];

	}//end synthesise()

	/**
	 * Whether the sidecar answers at all.
	 *
	 * @return bool True when reachable.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector
	 */
	public function isAvailable(): bool {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get($this->baseUrl() . '/health', ['timeout' => 5]);

			return ($response->getStatusCode() === 200);
		} catch (Throwable) {
			return false;
		}

	}//end isAvailable()

	/**
	 * POST to the sidecar and decode the reply.
	 *
	 * @param string $path The API path.
	 * @param array<string, mixed> $options Guzzle-style request options.
	 *
	 * @return array<string, mixed> The decoded payload.
	 *
	 * @throws RuntimeException When the call fails or the reply is unreadable.
	 */
	private function post(string $path, array $options): array {
		$decoded = json_decode($this->postForBytes(path: $path, options: $options), true);

		if (is_array($decoded) === false) {
			throw new RuntimeException('The speech service returned an unreadable response.');
		}

		return $decoded;

	}//end post()

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
	public function isReachable(): bool {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$this->baseUrl() . '/health',
				['timeout' => self::HEALTH_TIMEOUT_SECONDS]
			);

			return $response->getStatusCode() === 200;
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq: speech sidecar unreachable', ['exception' => $e]);

			return false;
		}

	}//end isReachable()

	/**
	 * POST to the sidecar and return the body verbatim.
	 *
	 * Separate from `post()` rather than a `$raw` flag on it: synthesis returns
	 * audio, transcription returns JSON, and a boolean switch between the two
	 * hides that they are different operations.
	 *
	 * @param string $path The API path.
	 * @param array<string, mixed> $options Guzzle-style request options.
	 *
	 * @return string The response body.
	 *
	 * @throws RuntimeException When the call fails.
	 */
	private function postForBytes(string $path, array $options): string {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				$this->baseUrl() . $path,
				(['timeout' => self::TIMEOUT_SECONDS] + $options)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: speech sidecar call failed', ['path' => $path, 'exception' => $e]);

			throw new RuntimeException('The speech service is not available.', 0, $e);
		}

		return (string)$response->getBody();

	}//end postForBytes()

	/**
	 * The sidecar base URL.
	 *
	 * @return string The configured base URL.
	 */
	private function baseUrl(): string {
		return rtrim($this->config(key: self::CONFIG_BASE_URL, fallback: self::DEFAULT_BASE_URL), '/');

	}//end baseUrl()

	/**
	 * Read an app-config value with a fallback.
	 *
	 * @param string $key The config key.
	 * @param string $fallback The default.
	 *
	 * @return string The value.
	 */
	private function config(string $key, string $fallback): string {
		$value = trim($this->appConfig->getValueString('hermiq', $key, ''));

		if ($value === '') {
			return $fallback;
		}

		return $value;

	}//end config()

}//end class
