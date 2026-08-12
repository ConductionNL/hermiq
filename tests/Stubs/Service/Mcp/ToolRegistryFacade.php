<?php

/**
 * Test stub for OpenRegister ToolRegistryFacade.
 *
 * Stands in for OCA\OpenRegister\Service\Mcp\ToolRegistryFacade when OpenRegister
 * is not installed (standalone CI: php:8.3-cli + OCP stubs). Mirrors the facade's
 * public contract exactly (or-tool-registry-facade, ai-mcp REQ-006):
 * listTools(toolWhitelist), describeTools() and invokeTool(toolId, arguments).
 * The real class ships with OpenRegister at runtime; Hermiq consumes ONLY this surface.
 *
 * ⚠️ Keep this in step with OpenRegister's real facade. `describeTools()` was added
 * there and consumed by AgentsController::tools(), but not mirrored here, so every
 * matrix cell in which OpenRegister failed to enable errored with
 * `Call to undefined method MockObject_ToolRegistryFacade::describeTools()`
 * instead of exercising the controller (run 31490144919).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

/**
 * Minimal ToolRegistryFacade stub for standalone unit runs.
 */
class ToolRegistryFacade {

	/**
	 * List every callable function descriptor known to the tool registry.
	 *
	 * @param array<int,string> $toolWhitelist Optional whitelist of registry ids
	 *                                         ({appId}.{toolName}). Empty = all.
	 *
	 * @return array<int,array<string,mixed>> Flattened LLPhant function descriptors.
	 */
	public function listTools(array $toolWhitelist = []): array {
		return [];
	}//end listTools()

	/**
	 * Describe every callable function for a human-facing picker.
	 *
	 * @return array<int,array{name: string, description: string, app: string, tool: string, group: string, right: string}>
	 *                                                                                                                      One entry per callable function.
	 */
	public function describeTools(): array {
		return [];
	}//end describeTools()

	/**
	 * Invoke a tool function by its descriptor name or dotted mcpId.
	 *
	 * @param string $toolId Function name or dotted mcpId.
	 * @param array<string,mixed> $arguments Decoded arguments object.
	 *
	 * @return array{result: array<string,mixed>, isError: bool} Result envelope.
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		return [
			'result' => [],
			'isError' => false,
		];
	}//end invokeTool()
}//end class
