<?php

/**
 * Tests for TaskCalendarObject (tools-nextcloud-tasks).
 *
 * The completion rewrite is tested property by property against a task as the
 * Nextcloud Tasks app stores it: a folded description, categories, an alarm and
 * the app's own X- properties. Completing a task may change four things and
 * nothing else.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\NcNative\TaskCalendarObject;
use PHPUnit\Framework\TestCase;

/**
 * Building and completing VTODO objects.
 *
 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
 */
final class TaskCalendarObjectTest extends TestCase {

	/**
	 * A task as the Tasks app writes it (v0.16), with a folded DESCRIPTION, a
	 * category, an alarm whose own lines must stay inside it, and the app's own
	 * X- property.
	 */
	public const TASKS_APP_FIXTURE = "BEGIN:VCALENDAR\r\n"
		. "VERSION:2.0\r\n"
		. "PRODID:-//Nextcloud Tasks v0.16.1\r\n"
		. "BEGIN:VTODO\r\n"
		. "UID:7c1e2a4b-5d6f-4a8b-9c0d-1e2f3a4b5c6d\r\n"
		. "CREATED:20260925T081500Z\r\n"
		. "LAST-MODIFIED:20260925T081700Z\r\n"
		. "DTSTAMP:20260925T081700Z\r\n"
		. "SUMMARY:Terugbellen mevrouw De Vries over parkeervergunning\r\n"
		. "DESCRIPTION:Mevrouw belde over de vergunning voor de Kerkstraat\\, zij wil \r\n"
		. " weten of de zone-uitbreiding ook haar straat raakt.\r\n"
		. "CATEGORIES:Burgerzaken\r\n"
		. "PRIORITY:5\r\n"
		. "DUE;VALUE=DATE:20261002\r\n"
		. "STATUS:NEEDS-ACTION\r\n"
		. "PERCENT-COMPLETE:0\r\n"
		. "X-APPLE-SORT-ORDER:1\r\n"
		. "BEGIN:VALARM\r\n"
		. "ACTION:DISPLAY\r\n"
		. "DESCRIPTION:Herinnering\r\n"
		. "TRIGGER;RELATED=START:-PT15M\r\n"
		. "END:VALARM\r\n"
		. "END:VTODO\r\n"
		. "END:VCALENDAR\r\n";

	/**
	 * The four property names completing may touch.
	 *
	 * @var array<int, string>
	 */
	private const TOUCHED = ['STATUS', 'COMPLETED', 'PERCENT-COMPLETE', 'X-HERMIQ-AGENT-AUTHORED'];

	/**
	 * Split an object into its lines, dropping the empty tail.
	 *
	 * @param string $ics The object.
	 *
	 * @return array<int, string> The lines.
	 */
	private function lines(string $ics): array {
		return array_values(array_filter(explode("\r\n", $ics), static fn (string $line): bool => $line !== ''));

	}//end lines()

	/**
	 * The name of a content line, or '' for a continuation line.
	 *
	 * @param string $line The line.
	 *
	 * @return string The property name.
	 */
	private function nameOf(string $line): string {
		if (str_starts_with($line, ' ') === true) {
			return '';
		}

		return strtoupper((string)preg_split('/[;:]/', $line, 2)[0]);

	}//end nameOf()

