<?php

/**
 * Hermiq tool-invocation export.
 *
 * One request for an agent's recorded tool invocations: the agent, the format and
 * the optional ISO 8601 window, as the oversight route receives them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * One tool-invocation export request.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */
final class ToolInvocationExport {

	/**
	 * The request.
	 *
	 * @param string $agentId Agent UUID.
	 * @param string $format `json` or `csv`.
	 * @param string $from Optional ISO 8601 lower bound (inclusive).
	 * @param string $to Optional ISO 8601 upper bound (inclusive).
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly string $format,
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()
}//end class
