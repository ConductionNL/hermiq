<?php

/**
 * Contract of TalkAgentBinding
 *
 * The public surface of TalkAgentBinding that other components call, so they depend
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
 * Contract of TalkAgentBinding.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkAgentBindingInterface {

	/**
	 * The uuid of the opted-in agent bound to a room, or null.
	 *
	 * Returns null — meaning "take no turn" — when the room has no binding,
	 * when the bound agent no longer exists, or when that agent is not
	 * Talk-enabled. Null is the DEFAULT state and is not an error.
	 *
	 * @param string $roomToken The Talk room token.
	 *
	 * @return string|null The agent uuid, or null when the bridge is not active here.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
	 */
	public function agentForRoom(string $roomToken): ?string;

	/**
	 * The agent's display name — the string a user types to mention it.
	 *
	 * Returns an empty string when the agent cannot be loaded, so a caller
	 * building a mention-target list adds nothing rather than a blank entry
	 * that would match every message.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return string The agent's name, or '' when unresolvable.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function agentName(string $agentId): string;

	/**
	 * Bind a room to an agent.
	 *
	 * @param string $roomToken The Talk room token.
	 * @param string $agentId The agent uuid, or an empty string to unbind.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
	 */
	public function bindRoom(string $roomToken, string $agentId): void;
}//end interface
