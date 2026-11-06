<?php

/**
 * Hermiq task calendar object (tools-nextcloud-tasks).
 *
 * Builds a new VTODO and completes an existing one as iCalendar text, without
 * Sabre VObject (the server ships it; this app's vendor tree does not). Building
 * works on text this class writes itself. Completing is a line edit on the
 * user's own object: it removes the four properties it owns from the top-level
 * VTODO and adds them back before that VTODO ends, and every other line,
 * folded continuations and nested alarms included, stays byte for byte. That is
 * a stronger guarantee than parsing and re-serialising, which reorders and
 * refolds what the user wrote.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use DateTimeImmutable;
use DateTimeZone;

/**
 * VTODO text for create and complete.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
 */
class TaskCalendarObject {

	/**
	 * The properties completing owns; every other property is left alone.
	 *
	 * @var array<int, string>
	 */
	private const COMPLETION_PROPERTIES = ['STATUS', 'COMPLETED', 'PERCENT-COMPLETE', AgentArtefactMarker::OBJECT_PROPERTY];

	/**
	 * RFC 5545 3.1: content lines are folded at 75 octets.
	 */
	private const FOLD_AT = 75;

	/**
	 * Build a new marked VTODO.
	 *
	 * @param string $taskUid The task's UID.
	 * @param array<string, mixed> $fields summary, description, due (validated ISO-8601), priority.
	 * @param string $markValue The ADR-088 mark value.
	 * @param DateTimeImmutable $now The creation time.
	 *
	 * @return string The iCalendar object.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
	 */
	public function build(string $taskUid, array $fields, string $markValue, DateTimeImmutable $now): string {
		$stamp = $this->utc(moment: $now);
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//Hermiq//EN',
			'BEGIN:VTODO',
			'UID:' . $this->escape(text: $taskUid),
			'DTSTAMP:' . $stamp,
			'CREATED:' . $stamp,
			'LAST-MODIFIED:' . $stamp,
			'SUMMARY:' . $this->escape(text: trim((string)($fields['summary'] ?? ''))),
		];

		$description = trim((string)($fields['description'] ?? ''));
		if ($description !== '') {
			$lines[] = 'DESCRIPTION:' . $this->escape(text: $description);
		}

		$due = trim((string)($fields['due'] ?? ''));
		if ($due !== '') {
			$lines[] = $this->dueLine(due: $due);
		}

		$priority = ($fields['priority'] ?? null);
		if (is_numeric($priority) === true) {
			$lines[] = 'PRIORITY:' . (int)$priority;
		}

