<?php

/**
 * Contract of AiFeatureService
 *
 * The public surface of AiFeatureService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of AiFeatureService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiFeatureServiceInterface {

	/**
	 * Transition outcome: the feature is now enabled.
	 *
	 * @var string
	 */
	public const RESULT_ENABLED = 'enabled';

	/**
	 * Transition outcome: the feature is now disabled.
	 *
	 * @var string
	 */
	public const RESULT_DISABLED = 'disabled';

	/**
	 * Transition outcome: the guard refused the transition (e.g. no DPO ack).
	 *
	 * @var string
	 */
	public const RESULT_BLOCKED = 'blocked';

	/**
	 * Transition outcome: the feature was not found (or is cross-tenant).
	 *
	 * @var string
	 */
	public const RESULT_NOT_FOUND = 'notfound';

	/**
	 * Record the DPO acknowledgement for a feature and stamp the object.
	 *
	 * Writes the authoritative acknowledgement to `IAppConfig`
	 * (`dpo_ack.<tenantId>.<slug>` = `<uid>@<ISO-8601>`, the non-empty string the guard
	 * treats as acknowledged), then stamps the human-readable mirror (`dpoAckBy`,
	 * `dpoAckAt`) onto the object via the single ObjectService write-path (auto-audited).
	 * The tenant is derived from the object identically to the guard so `enable` unblocks.
	 *
	 * @param string $slug The feature slug to acknowledge.
	 * @param string $uid The acknowledging user id (the DPO / admin).
	 *
	 * @return ObjectEntity|null The stamped AiFeature, or null when no such feature.
	 *
	 * @spec openspec/changes/archive/2026-09-06-ai-feature-governance-register/tasks.md#task-3-3
	 */
	public function acknowledge(string $slug, string $uid): ?ObjectEntity;

	/**
	 * Drive the `disable` lifecycle transition (unguarded).
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return string One of the RESULT_* outcomes.
	 *
	 * @spec openspec/changes/archive/2026-09-06-ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function disable(string $id): string;

	/**
	 * Drive the `enable` lifecycle transition (guarded by the DPO-ack guard).
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return string One of the RESULT_* outcomes.
	 *
	 * @spec openspec/changes/archive/2026-09-06-ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function enable(string $id): string;

	/**
	 * Get an AiFeature by UUID (RBAC + tenancy scoped), or null.
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return ObjectEntity|null The AiFeature object, or null when absent / cross-tenant.
	 *
	 * @spec openspec/changes/archive/2026-09-06-ai-feature-governance-register/tasks.md#task-3-4
	 */
	public function getFeature(string $id): ?ObjectEntity;

	/**
	 * List the AiFeature objects visible in the caller's tenant (RBAC + tenancy scoped).
	 *
	 * @return array<int, ObjectEntity> The AiFeature objects.
	 *
	 * @spec openspec/changes/archive/2026-09-06-ai-feature-governance-register/tasks.md#task-3-2
	 */
	public function listFeatures(): array;

	/**
	 * Record a successful Algoritmeregister publication on the feature.
	 *
	 * Sets `algoritmeregisterStatus = gepubliceerd` and stores the external register
	 * reference through the single ObjectService write-path (auto-audited). Returns null
	 * when the feature is absent / cross-tenant.
	 *
	 * @param string $id The AiFeature UUID.
	 * @param string $reference The external register reference returned by the seam.
	 *
	 * @return ObjectEntity|null The stamped feature, or null when absent.
	 *
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function recordPublication(string $id, string $reference): ?ObjectEntity;

	/**
	 * Record a withdrawal (intrekken) from the Algoritmeregister on the feature.
	 *
	 * Sets `algoritmeregisterStatus = ingetrokken` through the single ObjectService
	 * write-path. Returns null when the feature is absent / cross-tenant.
	 *
	 * @param string $id The AiFeature UUID.
	 *
	 * @return ObjectEntity|null The stamped feature, or null when absent.
	 *
	 * @spec openspec/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function recordWithdrawal(string $id): ?ObjectEntity;
}//end interface
