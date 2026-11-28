<?php

/**
 * Contract of TalkTurnDispatcher
 *
 * The public surface of TalkTurnDispatcher that other components call, so they depend
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

use OCA\Hermiq\Service\Talk\TalkTurn;

/**
 * Contract of TalkTurnDispatcher.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkTurnDispatcherInterface {

	/**
	 * Hand the turn off for execution outside this request.
	 *
	 * @param TalkTurn $turn The turn: conversation, speaker, message, room, and the
	 *                       optional turn id, agent, addressing message and chain.
	 *
	 * @return string The path taken: `triggered`, `instant` or `queued`.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function dispatch(TalkTurn $turn): string;
}//end interface
