<?php

/**
 * Hermiq EffectiveModelPolicy.
 *
 * Which `ModelPolicy` governs an organisation, and whether it allows a (provider, model)
 * pair. Resolution order (design.md "Decisions"): (1) the organisation's own ModelPolicy
 * if one exists; (2) else the organisation-less instance-wide default ModelPolicy
 * (`ObjectEntity.organisation === ''`) if one exists; (3) else — no policy anywhere —
 * a synthetic, fail-CLOSED policy restricting to whatever `hermiq.llm.chatProvider`
 * is currently configured, never fully open. The policy read skips RBAC and tenancy
 * (matched by `ObjectEntity.organisation`) because a schedule tick or a flow-triggered
 * run checks it before any per-user context exists.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\EffectiveModelPolicyInterface;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Resolves the effective model policy of an organisation and checks a pair against it.
 *
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
 */
class EffectiveModelPolicy implements EffectiveModelPolicyInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for model-policy objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'modelpolicy';

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService   OpenRegister object reads.
	 * @param LlmSettingsHandler $settingsHandler Reads `hermiq.llm.chatProvider` for the
	 *                                            fail-closed fallback policy.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LlmSettingsHandler $settingsHandler,
	) {
	}//end __construct()

	/**
	 * Get the ModelPolicy object for an organisation, if one exists.
	 *
	 * Matched by `ObjectEntity.organisation`. An empty string matches the
	 * organisation-less INSTANCE-DEFAULT policy (a deliberate, valid lookup — not
	 * an error case, unlike `OrganisationControlService::getForOrganisation()` which
	 * treats `''` as "no organisation given").
	 *
	 * @param string $organisation The organisation identifier, or '' for the instance default.
	 *
	 * @return ObjectEntity|null The policy object, or null when none exists at this scope.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
	 */
	public function getForOrganisation(string $organisation): ?ObjectEntity {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA_SLUG)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getOrganisation() ?? '') === $organisation) {
				return $object;
			}
		}

		return null;
	}//end getForOrganisation()

	/**
	 * Resolve the effective policy for an organisation: its own ModelPolicy, else
	 * the organisation-less instance default, else a fail-closed synthetic policy
	 * restricting to the currently-configured `hermiq.llm.chatProvider` only.
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 *
	 * @return array{source:string,allowed:list<array{provider:string,models:list<string>}>,defaultModel:array{provider:string,model:string}|null}
	 *                                                                                                                                             The effective, shaped policy.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
	 */
	public function effectivePolicyFor(string $organisation): array {
		if ($organisation !== '') {
			$own = $this->getForOrganisation(organisation: $organisation);
			if ($own !== null) {
				return $this->shape(policy: $own, source: 'organisation');
			}
		}

		$instanceDefault = $this->getForOrganisation(organisation: '');
		if ($instanceDefault !== null) {
			return $this->shape(policy: $instanceDefault, source: 'instance');
		}

		return $this->fallbackPolicy();
	}//end effectivePolicyFor()

	/**
	 * The fail-closed synthetic policy used when no ModelPolicy exists anywhere
	 * (organisation-specific or instance-wide): the single provider currently
	 * configured in `hermiq.llm.chatProvider`, or nothing when even that is unset
	 * — never "every provider is allowed".
	 *
	 * @return array{source: string, allowed: array<int, array{provider: string, models: array<int, string>}>, defaultModel: null}
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
	 */
	private function fallbackPolicy(): array {
		$chatProvider = $this->settingsHandler->getLLMSettingsOnly()['chatProvider'] ?? null;

		$allowed = [];
		if (is_string($chatProvider) === true && $chatProvider !== '') {
			$allowed[] = ['provider' => $chatProvider, 'models' => []];
		}

		return [
			'source' => 'fallback',
			'allowed' => $allowed,
			'defaultModel' => null,
			'requireNoTraining' => false,
			'fallbackChain' => [],
		];

	}//end fallbackPolicy()

	/**
	 * Whether a resolved (provider, model) pair is within an organisation's
	 * effective policy. An empty `models[]` for an allowed provider means "any
	 * model for that provider is permitted".
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 * @param string $provider The resolved provider.
	 * @param string $model The resolved model id.
	 *
	 * @return bool True when the pair is allowed.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 */
	public function isAllowed(string $organisation, string $provider, string $model): bool {
		$policy = $this->effectivePolicyFor(organisation: $organisation);

		return $this->matchesAllowed(allowed: $policy['allowed'], provider: $provider, model: $model);
	}//end isAllowed()

	/**
	 * Whether an organisation's effective policy only allows providers that never
	 * train on its data (models-no-training-guarantee). An organisation without a
	 * policy of its own inherits the instance default's answer.
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 *
	 * @return bool True when the requirement is on.
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function requiresNoTraining(string $organisation): bool {
		return ($this->effectivePolicyFor(organisation: $organisation)['requireNoTraining'] ?? false) === true;
	}//end requiresNoTraining()

	/**
	 * Whether (provider, model) matches an `allowed` list — an empty `models[]`
	 * for a matched provider means "any model".
	 *
	 * @param array<int, array{provider: string, models: array<int, string>}> $allowed The allowlist.
	 * @param string $provider The provider to check.
	 * @param string $model The model id to check.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
	 */
	public function matchesAllowed(array $allowed, string $provider, string $model): bool {
		foreach ($allowed as $entry) {
			if ($entry['provider'] !== $provider) {
				continue;
			}

			$models = $entry['models'];
			if ($models === []) {
				return true;
			}

			if (in_array($model, $models, true) === true) {
				return true;
			}
		}

		return false;
	}//end matchesAllowed()

	/**
	 * Shape a ModelPolicy ObjectEntity into the API response payload.
	 *
	 * @param ObjectEntity $policy The policy object.
	 * @param string $source Where this policy came from ('organisation'|'instance').
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
	 */
	public function shape(ObjectEntity $policy, string $source): array {
		$data = $policy->getObject();

		return [
			'id' => (string)($policy->getUuid() ?? ''),
			'organisation' => (string)($policy->getOrganisation() ?? ''),
			'source' => $source,
			'allowed' => $data['allowed'] ?? [],
			'defaultModel' => $data['defaultModel'] ?? null,
			'requireNoTraining' => (($data['requireNoTraining'] ?? false) === true),
			// The providers a turn moves to when its own fails (models-several-models-per-turn).
			'fallbackChain' => array_values((array)($data['fallbackChain'] ?? [])),
		];

	}//end shape()
}//end class
