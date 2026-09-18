<?php

/**
 * Hermiq FeatureProviderResolver.
 *
 * The join between two registers that never met: `ModelPolicy`, which says per
 * organisation which `{provider, models[]}` pairs are permitted at all, and
 * `AiFeature`, which says per feature what it is and whether it is on. The question
 * every functionaris gegevensbescherming asks first — which model saw this case, and
 * in which jurisdiction — falls exactly between them.
 *
 * The feature narrows, and can never widen. A binding is resolved first, the
 * organisation's effective policy is then applied to the resolved pair on every turn
 * whatever the trigger, and only then is the residency the feature demands checked.
 * Each step names itself when it refuses, and the whole sequence runs before any
 * request reaches a provider: a run refused after the text has been sent records a
 * breach rather than preventing one.
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

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\TenantModelPolicyService;

/**
 * Resolves which provider a feature's run uses, and refuses it before the call.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
 */
class FeatureProviderResolver {

	/**
	 * The binding came from the feature itself.
	 *
	 * @var string
	 */
	public const SOURCE_FEATURE = 'feature';

	/**
	 * The binding came from the organisation's effective ModelPolicy default.
	 *
	 * @var string
	 */
	public const SOURCE_POLICY = 'policy';

	/**
	 * Nothing bound this run: the instance's configured provider is used, which is
	 * the behaviour of every feature before this change.
	 *
	 * @var string
	 */
	public const SOURCE_INSTANCE = 'instance';

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService $features Reads the AiFeature register.
	 * @param TenantModelPolicyService $modelPolicy The ceiling a binding narrows within.
	 * @param ProviderResidencyRegistry $residency Where each provider runs, as administered.
	 * @param RedactionOutcomeReader|null $redaction Reads filinq's redaction outcome for a
	 *                                               document. Nullable and trailing so a
	 *                                               resolver built by hand keeps its old
	 *                                               shape; a null reader refuses every
	 *                                               feature that requires redaction, which
	 *                                               is the same fail-closed answer an
	 *                                               absent filinq gives.
	 */
	public function __construct(
		private readonly AiFeatureService $features,
		private readonly TenantModelPolicyService $modelPolicy,
		private readonly ProviderResidencyRegistry $residency,
		private readonly ?RedactionOutcomeReader $redaction = null,
	) {
	}//end __construct()

