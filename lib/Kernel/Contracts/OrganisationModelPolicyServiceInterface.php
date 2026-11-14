<?php

/**
 * Contract of OrganisationModelPolicyService
 *
 * The public surface of OrganisationModelPolicyService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of OrganisationModelPolicyService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationModelPolicyServiceInterface {

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
	public function findById(string $uuid): ?ObjectEntity;

	/**
	 * List every ModelPolicy object, system-wide (controller filters visibility).
	 *
	 * @return array<int, ObjectEntity> Every policy object.
	 *
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-model-policy-authorization
	 */
	public function listAll(): array;

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
	public function update(string $uuid, array $payload): array;

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
	public function upsertForOrganisation(string $organisation, array $payload): array;

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
	public function getForOrganisation(string $organisation): ?ObjectEntity;
}//end interface
