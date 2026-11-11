<?php

/**
 * Hermiq agent-by-name lookup for the seed repair steps.
 *
 * The demo seeds (budgets, incidents) attach their rows to a seeded agent they
 * know only by its display name. They read every agent in system context (no
 * RBAC, no multitenancy) and take the first entity whose `name` matches
 * exactly; a seed whose agent is missing defers its row to a later run.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair\Support
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

namespace OCA\Hermiq\Repair\Support;

use OCA\Hermiq\Service\NamedObjectRows;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Finds an agent object by its exact display name, system-wide.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
final class AgentsByName {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Find an agent object by its exact display name (system-wide read).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string        $name          The agent name to match.
	 *
	 * @return ObjectEntity|null The matching agent, or null when none exists.
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public static function find(ObjectService $objectService, string $name): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AGENT_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		return NamedObjectRows::first(objects: $objects, name: $name);
	}//end find()
}//end class
