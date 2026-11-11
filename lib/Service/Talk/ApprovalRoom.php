<?php

/**
 * Hermiq ApprovalRoom.
 *
 * Where an approval request is posted: the agent whose run is being judged and
 * the Talk room bound to it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * The agent and room of one approval request.
 *
 * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
 */
final class ApprovalRoom {

	/**
	 * Build the pair.
	 *
	 * @param string $agentId   The agent whose run is being judged.
	 * @param string $roomToken The Talk room bound to that agent.
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly string $roomToken,
	) {
	}//end __construct()
}//end class
