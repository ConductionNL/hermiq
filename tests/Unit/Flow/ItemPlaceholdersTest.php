<?php

/**
 * Characterisation of ItemPlaceholders: `{{path}}` lookups into a flow item,
 * nested paths, arrays rendered as JSON, and missing or malformed
 * placeholders.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Flow
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

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\ItemPlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder rendering against a flow item.
 *
 * @covers \OCA\Hermiq\Flow\ItemPlaceholders
 */
final class ItemPlaceholdersTest extends TestCase {

	/**
	 * Template to expected rendering against one item.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function templates(): array {
		return [
			'top-level value'         => ['Repo {{repo}}', 'Repo example-org/app'],
			'whitespace inside'       => ['{{  repo  }}', 'example-org/app'],
			'nested path'             => ['{{owner.uid}}@{{owner.org.name}}', 'alice@Gemeente'],
			'numeric and bool'        => ['{{count}}/{{draft}}/{{closed}}', '3/1/'],
			'list index'              => ['{{labels.1}}', 'bug'],
			'array as json'           => ['{{labels}}', '["ui","bug"]'],
			'object as json'          => ['{{owner.org}}', '{"name":"Gemeente"}'],
			'missing key'             => ['[{{nope}}]', '[]'],
			'path through a scalar'   => ['[{{repo.name}}]', '[]'],
			'null value'              => ['[{{empty}}]', '[]'],
			'at sign allowed'         => ['{{@type}}', 'Issue'],
			'not a placeholder'       => ['{{ two words }} {repo} {{}}', '{{ two words }} {repo} {{}}'],
			'repeated'                => ['{{repo}} {{repo}}', 'example-org/app example-org/app'],
		];
	}//end templates()

	/**
	 * Each template renders as recorded.
	 *
	 * @param string $template The template.
	 * @param string $expected The rendering.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('templates')]
	public function testRender(string $template, string $expected): void {
		$placeholders = new ItemPlaceholders(
			json: [
				'repo' => 'example-org/app',
				'owner' => ['uid' => 'alice', 'org' => ['name' => 'Gemeente']],
				'count' => 3,
				'draft' => true,
				'closed' => false,
				'labels' => ['ui', 'bug'],
				'empty' => null,
				'@type' => 'Issue',
			]
		);

		self::assertSame($expected, $placeholders->render(template: $template));
	}//end testRender()
}//end class