	/**
	 * Resolve which `(provider, model)` a feature's run should use: the feature's
	 * own binding when both fields are set, else the organisation's effective
	 * `ModelPolicy` default, else nothing, which leaves today's instance-wide
	 * configuration in charge.
	 *
	 * This step only resolves. It never refuses: the policy is applied to the
	 * resolved pair by `enforceForRun()`, so a binding cannot skip the ceiling by
	 * being resolved.
	 *
	 * @param string $featureSlug The AI feature slug this run belongs to.
	 * @param string $organisation The organisation the run belongs to.
	 *
	 * @return array{provider: string|null, model: string|null, requiredResidency: string|null, requiresRedaction: bool, source: string}
	 *         The resolved binding.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function bindingFor(string $featureSlug, string $organisation): array {
		$data = $this->featureData(featureSlug: $featureSlug);

		$provider = trim((string)($data['provider'] ?? ''));
		$model = trim((string)($data['model'] ?? ''));
		$requiredResidency = trim((string)($data['requiredResidency'] ?? ''));
		if ($requiredResidency === '') {
			$requiredResidency = null;
		}

		$requiresRedaction = (($data['requiresRedaction'] ?? false) === true);

		if ($provider !== '' && $model !== '') {
			return [
				'provider' => $provider,
				'model' => $model,
				'requiredResidency' => $requiredResidency,
				'requiresRedaction' => $requiresRedaction,
				'source' => self::SOURCE_FEATURE,
			];
		}

		$default = ($this->modelPolicy->effectivePolicyFor(organisation: $organisation)['defaultModel'] ?? null);
		if (is_array($default) === true
			&& (string)($default['provider'] ?? '') !== ''
			&& (string)($default['model'] ?? '') !== ''
		) {
			return [
				'provider' => (string)$default['provider'],
				'model' => (string)$default['model'],
				'requiredResidency' => $requiredResidency,
				'requiresRedaction' => $requiresRedaction,
				'source' => self::SOURCE_POLICY,
			];
		}

		return [
			'provider' => null,
			'model' => null,
			'requiredResidency' => $requiredResidency,
			'requiresRedaction' => $requiresRedaction,
			'source' => self::SOURCE_INSTANCE,
		];

	}//end bindingFor()

	/**
	 * Apply both pre-call gates to an already-resolved pair, in the specified
	 * order: narrow by the organisation's effective policy, check the residency the
	 * feature demands, then check that any document it was handed has been redacted.
	 * Returns what the run must record.
	 *
	 * The policy is read here rather than at write time alone, so a policy
	 * narrowed after a binding was written takes effect on the next run with no
	 * edit to the feature.
	 *
	 * @param string $featureSlug The AI feature slug this run belongs to.
	 * @param string $organisation The organisation the run belongs to.
	 * @param string $provider The resolved provider.
	 * @param string $model The resolved model id.
	 * @param string|null $documentReference The document this run was handed, when there is one.
	 *
	 * @return array{feature: string, provider: string, model: string, residency: string, location: string}
	 *         The disclosure to copy onto the run.
	 *
	 * @throws ModelPolicyViolationException When the pair is outside the effective policy.
	 * @throws ResidencyViolationException When the provider runs somewhere the feature forbids.
	 * @throws RedactionRequiredException When the document has not been redacted and the feature requires it.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-the-feature-binding-narrows-the-policy-and-is-re-checked-on-every-turn
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-feature-may-require-a-residency-and-a-run-outside-it-is-refused-before-the-call
	 */
	public function enforceForRun(
		string $featureSlug,
		string $organisation,
		string $provider,
		string $model,
		?string $documentReference = null,
	): array {
		if ($this->modelPolicy->isAllowed(organisation: $organisation, provider: $provider, model: $model) === false) {
			$orgLabel = $organisation;
			if ($orgLabel === '') {
				$orgLabel = '(instance-wide)';
			}

			throw new ModelPolicyViolationException(
				sprintf(
					"Refused by the %s check: organisation '%s' does not permit provider '%s' model '%s' for feature '%s'.",
					ModelPolicyViolationException::STEP,
					$orgLabel,
					$provider,
					$model,
					$featureSlug
				),
				422
			);
		}

		$binding = $this->bindingFor(featureSlug: $featureSlug, organisation: $organisation);

		$declaration = $this->residency->forProvider(provider: $provider);
		$required = ($binding['requiredResidency'] ?? null);

		if ($required !== null && $declaration['residency'] !== $required) {
			throw new ResidencyViolationException(
				featureSlug: $featureSlug,
				requiredResidency: $required,
				actualResidency: $declaration['residency'],
				provider: $provider
			);
		}

		$redaction = $this->enforceRedaction(
			featureSlug: $featureSlug,
			requiresRedaction: (($binding['requiresRedaction'] ?? false) === true),
			documentReference: $documentReference
		);

		return $this->disclosure(
			featureSlug: $featureSlug,
			provider: $provider,
			model: $model,
			declaration: $declaration,
			redaction: $redaction
		);
	}//end enforceForRun()

