<?php

/**
 * Hermiq first-organisation lookup for seed repair steps.
 *
 * A tenant-scoped seed row needs an OpenRegister organisation to pin itself to. On a
 * fresh install none exists yet, and the seed is deferred rather than fabricated into
 * a bogus tenant; a lookup that fails reads the same as none.
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

use OCA\OpenRegister\Db\OrganisationMapper;
use Throwable;

/**
 * Reads the first available OpenRegister organisation.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
final class FirstOrganisation {

	/**
	 * The lookup.
	 *
	 * @param OrganisationMapper $organisationMapper The organisation lookup.
	 */
	public function __construct(
		private readonly OrganisationMapper $organisationMapper,
	) {
	}//end __construct()

	/**
	 * The first available OpenRegister organisation's uuid, or '' when none exists
	 * yet or the lookup fails.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function uuid(): string {
		try {
			$organisations = $this->organisationMapper->findAll(limit: 1);
		} catch (Throwable $e) {
			return '';
		}

		if ($organisations === []) {
			return '';
		}

		return (string)($organisations[0]->getUuid() ?? '');
	}//end uuid()
}//end class
