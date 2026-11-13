<?php

/**
 * Hermiq DispatchOptions
 *
 * When a schedule is dispatched and which gates apply.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;

/**
 * When a schedule is dispatched and which gates apply.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DispatchOptions {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $now The current UTC moment.
	 * @param array<int,string> $engagedOrganisations Organisations whose kill-switch is engaged.
	 * @param bool $bypassApprovalGate When true, skip the requiresApproval gate
	 *                                 (an approved occurrence running via approve()).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly DateTimeImmutable $now,
		public readonly array $engagedOrganisations = [],
		public readonly bool $bypassApprovalGate = false,
	) {
	}//end __construct()
}//end class
