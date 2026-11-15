<?php

/**
 * Hermiq OrganisationModelPolicyService.
 *
 * Reads and writes the per-organisation `ModelPolicy` OpenRegister object: an
 * allowlist of {provider, models[]} pairs (drawn from the four supported chat
 * drivers) plus an optional suggested default model. Mirrors
 * `OrganisationControlService`'s exact per-organisation-object pattern (`_rbac: false,
 * _multitenancy: false`, matched by `ObjectEntity.organisation`) because a policy
 * read must work for a system-wide schedule tick or flow-triggered run — which run
 * before any per-user RBAC context exists — exactly as the kill-switch read does.
 *
 * Which policy governs an organisation (own policy → instance default → fail-closed
 * fallback) is resolved by EffectiveModelPolicy.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle (same role as
 * OrganisationControlService).
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
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\Contracts\OrganisationModelPolicyServiceInterface;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Reads/writes ModelPolicy objects. Which policy governs an organisation, and what it
 * allows, is EffectiveModelPolicy's.
 *
 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
 */
class OrganisationModelPolicyService implements OrganisationModelPolicyServiceInterface {

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
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param EffectiveModelPolicy $effectivePolicy Reads a stored policy and its shape, and
	 *                                              checks a pair against the effective policy.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EffectiveModelPolicy $effectivePolicy,
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
		return $this->effectivePolicy->getForOrganisation(organisation: $organisation);
	}//end getForOrganisation()

	/**
	 * Find a single ModelPolicy by UUID, system-wide (controller write-path
	 * authorization needs the policy's organisation before deciding whether the
	 * caller may administer it — mirrors `BudgetService::findById()`).
	 *
	 * @param string $uuid The ModelPolicy object UUID.
	 *
	 * @return ObjectEntity|null The policy, or null when not found.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function findById(string $uuid): ?ObjectEntity {
		if ($uuid === '') {
			return null;
		}

		return $this->objectService->find(
			id: $uuid,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			_rbac: false,
			_multitenancy: false
		);

	}//end findById()

	/**
	 * List every ModelPolicy object, system-wide (controller filters visibility).
	 *
	 * @return array<int, ObjectEntity> Every policy object.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function listAll(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA_SLUG)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end listAll()

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
		return $this->effectivePolicy->isAllowed(organisation: $organisation, provider: $provider, model: $model);
	}//end isAllowed()

	/**
	 * Create-or-update the ModelPolicy for an organisation (at most one per
	 * organisation, mirroring `OrganisationControlService::toggle()`'s upsert
	 * semantics). `$organisation === ''` upserts the organisation-less instance
	 * default.
	 *
	 * @param string $organisation The target organisation ('' for the instance default).
	 * @param array<string, mixed> $payload The requested `allowed`/`defaultModel` fields.
	 *
	 * @return array<string, mixed> The shaped, persisted policy.
	 *
	 * @throws InvalidArgumentException When `allowed`/`defaultModel` fail validation.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
	 */
	public function upsertForOrganisation(string $organisation, array $payload): array {
		$allowed = $this->normaliseAllowed(raw: ($payload['allowed'] ?? []));
		$defaultModel = $this->normaliseDefaultModel(raw: ($payload['defaultModel'] ?? null), allowed: $allowed);

		$existing = $this->getForOrganisation(organisation: $organisation);

		$data = [];
		$uuid = null;
		if ($existing !== null) {
			$data = $existing->getObject();
			$uuid = (string)$existing->getUuid();
		}

		$data['allowed'] = $allowed;
		$data['defaultModel'] = $defaultModel;
		$data['requireNoTraining'] = (($payload['requireNoTraining'] ?? ($data['requireNoTraining'] ?? false)) === true);

		// Pin the policy to the TARGET organisation, not the actor's active
		// organisation (mirrors OrganisationControlService::toggle()'s @self.organisation
		// trick) — without this an instance admin upserting a different tenant's
		// policy, or the org-less instance default, would be stamped into their
		// own active org instead.
		$self = (array)($data['@self'] ?? []);
		$self['organisation'] = $organisation;
		$data['@self'] = $self;

		$policy = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$source = 'organisation';
		if ($organisation === '') {
			$source = 'instance';
		}

		return $this->shape(policy: $policy, source: $source);
	}//end upsertForOrganisation()

	/**
	 * Update an existing ModelPolicy by UUID (controller-gated).
	 *
	 * @param string $uuid The ModelPolicy object UUID.
	 * @param array<string, mixed> $payload The fields to update.
	 *
	 * @return array<string, mixed> The shaped, updated policy.
	 *
	 * @throws RuntimeException When the policy cannot be found.
	 * @throws InvalidArgumentException When the merged payload fails validation.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function update(string $uuid, array $payload): array {
		$existing = $this->findById(uuid: $uuid);
		if ($existing === null) {
			throw new RuntimeException("ModelPolicy '{$uuid}' does not exist");
		}

		$data = $existing->getObject();

		$allowedRaw = $data['allowed'] ?? [];
		if (array_key_exists('allowed', $payload) === true) {
			$allowedRaw = $payload['allowed'];
		}

		$defaultModelRaw = $data['defaultModel'] ?? null;
		if (array_key_exists('defaultModel', $payload) === true) {
			$defaultModelRaw = $payload['defaultModel'];
		}

		$allowed = $this->normaliseAllowed(raw: $allowedRaw);
		$data['allowed'] = $allowed;
		$data['defaultModel'] = $this->normaliseDefaultModel(raw: $defaultModelRaw, allowed: $allowed);
		if (array_key_exists('requireNoTraining', $payload) === true) {
			$data['requireNoTraining'] = ($payload['requireNoTraining'] === true);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');

		$policy = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$source = 'organisation';
		if ($organisation === '') {
			$source = 'instance';
		}

		return $this->shape(policy: $policy, source: $source);
	}//end update()

	/**
	 * Validate + normalise the `allowed` payload into a list of
	 * `{provider, models[]}` entries.
	 *
	 * @param mixed $raw The raw `allowed` payload value.
	 *
	 * @return array<int, array{provider: string, models: array<int, string>}> The normalised list.
	 *
	 * @throws InvalidArgumentException When an entry is malformed or names an
	 *                                  unsupported provider.
	 */
	private function normaliseAllowed(mixed $raw): array {
		if (is_array($raw) === false) {
			throw new InvalidArgumentException('allowed must be an array of {provider, models[]} entries');
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				throw new InvalidArgumentException('Each allowed entry must be an object with provider/models');
			}

			$provider = (string)($entry['provider'] ?? '');
			if (in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false) {
				throw new InvalidArgumentException(
					"Unsupported provider '{$provider}' — must be one of: " . implode(', ', LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS)
				);
			}

			$models = $entry['models'] ?? [];
			if (is_array($models) === false) {
				throw new InvalidArgumentException('models must be an array of model id strings');
			}

			$out[] = [
				'provider' => $provider,
				'models' => array_values(array_map('strval', $models)),
			];
		}//end foreach

		return $out;
	}//end normaliseAllowed()

	/**
	 * Validate + normalise the `defaultModel` payload. Must itself be one of the
	 * already-normalised `allowed` combinations, or empty/null.
	 *
	 * @param mixed $raw The raw `defaultModel` payload value.
	 * @param array<int, array{provider: string, models: array<int, string>}> $allowed The normalised allowlist to validate against.
	 *
	 * @return array{provider: string, model: string}|null The normalised default, or null when unset.
	 *
	 * @throws InvalidArgumentException When `defaultModel` is not one of the allowed combinations.
	 */
	private function normaliseDefaultModel(mixed $raw, array $allowed): ?array {
		if ($raw === null || $raw === []) {
			return null;
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException('defaultModel must be an object with provider/model');
		}

		$provider = (string)($raw['provider'] ?? '');
		$model = (string)($raw['model'] ?? '');

		if ($provider === '' || $model === '') {
			return null;
		}

		if ($this->effectivePolicy->matchesAllowed(allowed: $allowed, provider: $provider, model: $model) === false) {
			throw new InvalidArgumentException(
				"defaultModel '{$provider}/{$model}' must itself be one of the allowed provider/model combinations"
			);
		}

		return [
			'provider' => $provider,
			'model' => $model,
		];

	}//end normaliseDefaultModel()

	/**
	 * Shape a ModelPolicy ObjectEntity into the API response payload.
	 *
	 * @param ObjectEntity $policy The policy object.
	 * @param string $source Where this policy came from ('organisation'|'instance').
	 *
	 * @return array<string, mixed> The response payload.
	 */
	private function shape(ObjectEntity $policy, string $source): array {
		return $this->effectivePolicy->shape(policy: $policy, source: $source);
	}//end shape()
}//end class
