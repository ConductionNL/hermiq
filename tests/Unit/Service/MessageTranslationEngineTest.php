<?php

/**
 * Unit tests for MessageTranslationEngine (message-translation-delegate).
 *
 * Proves the gate-then-execute invariant: zero LLM-provider footprint until
 * the `message-translation` AiFeature is enabled, a mandatory
 * machine-translation notice on every success, a glossary rendered verbatim
 * into the prompt, and a provider failure degrading to a structured
 * `{available: false}` result rather than an unhandled exception.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\MessageTranslationEngine;
use OCA\Hermiq\Service\Translation\TranslationDisclosure;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the message-translation-delegate gated translation engine.
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md
 */
class MessageTranslationEngineTest extends TestCase {

	/**
	 * Build an AiFeature ObjectEntity with the given lifecycle.
	 *
	 * @param string $lifecycle `enabled` or `disabled`.
	 *
	 * @return ObjectEntity
	 */
	private function feature(string $lifecycle): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('feature-1');
		$entity->setObject(['slug' => 'message-translation', 'lifecycle' => $lifecycle]);
		return $entity;
	}//end feature()

	/**
	 * Build the engine with the given collaborators.
	 *
	 * @param ObjectEntity|null $feature The AiFeature `findBySlug()` returns (null = absent).
	 * @param ProviderFactory|null $providerFactory A pre-configured provider-factory double.
	 *
	 * @return MessageTranslationEngine
	 */
	private function engine(?ObjectEntity $feature, ?ProviderFactory $providerFactory = null): MessageTranslationEngine {
		$aiFeatureService = $this->createMock(AiFeatureService::class);
		$aiFeatureService->expects($this->never())->method('findBySlug');
		$aiFeatureService->method('findBySlugForGate')->willReturn($feature);

		return new MessageTranslationEngine(
			aiFeatureService: $aiFeatureService,
			providerFactory: $providerFactory ?? $this->createMock(ProviderFactory::class),
			logger: $this->createMock(LoggerInterface::class),
			disclosure: new class extends TranslationDisclosure {
				/**
				 * Pin the endonym path so the disclosure does not depend on the machine.
				 *
				 * @return bool Always false.
				 */
				protected function intlAvailable(): bool {
					return false;
				}
			},
		);
	}//end engine()

	/**
	 * Zero LLM footprint when the feature is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 */
	public function testUnavailableWhenFeatureAbsent(): void {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->expects($this->never())->method('generateText');
		$providerFactory->expects($this->never())->method('getLlmConfig');

		$result = $this->engine(feature: null, providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$this->assertFalse($result['available']);
		$this->assertSame('feature-not-enabled', $result['reason']);

	}//end testUnavailableWhenFeatureAbsent()

	/**
	 * Zero LLM footprint when the feature is disabled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 */
	public function testUnavailableWhenFeatureDisabled(): void {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->expects($this->never())->method('generateText');

		$result = $this->engine(feature: $this->feature(lifecycle: 'disabled'), providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$this->assertFalse($result['available']);
		$this->assertSame('feature-not-enabled', $result['reason']);

	}//end testUnavailableWhenFeatureDisabled()

	/**
	 * A successful translation carries the mandatory notice and provider name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-002-a-successful-translation-always-carries-a-machine-translation-notice
	 */
	public function testSuccessfulTranslationCarriesNotice(): void {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn(['chatProvider' => 'openai']);
		$providerFactory->method('generateText')->willReturn('Hello');

		$result = $this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$this->assertTrue($result['available']);
		$this->assertSame('Hello', $result['translatedText']);
		$this->assertSame('en', $result['targetLanguage']);
		$this->assertSame('openai', $result['provider']);
		$this->assertNotSame('', trim((string)$result['machineTranslationNotice']));

	}//end testSuccessfulTranslationCarriesNotice()

	/**
	 * Every glossary term's supplied translation appears verbatim in the
	 * prompt handed to the provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-003-a-supplied-glossary-is-honoured-verbatim
	 */
	public function testGlossaryTermsAppearVerbatimInPrompt(): void {
		$capturedPrompt = null;

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn(['chatProvider' => 'openai']);
		$providerFactory->method('generateText')->willReturnCallback(
			function (string $prompt) use (&$capturedPrompt): string {
				$capturedPrompt = $prompt;
				return 'translated';
			}
		);

		$this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $providerFactory)
			->translate(
				sourceText: 'De klas gaat trakteren.',
				targetLanguage: 'ar',
				sourceLanguage: 'nl',
				glossary: [
					['term' => 'trakteren', 'translation' => 'bringing treats to share with the class'],
				]
			);

		$this->assertIsString($capturedPrompt);
		$this->assertStringContainsString('trakteren', $capturedPrompt);
		$this->assertStringContainsString('bringing treats to share with the class', $capturedPrompt);

	}//end testGlossaryTermsAppearVerbatimInPrompt()

	/**
	 * An empty glossary produces a prompt with no glossary section.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-003-a-supplied-glossary-is-honoured-verbatim
	 */
	public function testEmptyGlossaryIsANoop(): void {
		$capturedPrompt = null;

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn(['chatProvider' => 'openai']);
		$providerFactory->method('generateText')->willReturnCallback(
			function (string $prompt) use (&$capturedPrompt): string {
				$capturedPrompt = $prompt;
				return 'translated';
			}
		);

		$this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en', glossary: [], sourceLanguage: 'nl');

		$this->assertIsString($capturedPrompt);
		$this->assertStringNotContainsString('fixed, required translation', $capturedPrompt);

	}//end testEmptyGlossaryIsANoop()

	/**
	 * A provider failure degrades to a structured unavailable result, never
	 * an unhandled exception.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-004-a-provider-failure-degrades-to-unavailable-never-an-unhandled-error
	 */
	public function testProviderFailureDegradesToUnavailable(): void {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willThrowException(new RuntimeException('no provider configured'));

		$result = $this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$this->assertFalse($result['available']);
		$this->assertSame('provider-error', $result['reason']);

	}//end testProviderFailureDegradesToUnavailable()

	/**
	 * An empty translation result (provider returned nothing usable)
	 * degrades to unavailable rather than a hollow success.
	 *
	 * @return void
	 */
	public function testEmptyProviderOutputDegradesToUnavailable(): void {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn(['chatProvider' => 'openai']);
		$providerFactory->method('generateText')->willReturn('   ');

		$result = $this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $providerFactory)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$this->assertFalse($result['available']);
		$this->assertSame('provider-error', $result['reason']);

	}//end testEmptyProviderOutputDegradesToUnavailable()

	/**
	 * A provider double that answers the translation prompt with `$translation`
	 * and the detection prompt with `$detected` (or throws when it is null),
	 * recording every prompt it was handed.
	 *
	 * @param array<string, mixed> $llmConfig The configuration `getLlmConfig()` returns.
	 * @param string $translation The translation answer.
	 * @param string|null $detected The detection answer; null makes detection throw.
	 * @param array<int, string> $prompts Receives every prompt, in order.
	 *
	 * @return ProviderFactory
	 */
	private function provider(array $llmConfig, string $translation, ?string $detected, array &$prompts): ProviderFactory {
		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getLlmConfig')->willReturn($llmConfig);
		$providerFactory->method('generateText')->willReturnCallback(
			function (string $prompt) use ($translation, $detected, &$prompts): string {
				$prompts[] = $prompt;
				if (str_starts_with($prompt, 'Reply with only the BCP-47 language tag') === false) {
					return $translation;
				}

				if ($detected === null) {
					throw new RuntimeException('detection unavailable');
				}

				return $detected;
			}
		);
		return $providerFactory;
	}//end provider()

	/**
	 * A success names what made it, both languages, the original and a disclosure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 */
	public function testSuccessCarriesTheProvenanceEnvelope(): void {
		$prompts = [];
		$config  = ['chatProvider' => 'openai', 'openaiConfig' => ['chatModel' => 'gpt-4o-mini']];

		$result = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(llmConfig: $config, translation: 'Merhaba', detected: 'xx', prompts: $prompts)
		)->translate(
			sourceText: 'Hallo',
			targetLanguage: 'tr',
			sourceLanguage: 'nl',
			originalRef: 'portaliq:message:42'
		);

		$this->assertTrue($result['available']);
		$this->assertTrue($result['translatedByAi']);
		$this->assertSame('nl', $result['sourceLanguage']);
		$this->assertFalse($result['sourceLanguageDetected']);
		$this->assertSame('tr', $result['targetLanguage']);
		$this->assertSame('openai/gpt-4o-mini', $result['model']);
		$this->assertSame('portaliq:message:42', $result['originalRef']);
		$this->assertSame('Yapay zekâ ile çevrildi. Orijinal dil: Nederlands. Bu çeviri hatalar içerebilir.', $result['disclosure']);
		$this->assertSame('tr', $result['disclosureLanguage']);
		$this->assertCount(1, $prompts, 'A caller-supplied source language needs no detection call.');

	}//end testSuccessCarriesTheProvenanceEnvelope()

	/**
	 * An absent original reference comes back as an empty string.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 */
	public function testOriginalRefDefaultsToEmpty(): void {
		$prompts = [];
		$result  = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(llmConfig: ['chatProvider' => 'openai'], translation: 'Hello', detected: 'nl', prompts: $prompts)
		)->translate(sourceText: 'Hallo', targetLanguage: 'en', sourceLanguage: 'nl');

		$this->assertSame('', $result['originalRef']);

	}//end testOriginalRefDefaultsToEmpty()

	/**
	 * Every unavailable answer says no AI translation happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 */
	public function testUnavailableAnswersCarryTranslatedByAiFalse(): void {
		$disabled = $this->engine(feature: $this->feature(lifecycle: 'disabled'))
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		$failing = $this->createMock(ProviderFactory::class);
		$failing->method('getLlmConfig')->willThrowException(new RuntimeException('down'));
		$failed = $this->engine(feature: $this->feature(lifecycle: 'enabled'), providerFactory: $failing)
			->translate(sourceText: 'Hallo', targetLanguage: 'en');

		foreach ([$disabled, $failed] as $result) {
			$this->assertFalse($result['available']);
			$this->assertFalse($result['translatedByAi']);
			$this->assertArrayNotHasKey('translatedText', $result);
		}

	}//end testUnavailableAnswersCarryTranslatedByAiFalse()

	/**
	 * A missing source language is detected and marked as detected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected
	 */
	public function testMissingSourceLanguageIsDetected(): void {
		$prompts = [];
		$result  = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(llmConfig: ['chatProvider' => 'openai'], translation: 'Hello', detected: " NL.\n", prompts: $prompts)
		)->translate(sourceText: str_repeat('Hallo allemaal. ', 100), targetLanguage: 'en');

		$this->assertSame('nl', $result['sourceLanguage']);
		$this->assertTrue($result['sourceLanguageDetected']);
		$this->assertSame('Translated by AI from Nederlands. This translation may contain errors.', $result['disclosure']);
		$this->assertCount(2, $prompts);
		$this->assertLessThan(700, mb_strlen($prompts[1]), 'The detection prompt reads a sample, not the whole text.');

	}//end testMissingSourceLanguageIsDetected()

	/**
	 * A detection answer that is not a tag, or a failing detection, becomes und
	 * and still returns the translation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected
	 */
	public function testUnusableDetectionBecomesUnd(): void {
		foreach (['The text is in Dutch.', null] as $detected) {
			$prompts = [];
			$result  = $this->engine(
				feature: $this->feature(lifecycle: 'enabled'),
				providerFactory: $this->provider(llmConfig: ['chatProvider' => 'openai'], translation: 'Hello', detected: $detected, prompts: $prompts)
			)->translate(sourceText: 'Hallo', targetLanguage: 'en');

			$this->assertTrue($result['available']);
			$this->assertSame('Hello', $result['translatedText']);
			$this->assertSame('und', $result['sourceLanguage']);
			$this->assertTrue($result['sourceLanguageDetected']);
			$this->assertSame('Translated by AI. This translation may contain errors.', $result['disclosure']);
		}

	}//end testUnusableDetectionBecomesUnd()

	/**
	 * A configuration full of secrets yields a clean label.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-008-the-model-label-never-carries-a-secret
	 */
	public function testModelLabelCarriesNoSecret(): void {
		$prompts = [];
		$config  = [
			'chatProvider' => 'openai',
			'openaiConfig' => [
				'credentialId'   => 'cred-CHANGE_ME-0000',
				'organizationId' => 'org-CHANGE_ME',
				'chatModel'      => 'gpt-4o-mini',
				'baseUrl'        => 'https://llm.example.invalid/v1',
			],
			'fireworksConfig' => ['credentialId' => 'cred-OTHER'],
		];

		$result = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(llmConfig: $config, translation: 'Hello', detected: 'nl', prompts: $prompts)
		)->translate(sourceText: 'Hallo', targetLanguage: 'en', sourceLanguage: 'nl');

		$this->assertSame('openai/gpt-4o-mini', $result['model']);
		$encoded = (string)json_encode($result);
		foreach (['cred-CHANGE_ME-0000', 'org-CHANGE_ME', 'llm.example.invalid', 'cred-OTHER'] as $secret) {
			$this->assertStringNotContainsString($secret, $encoded);
		}

	}//end testModelLabelCarriesNoSecret()

	/**
	 * A provider with no configured model is labelled by the provider alone,
	 * and characters outside a model-id set are dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-008-the-model-label-never-carries-a-secret
	 */
	public function testModelLabelWithoutModelAndWithOddCharacters(): void {
		$prompts = [];
		$plain   = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(llmConfig: ['chatProvider' => 'nextcloud'], translation: 'Hello', detected: 'nl', prompts: $prompts)
		)->translate(sourceText: 'Hallo', targetLanguage: 'en', sourceLanguage: 'nl');
		$this->assertSame('nextcloud', $plain['model']);

		$odd = $this->engine(
			feature: $this->feature(lifecycle: 'enabled'),
			providerFactory: $this->provider(
				llmConfig: ['chatProvider' => 'ollama', 'ollamaConfig' => ['chatModel' => 'llama3.1:8b <script> '.str_repeat('x', 200)]],
				translation: 'Hello',
				detected: 'nl',
				prompts: $prompts
			)
		)->translate(sourceText: 'Hallo', targetLanguage: 'en', sourceLanguage: 'nl');
		$this->assertStringStartsWith('ollama/llama3.1:8bscript', $odd['model']);
		$this->assertLessThanOrEqual(120, strlen($odd['model']));

	}//end testModelLabelWithoutModelAndWithOddCharacters()
}//end class
