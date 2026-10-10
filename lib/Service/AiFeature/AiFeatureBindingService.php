<?php

/**
 * Hermiq AiFeatureBindingService.
 *
 * The write side of the join: binding one `AiFeature` to a provider and model, and
 * declaring the residency that feature demands. The organisation's effective
 * `ModelPolicy` is the ceiling, so a binding outside it is refused here, naming the
 * policy that forbids it. The write-time refusal is a courtesy to whoever is typing;
 * the refusal that matters is the run-time one in `FeatureProviderResolver`, because
 * a policy narrowed later must take effect without anybody revisiting the feature.
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Writes an AI feature's provider binding and required residency.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
 */
class AiFeatureBindingService {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for AiFeature objects.
	 *
	 * @var string
	 */
	private const AIFEATURE_SCHEMA = 'agentaifeature';

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService $features Reads the AiFeature register (RBAC + tenancy scoped).
	 * @param TenantModelPolicyService $modelPolicy The ceiling a binding must stay within.
	 * @param ProviderResidencyRegistry $residency Validates the residency vocabulary.
	 * @param ObjectService $objectService OpenRegister single write-path.
	 */
	public function __construct(
		private readonly AiFeatureService $features,
		private readonly TenantModelPolicyService $modelPolicy,
		private readonly ProviderResidencyRegistry $residency,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Bind a provider and model to a feature, and set the residency it requires.
	 *
	 * Passing an empty provider and model clears the binding, which returns the
	 * feature to the organisation's effective `ModelPolicy` default: the behaviour
	 * every feature had before this change.
	 *
	 * @param string $id The AiFeature UUID.
	 * @param string|null $provider The provider to bind, '' or null to clear.
	 * @param string|null $model The model to bind, '' or null to clear.
	 * @param string|null $requiredResidency The residency the feature demands, '' or null to clear.
	 *
	 * @return ObjectEntity|null The stored feature, or null when there is no such feature.
	 *
	 * @throws InvalidArgumentException When the pair is outside the effective policy, when only
	 *                                  one half of the pair is given, or when the residency is
	 *                                  not one an administrator may state.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function bind(string $id, ?string $provider, ?string $model, ?string $requiredResidency = null): ?ObjectEntity {
		$feature = $this->features->getFeature(id: $id);
		if ($feature === null) {
			return null;
		}

		$provider = trim((string)$provider);
		$model = trim((string)$model);
		$requiredResidency = trim((string)$requiredResidency);

		if (($provider === '') !== ($model === '')) {
			throw new InvalidArgumentException(
				'A binding needs both a provider and a model, or neither: a provider without a model '
				. 'would leave the model to the instance configuration and the binding would not mean what it says.'
			);
		}

		if ($requiredResidency !== ''
			&& in_array($requiredResidency, ProviderResidencyRegistry::DECLARABLE_RESIDENCIES, true) === false
		) {
			throw new InvalidArgumentException(
				"Unsupported residency '{$requiredResidency}' — must be one of: "
				. implode(', ', ProviderResidencyRegistry::DECLARABLE_RESIDENCIES)
			);
		}

		$organisation = (string)($feature->getOrganisation() ?? '');

		if ($provider !== '') {
			$this->assertWithinPolicy(organisation: $organisation, provider: $provider, model: $model);
		}

		$data = $feature->getObject();
		$data['provider'] = $provider;
		$data['model'] = $model;
		$data['requiredResidency'] = $requiredResidency;

		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::AIFEATURE_SCHEMA,
			uuid: (string)$feature->getUuid()
		);

	}//end bind()

	/**
	 * What the feature register shows about where each feature's runs will go: the
	 * provider it will use, where it was resolved from, and the residency of that
	 * provider. What leaves the building is then readable in one place.
	 *
	 * @param FeatureProviderResolver $resolver Resolves each feature's effective binding.
	 *
	 * @return array<int, array<string, mixed>> One row per feature.
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-split-deployment-must-be-expressible-as-configuration
	 */
	public function overview(FeatureProviderResolver $resolver): array {
		$rows = [];
		foreach ($this->features->listFeatures() as $feature) {
			$data = $feature->getObject();
			$slug = (string)($data['slug'] ?? '');
			$organisation = (string)($feature->getOrganisation() ?? '');

			$binding = $resolver->bindingFor(featureSlug: $slug, organisation: $organisation);
			$declaration = ['residency' => ProviderResidencyRegistry::RESIDENCY_UNDECLARED, 'location' => ''];
			if ($binding['provider'] !== null) {
				$declaration = $this->residency->forProvider(provider: $binding['provider']);
			}

			$rows[] = [
				'id' => (string)($feature->getUuid() ?? ''),
				'slug' => $slug,
				'name' => (string)($data['name'] ?? ''),
				'provider' => $binding['provider'],
				'model' => $binding['model'],
				'source' => $binding['source'],
				'requiredResidency' => $binding['requiredResidency'],
				'residency' => $declaration['residency'],
				'location' => $declaration['location'],
			];
		}//end foreach

		return $rows;
	}//end overview()

	/**
	 * Refuse a binding the organisation's effective policy does not permit, naming
	 * the policy that forbids it.
	 *
	 * @param string $organisation The feature's organisation.
	 * @param string $provider The provider being bound.
	 * @param string $model The model being bound.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the pair is outside the effective policy.
	 */
	private function assertWithinPolicy(string $organisation, string $provider, string $model): void {
		if ($this->modelPolicy->isAllowed(organisation: $organisation, provider: $provider, model: $model) === true) {
			return;
		}

		$policy = $this->modelPolicy->effectivePolicyFor(organisation: $organisation);
		$orgLabel = $organisation;
		if ($orgLabel === '') {
			$orgLabel = '(instance-wide)';
		}

		throw new InvalidArgumentException(
			sprintf(
				"Refused by the model-policy check: the %s model policy for '%s' does not permit provider '%s' model '%s'.",
				(string)($policy['source'] ?? 'effective'),
				$orgLabel,
				$provider,
				$model
			)
		);

	}//end assertWithinPolicy()
}//end class
