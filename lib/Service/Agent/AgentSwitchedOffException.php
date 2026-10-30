<?php

/**
 * Hermiq: the agent is switched off (agents-switch-off-and-stop).
 *
 * Thrown where a turn would start for an agent whose owner or organisation
 * admin switched it off. The chat answers it with HTTP 409 and the sentence.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use RuntimeException;

/**
 * The agent is switched off, so no turn starts.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */
class AgentSwitchedOffException extends RuntimeException {

	/**
	 * A stable code the chat keys its message off.
	 */
	public const ERROR_CODE = 'agent_switched_off';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(message: 'This agent is switched off.', code: 409);

	}//end __construct()

}//end class
