<?php

/**
 * Tests for the NcNativeWriteService facade.
 *
 * The facade exists so `HermiqToolProvider` gains one dependency instead of three.
 * What is worth asserting is that each entry point reaches the RIGHT subsystem
 * with the caller's arguments intact — a facade that quietly routes a note write
 * to the contacts service would still return a plausible-looking envelope.
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

use OCA\Hermiq\Service\NcNative\CalendarWriteService;
use OCA\Hermiq\Service\NcNative\ContactWriteService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NcNativeWriteService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class NcNativeWriteServiceTest extends TestCase {

	/**
	 * The calendar service double.
	 *
	 * @var CalendarWriteService
	 */
	private CalendarWriteService $calendar;

	/**
	 * The contacts service double.
	 *
	 * @var ContactWriteService
	 */
	private ContactWriteService $contacts;

	/**
	 * The notes service double.
	 *
	 * @var NotesWriteService
	 */
	private NotesWriteService $notes;

	/**
	 * The facade under test.
	 *
	 * @var NcNativeWriteService
	 */
	private NcNativeWriteService $facade;

	/**
	 * Build the facade over three doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calendar = $this->createMock(CalendarWriteService::class);
		$this->contacts = $this->createMock(ContactWriteService::class);
		$this->notes = $this->createMock(NotesWriteService::class);
		$this->facade = new NcNativeWriteService($this->calendar, $this->contacts, $this->notes);

	}//end setUp()

	/**
	 * Event creation reaches the calendar service with uid, arguments and agent id.
	 *
	 * @return void
	 */
	public function testCreateCalendarEventDelegatesToTheCalendarService(): void {
		$this->calendar->expects($this->once())
			->method('create')
			->with('alice', ['summary' => 'Kickoff'], 'agent-7')
			->willReturn(['created' => true]);
		$this->contacts->expects($this->never())->method('upsert');
		$this->notes->expects($this->never())->method('createNote');

		$this->assertSame(
			['created' => true],
			$this->facade->createCalendarEvent('alice', ['summary' => 'Kickoff'], 'agent-7')
		);

	}//end testCreateCalendarEventDelegatesToTheCalendarService()

	/**
	 * Contact upsert reaches the contacts service.
	 *
	 * @return void
	 */
	public function testUpsertContactDelegatesToTheContactsService(): void {
		$this->contacts->expects($this->once())
			->method('upsert')
			->with('alice', ['name' => 'Jansen'], 'agent-7')
			->willReturn(['saved' => true]);
		$this->calendar->expects($this->never())->method('create');

		$this->assertSame(
			['saved' => true],
			$this->facade->upsertContact('alice', ['name' => 'Jansen'], 'agent-7')
		);

	}//end testUpsertContactDelegatesToTheContactsService()

	/**
	 * Listing notes reaches the notes service.
	 *
	 * @return void
	 */
	public function testListNotesDelegatesToTheNotesService(): void {
		$this->notes->expects($this->once())
			->method('listNotes')
			->with('alice')
			->willReturn(['notes' => []]);

		$this->assertSame(['notes' => []], $this->facade->listNotes('alice'));

	}//end testListNotesDelegatesToTheNotesService()

	/**
	 * Note creation reaches the notes service — and carries NO agent id, because a
	 * system tag cannot hold per-agent data.
	 *
	 * @return void
	 */
	public function testCreateNoteDelegatesWithoutAnAgentId(): void {
		$this->notes->expects($this->once())
			->method('createNote')
			->with('alice', ['title' => 'Groceries'])
			->willReturn(['created' => true]);

		$this->assertSame(
			['created' => true],
			$this->facade->createNote('alice', ['title' => 'Groceries'])
		);

	}//end testCreateNoteDelegatesWithoutAnAgentId()

	/**
	 * Note update reaches the notes service.
	 *
	 * @return void
	 */
	public function testUpdateNoteDelegatesToTheNotesService(): void {
		$this->notes->expects($this->once())
			->method('updateNote')
			->with('alice', ['id' => 4, 'content' => 'x'])
			->willReturn(['updated' => true]);

		$this->assertSame(
			['updated' => true],
			$this->facade->updateNote('alice', ['id' => 4, 'content' => 'x'])
		);

	}//end testUpdateNoteDelegatesToTheNotesService()

	/**
	 * The facade adds no verbs of its own — in particular no delete.
	 *
	 * @return void
	 */
	public function testFacadeExposesNoDeleteVerb(): void {
		foreach (get_class_methods(NcNativeWriteService::class) as $method) {
			$this->assertStringNotContainsStringIgnoringCase('delete', $method);
			$this->assertStringNotContainsStringIgnoringCase('remove', $method);
		}

	}//end testFacadeExposesNoDeleteVerb()

}//end class
