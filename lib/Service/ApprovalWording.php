<?php

/**
 * Hermiq ApprovalWording.
 *
 * How an approval request names the gated run: the display name (schedule
 * name, flow name, agent id, skill name or tool id) and the `approval_summary`
 * message parameters.
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
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;


/**
 * The name and message parameters of one approval request.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */
final class ApprovalWording {

	/**
	 * Build the wording.
	 *
	 * @param string $displayName The human label for the gated run.
	 * @param array<string, mixed> $messageParams The `approval_summary` message parameters.
	 */
	public function __construct(
		public readonly string $displayName,
		public readonly array $messageParams,
	) {
	}//end __construct()
}//end class
