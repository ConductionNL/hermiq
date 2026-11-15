<?php

/**
 * Tests for the exact requests SpeechClient sends and how it reads the replies.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
 * Pins the request each call sends (path, timeout, parts, body) and how the reply is read.
 *
 * @covers \OCA\Hermiq\Service\Speech\SpeechClient
 */
class SpeechClientRequestShapeTest extends TestCase {

	/**
	 * Every POST made: url and options.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * Every GET made: url and options.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $gets = [];

	/**
	 * A client whose HTTP layer records each call and answers with $body and $status.
	 *
	 * @param string                $body   The response body.
	 * @param array<string, string> $config App-config values.
	 * @param int                   $status The response status.
	 * @param LoggerInterface|null  $logger The logger.
	 *
	 * @return SpeechClient
	 */
	private function client(string $body, array $config=[], int $status=200, ?LoggerInterface $logger=null): SpeechClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$response->method('getStatusCode')->willReturn($status);

		$http = $this->createMock(IClient::class);
		$http->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->posts[] = [$url, $options];
				return $response;
			}
		);
		$http->method('get')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->gets[] = [$url, $options];
				return $response;
			}
		);

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($app === 'hermiq' ? ($config[$key] ?? '') : 'wrong-app')
		);

		return new SpeechClient($service, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end client()

	/**
	 * A transcription posts the model and the audio as multipart, with the long timeout, to the default sidecar.
	 *
	 * @return void
	 */
	public function testTranscriptionRequestShape(): void {
		$this->client('{"text":"hallo"}')->transcribe('RIFFDATA', '  nl ');

		self::assertCount(1, $this->posts);
		[$url, $options] = $this->posts[0];
		self::assertSame('http://127.0.0.1:8000/v1/audio/transcriptions', $url);
		self::assertSame(900, $options['timeout']);
		self::assertSame(
			[
				['name' => 'model', 'contents' => 'Systran/faster-whisper-base'],
				['name' => 'file', 'contents' => 'RIFFDATA', 'filename' => 'audio.wav'],
				['name' => 'language', 'contents' => 'nl'],
			],
			$options['multipart']
		);
	}//end testTranscriptionRequestShape()

	/**
	 * The language the service reports wins; without one the stated language is kept, and no text is ''.
	 *
	 * @return void
	 */
	public function testTheReportedLanguageWins(): void {
		self::assertSame(
			['text' => 'hello', 'language' => 'en', 'engine' => 'Systran/faster-whisper-base'],
			$this->client('{"text":"hello","language":"en"}')->transcribe('A', 'nl')
		);
		self::assertSame(
			['text' => '', 'language' => 'nl', 'engine' => 'Systran/faster-whisper-base'],
			$this->client('{}')->transcribe('A', 'nl')
		);
	}//end testTheReportedLanguageWins()

	/**
	 * A synthesis posts the model, text, voice and wav format as JSON.
	 *
	 * @return void
	 */
	public function testSynthesisRequestShape(): void {
		$client = $this->client('RIFF');

		$client->synthesise('Goedemorgen');
		$client->synthesise('Hoi', 'nl_female');

		self::assertSame('http://127.0.0.1:8000/v1/audio/speech', $this->posts[0][0]);
		self::assertSame(900, $this->posts[0][1]['timeout']);
		self::assertSame(
			['model' => 'speaches-ai/Kokoro-82M-v1.0-ONNX', 'input' => 'Goedemorgen', 'voice' => 'af_heart', 'response_format' => 'wav'],
			$this->posts[0][1]['json']
		);
		self::assertSame('nl_female', $this->posts[1][1]['json']['voice']);
	}//end testSynthesisRequestShape()

	/**
	 * A blank configured value falls back to the default, and the transcription model is configurable.
	 *
	 * @return void
	 */
	public function testBlankConfigFallsBackToTheDefaults(): void {
		$result = $this->client(
			'{"text":"x"}',
			[SpeechClient::CONFIG_BASE_URL => '   ', SpeechClient::CONFIG_STT_MODEL => ' gpu/whisper-large ']
		)->transcribe('A');

		self::assertSame('gpu/whisper-large', $result['engine']);
		self::assertSame('http://127.0.0.1:8000/v1/audio/transcriptions', $this->posts[0][0]);
	}//end testBlankConfigFallsBackToTheDefaults()

	/**
	 * The availability probe asks /health with a short timeout, and only a 200 is available.
	 *
	 * @return void
	 */
	public function testTheAvailabilityProbe(): void {
		self::assertTrue($this->client('', [SpeechClient::CONFIG_BASE_URL => 'http://speech:9000//'])->isAvailable());
		self::assertSame([['http://speech:9000/health', ['timeout' => 5]]], $this->gets);

		self::assertFalse($this->client('', [], 503)->isAvailable());
	}//end testTheAvailabilityProbe()

	/**
	 * A failed call is logged with its path and surfaces as a not-available error carrying the cause.
	 *
	 * @return void
	 */
	public function testAFailedCallIsLoggedAndWrapped(): void {
		$cause = new RuntimeException('connection refused');
		$http  = $this->createMock(IClient::class);
		$http->method('post')->willThrowException($cause);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('Hermiq: speech sidecar call failed', ['path' => '/v1/audio/speech', 'exception' => $cause]);

		$client = new SpeechClient($service, $this->createMock(IAppConfig::class), $logger);

		try {
			$client->synthesise('x');
			self::fail('A failed call must throw.');
		} catch (RuntimeException $e) {
			self::assertSame('The speech service is not available.', $e->getMessage());
			self::assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedCallIsLoggedAndWrapped()

	/**
	 * A reply that is JSON but not an object is as unreadable as no JSON.
	 *
	 * @return void
	 */
	public function testAScalarJsonReplyIsUnreadable(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The speech service returned an unreadable response.');

		$this->client('"just text"')->transcribe('A');
	}//end testAScalarJsonReplyIsUnreadable()
}//end class
