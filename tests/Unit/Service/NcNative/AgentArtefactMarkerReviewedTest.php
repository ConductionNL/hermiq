<?php

/**
 * Hermiq AgentArtefactMarker: the reviewed mark and the tagged-file read.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
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

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the reviewed mark.
 */
class AgentArtefactMarkerReviewedTest extends TestCase {

	/**
	 * A system tag double with the given id.
	 *
	 * @param string $id The tag id.
	 *
	 * @return ISystemTag
	 */
	private function tag(string $id): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		return $tag;
	}//end tag()

	/**
	 * The reviewed tag is created visible and user-assignable on first use and
	 * assigned to the file; the agent-authored tag is not touched.
	 *
	 * @return void
	 */
	public function testMarkReviewedCreatesAndAssignsTheReviewedTag(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->expects($this->once())->method('getTag')
			->with(AgentArtefactMarker::REVIEWED_TAG_NAME, true, true)
			->willThrowException(new TagNotFoundException());
		$manager->expects($this->once())->method('createTag')
			->with(AgentArtefactMarker::REVIEWED_TAG_NAME, true, true)
			->willReturn($this->tag('9'));
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->once())->method('assignTags')->with('42', 'files', ['9']);
		$mapper->expects($this->never())->method('unassignTags');

		(new AgentArtefactMarker($manager, $mapper, new NullLogger()))->markReviewed(fileId: 42);
	}

	/**
	 * A failed assignment is a failure, never a silent success.
	 *
	 * @return void
	 */
	public function testAFailedReviewedMarkThrows(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willReturn($this->tag('9'));
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('assignTags')->willThrowException(new RuntimeException('db down'));

		$this->expectException(ArtefactMarkingFailedException::class);
		$this->expectExceptionMessage('The file could not be marked as reviewed.');
		(new AgentArtefactMarker($manager, $mapper, new NullLogger()))->markReviewed(fileId: 42);
	}

	/**
	 * The tagged files are read by tag id without creating a missing tag; a tag
	 * that was never created means no files.
	 *
	 * @return void
	 */
	public function testTaggedFilesReadsWithoutCreating(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willReturnCallback(
			function (string $name, bool $visible, bool $assignable): ISystemTag {
				if ($name === AgentArtefactMarker::TAG_NAME && $visible === true && $assignable === false) {
					return $this->tag('3');
				}

				throw new TagNotFoundException();
			}
		);
		$manager->expects($this->never())->method('createTag');
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->once())->method('getObjectIdsForTags')->with(['3'], 'files')->willReturn([11, '12']);
		$marker = new AgentArtefactMarker($manager, $mapper, new NullLogger());

		$this->assertSame(['tagId' => '3', 'fileIds' => ['11', '12']], $marker->taggedFiles(name: AgentArtefactMarker::TAG_NAME));
		$this->assertSame(['tagId' => null, 'fileIds' => []], $marker->taggedFiles(name: AgentArtefactMarker::REVIEWED_TAG_NAME));
	}
}
