<?php

/**
 * Tests for the ADR-088 AgentArtefactMarker.
 *
 * The behaviour worth pinning here is that a FAILED MARK THROWS. ADR-088 §5 makes
 * an unmarked artefact a failed write, and the only thing standing between that
 * rule and a silent success is this class raising rather than returning false.
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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for AgentArtefactMarker.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class AgentArtefactMarkerTest extends TestCase {

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
	 * Build the marker.
	 *
	 * @param ISystemTagManager $manager Tag manager double.
	 * @param ISystemTagObjectMapper $mapper Tag mapper double.
	 *
	 * @return AgentArtefactMarker
	 */
	private function marker(ISystemTagManager $manager, ISystemTagObjectMapper $mapper): AgentArtefactMarker {
		return new AgentArtefactMarker($manager, $mapper, $this->createMock(LoggerInterface::class));

	}//end marker()

	/**
	 * An existing tag is reused rather than recreated, and assigned to the file.
	 *
	 * @return void
	 */
	public function testExistingTagIsReusedAndAssigned(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->expects($this->once())
			->method('getTag')
			->with(AgentArtefactMarker::TAG_NAME, true, false)
			->willReturn($this->tag('42'));
		$manager->expects($this->never())->method('createTag');

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->once())->method('assignTags')->with('7', 'files', ['42']);

		$this->marker($manager, $mapper)->markFile(fileId: 7);

	}//end testExistingTagIsReusedAndAssigned()

	/**
	 * The tag is created on first use, and NOT user-assignable — a human hand-
	 * applying "Agent authored" would make the mark mean two different things.
	 *
	 * @return void
	 */
	public function testTagIsCreatedOnFirstUseAsNotUserAssignable(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willThrowException(new TagNotFoundException());
		$manager->expects($this->once())
			->method('createTag')
			->with(AgentArtefactMarker::TAG_NAME, true, false)
			->willReturn($this->tag('99'));

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects($this->once())->method('assignTags')->with('7', 'files', ['99']);

		$this->marker($manager, $mapper)->markFile(fileId: 7);

	}//end testTagIsCreatedOnFirstUseAsNotUserAssignable()

	/**
	 * A failure to assign THROWS. This is the single most important behaviour in
	 * the class: a boolean return would be honoured by the first caller and quietly
	 * dropped by the third, leaving unmarked artefacts reported as marked.
	 *
	 * @return void
	 */
	public function testAssignFailureThrows(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willReturn($this->tag('42'));

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('assignTags')->willThrowException(new RuntimeException('nope'));

		$this->expectException(ArtefactMarkingFailedException::class);

		$this->marker($manager, $mapper)->markFile(fileId: 7);

	}//end testAssignFailureThrows()

	/**
	 * A failure to create the tag also throws, not just a failure to assign it.
	 *
	 * @return void
	 */
	public function testTagCreationFailureThrows(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willThrowException(new TagNotFoundException());
		$manager->method('createTag')->willThrowException(new RuntimeException('forbidden'));

		$this->expectException(ArtefactMarkingFailedException::class);

		$this->marker($manager, $this->createMock(ISystemTagObjectMapper::class))->markFile(fileId: 7);

	}//end testTagCreationFailureThrows()

	/**
	 * The read-only probe reports whether a file carries the mark.
	 *
	 * @return void
	 */
	public function testIsFileMarkedReflectsTheMapper(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willReturn($this->tag('42'));

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->method('haveTag')->with(['7'], 'files', '42')->willReturn(true);

		$this->assertTrue($this->marker($manager, $mapper)->isFileMarked(fileId: 7));

	}//end testIsFileMarkedReflectsTheMapper()

	/**
	 * The probe answers false rather than throwing when tags are unavailable — it
	 * is diagnostic, and must never be the thing that breaks a run.
	 *
	 * @return void
	 */
	public function testIsFileMarkedIsFalseWhenTagsAreUnavailable(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willThrowException(new RuntimeException('down'));
		$manager->method('createTag')->willThrowException(new RuntimeException('down'));

		$this->assertFalse(
			$this->marker($manager, $this->createMock(ISystemTagObjectMapper::class))->isFileMarked(fileId: 7)
		);

	}//end testIsFileMarkedIsFalseWhenTagsAreUnavailable()

	/**
	 * The object property value carries the agent id when there is one, and a
	 * stable fallback when the run has no agent attributed.
	 *
	 * @return void
	 */
	public function testObjectPropertyValueEncodesTheAgent(): void {
		$marker = $this->marker(
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class)
		);

		$this->assertSame('hermiq:agent-7', $marker->objectPropertyValue(agentId: 'agent-7'));
		$this->assertSame('hermiq:agent-7', $marker->objectPropertyValue(agentId: '  agent-7  '));
		$this->assertSame('hermiq', $marker->objectPropertyValue(agentId: ''));
		$this->assertSame('hermiq', $marker->objectPropertyValue());

	}//end testObjectPropertyValueEncodesTheAgent()

	/**
	 * The tag name is a stable identifier, deliberately untranslated: translating
	 * it would fork the tag per UI language, so a Dutch session and an English
	 * session would tag the same artefact differently and neither filter find both.
	 *
	 * @return void
	 */
	public function testTagNameIsAStableIdentifier(): void {
		$this->assertSame('Agent authored', AgentArtefactMarker::TAG_NAME);
		$this->assertSame('X-HERMIQ-AGENT-AUTHORED', AgentArtefactMarker::OBJECT_PROPERTY);

	}//end testTagNameIsAStableIdentifier()

}//end class
