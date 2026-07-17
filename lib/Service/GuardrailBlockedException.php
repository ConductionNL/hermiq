<?php

/**
 * Hermiq GuardrailBlockedException.
 *
 * Thrown by `Engine::processMessage()` (and, on the legacy `ChatService` branch,
 * by `ScheduleService::runAgentAsOwner()`) when the effective `GuardrailPolicy`'s
 * input filter refuses a turn — either a `piiAction: block` match or a
 * `promptInjectionAction: block` match. A distinct exception (rather than a plain
 * `Exception`) lets `ChatController::sendMessage()` recognise the case and surface
 * a stable `errorCode` the frontend can key a translated message off, instead of
 * matching on exception message text (agent-guardrails).
 *
 * On a scheduled/flow/webhook run this exception is caught by the SAME try/catch
 * every other agent-turn failure already flows through, so it inherits
 * run-reliability's retry/dead-letter/circuit-breaker handling with zero new
 * failure-handling code (design.md Risk 1).
 *
 * @category Service
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
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Exception;

/**
 * Signals that an agent turn's input was refused by the effective GuardrailPolicy.
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-input-is-filtered-before-every-llm-turn
 */
class GuardrailBlockedException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $reason The filter's short reason code. `prompt_injection`|`sensitive_content`
     *                       when the acting user's own message matched; the `_in_context`-suffixed
     *                       `prompt_injection_in_context`|`sensitive_content_in_context` when the
     *                       assembled context preamble matched instead — the suffix is what tells
     *                       an operator that a document, not the user, tripped the filter
     *                       (hermiq-guardrail-preamble-filter).
     */
    public function __construct(private readonly string $reason)
    {
        parent::__construct(
            message: "Message blocked by guardrail policy ({$reason})",
            code: 422
        );

    }//end __construct()

    /**
     * The filter's short reason code.
     *
     * @return string
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md
     */
    public function getReason(): string
    {
        return $this->reason;

    }//end getReason()
}//end class
