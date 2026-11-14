<?php

/**
 * Contract of GuardrailPolicyService
 *
 * The public surface of GuardrailPolicyService that other components call, so they depend
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
 * Contract of GuardrailPolicyService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuardrailPolicyServiceInterface {

	/**
	 * Resolve the effective policy for an organisation: its own GuardrailPolicy
	 * (if enabled), else the organisation-less instance default (if enabled),
	 * else a fully-open synthetic policy (design.md Decision 1 — fail-OPEN, not
	 * fail-closed).
	 *
	 * @param string $organisation The organisation identifier (may be '').
	 *
	 * @return array{source:string,inputFilters:array{piiAction:string,promptInjectionAction:string},outputFilters:array{piiAction:string},toolPolicy:list<array{toolId:string,classification:string}>,enabled:bool}
	 *                                                                                                                                                                                                               The effective, shaped policy.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function effectivePolicyFor(string $organisation): array;

	/**
	 * Apply the output filters to a tool call's arguments or result, as shown to the user.
	 *
	 * A non-string value is filtered as its JSON text, so key-based patterns such as
	 * `"password": "..."` match too. A value the filter does not change is returned
	 * as given; a redacted one comes back decoded when the redacted JSON still
	 * parses, and as the redacted text otherwise. A blocked value becomes the
	 * withheld placeholder, which is not JSON and so comes back as text.
	 *
	 * @param array<string,mixed> $policy The effective policy (from `effectivePolicyFor()`).
	 * @param mixed $value The arguments or result to show.
	 *
	 * @return mixed The value to show in its place.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function filterOutputValue(array $policy, mixed $value): mixed;

	/**
	 * Find a single GuardrailPolicy by UUID, system-wide (controller write-path
	 * authorization needs the policy's organisation before deciding whether the
	 * caller may administer it — mirrors `OrganisationModelPolicyService::findById()`).
	 *
	 * @param string $uuid The GuardrailPolicy object UUID.
	 *
	 * @return ObjectEntity|null The policy, or null when not found.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function findById(string $uuid): ?ObjectEntity;

	/**
	 * List every GuardrailPolicy object, system-wide (controller filters visibility).
	 *
	 * @return array<int, ObjectEntity> Every policy object.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function listAll(): array;

	/**
	 * Update an existing GuardrailPolicy by UUID (controller-gated).
	 *
	 * @param string $uuid The GuardrailPolicy object UUID.
	 * @param array<string, mixed> $payload The fields to update.
	 *
	 * @return array<string, mixed> The shaped, updated policy.
	 *
	 * @throws RuntimeException When the policy cannot be found.
	 * @throws InvalidArgumentException When the merged payload fails validation.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-guardrail-policy-administration-is-authorization-guarded
	 */
	public function update(string $uuid, array $payload): array;

	/**
	 * Create-or-update the GuardrailPolicy for an organisation (at most one per
	 * organisation, mirroring `OrganisationModelPolicyService::upsertForOrganisation()`).
	 * `$organisation === ''` upserts the organisation-less instance default.
	 *
	 * @param string $organisation The target organisation ('' for the instance default).
	 * @param array<string, mixed> $payload The requested `inputFilters`/`outputFilters`/`toolPolicy`/`enabled` fields.
	 *
	 * @return array<string, mixed> The shaped, persisted policy.
	 *
	 * @throws InvalidArgumentException When the payload fails validation.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-organisation-guardrail-policy-with-a-fully-open-fallback
	 */
	public function upsertForOrganisation(string $organisation, array $payload): array;

	/**
	 * The placeholder text substituted for a `block`-classified output.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
	 */
	public function withheldPlaceholder(): string;
}//end interface
