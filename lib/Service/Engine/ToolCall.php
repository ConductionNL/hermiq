<?php

/**
 * Hermiq ToolCall.
 *
 * One tool call the model asked for: the dotted tool id and its decoded arguments.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * One tool call.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */
final class ToolCall {

	/**
	 * Build the call.
	 *
	 * @param string               $toolId    The dotted tool id.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly array $arguments,
	) {
	}//end __construct()
}//end class
