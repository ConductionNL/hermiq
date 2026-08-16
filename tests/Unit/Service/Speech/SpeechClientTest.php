<?php

/**
 * Tests for the speech-services SpeechClient.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Speech;

use OCA\Hermiq\Service\Speech\SpeechClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for SpeechClient.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
 */
class SpeechClientTest extends TestCase {

	/**
	 * Captured request options from the last POST.
	 *
	 * @var array<string, mixed>
	 */
	private array $captured = [];

	/**
	 * The last URL posted to.
	 *
	 * @var string
	 */
	private string $capturedUrl = '';

	/**
	 * Build a client whose HTTP layer returns $body.
	 *
	 * @param string $body The response body.
	 * @param array<string, string> $config App-config overrides.
	 * @param bool $throws Whether the HTTP call throws.
	 *
	 * @return SpeechClient
	 */
	private function client(string $body, array $config = [], bool $throws = false): SpeechClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn(200);

		$http = $this->createMock(IClient::class);
		if ($throws === true) {
			$http->method('post')->willThrowException(new RuntimeException('connection refused'));
			$http->method('get')->willThrowException(new RuntimeException('connection refused'));
		} else {
			$http->method('post')->willReturnCallback(
				function (string $url, array $options) use ($response): IResponse {
					$this->capturedUrl = $url;
					$this->captured = $options;

					return $response;
				}
			);
			$http->method('get')->willReturn($response);
		}

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? '')
		);

		return new SpeechClient($service, $appConfig, $this->createMock(LoggerInterface::class));

	}//end client()

	/**
	 * Transcription posts multipart to the OpenAI-compatible path and returns text.
	 *
	 * @return void
	 */
	public function testTranscribeReturnsTextAndEngine(): void {
		$result = $this->client('{"text":"hello there","language":"en"}')->transcribe('AUDIOBYTES', 'en');

		$this->assertSame('hello there', $result['text']);
		$this->assertSame('en', $result['language']);
		$this->assertStringContainsString('/v1/audio/transcriptions', $this->capturedUrl);
		$this->assertArrayHasKey('multipart', $this->captured);

	}//end testTranscribeReturnsTextAndEngine()

	/**
	 * An explicitly stated language is forwarded. Detection misfires on short and
	 * code-switched speech, so a caller that knows the language must be able to
	 * say so rather than hope.
	 *
	 * @return void
	 */
	public function testStatedLanguageIsForwarded(): void {
		$this->client('{"text":"hallo"}')->transcribe('A', 'nl');

		$names = array_column($this->captured['multipart'], 'name');
		$this->assertContains('language', $names);

	}//end testStatedLanguageIsForwarded()

	/**
	 * An unstated language is NOT forwarded — the model detects rather than being
	 * pinned to an empty string.
	 *
	 * @return void
	 */
	public function testUnstatedLanguageIsOmitted(): void {
		$this->client('{"text":"x"}')->transcribe('A', '');

		$this->assertNotContains('language', array_column($this->captured['multipart'], 'name'));

	}//end testUnstatedLanguageIsOmitted()

	/**
	 * Synthesis returns the body VERBATIM — audio is not JSON, and decoding it
	 * would corrupt it.
	 *
	 * @return void
	 */
	public function testSynthesiseReturnsRawAudio(): void {
		$result = $this->client('RIFF....WAVEDATA')->synthesise('speak this');

		$this->assertSame('RIFF....WAVEDATA', $result['audio']);
		$this->assertStringContainsString('/v1/audio/speech', $this->capturedUrl);
		$this->assertSame('speak this', $this->captured['json']['input']);

	}//end testSynthesiseReturnsRawAudio()

	/**
	 * Configured model ids and base URL override the defaults.
	 *
	 * @return void
	 */
	public function testConfiguredModelAndBaseUrlAreUsed(): void {
		$client = $this->client(
			'RIFF',
			[
				SpeechClient::CONFIG_BASE_URL => 'http://hermiq-speech:8000/',
				SpeechClient::CONFIG_TTS_MODEL => 'custom/voice-model',
			]
		);

		$result = $client->synthesise('x');

		$this->assertSame('custom/voice-model', $result['engine']);
		// The trailing slash is trimmed rather than producing a double slash.
		$this->assertStringStartsWith('http://hermiq-speech:8000/v1/', $this->capturedUrl);

	}//end testConfiguredModelAndBaseUrlAreUsed()

	/**
	 * The default transcription model is the deepdml turbo conversion — NOT a
	 * `Systran/...` turbo id, which does not exist and which HuggingFace answers
	 * with 401 rather than 404, so a wrong default would read as an auth failure.
	 *
	 * @return void
	 */
	public function testDefaultTranscriptionModelIsTheOneThatExists(): void {
		$result = $this->client('{"text":"x"}')->transcribe('A');

		$this->assertSame('deepdml/faster-whisper-large-v3-turbo-ct2', $result['engine']);
		$this->assertStringNotContainsStringIgnoringCase('systran', $result['engine']);

	}//end testDefaultTranscriptionModelIsTheOneThatExists()

	/**
	 * An unreachable sidecar raises rather than returning a plausible empty result.
	 *
	 * @return void
	 */
	public function testUnreachableSidecarThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->client('', [], true)->transcribe('A');

	}//end testUnreachableSidecarThrows()

	/**
	 * A non-JSON reply to a JSON endpoint is an error, not an empty transcript —
	 * silently returning '' would look like "the audio was silent".
	 *
	 * @return void
	 */
	public function testUnreadableJsonReplyThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->client('<html>gateway error</html>')->transcribe('A');

	}//end testUnreadableJsonReplyThrows()

	/**
	 * Availability reflects the probe, and never throws.
	 *
	 * @return void
	 */
	public function testAvailabilityProbe(): void {
		$this->assertTrue($this->client('{}')->isAvailable());
		$this->assertFalse($this->client('', [], true)->isAvailable());

	}//end testAvailabilityProbe()

}//end class
