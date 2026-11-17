<?php

/**
 * Hermiq: the turn was stopped (agents-switch-off-and-stop).
 *
 * Thrown by the tool invoker when the model asks for another tool call after
 * the turn was stopped, because its agent was switched off or the turn used
 * every tool call its owner allows. The response handler ends the turn with
 * the stop reason as the answer.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use RuntimeException;

/**
 * The turn is over; no further tool call runs.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */
class TurnStoppedException extends RuntimeException {
}//end class
