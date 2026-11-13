<?php

/**
 * Hermiq model-policy seed run.
 *
 * The services and output one SeedModelPolicies run works with, resolved once when the
 * run starts. Built by SeedModelPolicies and never handed out of it.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\Migration\IOutput;

/**
 * One model-policy seed run.
 *
 * @spec openspec/changes/archive/2026-07-12-tenant-model-policy/tasks.md#task-9-seed-data
 */
final class ModelPolicySeedRun {

	/**
	 * The policy write-path.
	 *
	 * @var OrganisationModelPolicyService
	 */
	public OrganisationModelPolicyService $policyService;

	/**
	 * The organisation lookup.
	 *
	 * @var OrganisationMapper
	 */
	public OrganisationMapper $organisationMapper;

	/**
	 * Start the run.
	 *
	 * @param IOutput $output Repair output channel.
	 */
	public function __construct(
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
