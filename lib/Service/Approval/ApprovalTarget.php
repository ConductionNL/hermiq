<?php

/**
 * Hermiq ApprovalTarget
 *
 * Which approval is stored, and for whom.
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
 * Which approval is stored, and for whom.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ApprovalTarget {

	/**
	 * Constructor.
	 *
	 * @param string|null $uuid The target UUID (null to create).
	 * @param string $owner The schedule owner UID to impersonate.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?string $uuid,
		public readonly string $owner,
	) {
	}//end __construct()
}//end class