	/**
	 * The fourth and last gate: a feature that reads no unredacted document is not
	 * handed one. The requirement attaches to the document, not to the run, so the
	 * same feature still summarises a handler's typed note.
	 *
	 * It fails closed in both directions that matter. A document with no recorded
	 * redaction is refused, and so is a document nobody can be asked about, because
	 * "filinq did not answer" is not "the document is clean". hermiq never
	 * substitutes its own detection: detection returns spans over the original text
	 * and needs the model to see that text, so treating it as evidence of redaction
	 * would mark a run safe on the strength of having sent exactly what it must not.
	 *
	 * @param string $featureSlug The AI feature slug.
	 * @param bool $requiresRedaction Whether the feature declares the requirement.
	 * @param string|null $documentReference The document this run was handed, when there is one.
	 *
	 * @return array{required: bool, satisfied: bool, status: string, redactedAt: string, reference: string}
	 *         What the run records about the document it read.
	 *
	 * @throws RedactionRequiredException When the document has no redaction to show.
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-missing-redaction-client-must-fail-closed
	 */
	private function enforceRedaction(string $featureSlug, bool $requiresRedaction, ?string $documentReference): array {
		$reference = trim((string)$documentReference);

		if ($requiresRedaction === false || $reference === '') {
			return [
				'required' => $requiresRedaction,
				'satisfied' => true,
				'status' => 'not-applicable',
				'redactedAt' => '',
				'reference' => $reference,
			];
		}

		if ($this->redaction === null) {
			throw new RedactionRequiredException(
				featureSlug: $featureSlug,
				documentReference: $reference,
				reason: 'the redaction client (filinq) is not available to this instance'
			);
		}

		$outcome = $this->redaction->outcomeFor(documentReference: $reference);

		if ($outcome['available'] === false || $outcome['redacted'] === false) {
			$reason = $outcome['reason'];
			if ($reason === '') {
				$reason = sprintf("the recorded redaction state is '%s'", $outcome['status']);
			}

			throw new RedactionRequiredException(
				featureSlug: $featureSlug,
				documentReference: $reference,
				reason: $reason
			);
		}

		return [
			'required' => true,
			'satisfied' => true,
			'status' => $outcome['status'],
			'redactedAt' => $outcome['redactedAt'],
			'reference' => $reference,
		];

	}//end enforceRedaction()

	/**
	 * What a completed run records about where it went: the feature, the provider
	 * and model actually used, and the residency and location as they stood at the
	 * time. The residency is copied, never referenced, so relabelling a provider
	 * next year cannot rewrite what last year's runs say.
	 *
	 * @param string $featureSlug The AI feature slug this run belongs to.
	 * @param string $provider The provider actually used.
	 * @param string $model The model actually used.
	 *
	 * @return array{feature: string, provider: string, model: string, residency: string, location: string}
	 *         The disclosure to copy onto the run.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-every-run-must-record-the-feature-the-provider-and-the-residency-in-force
	 */
	public function disclosureFor(string $featureSlug, string $provider, string $model): array {
		return $this->disclosure(
			featureSlug: $featureSlug,
			provider: $provider,
			model: $model,
			declaration: $this->residency->forProvider(provider: $provider)
		);

	}//end disclosureFor()

	/**
	 * Shape one disclosure record.
	 *
	 * @param string $featureSlug The AI feature slug.
	 * @param string $provider The provider actually used.
	 * @param string $model The model actually used.
	 * @param array{provider: string, residency: string, location: string} $declaration The provider's declaration.
	 * @param array<string, mixed> $redaction What the redaction gate found, when the run passed through it.
	 *
	 * @return array{feature: string, provider: string, model: string, residency: string, location: string}
	 *         The disclosure.
	 */
	private function disclosure(
		string $featureSlug,
		string $provider,
		string $model,
		array $declaration,
		array $redaction = [],
	): array {
		$disclosure = [
			'feature' => $featureSlug,
			'provider' => $provider,
			'model' => $model,
			'residency' => $declaration['residency'],
			'location' => $declaration['location'],
		];

		if ($redaction !== []) {
			$disclosure['redaction'] = $redaction;
		}

		return $disclosure;
	}//end disclosure()

	/**
	 * The stored object data of one AiFeature, or an empty array when the feature
	 * is not registered.
	 *
	 * @param string $featureSlug The AI feature slug.
	 *
	 * @return array<string, mixed> The feature's object data.
	 */
	private function featureData(string $featureSlug): array {
		if ($featureSlug === '') {
			return [];
		}

		$feature = $this->features->findBySlug(slug: $featureSlug);
		if ($feature === null) {
			return [];
		}

		return $feature->getObject();
	}//end featureData()
}//end class
