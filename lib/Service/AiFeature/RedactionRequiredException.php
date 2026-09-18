<?php

/**
 * Hermiq RedactionRequiredException.
 *
 * Thrown before any request reaches a provider when a feature declaring
 * `requiresRedaction` is handed a document that filinq has not redacted, or when
 * filinq cannot be asked at all. Refusing afterwards would record that the citizen's
 * data was sent, which is the opposite of the capability this is.
 *
 * @category Exception
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use RuntimeException;

/**
 * A run refused because the document it was handed has not been redacted.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-the-redaction-check-must-sit-in-the-ordered-pre-call-path
 */
class RedactionRequiredException extends RuntimeException {

	/**
	 * The name of the check that refused. Three gates now sit in front of a
	 * provider call, and a refusal that does not say which one spoke sends a
	 * reader to the wrong screen.
	 *
	 * @var string
	 */
	public const STEP = 'redaction';

	/**
	 * Constructor.
	 *
	 * @param string $featureSlug The AI feature that was run.
	 * @param string $documentReference The document the run was handed.
	 * @param string $reason Why no redaction could be shown for it.
	 */
	public function __construct(
		public readonly string $featureSlug,
		public readonly string $documentReference,
		public readonly string $reason,
	) {
		parent::__construct(
			sprintf(
				"Refused by the %s check: feature '%s' reads no document that has not been redacted, and document '%s' has none: %s.",
				self::STEP,
				$featureSlug,
				$documentReference,
				$reason
			),
			422
		);

	}//end __construct()

	/**
	 * Which check refused this run.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-the-redaction-check-must-sit-in-the-ordered-pre-call-path
	 */
	public function step(): string {
		return self::STEP;
	}//end step()
}//end class
