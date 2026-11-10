<?php

/**
 * Hermiq ApprovalRound.
 *
 * The pending approval a round of reviewer notifications is about, and the
 * reviewers to notify.
 *
 * @category ValueObject
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;


/**
 * One approval and its reviewers.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 */
final class ApprovalRound {

	/**
	 * Build the round.
	 *
	 * @param string $approvalUuid The pending approval's UUID.
	 * @param array<int, mixed> $reviewerUids The resolved reviewer user ids.
	 */
	public function __construct(
		public readonly string $approvalUuid,
		public readonly array $reviewerUids,
	) {
	}//end __construct()
}//end class
