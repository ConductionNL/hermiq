<?php

/**
 * Hermiq TenantModelPolicyService.
 *
 * Reads and writes the per-organisation `ModelPolicy` OpenRegister object: an
 * allowlist of {provider, models[]} pairs (drawn from the four supported chat
 * drivers) plus an optional suggested default model. Mirrors
 * `TenantControlService`'s exact per-organisation-object pattern (`_rbac: false,
 * _multitenancy: false`, matched by `ObjectEntity.organisation`) because a policy
 * read must work for a system-wide schedule tick or flow-triggered run — which run
 * before any per-user RBAC context exists — exactly as the kill-switch read does.
 *
 * Resolution order (design.md "Decisions"): (1) the organisation's own ModelPolicy
 * if one exists; (2) else the organisation-less instance-wide default ModelPolicy
 * (`ObjectEntity.organisation === ''`) if one exists; (3) else — no policy anywhere —
 * a synthetic, fail-CLOSED policy restricting to whatever `hermiq.llm.chatProvider`
 * is currently configured, never fully open.
 *
 * This is a recognised ADR-031 imperative exception: a side-effecting governance
 * service, not a derived value or declarative lifecycle (same role as
 * TenantControlService).
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
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Reads/writes ModelPolicy objects and resolves the effective policy for an
 * organisation (own policy → instance default → fail-closed fallback).
 *
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
 */
