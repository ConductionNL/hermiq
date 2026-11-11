<?php

/**
 * Hermiq: convert flattened tool descriptors into LLPhant FunctionInfo objects.
 *
 * LLPhant's formatters require `itemsOrProperties` to be either a STRING (the
 * element type of an array of scalars) or an array of Parameter OBJECTS (an
 * object's properties / an array of objects), and `requiredParameters` as
 * Parameter objects. This builds those shapes from the JSON-schema fragments a
 * tool descriptor carries, for ToolLoop.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;

/**
 * Builds the FunctionInfo objects of one tool surface.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
final class FunctionInfoBuilder {

	/**
	 * Convert every named descriptor into a FunctionInfo; entries that are not
	 * an array or carry no `name` are skipped.
	 *
	 * @param array<int|string, mixed> $functions Flattened LLPhant function descriptors.
	 * @param FacadeToolInvoker        $invoker   The build's shared invoker.
	 *
	 * @return array<int, FunctionInfo>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
	 */
	public function build(array $functions, FacadeToolInvoker $invoker): array {
		$functionInfoObjects = [];
		foreach ($this->namedDescriptors(functions: $functions) as $func) {
			$functionInfoObjects[] = $this->functionInfoFor(func: $func, invoker: $invoker);
		}

		return $functionInfoObjects;
	}//end build()

	/**
	 * The descriptors that are an array carrying a `name`, in their original order.
	 *
	 * @param array<int|string, mixed> $functions Flattened LLPhant function descriptors.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
	 */
	public function namedDescriptors(array $functions): array {
		$named = [];
		foreach ($functions as $func) {
			if (is_array($func) === true && isset($func['name']) === true) {
				$named[] = $func;
			}
		}

		return $named;
	}//end namedDescriptors()

	/**
	 * Convert one named descriptor into a FunctionInfo dispatching to the invoker.
	 *
	 * LLPhant's FunctionInfo expects requiredParameters as Parameter OBJECTS, not
	 * name strings (ToolFormatter reads $param->name), so the required names are
	 * mapped back to the Parameter objects built from the properties.
	 *
	 * @param array<string, mixed> $func    One descriptor carrying a `name`.
	 * @param FacadeToolInvoker    $invoker The build's shared invoker.
	 *
	 * @return FunctionInfo
	 */
	private function functionInfoFor(array $func, FacadeToolInvoker $invoker): FunctionInfo {
		$parameters = $this->propertiesToParameters(properties: $this->declaredProperties(func: $func));

		return new FunctionInfo(
			$func['name'],
			$invoker,
			$func['description'] ?? '',
			$parameters,
			$this->requiredParametersOf(parameters: $parameters, required: ($func['parameters']['required'] ?? []))
		);
	}//end functionInfoFor()

	/**
	 * The JSON-schema `properties` map a descriptor declares, or `[]` when it
	 * declares none.
	 *
	 * @param array<string, mixed> $func One descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private function declaredProperties(array $func): array {
		$properties = ($func['parameters']['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end declaredProperties()

	/**
	 * The Parameter objects whose name is in the required list, in parameter order.
	 *
	 * @param Parameter[]  $parameters The descriptor's parameters.
	 * @param array<mixed> $required   The descriptor's required parameter names.
	 *
	 * @return Parameter[]
	 */
	private function requiredParametersOf(array $parameters, array $required): array {
		$requiredParameters = [];
		foreach ($parameters as $parameterObject) {
			if (in_array($parameterObject->name, $required, true) === true) {
				$requiredParameters[] = $parameterObject;
			}
		}

		return $requiredParameters;
	}//end requiredParametersOf()

	/**
	 * Convert a single JSON-schema property into an LLPhant Parameter.
	 *
	 * LLPhant's formatters require `itemsOrProperties` to be either a STRING
	 * (the element type of an array of scalars) or an array of Parameter
	 * OBJECTS (an object's properties / an array of objects). Passing the raw
	 * JSON-schema `items`/`properties` (associative arrays of schema fragments)
	 * makes FunctionFormatter read `->name` on a string and throw. This builds
	 * the correct shape, recursing one level for nested objects. (Ported
	 * verbatim from ToolManagementHandler.)
	 *
	 * @param string $name The property name.
	 * @param array<string,mixed> $def The JSON-schema fragment for the property.
	 *
	 * @return Parameter
	 */
	private function schemaToParameter(string $name, array $def): Parameter {
		$type = $def['type'] ?? 'string';
		if ($type === 'object') {
			return $this->objectParameter(name: $name, def: $def);
		}

		$itemsOrProperties = null;
		if ($type === 'array') {
			$itemsOrProperties = $this->arrayItemsShape(items: ($def['items'] ?? []));
		}

		return new Parameter($name, $type, $def['description'] ?? '', $def['enum'] ?? [], $def['format'] ?? null, $itemsOrProperties);
	}//end schemaToParameter()

	/**
	 * Convert an `object` JSON-schema property into an LLPhant Parameter.
	 *
	 * A free-form object with no declared sub-properties (e.g. a schema's
	 * `properties` map) is represented as a JSON string the model fills: LLPhant
	 * serialises an empty object schema as "properties": [] (a JSON array), which
	 * Ollama rejects with "Value looks like object, but can't find closing '}'".
	 *
	 * @param string              $name The property name.
	 * @param array<string,mixed> $def  The JSON-schema fragment for the property.
	 *
	 * @return Parameter
	 */
	private function objectParameter(string $name, array $def): Parameter {
		$description = $def['description'] ?? '';
		$properties = $this->propertiesToParameters(properties: ($def['properties'] ?? []));
		if (count($properties) === 0) {
			$description = $this->freeFormObjectDescription(description: $description);
			return new Parameter($name, 'string', $description, $def['enum'] ?? [], $def['format'] ?? null, null);
		}

		return new Parameter($name, 'object', $description, $def['enum'] ?? [], $def['format'] ?? null, $properties);
	}//end objectParameter()

	/**
	 * The `itemsOrProperties` shape of an `array` property: the element type as
	 * a plain string for scalars, the element's Parameter objects for an array
	 * of objects, and `string` for an array of free-form objects (the same
	 * empty-object guard as objectParameter()).
	 *
	 * @param mixed $items The JSON-schema `items` fragment.
	 *
	 * @return Parameter[]|string
	 */
	private function arrayItemsShape(mixed $items): array|string {
		$itemType = 'string';
		if (is_array($items) === true && isset($items['type']) === true) {
			$itemType = (string)$items['type'];
		}

		if ($itemType !== 'object') {
			return $itemType;
		}

		$properties = $this->propertiesToParameters(properties: ($items['properties'] ?? []));
		if (count($properties) === 0) {
			return 'string';
		}

		return $properties;
	}//end arrayItemsShape()

	/**
	 * Build the description used when a free-form object (no declared
	 * sub-properties) is represented as a JSON string instead.
	 *
	 * @param string $description Original property description (may be empty).
	 *
	 * @return string Description guiding the model to pass a JSON object.
	 */
	private function freeFormObjectDescription(string $description): string {
		if ($description === '') {
			return 'A JSON object.';
		}

		return ($description . ' (pass as a JSON object).');
	}//end freeFormObjectDescription()

	/**
	 * Convert a JSON-schema `properties` map into an array of Parameter objects.
	 *
	 * @param array<string,mixed> $properties The properties map (name => schema).
	 *
	 * @return Parameter[]
	 */
	private function propertiesToParameters(array $properties): array {
		$out = [];
		foreach ($properties as $propName => $propDef) {
			$propDefArray = [];
			if (is_array($propDef) === true) {
				$propDefArray = $propDef;
			}

			$out[] = $this->schemaToParameter(
				name: (string)$propName,
				def: $propDefArray
			);
		}

		return $out;
	}//end propertiesToParameters()
}//end class
