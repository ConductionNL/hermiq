<?php

/**
 * Hermiq ProviderResearchModel.
 *
 * A research run's model calls on the configured provider path, checked
 * against the organisation's model policy like every other Hermiq generation
 * (`ProviderFactoryInterface::generateText()` with an organisation).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;

/**
 * Generates a research run's completions through the provider factory.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ProviderResearchModel implements ResearchModel {
	/**
	 * Constructor.
	 *
	 * @param ProviderFactoryInterface $providers The provider path every generation goes through.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ProviderFactoryInterface $providers,
	) {
	}//end __construct()

	/**
	 * One completion, checked against the organisation's model policy.
	 *
	 * @param string $prompt       The prompt.
	 * @param string $userId       The user the research runs for.
	 * @param string $organisation The organisation whose model policy applies.
	 *
	 * @return string The generated text.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function generate(string $prompt, string $userId, string $organisation): string {
		return $this->providers->generateText(prompt: $prompt, userId: $userId, organisation: $organisation);
	}//end generate()
}//end class
