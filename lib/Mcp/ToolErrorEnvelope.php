<?php

/**
 * Hermiq ToolErrorEnvelope.
 *
 * The structured `['error' => ['code', 'message']]` result the hermiq MCP tools
 * return instead of throwing, shared by HermiqToolProvider and AgentMemoryTools.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

/**
 * Builds the error envelopes of the hermiq MCP tools.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */
final class ToolErrorEnvelope {

	/**
	 * Build an error envelope.
	 *
	 * @param string $code The machine error code.
	 * @param string $message The human-readable message.
	 *
	 * @return array<string, mixed> The error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
	 */
	public function build(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end build()

	/**
	 * The error envelope for a tool called with no agent context
	 * (agent-less chat — `FacadeToolInvoker::withAgentId()` never injects an
	 * `agentId` when the run has none).
	 *
	 * @return array<string, mixed> The error envelope.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
	 */
	public function noAgentContext(): array {
		return $this->build(
			code: 'no_agent_context',
			message: 'This tool requires an agent context and cannot be called outside an agent run.'
		);
	}//end noAgentContext()
}//end class
