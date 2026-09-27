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
 * Every answer carries its AI provenance (ai-translation-provenance, decision
 * D24 "AI-made translations are visible"): `translatedByAi`, the source and
 * target language, a secret-free model label, the caller's reference to the
 * original, and a fixed disclosure sentence in the reader's language.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Translation\TranslationDisclosure;
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
	 * How much of the source text the language-detection prompt reads. A
	 * language is recognisable from a few sentences; the rest is not sent twice.
	 *
	 * @var int
	 */
	private const DETECTION_SAMPLE_LENGTH = 500;

	/**
	 * The longest model label returned.
	 *
	 * @var int
	 */
	private const MODEL_LABEL_MAX_LENGTH = 120;

	/**
	 * The fixed disclosure table and language names.
	 *
	 * @var TranslationDisclosure
	 */
	private readonly TranslationDisclosure $disclosure;

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService $aiFeatureService The AiFeature gate (REQ-001).
	 * @param ProviderFactory $providerFactory Credential-broker-backed LLM call.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param TranslationDisclosure|null $disclosure The disclosure table. Nullable and
	 *                                               trailing so an engine built by hand
	 *                                               keeps its old shape.
	 */
	public function __construct(
		private readonly AiFeatureService $aiFeatureService,
		private readonly ProviderFactory $providerFactory,
		private readonly LoggerInterface $logger,
		?TranslationDisclosure $disclosure=null,
	) {
		$this->disclosure = $disclosure ?? new TranslationDisclosure();
	}//end __construct()

	/**
	 * Translate `sourceText` into `targetLanguage`, honouring an optional glossary.
	 *
	 * @param string $sourceText The text to translate.
	 * @param string $targetLanguage A BCP-47 language tag (e.g. "ar", "tr").
	 * @param array<int, array{term: string, translation: string}> $glossary Terms to render verbatim.
	 * @param string|null $sourceLanguage The caller's BCP-47 tag for the source text; null
	 *                                    lets the engine detect it.
	 * @param string $originalRef What the caller uses to identify the original, echoed back.
	 *
	 * @return array<string, mixed> `{available: true, translatedText, targetLanguage,
	 *                               machineTranslationNotice, provider, translatedByAi: true,
	 *                               sourceLanguage, sourceLanguageDetected, model, originalRef,
	 *                               disclosure, disclosureLanguage}` on success, or
	 *                              `{available: false, reason, translatedByAi: false}` when the
	 *                              feature is not enabled or the provider fails.
	 *
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-002-a-successful-translation-always-carries-a-machine-translation-notice
	 * @spec openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-004-a-provider-failure-degrades-to-unavailable-never-an-unhandled-error
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected
	 */
	public function translate(
		string $sourceText,
		string $targetLanguage,
		array $glossary=[],
		?string $sourceLanguage=null,
		string $originalRef='',
	): array {
		// Gate (REQ-001): zero LLM-provider footprint when missing or not enabled.
		$feature = $this->aiFeatureService->findBySlug(slug: self::AIFEATURE_SLUG);
		if ($feature === null || (string)($feature->getObject()['lifecycle'] ?? '') !== 'enabled') {
			return $this->unavailableResult(reason: 'feature-not-enabled');
		}

		try {
			$llmConfig = $this->providerFactory->getLlmConfig();
			$provider  = (string)($llmConfig['chatProvider'] ?? '');

			$translatedText = trim($this->providerFactory->generateText(
				prompt: $this->buildPrompt(sourceText: $sourceText, targetLanguage: $targetLanguage, glossary: $glossary)
			));

			if ($translatedText === '') {
				return $this->unavailableResult(reason: 'provider-error');
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[MessageTranslationEngine] Translation failed: ' . $e->getMessage()
			);
			return $this->unavailableResult(reason: 'provider-error');
		}//end try

		// Provenance (REQ-006/REQ-007): caller first, detected second, und last.
		$detected = false;
		if ($sourceLanguage === null || $sourceLanguage === '') {
			$sourceLanguage = $this->detectLanguage(sourceText: $sourceText);
			$detected       = true;
		}

		$disclosure = $this->disclosure->forLanguages(sourceLanguage: $sourceLanguage, targetLanguage: $targetLanguage);

		return [
			'available'                => true,
			'translatedText'           => $translatedText,
			'targetLanguage'           => $targetLanguage,
			'machineTranslationNotice' => self::MACHINE_TRANSLATION_NOTICE,
			'provider'                 => $provider,
			'translatedByAi'           => true,
			'sourceLanguage'           => $sourceLanguage,
			'sourceLanguageDetected'   => $detected,
			'model'                    => $this->modelLabel(llmConfig: $llmConfig),
			'originalRef'              => $originalRef,
			'disclosure'               => $disclosure['disclosure'],
			'disclosureLanguage'       => $disclosure['disclosureLanguage'],
		];

	}//end translate()

	/**
	 * Ask the provider for the BCP-47 tag of the source text. Any answer that
	 * is not a well-shaped tag, and any provider failure, yields `und`: a
	 * failed detection never costs the reader the translation.
	 *
	 * @param string $sourceText The text whose language is asked for.
	 *
	 * @return string The detected tag, primary subtag lower-cased, or `und`.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected
	 */
	private function detectLanguage(string $sourceText): string {
		$prompt = 'Reply with only the BCP-47 language tag (for example "nl" or "ar") of the language '
			. 'the following text is written in. No other words, no punctuation.'
			. "\n\nText:\n" . mb_substr($sourceText, 0, self::DETECTION_SAMPLE_LENGTH);

		try {
			$answer = trim($this->providerFactory->generateText(prompt: $prompt), " \t\n\r\0\x0B.\"'`");
		} catch (Throwable $e) {
			$this->logger->info('[MessageTranslationEngine] Language detection failed: ' . $e->getMessage());
			return TranslationDisclosure::UNDETERMINED;
		}

		if ($this->disclosure->isLanguageTag(tag: $answer) === false) {
			return TranslationDisclosure::UNDETERMINED;
		}

		$subtags    = explode('-', $answer);
		$subtags[0] = strtolower($subtags[0]);
		return implode('-', $subtags);
	}//end detectLanguage()

	/**
	 * The provider label: `chatProvider`, plus `/<chat model id>` when one is
	 * configured. Only those two keys are read, and the result is filtered to a
	 * model-id character set, so no credential id, organisation id, base URL or
	 * key can reach a response.
	 *
	 * @param array<string, mixed> $llmConfig The `hermiq.llm` configuration.
	 *
	 * @return string The label, or an empty string when no provider is configured.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-008-the-model-label-never-carries-a-secret
	 */
	private function modelLabel(array $llmConfig): string {
		$provider = (string)($llmConfig['chatProvider'] ?? '');
		if ($provider === '') {
			return '';
		}

		$providerConfig = $llmConfig[$provider . 'Config'] ?? [];
		$model          = '';
		if (is_array($providerConfig) === true) {
			$model = (string)($providerConfig['chatModel'] ?? $providerConfig['model'] ?? '');
		}

		$label = $provider;
		if ($model !== '') {
			$label .= '/' . $model;
		}

		$label = (string)preg_replace('/[^A-Za-z0-9._:\/@+-]/', '', $label);
		return substr($label, 0, self::MODEL_LABEL_MAX_LENGTH);
	}//end modelLabel()

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
	 * @return array{available: false, reason: string, translatedByAi: false}
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 */
	private function unavailableResult(string $reason): array {
		return [
			'available'      => false,
			'reason'         => $reason,
			'translatedByAi' => false,
		];
	}//end unavailableResult()
}//end class
