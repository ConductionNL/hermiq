<?php

/**
 * Unit tests for NamedObjectRows, the exact-name row check shared by the
 * agent-template and example-skill seeds and the skill bundle installer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\NamedObjectRows;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The first exact-name entity rule.
 *
 * @covers \OCA\Hermiq\Service\NamedObjectRows
 */
class NamedObjectRowsTest extends TestCase {

	/**
	 * A row with the name.
	 *
	 * @param string $name The row's name.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $name): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => $name]);
		return $entity;
	}//end row()

	/**
	 * The first entity with the exact name wins; non-entity rows and other
	 * spellings are skipped; no match is null.
	 *
	 * @return void
	 */
	public function testFirstTakesTheFirstExactNameEntity(): void {
		$match = $this->row(name: 'tender-summary');
		$rows = new NamedObjectRows(
			objects: [['name' => 'tender-summary'], $this->row(name: 'Tender-summary'), $match, $this->row(name: 'tender-summary')]
		);

		$this->assertSame($match, $rows->first(name: 'tender-summary'));
		$this->assertNull($rows->first(name: 'woo-request-triage'));
		$this->assertNull((new NamedObjectRows(objects: []))->first(name: 'tender-summary'));

	}//end testFirstTakesTheFirstExactNameEntity()
}//end class
