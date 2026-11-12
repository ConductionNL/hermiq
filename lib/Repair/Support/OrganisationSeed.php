<?php

/**
 * Hermiq organisation-scoped seed row.
 *
 * One row a seed repair step writes: the organisation it is pinned to ('' for the
 * instance default) and the fields it is written with.
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

/**
 * One organisation-scoped seed row.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
final class OrganisationSeed {

	/**
	 * The row.
	 *
	 * @param string $organisation The organisation the row is pinned to ('' = instance default).
	 * @param array<string, mixed> $payload The fields the row is written with.
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
