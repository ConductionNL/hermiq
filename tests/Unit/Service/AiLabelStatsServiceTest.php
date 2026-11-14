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
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the label counts.
 */
class AiLabelStatsServiceTest extends TestCase {

	/**
	 * The service over the given tags and the ids the viewer can read.
	 *
	 * @param list<string> $authored The agent-authored file ids.
	 * @param list<string> $reviewed The reviewed file ids.
	 * @param list<int>    $readable The ids the viewer can read.
	 *
	 * @return AiLabelStatsService
	 */
	private function service(array $authored, array $reviewed, array $readable): AiLabelStatsService {
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

		return new AiLabelStatsService(marker: $marker, rootFolder: $root);
	}//end service()

	/**
	 * Four readable agent-authored files, one reviewed: 4 files, 25% reviewed.
	 *
	 * @return void
	 */
	public function testFourFilesOneReviewedIsAQuarter(): void {
		$stats = $this->service(authored: ['1', '2', '3', '4'], reviewed: ['2'], readable: [1, 2, 3, 4])->stats(uid: 'olga');

		$this->assertSame(['files' => 4, 'reviewed' => 1, 'reviewedShare' => 25, 'tagId' => '3', 'truncated' => false], $stats);
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

		$this->assertSame(['files' => 0, 'reviewed' => 0, 'reviewedShare' => 0, 'tagId' => '3', 'truncated' => false], $stats);
	}
}
