<?php

/**
 * Hermiq FeatureRunCheck.
 *
 * One AI-feature run as the pre-call gates see it: the feature it belongs to, the
 * organisation it runs for, the provider and model it resolved to, and the document
 * it was handed, when there is one.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-the-feature-binding-narrows-the-policy-and-is-re-checked-on-every-turn
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

/**
 * One AI-feature run, as checked before the call.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-the-feature-binding-narrows-the-policy-and-is-re-checked-on-every-turn
 */
final class FeatureRunCheck {

	/**
	 * The run.
	 *
	 * @param string $featureSlug The AI feature slug this run belongs to.
	 * @param string $organisation The organisation the run belongs to ('' = instance-wide).
	 * @param string $provider The resolved provider.
	 * @param string $model The resolved model id.
	 * @param string|null $documentReference The document this run was handed, when there is one.
	 */
	public function __construct(
		public readonly string $featureSlug,
		public readonly string $organisation,
		public readonly string $provider,
		public readonly string $model,
		public readonly ?string $documentReference = null,
	) {
	}//end __construct()

	/**
	 * The document reference, trimmed; '' when there is none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
	 */
	public function documentReference(): string {
		return trim((string)$this->documentReference);
	}//end documentReference()

	/**
	 * The organisation as a refusal names it: '(instance-wide)' when there is none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-the-feature-binding-narrows-the-policy-and-is-re-checked-on-every-turn
	 */
	public function organisationLabel(): string {
		if ($this->organisation === '') {
			return '(instance-wide)';
		}

		return $this->organisation;
	}//end organisationLabel()
}//end class
