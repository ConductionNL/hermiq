<?php

/**
 * Hermiq FunctionInfoBuilder unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\FunctionInfoBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for turning tool descriptors into LLPhant FunctionInfo objects.
 *
 * @covers \OCA\Hermiq\Service\Engine\FunctionInfoBuilder
 */
class FunctionInfoBuilderTest extends TestCase {

	/**
	 * Build from descriptors with a mocked invoker.
	 *
	 * @param list<mixed> $functions The descriptors.
	 *
	 * @return array{0: list<FunctionInfo>, 1: FacadeToolInvoker} The objects and the invoker.
	 */
	private function build(array $functions): array {
		$invoker = $this->createMock(FacadeToolInvoker::class);
		return [(new FunctionInfoBuilder())->build($functions, $invoker), $invoker];
	}//end build()

	/**
	 * Only array descriptors with a name are kept, in order.
	 *
	 * @return void
	 */
	public function testOnlyNamedDescriptorsAreKept(): void {
		$a = ['name' => 'a'];
		$b = ['name' => 'b', 'description' => 'B'];

		$this->assertSame([$a, $b], (new FunctionInfoBuilder())->namedDescriptors(['x', ['description' => 'no name'], $a, null, $b]));
	}//end testOnlyNamedDescriptorsAreKept()

	/**
	 * Each function carries its name, the invoker, its description and its required parameters.
	 *
	 * @return void
	 */
	public function testFunctionsCarryNameInvokerDescriptionAndRequired(): void {
		[$infos, $invoker] = $this->build(
			[
				['name' => 'bare'],
				[
					'name' => 'search',
					'description' => 'Search objects',
					'parameters' => [
						'properties' => [
							'query' => ['type' => 'string', 'description' => 'Text'],
							'limit' => ['type' => 'integer', 'enum' => [10, 20], 'format' => 'int32'],
							'loose' => 'not an array',
						],
						'required' => ['query', 'missing'],
					],
				],
			]
		);

		$this->assertCount(2, $infos);
		$this->assertSame('bare', $infos[0]->name);
		$this->assertSame('', $infos[0]->description);
		$this->assertSame([], $infos[0]->parameters);
		$this->assertSame($invoker, $infos[1]->instance);
		$this->assertSame('Search objects', $infos[1]->description);
		$this->assertEquals(
			[
				new Parameter('query', 'string', 'Text', [], null, null),
				new Parameter('limit', 'integer', '', [10, 20], 'int32', null),
				new Parameter('loose', 'string', '', [], null, null),
			],
			$infos[1]->parameters
		);
		$this->assertSame([$infos[1]->parameters[0]], $infos[1]->requiredParameters);
	}//end testFunctionsCarryNameInvokerDescriptionAndRequired()

	/**
	 * Non-array properties declare no parameters.
	 *
	 * @return void
	 */
	public function testNonArrayPropertiesDeclareNothing(): void {
		[$infos] = $this->build([['name' => 'f', 'parameters' => ['properties' => 'nope']]]);

		$this->assertSame([], $infos[0]->parameters);
	}//end testNonArrayPropertiesDeclareNothing()

	/**
	 * An object with properties nests them; a free-form object becomes a described string.
	 *
	 * @return void
	 */
	public function testObjectsNestOrBecomeDescribedStrings(): void {
		[$infos] = $this->build(
			[
				[
					'name' => 'f',
					'parameters' => [
						'properties' => [
							'filter' => ['type' => 'object', 'description' => 'Filter', 'properties' => ['status' => ['type' => 'string']]],
							'data' => ['type' => 'object', 'description' => 'Payload'],
							'meta' => ['type' => 'object'],
						],
					],
				],
			]
		);

		$this->assertEquals(
			[
				new Parameter('filter', 'object', 'Filter', [], null, [new Parameter('status', 'string', '', [], null, null)]),
				new Parameter('data', 'string', 'Payload (pass as a JSON object).', [], null, null),
				new Parameter('meta', 'string', 'A JSON object.', [], null, null),
			],
			$infos[0]->parameters
		);
	}//end testObjectsNestOrBecomeDescribedStrings()

	/**
	 * Array items are their scalar type, nested object properties, or string for anything shapeless.
	 *
	 * @return void
	 */
	public function testArrayItemShapes(): void {
		[$infos] = $this->build(
			[
				[
					'name' => 'f',
					'parameters' => [
						'properties' => [
							'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
							'tags' => ['type' => 'array'],
							'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
							'blobs' => ['type' => 'array', 'items' => ['type' => 'object']],
							'odd' => ['type' => 'array', 'items' => 'integer'],
						],
					],
				],
			]
		);

		$shapes = array_map(static fn (Parameter $p): array|string|null => $p->itemsOrProperties, $infos[0]->parameters);

		$this->assertEquals(
			['integer', 'string', [new Parameter('id', 'integer', '', [], null, null)], 'string', 'string'],
			$shapes
		);
	}//end testArrayItemShapes()
}//end class
