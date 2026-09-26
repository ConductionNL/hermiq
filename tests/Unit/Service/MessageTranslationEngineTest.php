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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\MessageTranslationEngine;
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
		$aiFeatureService->method('findBySlug')->willReturn($feature);

		return new MessageTranslationEngine(
			aiFeatureService: $aiFeatureService,
			providerFactory: $providerFactory ?? $this->createMock(ProviderFactory::class),
			logger: $this->createMock(LoggerInterface::class),
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
			->translate(sourceText: 'Hallo', targetLanguage: 'en', glossary: []);

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
}//end class
