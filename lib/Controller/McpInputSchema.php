<?php

/**
 * Hermiq MCP input schema.
 *
 * Turns a facade tool descriptor's JSON-schema fragment into the `inputSchema` an MCP
 * `tools/list` answer carries, with every `properties` map serialising as a JSON
 * object — never `[]`, the exact shape the `claude` CLI's strict validator rejects.
 * Built by McpRunController when it lists a run's tools.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use stdClass;

/**
 * The MCP-safe input schema of a tool descriptor.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */
final class McpInputSchema {

	/**
	 * Normalise a facade descriptor's JSON-schema into an MCP `inputSchema` whose
	 * `properties` is ALWAYS a JSON object — never `[]` (the exact shape the
	 * `claude` CLI's strict validator rejects). Recurses so nested object schemas
	 * with an empty `properties` are also objects.
	 *
	 * @param mixed $schema The descriptor's `parameters` fragment.
	 *
	 * @return array<string, mixed> The MCP-safe input schema.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
	 */
	public function normalise(mixed $schema): array {
		if (is_array($schema) === false) {
			$schema = [];
		}

		if (isset($schema['type']) === false) {
			$schema['type'] = 'object';
		}

		// An object schema MUST carry a `properties` object — an argument-less tool
		// that omits it entirely (or carries `[]`) is exactly what the CLI's strict
		// validator rejects. Force a present, object-typed `properties`.
		if ($schema['type'] === 'object' && array_key_exists('properties', $schema) === false) {
			$schema['properties'] = new stdClass();
		}

		return $this->normaliseSchemaProperties(schema: $schema);
	}//end normalise()

	/**
	 * Force every `properties` map in a schema fragment to serialise as an object,
	 * recursing into each property's own schema.
	 *
	 * @param array<string, mixed> $schema The schema fragment.
	 *
	 * @return array<string, mixed> The normalised fragment.
	 */
	private function normaliseSchemaProperties(array $schema): array {
		if (array_key_exists('properties', $schema) === true) {
			$schema['properties'] = $this->normaliseProperties(properties: $schema['properties']);
		}

		if (isset($schema['items']) === true && is_array($schema['items']) === true) {
			$schema['items'] = $this->normaliseSchemaProperties(schema: $schema['items']);
		}

		return $schema;
	}//end normaliseSchemaProperties()

	/**
	 * A schema's `properties`, each property schema normalised in turn. Empty (or
	 * non-array) properties MUST be an object, not `[]`.
	 *
	 * @param mixed $properties The schema's `properties` value.
	 *
	 * @return array<mixed>|stdClass The normalised properties, or an empty object.
	 */
	private function normaliseProperties(mixed $properties): array|stdClass {
		if (is_array($properties) === false || $properties === []) {
			return new stdClass();
		}

		$normalised = [];
		foreach ($properties as $propName => $propSchema) {
			$normalised[$propName] = $propSchema;
			if (is_array($propSchema) === true) {
				$normalised[$propName] = $this->normaliseSchemaProperties(schema: $propSchema);
			}
		}

		return $normalised;

	}//end normaliseProperties()
}//end class