		// ADR-088: the mark sits inside the same object that is stored, so the task
		// cannot exist unmarked even for a moment.
		$lines[] = 'STATUS:NEEDS-ACTION';
		$lines[] = AgentArtefactMarker::OBJECT_PROPERTY . ':' . $markValue;
		$lines[] = 'END:VTODO';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", array_map(fn (string $line): string => $this->fold(line: $line), $lines)) . "\r\n";

	}//end build()

	/**
	 * Complete the first top-level VTODO of an object, or null when it has none.
	 *
	 * @param string $ics The stored object, as the user's client wrote it.
	 * @param string $markValue The ADR-088 mark value.
	 * @param DateTimeImmutable $now The completion time.
	 *
	 * @return string|null The rewritten object, or null when there is no task to complete.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function complete(string $ics, string $markValue, DateTimeImmutable $now): ?string {
		$newline = "\n";
		if (str_contains($ics, "\r\n") === true) {
			$newline = "\r\n";
		}

		$logical = $this->logicalLines(physical: preg_split('/\r\n|\n/', $ics));
		$start = null;
		foreach ($logical as $index => $group) {
			if (strtoupper($group[0]) === 'BEGIN:VTODO') {
				$start = $index;
				break;
			}
		}

		if ($start === null) {
			return null;
		}

		$added = [
			'STATUS:COMPLETED',
			'COMPLETED:' . $this->utc(moment: $now),
			'PERCENT-COMPLETE:100',
			AgentArtefactMarker::OBJECT_PROPERTY . ':' . $markValue,
		];

		$rewritten = $this->rewriteTask(logical: $logical, start: $start, added: $added);
		if ($rewritten === null) {
			return null;
		}

		return implode($newline, array_merge(...$rewritten));

	}//end complete()

	/**
	 * Group physical lines into logical content lines: a line that starts with a
	 * space or tab continues the one above it.
	 *
	 * @param array<int, string> $physical The physical lines.
	 *
	 * @return array<int, array<int, string>> The logical lines, each its physical lines.
	 */
	private function logicalLines(array $physical): array {
		$logical = [];
		foreach ($physical as $line) {
			$continues = ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"));
			if ($continues === true && $logical !== []) {
				$logical[(count($logical) - 1)][] = $line;
				continue;
			}

			$logical[] = [$line];
		}

		return $logical;

	}//end logicalLines()

	/**
	 * Drop the completion properties of the task that begins at `$start` and add
	 * the new ones before that task ends; every other logical line is kept whole.
	 *
	 * @param array<int, array<int, string>> $logical The logical lines.
	 * @param int $start The index of BEGIN:VTODO.
	 * @param array<int, string> $added The lines to add.
	 *
	 * @return array<int, array<int, string>>|null The rewritten lines, or null when the task never ends.
	 */
	private function rewriteTask(array $logical, int $start, array $added): ?array {
		$depth = 0;
		$count = count($logical);
		for ($index = ($start + 1); $index < $count; $index++) {
			$upper = strtoupper($logical[$index][0]);
			if ($depth === 0 && $upper === 'END:VTODO') {
				array_splice($logical, $index, 0, [$added]);

				return array_values($logical);
			}

			$depth += $this->depthChange(upper: $upper);
			$name = (string)preg_split('/[;:]/', $upper, 2)[0];
			if ($depth === 0 && in_array($name, self::COMPLETION_PROPERTIES, true) === true) {
				$logical[$index] = [];
			}
		}

		return null;

	}//end rewriteTask()

	/**
	 * How a content line changes the component nesting depth.
	 *
	 * @param string $upper The line, upper-cased.
	 *
	 * @return int 1 for BEGIN, -1 for END, 0 otherwise.
	 */
	private function depthChange(string $upper): int {
		if (str_starts_with($upper, 'BEGIN:') === true) {
			return 1;
		}

		if (str_starts_with($upper, 'END:') === true) {
			return -1;
		}

		return 0;

	}//end depthChange()

	/**
	 * The DUE line: a date-only due stays a DATE, a date-time is stored in UTC.
	 *
	 * @param string $due A validated ISO-8601 date or date-time.
	 *
	 * @return string The content line.
	 */
	private function dueLine(string $due): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) === 1) {
			return 'DUE;VALUE=DATE:' . str_replace('-', '', $due);
		}

		return 'DUE:' . $this->utc(moment: new DateTimeImmutable($due));

	}//end dueLine()

	/**
	 * Format a moment as an iCalendar UTC date-time.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return string The UTC value, e.g. 20260930T103456Z.
	 */
	private function utc(DateTimeImmutable $moment): string {
		return $moment->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

	}//end utc()

	/**
	 * Escape a TEXT value (RFC 5545 3.3.11).
	 *
	 * @param string $text The raw text.
	 *
	 * @return string The escaped value.
	 */
	private function escape(string $text): string {
		$text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);

		return str_replace(["\r\n", "\r", "\n"], '\\n', $text);

	}//end escape()

	/**
	 * Fold a content line at 75 octets without splitting a UTF-8 character.
	 *
	 * @param string $line The unfolded line.
	 *
	 * @return string The folded line.
	 */
	private function fold(string $line): string {
		if (strlen($line) <= self::FOLD_AT) {
			return $line;
		}

		$folded = '';
		$current = '';
		$limit = self::FOLD_AT;
		foreach (mb_str_split($line, 1, 'UTF-8') as $character) {
			if ((strlen($current) + strlen($character)) > $limit) {
				$folded .= $current . "\r\n ";
				$current = '';
				// A continuation line starts with the space, which counts.
				$limit = (self::FOLD_AT - 1);
			}

			$current .= $character;
		}

		return $folded . $current;

	}//end fold()

}//end class
