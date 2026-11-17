<?php

/**
 * Contract of OrganisationControlService
 *
 * The public surface of OrganisationControlService that other components call, so they depend
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
 * Contract of OrganisationControlService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationControlServiceInterface {

	/**
	 * Get the TenantControl object for an organisation, if one exists.
	 *
	 * Matched by `ObjectEntity.organisation` (the tenant scope, an OpenRegister
	 * organisation UUID — the same value schedules carry) rather than a payload field.
	 * There is at most one control per organisation.
	 *
	 * @param string $organisation The organisation identifier (OpenRegister org UUID).
	 *
	 * @return ObjectEntity|null The control object, or null when none exists.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-5-1
	 */
	public function getForOrganisation(string $organisation): ?ObjectEntity;

	/**
	 * Engage or disengage the kill-switch for an organisation.
	 *
	 * Updates the existing TenantControl when present, otherwise creates one. The
	 * write goes through ObjectService so it is recorded in OpenRegister's hash-chained
	 * AuditTrail (ADR-004).
	 *
	 * @param string $organisation The organisation identifier.
	 * @param bool $engaged The new engaged state.
	 * @param string|null $reason Optional free-text reason for the change.
	 * @param string $actorUid The org-subadmin/instance-admin performing the toggle.
	 *
	 * @return ObjectEntity The persisted control object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-5-1
	 */
	public function toggle(string $organisation, bool $engaged, ?string $reason, string $actorUid): ObjectEntity;
}//end interface
