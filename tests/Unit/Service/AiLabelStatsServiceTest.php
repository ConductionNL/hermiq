<?php

/**
 * Hermiq AiLabelStatsService: the label counts of the AI oversight page.
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
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiLabelStatsService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\MarkedObjectCounter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the label counts.
 *
 * @covers \OCA\Hermiq\Service\AiLabelStatsService
 */
class AiLabelStatsServiceTest extends TestCase {

	/**
	 * The service over the given tags and the ids the viewer can read.
	 *
	 * @param list<string> $authored The agent-authored file ids.
	 * @param list<string> $reviewed The reviewed file ids.
	 * @param list<int>    $readable The ids the viewer can read.
	 * @param array{count: int, truncated: bool} $contacts      The marked contacts.
	 * @param array{count: int, truncated: bool} $calendarItems The marked calendar items.
	 *
	 * @return AiLabelStatsService
	 */
	private function service(
		array $authored,
		array $reviewed,
		array $readable,
		array $contacts = ['count' => 0, 'truncated' => false],
		array $calendarItems = ['count' => 0, 'truncated' => false],
	): AiLabelStatsService {
		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->method('taggedFiles')->willReturnCallback(
			static fn (string $name): array => ($name === AgentArtefactMarker::TAG_NAME
				? ['tagId' => '3', 'fileIds' => $authored]
				: ['tagId' => '9', 'fileIds' => $reviewed])
		);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(
			function (int $id) use ($readable): array {
				$file = $this->createMock(File::class);
				$file->method('isReadable')->willReturn(in_array($id, $readable, true));
				return [$file];
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->atMost(1))->method('getUserFolder')->with('olga')->willReturn($folder);

		$objects = $this->createMock(MarkedObjectCounter::class);
		$objects->expects($this->once())->method('contacts')->with(AgentArtefactMarker::OBJECT_PROPERTY)->willReturn($contacts);
		$objects->expects($this->once())->method('calendarItems')->with('olga', AgentArtefactMarker::OBJECT_PROPERTY)->willReturn($calendarItems);

		return new AiLabelStatsService(marker: $marker, rootFolder: $root, objects: $objects);
	}//end service()

	/**
	 * Four readable agent-authored files, one reviewed: 4 files, 25% reviewed.
	 *
	 * @return void
	 */
	public function testFourFilesOneReviewedIsAQuarter(): void {
		$stats = $this->service(authored: ['1', '2', '3', '4'], reviewed: ['2'], readable: [1, 2, 3, 4])->stats(uid: 'olga');

		$this->assertSame(
			[
				'files' => 4,
				'reviewed' => 1,
				'reviewedShare' => 25,
				'tagId' => '3',
				'truncated' => false,
				'contacts' => 0,
				'calendarItems' => 0,
				'objectsTruncated' => false,
			],
			$stats
		);
	}

	/**
	 * Only files the viewer can read count, and a reviewed file they cannot read is not counted.
	 *
	 * @return void
	 */
	public function testOnlyReadableFilesCount(): void {
		$stats = $this->service(authored: ['1', '2', '3'], reviewed: ['3', '7'], readable: [1, 2])->stats(uid: 'olga');

		$this->assertSame(2, $stats['files']);
		$this->assertSame(0, $stats['reviewed']);
		$this->assertSame(0, $stats['reviewedShare']);
	}

	/**
	 * No tagged files: zeros and no division by zero.
	 *
	 * @return void
	 */
	public function testNoTaggedFilesIsZero(): void {
		$stats = $this->service(authored: [], reviewed: [], readable: [])->stats(uid: 'olga');

		$this->assertSame(
			[
				'files' => 0,
				'reviewed' => 0,
				'reviewedShare' => 0,
				'tagId' => '3',
				'truncated' => false,
				'contacts' => 0,
				'calendarItems' => 0,
				'objectsTruncated' => false,
			],
			$stats
		);
	}

	/**
	 * The marked contacts and calendar items the viewer can see are counted next to the files.
	 *
	 * @return void
	 */
	public function testCountsMarkedContactsAndCalendarItems(): void {
		$stats = $this->service(
			authored: ['1'],
			reviewed: [],
			readable: [1],
			contacts: ['count' => 3, 'truncated' => false],
			calendarItems: ['count' => 5, 'truncated' => false]
		)->stats(uid: 'olga');

		$this->assertSame(3, $stats['contacts']);
		$this->assertSame(5, $stats['calendarItems']);
		$this->assertFalse($stats['objectsTruncated']);
		$this->assertSame(1, $stats['files']);
	}

	/**
	 * A capped contact or calendar read says the object counts are truncated.
	 *
	 * @return void
	 */
	public function testACappedObjectReadIsTruncated(): void {
		$stats = $this->service(
			authored: [],
			reviewed: [],
			readable: [],
			contacts: ['count' => 1, 'truncated' => false],
			calendarItems: ['count' => MarkedObjectCounter::MAX_OBJECTS, 'truncated' => true]
		)->stats(uid: 'olga');

		$this->assertTrue($stats['objectsTruncated']);
		$this->assertFalse($stats['truncated']);
	}
}
