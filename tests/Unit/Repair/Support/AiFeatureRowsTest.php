<?php

/**
 * Unit tests for AiFeatureRows, the AI-feature row lookup the seed repair steps
 * share before seeding a feature.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair\Support;

use OCA\Hermiq\Repair\Support\AiFeatureRows;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the query (register, schema, slug filter, system context) and the
 * entity-and-slug re-check of every returned row.
 *
 * @covers \OCA\Hermiq\Repair\Support\AiFeatureRows
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-5-1
 */
class AiFeatureRowsTest extends TestCase {

	/**
	 * An object service that answers the given rows to the expected query only.
	 *
	 * @param array<int, mixed> $rows The rows findAll() returns.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $rows): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$objectService->expects($this->once())->method('setSchema')->with('agentaifeature')->willReturnSelf();
		$objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['slug' => 'record-summary'], 'limit' => 200], false, false)
			->willReturn($rows);
		return $objectService;
	}//end objectService()

	/**
	 * A row with the slug.
	 *
	 * @param string $slug The row's slug.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $slug): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['slug' => $slug]);
		return $entity;
	}//end row()

	/**
	 * The first entity whose own slug matches is the answer; non-entities and
	 * another feature's row are skipped.
	 *
	 * @return void
	 */
	public function testFindReturnsTheFirstMatchingEntity(): void {
		$match = $this->row(slug: 'record-summary');
		$rows = [['slug' => 'record-summary'], $this->row(slug: 'other'), $match, $this->row(slug: 'record-summary')];

		$found = (new AiFeatureRows(objectService: $this->objectService(rows: $rows)))->find(slug: 'record-summary');

		$this->assertSame($match, $found);

	}//end testFindReturnsTheFirstMatchingEntity()

	/**
	 * No matching entity is null for find() and false for exists().
	 *
	 * @return void
	 */
	public function testNoMatchingEntityIsAbsent(): void {
		$rows = [['slug' => 'record-summary'], $this->row(slug: 'other')];

		$this->assertNull((new AiFeatureRows(objectService: $this->objectService(rows: $rows)))->find(slug: 'record-summary'));
		$this->assertFalse((new AiFeatureRows(objectService: $this->objectService(rows: $rows)))->exists(slug: 'record-summary'));
		$this->assertTrue((new AiFeatureRows(objectService: $this->objectService(rows: [$this->row(slug: 'record-summary')])))->exists(slug: 'record-summary'));

	}//end testNoMatchingEntityIsAbsent()
}//end class
