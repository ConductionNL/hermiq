<?php

/**
 * CustomToolDefinition unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CustomTool;

use InvalidArgumentException;
use OCA\Hermiq\Service\CustomTool\CustomToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an admin may send for a custom tool.
 */
final class CustomToolDefinitionTest extends TestCase {

	/**
	 * The permit check of the design, with a flow target.
	 *
	 * @return array<string, mixed>
	 */
	public static function vergunningcheck(): array {
		return [
			'slug' => 'vergunningcheck',
			'name' => 'Vergunningcheck',
			'description' => 'Checks whether an activity at an address needs a permit, and names the rule.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'adres' => ['type' => 'string', 'description' => 'The address'],
					'activiteit' => ['type' => 'string', 'enum' => ['terras', 'evenement', 'kap']],
				],
				'required' => ['adres'],
			],
			'changesData' => 'no',
			'target' => ['type' => 'flow', 'flowId' => 'flow-42'],
		];
	}//end vergunningcheck()

	/**
	 * A valid tool is stored as sent, enabled by default.
	 *
	 * @return void
	 */
	public function testAValidFlowToolIsNormalised(): void {
		$tool = CustomToolDefinition::normalise(payload: self::vergunningcheck());

		$this->assertSame('vergunningcheck', $tool['slug']);
		$this->assertSame(['type' => 'flow', 'flowId' => 'flow-42'], $tool['target']);
		$this->assertSame(['adres'], $tool['inputSchema']['required']);
		$this->assertSame(['terras', 'evenement', 'kap'], $tool['inputSchema']['properties']['activiteit']['enum']);
		$this->assertTrue($tool['enabled']);
	}//end testAValidFlowToolIsNormalised()

	/**
	 * An integriq target keeps its method, path and body template.
	 *
	 * @return void
	 */
	public function testAnIntegriqTarget(): void {
		$payload = self::vergunningcheck();
		$payload['target'] = ['type' => 'integriq', 'source' => 'bag-api', 'method' => 'post', 'path' => '/adressen', 'bodyTemplate' => '{"adres": "{{input.adres}}", "n": {{input.n}}}'];

		$tool = CustomToolDefinition::normalise(payload: $payload);

		$this->assertSame('POST', $tool['target']['method']);
		$this->assertSame('/adressen', $tool['target']['path']);
		$this->assertSame('{"adres": "{{input.adres}}", "n": {{input.n}}}', $tool['target']['bodyTemplate']);
	}//end testAnIntegriqTarget()

	/**
	 * Payloads that are refused, and the field the refusal names.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		$base = self::vergunningcheck();
		$with = static function (array $change) use ($base): array {
			return array_replace_recursive($base, $change);
		};

		$nested = $base;
		$nested['inputSchema']['properties']['adres'] = ['type' => 'object', 'properties' => ['straat' => ['type' => 'string']]];
		$array = $base;
		$array['inputSchema']['properties']['adres'] = ['type' => 'array'];
		$noFlow = $base;
		$noFlow['target'] = ['type' => 'flow'];

		return [
			'an upper-case slug' => [$with(['slug' => 'Vergunning']), 'slug'],
			'a short slug' => [$with(['slug' => 'ab']), 'slug'],
			'a nested object input' => [$nested, 'string, number, integer, boolean'],
			'an array input' => [$array, 'string, number, integer, boolean'],
			'an enum on a number' => [$with(['inputSchema' => ['properties' => ['adres' => ['type' => 'number', 'enum' => ['1']]]]]), 'enum'],
			'a required input that does not exist' => [$with(['inputSchema' => ['required' => ['postcode']]]), 'inputSchema.required'],
			'an unknown change' => [$with(['changesData' => 'maybe']), 'changesData'],
			'a flow target without a flow' => [$noFlow, 'target.flowId'],
			'an unknown target' => [$with(['target' => ['type' => 'webhook']]), 'target.type'],
			'an absolute URL as path' => [$with(['target' => ['type' => 'integriq', 'source' => 's', 'method' => 'GET', 'path' => 'https://evil.example/x']]), 'target.path'],
			'a method outside the list' => [$with(['target' => ['type' => 'integriq', 'source' => 's', 'method' => 'TRACE', 'path' => '/x']]), 'target.method'],
			'a body template that is not JSON' => [$with(['target' => ['type' => 'integriq', 'source' => 's', 'method' => 'POST', 'path' => '/x', 'bodyTemplate' => 'adres={{input.adres}}']]), 'target.bodyTemplate'],
			'no description' => [$with(['description' => '  ']), 'description'],
		];
	}//end refused()

	/**
	 * Each refusal names what is wrong.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string               $names   Text the message carries.
	 *
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testARefusalNamesTheField(array $payload, string $names): void {
		try {
			CustomToolDefinition::normalise(payload: $payload);
			$this->fail('accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString($names, $e->getMessage());
		}
	}//end testARefusalNamesTheField()
}//end class
