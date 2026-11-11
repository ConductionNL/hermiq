<?php

/**
 * Hermiq budget seed run.
 *
 * The services and output one SeedBudgets run works with, resolved once when the run
 * starts. Built by SeedBudgets and never handed out of it.
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;

/**
 * One budget seed run.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
final class BudgetSeedRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectService
	 */
	public ObjectService $objectService;

	/**
	 * The organisation lookup.
	 *
	 * @var OrganisationMapper
	 */
	public OrganisationMapper $organisationMapper;

	/**
	 * The budget write-path.
	 *
	 * @var BudgetService
	 */
	public BudgetService $budgetService;

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
