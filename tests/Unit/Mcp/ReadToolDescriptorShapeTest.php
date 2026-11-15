<?php

/**
 * Pins the exact shape of the read-only knowledge-graph and workspace tool
 * descriptors: every read tool ends with the same four hint keys, in the same
 * order, with the same values.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
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

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\GraphToolDescriptors;
use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use PHPUnit\Framework\TestCase;

/**
 * The read tools' descriptor keys and hint values.
 */
class ReadToolDescriptorShapeTest extends TestCase {

	/**
	 * The keys every read descriptor carries, in order.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = [
		'id',
		'subject',
		'action',
		'reach',
		'name',
		'description',
		'inputSchema',
		'readOnlyHint',
		'destructiveHint',
		'idempotentHint',
		'scope',
	];

	/**
	 * The read descriptors of both classes, by tool id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function readDescriptors(): array {
		$read = [];
		foreach (array_merge(GraphToolDescriptors::ALL, WorkspaceToolDescriptors::ALL) as $descriptor) {
			if ($descriptor['scope'] === 'read') {
				$read[$descriptor['id']] = $descriptor;
			}
		}

		return $read;
	}//end readDescriptors()

	/**
	 * Both graph tools and the five workspace read tools end with the read hints.
	 *
	 * @return void
	 */
	public function testReadDescriptorsEndWithTheReadOnlyHints(): void {
		$read = $this->readDescriptors();

		$this->assertSame(
			[
				GraphToolDescriptors::NEIGHBORS,
				GraphToolDescriptors::PATH,
				WorkspaceToolDescriptors::STATUS,
				WorkspaceToolDescriptors::DIFF,
				WorkspaceToolDescriptors::LOG,
				WorkspaceToolDescriptors::LIST_FILES,
				WorkspaceToolDescriptors::READ_FILE,
			],
			array_keys($read)
		);
		foreach ($read as $descriptor) {
			$this->assertSame(self::KEYS, array_keys($descriptor));
			$this->assertSame(
				['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
				array_slice($descriptor, -4, 4, true)
			);
		}

	}//end testReadDescriptorsEndWithTheReadOnlyHints()
}//end class