class TenantModelPolicyService
{

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
     * @param ObjectService      $objectService   OpenRegister object read/write (single write-path).
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
     * an error case, unlike `TenantControlService::getForOrganisation()` which
     * treats `''` as "no organisation given").
     *
     * @param string $organisation The organisation identifier, or '' for the instance default.
     *
     * @return ObjectEntity|null The policy object, or null when none exists at this scope.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
     */
    public function getForOrganisation(string $organisation): ?ObjectEntity
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(config: [], _rbac: false, _multitenancy: false);

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getOrganisation() ?? '') === $organisation) {
                return $object;
            }
        }

        return null;

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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function findById(string $uuid): ?ObjectEntity
    {
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function listAll(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEMA_SLUG)
            ->findAll(config: [], _rbac: false, _multitenancy: false);

        return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));

    }//end listAll()

    /**
     * Resolve the effective policy for an organisation: its own ModelPolicy, else
     * the organisation-less instance default, else a fail-closed synthetic policy
     * restricting to the currently-configured `hermiq.llm.chatProvider` only.
     *
     * @param string $organisation The organisation identifier (may be '').
     *
     * @return array{source:string,allowed:list<array{provider:string,models:list<string>}>,defaultModel:array{provider:string,model:string}|null}
     *         The effective, shaped policy.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
     */
    public function effectivePolicyFor(string $organisation): array
    {
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
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-instance-admin-fallback-policy
     */
    private function fallbackPolicy(): array
    {
        $chatProvider = $this->settingsHandler->getLLMSettingsOnly()['chatProvider'] ?? null;

        $allowed = [];
        if (is_string($chatProvider) === true && $chatProvider !== '') {
            $allowed[] = ['provider' => $chatProvider, 'models' => []];
        }

        return [
            'source'       => 'fallback',
            'allowed'      => $allowed,
            'defaultModel' => null,
        ];

    }//end fallbackPolicy()

    /**
     * Whether a resolved (provider, model) pair is within an organisation's
     * effective policy. An empty `models[]` for an allowed provider means "any
     * model for that provider is permitted".
     *
     * @param string $organisation The organisation identifier (may be '').
     * @param string $provider     The resolved provider.
     * @param string $model        The resolved model id.
     *
     * @return bool True when the pair is allowed.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function isAllowed(string $organisation, string $provider, string $model): bool
    {
        $policy = $this->effectivePolicyFor(organisation: $organisation);

        return $this->matchesAllowed(allowed: $policy['allowed'], provider: $provider, model: $model);

    }//end isAllowed()

    /**
     * Create-or-update the ModelPolicy for an organisation (at most one per
     * organisation, mirroring `TenantControlService::toggle()`'s upsert
     * semantics). `$organisation === ''` upserts the organisation-less instance
     * default.
     *
     * @param string               $organisation The target organisation ('' for the instance default).
     * @param array<string, mixed> $payload      The requested `allowed`/`defaultModel` fields.
     *
     * @return array<string, mixed> The shaped, persisted policy.
     *
     * @throws InvalidArgumentException When `allowed`/`defaultModel` fail validation.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-per-organisation-model-policy-object
     */
    public function upsertForOrganisation(string $organisation, array $payload): array
    {
        $allowed      = $this->normaliseAllowed(raw: ($payload['allowed'] ?? []));
        $defaultModel = $this->normaliseDefaultModel(raw: ($payload['defaultModel'] ?? null), allowed: $allowed);

        $existing = $this->getForOrganisation(organisation: $organisation);

        $data = [];
        $uuid = null;
        if ($existing !== null) {
            $data = $existing->getObject();
            $uuid = (string) $existing->getUuid();
        }

        $data['allowed']      = $allowed;
        $data['defaultModel'] = $defaultModel;

        // Pin the policy to the TARGET organisation, not the actor's active
        // organisation (mirrors TenantControlService::toggle()'s @self.organisation
        // trick) — without this an instance admin upserting a different tenant's
        // policy, or the org-less instance default, would be stamped into their
        // own active org instead.
        $self = (array) ($data['@self'] ?? []);
        $self['organisation'] = $organisation;
        $data['@self']        = $self;

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
     * @param string               $uuid    The ModelPolicy object UUID.
     * @param array<string, mixed> $payload The fields to update.
     *
     * @return array<string, mixed> The shaped, updated policy.
     *
     * @throws RuntimeException        When the policy cannot be found.
     * @throws InvalidArgumentException When the merged payload fails validation.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
     */
    public function update(string $uuid, array $payload): array
    {
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

        $allowed         = $this->normaliseAllowed(raw: $allowedRaw);
        $data['allowed'] = $allowed;
        $data['defaultModel'] = $this->normaliseDefaultModel(raw: $defaultModelRaw, allowed: $allowed);

        $organisation = (string) ($existing->getOrganisation() ?? '');

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
    private function normaliseAllowed(mixed $raw): array
    {
        if (is_array($raw) === false) {
            throw new InvalidArgumentException('allowed must be an array of {provider, models[]} entries');
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) === false) {
                throw new InvalidArgumentException('Each allowed entry must be an object with provider/models');
            }

            $provider = (string) ($entry['provider'] ?? '');
            if (in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false) {
                throw new InvalidArgumentException(
                    "Unsupported provider '{$provider}' — must be one of: ".implode(', ', LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS)
                );
            }

            $models = $entry['models'] ?? [];
            if (is_array($models) === false) {
                throw new InvalidArgumentException('models must be an array of model id strings');
            }

            $out[] = [
                'provider' => $provider,
                'models'   => array_values(array_map('strval', $models)),
            ];
        }//end foreach

        return $out;

    }//end normaliseAllowed()

    /**
     * Validate + normalise the `defaultModel` payload. Must itself be one of the
     * already-normalised `allowed` combinations, or empty/null.
     *
     * @param mixed                                                           $raw     The raw `defaultModel` payload value.
     * @param array<int, array{provider: string, models: array<int, string>}> $allowed The normalised allowlist to validate against.
     *
     * @return array{provider: string, model: string}|null The normalised default, or null when unset.
     *
     * @throws InvalidArgumentException When `defaultModel` is not one of the allowed combinations.
     */
    private function normaliseDefaultModel(mixed $raw, array $allowed): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        if (is_array($raw) === false) {
            throw new InvalidArgumentException('defaultModel must be an object with provider/model');
        }

        $provider = (string) ($raw['provider'] ?? '');
        $model    = (string) ($raw['model'] ?? '');

        if ($provider === '' || $model === '') {
            return null;
        }

        if ($this->matchesAllowed(allowed: $allowed, provider: $provider, model: $model) === false) {
            throw new InvalidArgumentException(
                "defaultModel '{$provider}/{$model}' must itself be one of the allowed provider/model combinations"
            );
        }

        return [
            'provider' => $provider,
            'model'    => $model,
        ];

    }//end normaliseDefaultModel()

    /**
     * Whether (provider, model) matches an `allowed` list — an empty `models[]`
     * for a matched provider means "any model".
     *
     * @param array<int, array{provider: string, models: array<int, string>}> $allowed  The allowlist.
     * @param string                                                          $provider The provider to check.
     * @param string                                                          $model    The model id to check.
     *
     * @return bool True when allowed.
     */
    private function matchesAllowed(array $allowed, string $provider, string $model): bool
    {
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
     * @param string       $source Where this policy came from ('organisation'|'instance').
     *
     * @return array<string, mixed> The response payload.
     */
    private function shape(ObjectEntity $policy, string $source): array
    {
        $data = $policy->getObject();

        return [
            'id'           => (string) ($policy->getUuid() ?? ''),
            'organisation' => (string) ($policy->getOrganisation() ?? ''),
            'source'       => $source,
            'allowed'      => $data['allowed'] ?? [],
            'defaultModel' => $data['defaultModel'] ?? null,
        ];

    }//end shape()
}//end class
