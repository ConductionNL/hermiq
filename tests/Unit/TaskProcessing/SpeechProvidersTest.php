<?php

/**
 * Tests for the speech-services TaskProcessing providers.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\Speech\SpeechClient;
use OCA\Hermiq\TaskProcessing\AudioToTextProvider;
use OCA\Hermiq\TaskProcessing\TextToSpeechProvider;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the two speech providers.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\TaskProcessing
 */
class SpeechProvidersTest extends TestCase {

	/**
	 * A no-op progress reporter.
	 *
	 * @return callable
	 */
	private function progress(): callable {
		return static function (float $p): void {
		};

	}//end progress()

	/**
	 * Both providers bind to the core task types, so every TaskProcessing consumer
	 * finds them — not to Hermiq-private ids.
	 *
	 * @return void
	 */
	public function testProvidersBindToCoreTaskTypes(): void {
		$stt = new AudioToTextProvider($this->createMock(SpeechClient::class), $this->createMock(LoggerInterface::class));
		$tts = new TextToSpeechProvider($this->createMock(SpeechClient::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(AudioToText::ID, $stt->getTaskTypeId());
		$this->assertSame(TextToSpeech::ID, $tts->getTaskTypeId());
		$this->assertSame('hermiq:audio2text', $stt->getId());
		$this->assertSame('hermiq:text2speech', $tts->getId());
		$this->assertGreaterThan(0, $stt->getExpectedRuntime());
		$this->assertGreaterThan(0, $tts->getExpectedRuntime());

	}//end testProvidersBindToCoreTaskTypes()

	/**
	 * Transcription returns the text AND the language actually used — without the
	 * latter, a wrong-language transcript is invisible rather than diagnosable.
	 *
	 * @return void
	 */
	public function testTranscriptionReportsTheLanguageActuallyUsed(): void {
		$speech = $this->createMock(SpeechClient::class);
		$speech->method('transcribe')->willReturn(
			['text' => 'the transcript', 'language' => 'nl', 'engine' => 'whisper']
		);

		$out = (new AudioToTextProvider($speech, $this->createMock(LoggerInterface::class)))
			->process('alice', ['input' => 'AUDIO'], $this->progress());

		$this->assertSame('the transcript', $out['output']);
		$this->assertSame('nl', $out['language']);

	}//end testTranscriptionReportsTheLanguageActuallyUsed()

	/**
	 * A stream input is read rather than rejected — Nextcloud hands a file
	 * resource for audio, and assuming raw bytes would fail obscurely.
	 *
	 * @return void
	 */
	public function testTranscriptionAcceptsAStreamInput(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, 'AUDIOBYTES');
		rewind($stream);

		$speech = $this->createMock(SpeechClient::class);
		$speech->expects($this->once())
			->method('transcribe')
			->with('AUDIOBYTES', '')
			->willReturn(['text' => 'ok', 'language' => 'en', 'engine' => 'e']);

		$out = (new AudioToTextProvider($speech, $this->createMock(LoggerInterface::class)))
			->process(null, ['input' => $stream], $this->progress());

		fclose($stream);
		$this->assertSame('ok', $out['output']);

	}//end testTranscriptionAcceptsAStreamInput()

	/**
	 * Missing audio raises rather than sending an empty request to the sidecar.
	 *
	 * @return void
	 */
	public function testTranscriptionWithoutAudioThrows(): void {
		$speech = $this->createMock(SpeechClient::class);
		$speech->expects($this->never())->method('transcribe');

		$this->expectException(RuntimeException::class);

		(new AudioToTextProvider($speech, $this->createMock(LoggerInterface::class)))
			->process('alice', [], $this->progress());

	}//end testTranscriptionWithoutAudioThrows()

	/**
	 * Synthesis returns the audio under the `speech` output key.
	 *
	 * @return void
	 */
	public function testSynthesisReturnsAudio(): void {
		$speech = $this->createMock(SpeechClient::class);
		$speech->method('synthesise')->willReturn(['audio' => 'RIFF', 'engine' => 'kokoro']);

		$out = (new TextToSpeechProvider($speech, $this->createMock(LoggerInterface::class)))
			->process('alice', ['input' => 'speak this'], $this->progress());

		$this->assertSame('RIFF', $out['speech']);

	}//end testSynthesisReturnsAudio()

	/**
	 * Empty text raises rather than synthesising silence.
	 *
	 * @return void
	 */
	public function testSynthesisWithoutTextThrows(): void {
		$speech = $this->createMock(SpeechClient::class);
		$speech->expects($this->never())->method('synthesise');

		$this->expectException(RuntimeException::class);

		(new TextToSpeechProvider($speech, $this->createMock(LoggerInterface::class)))
			->process('alice', ['input' => '   '], $this->progress());

	}//end testSynthesisWithoutTextThrows()

	/**
	 * Neither provider logs the transcript or the spoken text. An oversight record
	 * that quotes the audio becomes a second copy of the conversation somewhere
	 * with different access rules.
	 *
	 * @return void
	 */
	public function testNeitherProviderLogsContent(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = ($message . ' ' . json_encode($context));
			}
		);

		$stt = $this->createMock(SpeechClient::class);
		$stt->method('transcribe')->willReturn(
			['text' => 'SECRET TRANSCRIPT', 'language' => 'en', 'engine' => 'whisper']
		);
		(new AudioToTextProvider($stt, $logger))->process('alice', ['input' => 'A'], $this->progress());

		$tts = $this->createMock(SpeechClient::class);
		$tts->method('synthesise')->willReturn(['audio' => 'RIFF', 'engine' => 'kokoro']);
		(new TextToSpeechProvider($tts, $logger))->process('alice', ['input' => 'SECRET SPOKEN TEXT'], $this->progress());

		$all = implode(' | ', $logged);
		$this->assertStringNotContainsString('SECRET TRANSCRIPT', $all);
		$this->assertStringNotContainsString('SECRET SPOKEN TEXT', $all);
		$this->assertStringContainsString('whisper', $all, 'The engine IS recorded — that is the auditable fact.');

	}//end testNeitherProviderLogsContent()

}//end class
