<?php

/**
 * Hermiq AudioToAudioTranslateProvider and its registration behind the class check.
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
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-other-apps-can-use-the-chain-through-taskprocessing-where-nextcloud-defines-it-req-strn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\Speech\SpeechTranslationException;
use OCA\Hermiq\Service\Speech\TranslationChain;
use OCA\Hermiq\TaskProcessing\AudioToAudioTranslateProvider;
use OCA\Hermiq\TaskProcessing\ProviderRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the audio translation provider.
 */
final class AudioToAudioTranslateProviderTest extends TestCase {

	/**
	 * The arguments the chain was called with.
	 *
	 * @var array<string, mixed>
	 */
	private array $called = [];

	/**
	 * The provider over a fake chain.
	 *
	 * @param string|null                     $audio   The spoken audio the chain returns.
	 * @param SpeechTranslationException|null $refusal What the chain throws, if anything.
	 *
	 * @return AudioToAudioTranslateProvider The provider.
	 */
	private function provider(?string $audio = 'RIFF-wav', ?SpeechTranslationException $refusal = null): AudioToAudioTranslateProvider {
		$spoken = $audio;
		$chain = $this->createMock(TranslationChain::class);
		$chain->method('translate')->willReturnCallback(
			function (string $audio, string $targetLanguage, string $sourceLanguage = '', ?string $uid = null, bool $allowNextcloud = true) use ($refusal, $spoken): array {
				$this->called = compact('audio', 'targetLanguage', 'sourceLanguage', 'uid', 'allowNextcloud');
				if ($refusal !== null) {
					throw $refusal;
				}

				return ['transcript' => 'x', 'translation' => 'y', 'audio' => $spoken, 'spoken' => $spoken !== null, 'record' => []];
			}
		);

		return new AudioToAudioTranslateProvider(chain: $chain);
	}//end provider()

	/**
	 * A task with a File input returns the spoken translation as `audio_output`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 */
	public function testAFileInputIsTranslatedIntoAudioOutput(): void {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('m4a-bytes');

		$output = $this->provider()->process('balie', ['input' => $file, 'origin_language' => 'detect_language', 'target_language' => 'fr'], static function (): void {
		});

		$this->assertSame(['audio_output' => 'RIFF-wav'], $output);
		$this->assertSame(
			['audio' => 'm4a-bytes', 'targetLanguage' => 'fr', 'sourceLanguage' => '', 'uid' => 'balie', 'allowNextcloud' => false],
			$this->called
		);
		$this->assertSame('core:audio2audio:translate', $this->provider()->getTaskTypeId());
	}//end testAFileInputIsTranslatedIntoAudioOutput()

	/**
	 * A target without a voice fails the task: an audio task cannot answer with text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
	 */
	public function testATargetWithoutAVoiceFailsTheTask(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('There is no voice for "nl" on this server');

		$this->provider(audio: null)->process('balie', ['input' => 'm4a-bytes', 'origin_language' => 'ar', 'target_language' => 'nl'], static function (): void {
		});
	}//end testATargetWithoutAVoiceFailsTheTask()

	/**
	 * A refused chain step fails the task with the refusal's message; no input fails before the chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 */
	public function testARefusalFailsTheTaskWithItsMessage(): void {
		try {
			$this->provider(refusal: new SpeechTranslationException('feature_not_enabled', 'Feature "speech-translation" is off.'))
				->process('balie', ['input' => 'm4a', 'target_language' => 'nl'], static function (): void {
				});
			$this->fail('A refusal must fail the task.');
		} catch (RuntimeException $e) {
			$this->assertSame('Feature "speech-translation" is off.', $e->getMessage());
		}

		$this->called = [];
		try {
			$this->provider()->process('balie', ['target_language' => 'nl'], static function (): void {
			});
			$this->fail('A task without audio must fail.');
		} catch (RuntimeException $e) {
			$this->assertSame('No audio was supplied to translate.', $e->getMessage());
		}

		$this->assertSame([], $this->called);
	}//end testARefusalFailsTheTaskWithItsMessage()

	/**
	 * Without the task type class (Nextcloud 32 to 34) the provider is not registered and nothing throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-nextcloud-34-boots-without-the-provider
	 */
	public function testTheProviderIsRegisteredOnlyWhereTheTaskTypeExists(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerTaskProcessingProvider')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		(new ProviderRegistrar())->register(context: $context);

		$this->assertSame(class_exists(AudioToAudioTranslateProvider::TASK_TYPE_CLASS), AudioToAudioTranslateProvider::isSupported());
		$this->assertSame(
			AudioToAudioTranslateProvider::isSupported(),
			in_array(AudioToAudioTranslateProvider::class, $registered, true)
		);
		$this->assertContains('OCA\\Hermiq\\TaskProcessing\\AudioToTextProvider', $registered);
	}//end testTheProviderIsRegisteredOnlyWhereTheTaskTypeExists()

	/**
	 * With the task type class present (Nextcloud 35) the provider is registered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-6-the-taskprocessing-provider-behind-a-class-check
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTheProviderIsRegisteredWhenTheTaskTypeIsPresent(): void {
		if (class_exists(AudioToAudioTranslateProvider::TASK_TYPE_CLASS) === false) {
			eval('namespace OCP\\TaskProcessing\\TaskTypes; class AudioToAudioTranslate {}');
		}

		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerTaskProcessingProvider')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		(new ProviderRegistrar())->register(context: $context);

		$this->assertContains(AudioToAudioTranslateProvider::class, $registered);
	}//end testTheProviderIsRegisteredWhenTheTaskTypeIsPresent()
}//end class
