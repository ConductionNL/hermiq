<?php

/**
 * Hermiq MessageTranslationEngine (message-translation-delegate).
 *
 * The gated translation primitive other Conduction apps delegate to: source
 * text, a target language and an optional glossary of school-specific terms
 * in, translated text plus a mandatory machine-translation notice out.
 * Mirrors `CourseRecommendationEngine`'s gate-then-execute shape exactly —
 * the `message-translation` `AiFeature` (EU AI Act, limited risk) MUST be
 * `lifecycle: enabled` before any LLM provider is touched, and any failure
 * (missing feature, disabled feature, provider error) degrades to a
 * structured `{available: false, reason}` result, never an exception.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gate-then-execute translation of ad-hoc text via the configured LLM provider.
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md
 */
class MessageTranslationEngine {

	/**
	 * The AiFeature slug this engine is gated by.
	 *
	 * @var string
	 */
	private const AIFEATURE_SLUG = 'message-translation';

	/**
	 * The disclosure notice attached to every successful translation
	 * (EU AI Act Art. 50 transparency obligation for a limited-risk system).
	 *
	 * @var string
	 */
	private const MACHINE_TRANSLATION_NOTICE = 'This text was translated automatically and may contain errors.';

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService $aiFeatureService The AiFeature gate (REQ-001).
	 * @param ProviderFactory $providerFactory Credential-broker-backed LLM call.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly AiFeatureService $aiFeatureService,
		private readonly ProviderFactory $providerFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate `sourceText` into `targetLanguage`, honouring an optional glossary.
	 *
	 * @param string $sourceText The text to translate.
	 * @param string $targetLanguage A BCP-47 language tag (e.g. "ar", "tr").
	 * @param array<int, array{term: string, translation: string}> $glossary Terms to render verbatim.
	 *
	 * @return array<string, mixed> `{available: true, translatedText, targetLanguage,
	 *                               machineTranslationNotice, provider}` on success, or
	 *                              `{available: false, reason}` when the feature is not
	 *                              enabled or the provider fails.
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-002-a-successful-translation-always-carries-a-machine-translation-notice
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-004-a-provider-failure-degrades-to-unavailable-never-an-unhandled-error
	 */
	public function translate(string $sourceText, string $targetLanguage, array $glossary = []): array {
		// Gate (REQ-001): zero LLM-provider footprint when missing or not enabled.
		$feature = $this->aiFeatureService->findBySlug(slug: self::AIFEATURE_SLUG);
		if ($feature === null || (string)($feature->getObject()['lifecycle'] ?? '') !== 'enabled') {
			return $this->unavailableResult(reason: 'feature-not-enabled');
		}

		try {
			$llmConfig = $this->providerFactory->getLlmConfig();
			$provider = (string)($llmConfig['chatProvider'] ?? '');

			$translatedText = trim($this->providerFactory->generateText(
				prompt: $this->buildPrompt(sourceText: $sourceText, targetLanguage: $targetLanguage, glossary: $glossary)
			));

			if ($translatedText === '') {
				return $this->unavailableResult(reason: 'provider-error');
			}

			return [
				'available' => true,
				'translatedText' => $translatedText,
				'targetLanguage' => $targetLanguage,
				'machineTranslationNotice' => self::MACHINE_TRANSLATION_NOTICE,
				'provider' => $provider,
			];
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MessageTranslationEngine] Translation failed: ' . $e->getMessage()
			);
			return $this->unavailableResult(reason: 'provider-error');
		}//end try

	}//end translate()

	/**
	 * Build the translation prompt, instructing the model to render every
	 * glossary term verbatim using its supplied translation.
	 *
	 * @param string $sourceText The text to translate.
	 * @param string $targetLanguage The target BCP-47 language tag.
	 * @param array<int, array{term: string, translation: string}> $glossary Terms to render verbatim.
	 *
	 * @return string The built prompt.
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-003-a-supplied-glossary-is-honoured-verbatim
	 */
	private function buildPrompt(string $sourceText, string $targetLanguage, array $glossary): string {
		$prompt = 'Translate the following text into the language with BCP-47 tag "' . $targetLanguage . '". '
			. 'Preserve the meaning and tone. Output only the translation, with no preamble, '
			. 'no explanation and no markdown.';

		if ($glossary !== []) {
			$terms = [];
			foreach ($glossary as $entry) {
				$term = (string)($entry['term'] ?? '');
				$translation = (string)($entry['translation'] ?? '');
				if ($term === '' || $translation === '') {
					continue;
				}

				$terms[] = '"' . $term . '" MUST be rendered as "' . $translation . '"';
			}

			if ($terms !== []) {
				$prompt .= ' The following terms have a fixed, required translation and MUST be used '
					. 'verbatim rather than translated freely: ' . implode('; ', $terms) . '.';
			}
		}

		$prompt .= "\n\nText to translate:\n" . $sourceText;

		return $prompt;
	}//end buildPrompt()

	/**
	 * Build the structured "unavailable" result.
	 *
	 * @param string $reason `feature-not-enabled` or `provider-error`.
	 *
	 * @return array{available: false, reason: string}
	 */
	private function unavailableResult(string $reason): array {
		return [
			'available' => false,
			'reason' => $reason,
		];
	}//end unavailableResult()
}//end class
