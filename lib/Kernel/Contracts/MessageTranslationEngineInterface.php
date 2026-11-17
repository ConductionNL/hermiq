<?php

/**
 * Contract of MessageTranslationEngine
 *
 * The public surface of MessageTranslationEngine that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of MessageTranslationEngine.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageTranslationEngineInterface {

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
	 *                              machineTranslationNotice, provider, translatedByAi: true,
	 *                              sourceLanguage, sourceLanguageDetected, model, originalRef,
	 *                              disclosure, disclosureLanguage}` on success, or
	 *                              `{available: false, reason, translatedByAi: false}` when the
	 *                              feature is not enabled or the provider fails.
	 *
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-002-a-successful-translation-always-carries-a-machine-translation-notice
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-004-a-provider-failure-degrades-to-unavailable-never-an-unhandled-error
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected
	 * @spec openspec/specs/message-translation/spec.md#requirement-req-011-the-gate-answers-for-a-caller-without-a-nextcloud-session
	 */
	public function translate(
		string $sourceText,
		string $targetLanguage,
		array $glossary = [],
		?string $sourceLanguage = null,
		string $originalRef = '',
	): array;
}//end interface
