<?php

/**
 * Unit tests for TaskReader: reading VTODO rows out of task lists, filtering
 * by status, due date and list, and sorting by due date.
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
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-list-the-acting-users-tasks-req-nctask-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use DateTimeImmutable;
use OCA\Hermiq\Service\NcNative\TaskReader;
use OCP\Calendar\ICalendar;
use PHPUnit\Framework\TestCase;

/**
 * The task rows an agent reads from the user's task lists.
 *
 * @covers \OCA\Hermiq\Service\NcNative\TaskReader
 */
class TaskReaderTest extends TestCase {

	/**
	 * A search row as CalDavBackend::search() shapes a VTODO.
	 *
	 * @param string               $uid        The task uid.
	 * @param array<string, mixed> $properties Property name to value.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $uid, array $properties): array {
		$object = [];
		foreach ($properties as $name => $value) {
			$object[$name] = [$value, []];
		}

		return ['uid' => $uid, 'objects' => [$object]];

	}//end row()

	/**
	 * A task list answering the given rows, asked only for VTODOs.
	 *
	 * @param string $uri  The list uri.
	 * @param string $name The display name.
	 * @param array  $rows The rows it answers.
	 *
	 * @return ICalendar
	 */
	private function taskList(string $uri, string $name, array $rows): ICalendar {
		$list = $this->createMock(ICalendar::class);
		$list->method('getUri')->willReturn($uri);
		$list->method('getDisplayName')->willReturn($name);
		$list->method('search')->with('', [], ['types' => ['VTODO']], 500)->willReturn($rows);
		return $list;

	}//end taskList()

	/**
	 * Two lists: an own work list and a colleague's.
	 *
	 * @return ICalendar[]
	 */
	private function lists(): array {
		return [
			$this->taskList(
				uri: 'work',
				name: 'Work',
				rows: [
					$this->row(uid: 'late', properties: ['SUMMARY' => 'Call back', 'DUE' => new DateTimeImmutable('2026-10-02T09:00:00+00:00'), 'PRIORITY' => '1']),
					$this->row(uid: 'done', properties: ['SUMMARY' => 'Filed', 'COMPLETED' => new DateTimeImmutable('2026-09-30')]),
					$this->row(uid: 'undated', properties: ['SUMMARY' => 'Someday']),
				]
			),
			$this->taskList(
				uri: 'shared',
				name: 'Shared',
				rows: [
					$this->row(uid: 'soon', properties: ['SUMMARY' => 'Permit', 'DUE' => new DateTimeImmutable('2026-10-01T09:00:00+00:00'), 'STATUS' => 'in-process', 'PRIORITY' => 'high']),
				]
			),
		];

	}//end lists()

	/**
	 * Every task is read with its fields, sorted by due date with undated ones last.
	 *
	 * @return void
	 */
	public function testAllTasksAreReadAndSortedByDueDate(): void {
		$tasks = (new TaskReader())->read(lists: $this->lists(), status: 'all', before: null, listUri: '');

		self::assertSame(['soon', 'late', 'done', 'undated'], array_column($tasks, 'uid'));
		self::assertSame(
			['uid' => 'soon', 'summary' => 'Permit', 'due' => '2026-10-01T09:00:00+00:00', 'status' => 'IN-PROCESS', 'priority' => null, 'list' => 'shared', 'listName' => 'Shared'],
			$tasks[0]
		);
		self::assertSame(1, $tasks[1]['priority']);

	}//end testAllTasksAreReadAndSortedByDueDate()

	/**
	 * Without a STATUS a task with a COMPLETED date is completed, and one without is still to do.
	 *
	 * @return void
	 */
	public function testTheStatusFallsBackOnTheCompletedDate(): void {
		$tasks = (new TaskReader())->read(lists: $this->lists(), status: 'all', before: null, listUri: '');
		$statuses = array_combine(array_column($tasks, 'uid'), array_column($tasks, 'status'));

		self::assertSame('COMPLETED', $statuses['done']);
		self::assertSame('NEEDS-ACTION', $statuses['undated']);
		self::assertSame('NEEDS-ACTION', $statuses['late']);

	}//end testTheStatusFallsBackOnTheCompletedDate()

	/**
	 * Open leaves out completed tasks; completed keeps only them.
	 *
	 * @return void
	 */
	public function testStatusFiltersOpenAndCompleted(): void {
		$reader = new TaskReader();

		self::assertSame(['soon', 'late', 'undated'], array_column($reader->read(lists: $this->lists(), status: 'open', before: null, listUri: ''), 'uid'));
		self::assertSame(['done'], array_column($reader->read(lists: $this->lists(), status: 'completed', before: null, listUri: ''), 'uid'));

	}//end testStatusFiltersOpenAndCompleted()

	/**
	 * A due-before bound keeps only tasks due strictly earlier, leaving out undated ones.
	 *
	 * @return void
	 */
	public function testDueBeforeKeepsOnlyEarlierDatedTasks(): void {
		$tasks = (new TaskReader())->read(
			lists: $this->lists(),
			status: 'all',
			before: new DateTimeImmutable('2026-10-02T09:00:00+00:00'),
			listUri: ''
		);

		self::assertSame(['soon'], array_column($tasks, 'uid'));

	}//end testDueBeforeKeepsOnlyEarlierDatedTasks()

	/**
	 * A list uri restricts the read to that list.
	 *
	 * @return void
	 */
	public function testAListUriReadsOnlyThatList(): void {
		$tasks = (new TaskReader())->read(lists: $this->lists(), status: 'all', before: null, listUri: 'shared');

		self::assertSame(['soon'], array_column($tasks, 'uid'));

	}//end testAListUriReadsOnlyThatList()

	/**
	 * A row without an object, or with malformed properties, still reads as an untitled open task.
	 *
	 * @return void
	 */
	public function testMalformedRowsReadAsUntitledOpenTasks(): void {
		$list = $this->taskList(
			uri: 'odd',
			name: 'Odd',
			rows: [
				['uid' => 'empty'],
				['uid' => 'scalar', 'objects' => [['SUMMARY' => 'not a list', 'DUE' => [], 'STATUS' => [['nested']]]]],
			]
		);

		$tasks = (new TaskReader())->read(lists: [$list], status: 'open', before: null, listUri: '');

		self::assertSame(['empty', 'scalar'], array_column($tasks, 'uid'));
		self::assertSame(['', ''], array_column($tasks, 'summary'));
		self::assertSame([null, null], array_column($tasks, 'due'));
		self::assertSame(['NEEDS-ACTION', 'NESTED'], array_column($tasks, 'status'));

	}//end testMalformedRowsReadAsUntitledOpenTasks()
}//end class
