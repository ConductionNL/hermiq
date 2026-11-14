<?php

/**
 * Hermiq ConfirmationActor
 *
 * The person and agent a confirmation is applied for.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ContextAgent
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

namespace OCA\Hermiq\Service\ContextAgent;

/**
 * The person and agent a confirmation is applied for.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ConfirmationActor {

	/**
	 * Constructor.
	 *
	 * @param string $userId The confirming user (must be the approval's reviewer).
	 * @param string $agentId The agent the approval must belong to.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $agentId,
	) {
	}//end __construct()
}//end class
