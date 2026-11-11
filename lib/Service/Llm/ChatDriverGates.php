<?php

/**
 * Hermiq ChatDriverGates.
 *
 * The gates a resolved chat driver passes before it is handed out. A run that names an
 * AI feature in an organisation takes the feature's provider binding and the feature's
 * own gates (binding, residency, redaction), once for its document and once per
 * attachment; any other run in an organisation takes the organisation's model-policy
 * check and then its data-use check; a run outside an organisation takes neither.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\OrganisationModelPolicyService;

/**
 * Binds a run to its AI feature's provider and enforces the gates on a resolved driver.
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */
final class ChatDriverGates {

	/**
	 * Constructor.
	 *
	 * @param OrganisationModelPolicyService|null $modelPolicyService The organisation's model policy.
	 * @param FeatureProviderResolver|null        $featureResolver    An AI feature's binding and gates.
	 * @param DataUseGate|null                    $dataUseGate        The organisation's data-use rule.
	 */
	public function __construct(
		private readonly ?OrganisationModelPolicyService $modelPolicyService,
		private readonly ?FeatureProviderResolver $featureResolver,
		private readonly ?DataUseGate $dataUseGate,
	) {
	}//end __construct()

	/**
	 * Resolve an AI feature's own provider and model binding, if it has one.
	 *
	 * Step 1 of the order `a-provider-and-a-place-per-ai-feature` specifies. It
	 * only RESOLVES: the model policy ceiling is applied by the caller, after
	 * this, so a binding can narrow the policy and never widen it.
	 *
	 * Extracted from createChatDriver() unchanged. `featureBound` is true
	 * whenever a feature and an organisation were both given and a resolver
	 * exists, INCLUDING when the resolver answered with nothing — that is the
	 * existing behaviour and the caller depends on it.
	 *
	 * @param ChatDriverRequest $request The resolution: its feature slug and organisation, and
	 *                                   the provider and model resolved so far, which a binding
	 *                                   replaces.
	 *
	 * @return void
	 *
	 * @spec exclude extracted verbatim from createChatDriver(); covered by its tests
	 */
	public function resolveFeatureBinding(ChatDriverRequest $request): void {
		if ($request->aiFeature === null || $request->aiFeature === '' || $this->featureResolver === null || $request->organisation === null) {
			return;
		}

		$request->featureBound = true;
		$binding = $this->featureResolver->bindingFor(featureSlug: $request->aiFeature, organisation: $request->organisation);
		if ($binding['provider'] !== null && $binding['model'] !== null) {
			$request->chatProvider = $binding['provider'];
			$request->agentModel = $binding['model'];
		}
	}//end resolveFeatureBinding()

	/**
	 * Tenant-model-policy: the single enforcement chokepoint, and steps 2 and 3 of
	 * this change's specified order. Runs AFTER the agent override is applied (the
	 * driver builders already resolved agentModel ?? providerConfig into
	 * $driver->model) so a policy cannot be bypassed by leaving the per-agent model
	 * field blank — see design.md "Decisions". Runs BEFORE any network call: driver
	 * construction only builds client value objects, it never sends a request.
	 *
	 * When the run names an AI feature the resolver applies both gates in order and
	 * names the one that refuses; with no feature named the model-policy gate alone
	 * runs, exactly as before, followed by the data-use step (models-no-training-
	 * guarantee), so a run that names no AI feature cannot reach a provider that
	 * may train.
	 *
	 * @param ChatDriverRequest $request The resolution.
	 * @param ChatDriver        $driver  The built driver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
	 */
	public function enforceDriverGates(ChatDriverRequest $request, ChatDriver $driver): void {
		if ($request->featureBound === true && $this->featureResolver !== null && $request->organisation !== null) {
			$this->enforceFeatureGates(request: $request, driver: $driver);
			return;
		}

		$this->enforceModelPolicy(organisation: $request->organisation, provider: $driver->provider, model: $driver->model);

		if ($request->organisation !== null) {
			$this->dataUseGate?->enforce(organisation: $request->organisation, provider: $driver->provider);
		}
	}//end enforceDriverGates()

	/**
	 * Apply the feature's gates to the run's document and then, one call per file,
	 * to every attachment, so a redacted first file cannot carry an unredacted
	 * second one past the gate (chat-attachments-and-images).
	 *
	 * @param ChatDriverRequest $request The resolution (feature-bound, with an organisation).
	 * @param ChatDriver        $driver  The built driver.
	 *
	 * @return void
	 */
	private function enforceFeatureGates(ChatDriverRequest $request, ChatDriver $driver): void {
		$this->featureResolver?->enforceForRun(
			featureSlug: (string)$request->aiFeature,
			organisation: (string)$request->organisation,
			provider: $driver->provider,
			model: $driver->model,
			documentReference: $request->documentReference
		);

		foreach ($request->attachmentReferences as $attachmentReference) {
			$this->featureResolver?->enforceForRun(
				featureSlug: (string)$request->aiFeature,
				organisation: (string)$request->organisation,
				provider: $driver->provider,
				model: $driver->model,
				documentReference: (string)$attachmentReference
			);
		}
	}//end enforceFeatureGates()

	/**
	 * Check a resolved (provider, model) pair against the calling organisation's
	 * effective ModelPolicy; throw when out of policy. A no-op when either
	 * `$organisation` is null (caller opted out of enforcement, e.g. a purely
	 * instance-wide background call) or no policy service was injected
	 * (backward-compatible default for pre-existing call sites/tests).
	 *
	 * @param string|null $organisation The calling organisation, or null to skip the check.
	 * @param string $provider The resolved provider.
	 * @param string $model The resolved model id.
	 *
	 * @return void
	 *
	 * @throws ModelPolicyViolationException When the pair is outside the effective policy.
	 *
	 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 */
	private function enforceModelPolicy(?string $organisation, string $provider, string $model): void {
		if ($organisation === null || $this->modelPolicyService === null) {
			return;
		}

		if ($this->modelPolicyService->isAllowed(organisation: $organisation, provider: $provider, model: $model) === true) {
			return;
		}

		$orgLabel = $organisation;
		if ($orgLabel === '') {
			$orgLabel = '(instance-wide)';
		}

		throw new ModelPolicyViolationException(
			sprintf(
				"Refused by the model-policy check: organisation '%s' does not permit provider '%s' model '%s'.",
				$orgLabel,
				$provider,
				$model
			),
			422
		);

	}//end enforceModelPolicy()
}//end class
