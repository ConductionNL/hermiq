<?php

/**
 * Hermiq ResidencyViolationException.
 *
 * Thrown before any request reaches a provider when the feature being run requires
 * a residency the resolved provider does not carry. It is raised at the same
 * chokepoint as the model-policy check and after it, so a refusal prevents a
 * transfer rather than recording one.
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-feature-may-require-a-residency-and-a-run-outside-it-is-refused-before-the-call
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use RuntimeException;

/**
 * A run refused because the provider runs somewhere the feature forbids.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-feature-may-require-a-residency-and-a-run-outside-it-is-refused-before-the-call
 */
class ResidencyViolationException extends RuntimeException {

	/**
	 * The name of the check that refused, so a reader never has to guess which
	 * of the two pre-call gates stopped the run.
	 *
	 * @var string
	 */
	public const STEP = 'residency';

	/**
	 * Constructor.
	 *
	 * @param string $featureSlug The AI feature that was run.
	 * @param string $requiredResidency The residency the feature demands.
	 * @param string $actualResidency The residency the resolved provider carries.
	 * @param string $provider The resolved provider id.
	 */
	public function __construct(
		public readonly string $featureSlug,
		public readonly string $requiredResidency,
		public readonly string $actualResidency,
		public readonly string $provider,
	) {
		parent::__construct(
			sprintf(
				"Refused by the %s check: feature '%s' requires a provider running %s, and provider '%s' runs %s.",
				self::STEP,
				$featureSlug,
				$requiredResidency,
				$provider,
				$actualResidency
			),
			422
		);

	}//end __construct()

	/**
	 * Which check refused this run.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-feature-may-require-a-residency-and-a-run-outside-it-is-refused-before-the-call
	 */
	public function step(): string {
		return self::STEP;
	}//end step()
}//end class
