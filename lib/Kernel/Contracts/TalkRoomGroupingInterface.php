<?php

/**
 * Contract of TalkRoomGrouping
 *
 * The public surface of TalkRoomGrouping that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of TalkRoomGrouping.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkRoomGroupingInterface {

	/**
	 * File a bound agent room under the Hermiq tag for every participant.
	 *
	 * Best-effort throughout: grouping is cosmetic and MUST never fail a bind,
	 * a turn or a delivery.
	 *
	 * @param string $roomToken The Talk room that was bound.
	 *
	 * @return int The number of participants the room was filed for.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
	 */
	public function groupRoom(string $roomToken): int;
}//end interface
