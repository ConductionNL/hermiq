<?php

/**
 * Hermiq ApprovalDecision
 *
 * What was decided on an approval, and why.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
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

namespace OCA\Hermiq\Service\Approval;

/**
 * What was decided on an approval, and why.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ApprovalDecision {

	/**
	 * Constructor.
	 *
	 * @param string $action The audit action (`approve`|`deny`).
	 * @param string $reason The raw reason (redacted here).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
