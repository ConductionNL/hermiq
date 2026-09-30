<?php

/**
 * Hermiq data-use gate (models-no-training-guarantee).
 *
 * The pre-call step between the model policy and residency: when the
 * organisation's effective model policy sets `requireNoTraining`, a provider
 * whose declaration is not zero-retention or no-training is refused before any
 * request is built. It runs for every run, whether or not the run names an AI
 * feature, because a tender requirement on training does not care whether the
 * agent was tagged with one.
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
 * @spec openspec/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\Hermiq\Service\TenantModelPolicyService;

/**
 * Refuses a run on a provider that may train, where the organisation forbids it.
 *
 * @spec openspec/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
 */
class DataUseGate {

	/**
	 * Constructor.
	 *
	 * @param TenantModelPolicyService $policies The effective model policy.
	 * @param ProviderDataUseRegistry $registry The providers' declarations.
	 */
	public function __construct(
		private readonly TenantModelPolicyService $policies,
		private readonly ProviderDataUseRegistry $registry,
	) {
	}//end __construct()

	/**
	 * Refuse the run when the organisation requires no training and the provider
	 * has not declared it.
	 *
	 * @param string $organisation The calling organisation ('' for instance-wide).
	 * @param string $provider The resolved provider.
	 *
	 * @return void
	 *
	 * @throws DataUseViolationException When the provider may train or has declared nothing.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function enforce(string $organisation, string $provider): void {
		if ($this->policies->requiresNoTraining(organisation: $organisation) === false) {
			return;
		}

		if ($this->registry->neverTrains(provider: $provider) === true) {
			return;
		}

		throw new DataUseViolationException(
			organisation: $organisation,
			provider: $provider,
			dataUse: $this->registry->forProvider(provider: $provider)['dataUse']
		);

	}//end enforce()

	/**
	 * The provider's declaration, for the run's disclosure.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return array{provider: string, dataUse: string, termsReference: string, declaredBy: string, declaredAt: string}
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-every-run-records-the-data-use-term-in-force-req-notrain-003
	 */
	public function declaration(string $provider): array {
		return $this->registry->forProvider(provider: $provider);

	}//end declaration()

}//end class