	/**
	 * Completing changes status, completed time, percent and the mark, and keeps
	 * every other line byte for byte, the folded description and the alarm included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testCompletingKeepsEveryPropertyItDoesNotOwn(): void {
		$now = new DateTimeImmutable('2026-09-30T12:34:56', new DateTimeZone('Europe/Amsterdam'));

		$completed = (new TaskCalendarObject())->complete(
			ics: self::TASKS_APP_FIXTURE,
			markValue: 'hermiq:agent-7',
			now: $now
		);

		$this->assertNotNull($completed);
		$before = $this->lines(self::TASKS_APP_FIXTURE);
		$after = $this->lines((string)$completed);

		$kept = array_values(array_filter($before, fn (string $line): bool => in_array($this->nameOf($line), self::TOUCHED, true) === false));
		$untouchedAfter = array_values(array_filter($after, fn (string $line): bool => in_array($this->nameOf($line), self::TOUCHED, true) === false));
		$this->assertSame($kept, $untouchedAfter, 'Every line completing does not own must survive unchanged and in order.');

		$added = array_values(array_diff($after, $before));
		$this->assertSame(
			[
				'STATUS:COMPLETED',
				'COMPLETED:20260930T103456Z',
				'PERCENT-COMPLETE:100',
				'X-HERMIQ-AGENT-AUTHORED:hermiq:agent-7',
			],
			$added
		);

		// The new lines belong to the task, not to its alarm.
		$endAlarm = array_search('END:VALARM', $after, true);
		$endTodo = array_search('END:VTODO', $after, true);
		$status = array_search('STATUS:COMPLETED', $after, true);
		$this->assertGreaterThan($endAlarm, $status);
		$this->assertLessThan($endTodo, $status);
		$this->assertNotContains('STATUS:NEEDS-ACTION', $after);
		$this->assertNotContains('PERCENT-COMPLETE:0', $after);

	}//end testCompletingKeepsEveryPropertyItDoesNotOwn()

	/**
	 * Completing twice replaces the mark rather than stacking a second one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testCompletingTwiceKeepsOneMark(): void {
		$object = new TaskCalendarObject();
		$now = new DateTimeImmutable('2026-09-30T10:00:00Z');

		$once = (string)$object->complete(ics: self::TASKS_APP_FIXTURE, markValue: 'hermiq:a', now: $now);
		$twice = (string)$object->complete(ics: $once, markValue: 'hermiq:b', now: $now);

		$this->assertSame(1, substr_count($twice, 'X-HERMIQ-AGENT-AUTHORED:'));
		$this->assertStringContainsString('X-HERMIQ-AGENT-AUTHORED:hermiq:b', $twice);
		$this->assertSame(1, substr_count($twice, 'STATUS:'));

	}//end testCompletingTwiceKeepsOneMark()

	/**
	 * An object without a task cannot be completed, so nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function testAnObjectWithoutATaskIsNotRewritten(): void {
		$event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

		$this->assertNull(
			(new TaskCalendarObject())->complete(ics: $event, markValue: 'hermiq', now: new DateTimeImmutable())
		);

	}//end testAnObjectWithoutATaskIsNotRewritten()

	/**
	 * A new task carries the summary, a date-only due, priority, the open status
	 * and the mark inside the same VTODO, with text escaped and long lines folded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testBuildWritesAMarkedVtodo(): void {
		$ics = (new TaskCalendarObject())->build(
			taskUid: 'task-1',
			fields: [
				'summary' => 'Besluit bezwaar Kerkstraat 12 versturen',
				'description' => "Brief klaarzetten; kopie naar dossier, daarna\nversturen",
				'due' => '2026-10-02',
				'priority' => 1,
			],
			markValue: 'hermiq:agent-7',
			now: new DateTimeImmutable('2026-09-30T10:00:00Z')
		);

		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
		$lines = $this->lines($ics);
		$start = array_search('BEGIN:VTODO', $lines, true);
		$end = array_search('END:VTODO', $lines, true);
		$todo = array_slice($lines, (int)$start, ((int)$end - (int)$start + 1));

		$this->assertContains('UID:task-1', $todo);
		$this->assertContains('DTSTAMP:20260930T100000Z', $todo);
		$this->assertContains('SUMMARY:Besluit bezwaar Kerkstraat 12 versturen', $todo);
		$this->assertContains('DUE;VALUE=DATE:20261002', $todo);
		$this->assertContains('PRIORITY:1', $todo);
		$this->assertContains('STATUS:NEEDS-ACTION', $todo);
		$this->assertContains('X-HERMIQ-AGENT-AUTHORED:hermiq:agent-7', $todo);
		$this->assertStringContainsString('DESCRIPTION:Brief klaarzetten\\; kopie naar dossier\\, daarna\\nversturen', $ics);

		foreach (explode("\r\n", $ics) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line), 'RFC 5545 lines are folded at 75 octets.');
		}

	}//end testBuildWritesAMarkedVtodo()

	/**
	 * A due date-time is stored in UTC; a long multibyte summary folds without
	 * splitting a character.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function testDueDateTimeIsUtcAndFoldingKeepsCharactersWhole(): void {
		$summary = str_repeat('Één overleg met de wijkagent ', 5);
		$ics = (new TaskCalendarObject())->build(
			taskUid: 'task-2',
			fields: ['summary' => $summary, 'due' => '2026-10-02T15:00:00+02:00'],
			markValue: 'hermiq',
			now: new DateTimeImmutable('2026-09-30T10:00:00Z')
		);

		$this->assertStringContainsString("\r\nDUE:20261002T130000Z\r\n", $ics);
		$unfolded = str_replace("\r\n ", '', $ics);
		$this->assertStringContainsString('SUMMARY:' . trim($summary), $unfolded);
		$this->assertTrue(mb_check_encoding($ics, 'UTF-8'));
		foreach (explode("\r\n", $ics) as $line) {
			$this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'A fold must not split a multibyte character.');
		}

	}//end testDueDateTimeIsUtcAndFoldingKeepsCharactersWhole()

}//end class
