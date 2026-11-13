<?php

/**
 * Hermiq CustomToolDefinition
 *
 * Checks and normalises what an admin sends for a custom tool.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CustomTool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CustomTool;

use InvalidArgumentException;

/**
 * The shape of a custom tool: a slug, a name, a description the model reads,
 * an input schema of simple types, what it changes, and a target that is an
 * OpenRegister flow or an endpoint of a system connected through integriq.
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
final class CustomToolDefinition {

	/**
	 * The input types a custom tool may take. Nested objects and arrays are refused.
	 *
	 * @var list<string>
	 */
	public const INPUT_TYPES = ['string', 'number', 'integer', 'boolean'];

	/**
	 * What a tool may change, from nothing to irreversibly.
	 *
	 * @var list<string>
	 */
	public const CHANGES = ['no', 'yes', 'irreversible'];

	/**
	 * The HTTP methods an integriq target may use.
	 *
	 * @var list<string>
	 */
	public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

	/**
	 * Check a payload and return the stored fields.
	 *
	 * @param array<string, mixed> $payload What the admin sent.
	 *
	 * @return array<string, mixed> slug, name, description, inputSchema, changesData, target and enabled.
	 *
	 * @throws InvalidArgumentException Naming the field that is wrong.
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	public static function normalise(array $payload): array {
		$slug = self::text(payload: $payload, field: 'slug');
		if (preg_match('/^[a-z0-9_]{3,40}$/', $slug) !== 1) {
			throw new InvalidArgumentException('slug: use 3 to 40 lowercase letters, digits or underscores');
		}

		$name = self::text(payload: $payload, field: 'name');
		if ($name === '' || mb_strlen($name) > 80) {
			throw new InvalidArgumentException('name: give a name of at most 80 characters');
		}

		$description = self::text(payload: $payload, field: 'description');
		if ($description === '' || mb_strlen($description) > 2000) {
			throw new InvalidArgumentException('description: describe what the tool does, in at most 2000 characters');
		}

		$changes = (string)($payload['changesData'] ?? 'no');
		if (in_array($changes, self::CHANGES, true) === false) {
			throw new InvalidArgumentException('changesData: must be one of ' . implode(', ', self::CHANGES));
		}

		return [
			'slug' => $slug,
			'name' => $name,
			'description' => $description,
			'inputSchema' => self::inputSchema(raw: ($payload['inputSchema'] ?? ['type' => 'object', 'properties' => []])),
			'changesData' => $changes,
			'target' => self::target(raw: ($payload['target'] ?? null)),
			'enabled' => ($payload['enabled'] ?? true) !== false,
		];
	}//end normalise()

	/**
	 * The input schema: an object of simple, named properties.
	 *
	 * @param mixed $raw The input schema as sent.
	 *
	 * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
	 *
	 * @throws InvalidArgumentException When a property is not a simple type.
	 */
	private static function inputSchema(mixed $raw): array {
		if (is_array($raw) === false || ($raw['type'] ?? 'object') !== 'object') {
			throw new InvalidArgumentException('inputSchema: must be an object schema');
		}

		$properties = ($raw['properties'] ?? []);
		if (is_array($properties) === false) {
			throw new InvalidArgumentException('inputSchema.properties: must be an object');
		}

		$clean = [];
		foreach ($properties as $name => $property) {
			$clean[(string)$name] = self::input(name: (string)$name, property: $property);
		}

		$required = ($raw['required'] ?? []);
		if (is_array($required) === false || array_is_list($required) === false) {
			throw new InvalidArgumentException('inputSchema.required: must be a list of input names');
		}

		foreach ($required as $name) {
			if (is_string($name) === false || isset($clean[$name]) === false) {
				throw new InvalidArgumentException('inputSchema.required: ' . (string)json_encode($name) . ' is not one of the inputs');
			}
		}

		return ['type' => 'object', 'properties' => $clean, 'required' => array_values(array_unique($required))];
	}//end inputSchema()

	/**
	 * One input: a simple type, a string enum, and an optional description.
	 *
	 * @param string $name     The input name.
	 * @param mixed  $property Its schema as sent.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When it is not a simple type.
	 */
	private static function input(string $name, mixed $property): array {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
			throw new InvalidArgumentException('inputSchema.properties: ' . $name . ' is not a valid input name');
		}

		$type = '';
		if (is_array($property) === true) {
			$type = (string)($property['type'] ?? '');
		}

		if (in_array($type, self::INPUT_TYPES, true) === false) {
			throw new InvalidArgumentException('inputSchema.properties.' . $name . ': the type must be one of ' . implode(', ', self::INPUT_TYPES));
		}

		$clean = ['type' => $type];
		if (isset($property['description']) === true) {
			$clean['description'] = (string)$property['description'];
		}

		if (isset($property['enum']) === true) {
			$enum = $property['enum'];
			if ($type !== 'string' || is_array($enum) === false || $enum === [] || count(array_filter($enum, 'is_string')) !== count($enum)) {
				throw new InvalidArgumentException('inputSchema.properties.' . $name . ': an enum is a non-empty list of strings on a string input');
			}

			$clean['enum'] = array_values($enum);
		}

		return $clean;
	}//end input()

	/**
	 * The target: a flow, or an integriq source with a method and a path.
	 *
	 * @param mixed $raw The target as sent.
	 *
	 * @return array<string, string>
	 *
	 * @throws InvalidArgumentException When it is incomplete.
	 */
	private static function target(mixed $raw): array {
		if (is_array($raw) === false) {
			throw new InvalidArgumentException('target: choose a flow or an integriq source');
		}

		$type = (string)($raw['type'] ?? '');
		if ($type === 'flow') {
			$flowId = self::text(payload: $raw, field: 'flowId');
			if ($flowId === '') {
				throw new InvalidArgumentException('target.flowId: choose the flow the tool runs');
			}

			return ['type' => 'flow', 'flowId' => $flowId];
		}

		if ($type !== 'integriq') {
			throw new InvalidArgumentException('target.type: must be flow or integriq');
		}

		$source = self::text(payload: $raw, field: 'source');
		if ($source === '') {
			throw new InvalidArgumentException('target.source: choose the integriq source the tool calls');
		}

		$method = strtoupper(self::text(payload: $raw, field: 'method'));
		if (in_array($method, self::METHODS, true) === false) {
			throw new InvalidArgumentException('target.method: must be one of ' . implode(', ', self::METHODS));
		}

		$path = self::text(payload: $raw, field: 'path');
		if (str_starts_with($path, '/') === false || str_contains($path, '://') === true) {
			throw new InvalidArgumentException('target.path: give a path under the source, starting with /');
		}

		$target = ['type' => 'integriq', 'source' => $source, 'method' => $method, 'path' => $path];
		$body   = self::text(payload: $raw, field: 'bodyTemplate');
		if ($body !== '') {
			if (json_decode(preg_replace('/\{\{\s*input\.[A-Za-z0-9_]+\s*\}\}/', '0', $body) ?? '') === null) {
				throw new InvalidArgumentException('target.bodyTemplate: must be JSON, with {{input.<name>}} where an argument goes');
			}

			$target['bodyTemplate'] = $body;
		}

		return $target;
	}//end target()

	/**
	 * A trimmed text field, or an empty string.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string               $field   The field.
	 *
	 * @return string
	 */
	private static function text(array $payload, string $field): string {
		$value = ($payload[$field] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
