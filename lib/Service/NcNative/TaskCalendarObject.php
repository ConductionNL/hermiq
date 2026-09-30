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
 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
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
 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
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
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-create-a-task-in-the-users-own-list-marked-as-agent-authored-req-nctask-002
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

		if (isset($fields['priority']) === true && $fields['priority'] !== '' && $fields['priority'] !== null) {
			$lines[] = 'PRIORITY:' . (int)$fields['priority'];
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
	 * @spec openspec/changes/tools-nextcloud-tasks/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function complete(string $ics, string $markValue, DateTimeImmutable $now): ?string {
		$newline = "\n";
		if (str_contains($ics, "\r\n") === true) {
			$newline = "\r\n";
		}

		$added = [
			'STATUS:COMPLETED',
			'COMPLETED:' . $this->utc(moment: $now),
			'PERCENT-COMPLETE:100',
			AgentArtefactMarker::OBJECT_PROPERTY . ':' . $markValue,
		];

		$output = [];
		$state = ['inTodo' => false, 'depth' => 0, 'done' => false, 'dropping' => false];
		foreach (preg_split('/\r\n|\n/', $ics) as $line) {
			$output = array_merge($output, $this->rewriteLine(line: $line, state: $state, added: $added));
		}

		if ($state['done'] === false) {
			return null;
		}

		return implode($newline, $output);

	}//end complete()

	/**
	 * Decide what one physical line becomes while completing.
	 *
	 * @param string $line The physical line.
	 * @param array<string, bool|int> $state The walk state, updated in place.
	 * @param array<int, string> $added The lines to add before the task ends.
	 *
	 * @return array<int, string> The lines to emit for this one.
	 */
	private function rewriteLine(string $line, array &$state, array $added): array {
		$isContinuation = ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"));
		if ($state['inTodo'] === false || $state['done'] === true) {
			if ($isContinuation === false && strtoupper($line) === 'BEGIN:VTODO' && $state['done'] === false) {
				$state['inTodo'] = true;
			}

			return [$line];
		}

		// A folded continuation belongs to the property above it.
		if ($isContinuation === true) {
			if ($state['dropping'] === true) {
				return [];
			}

			return [$line];
		}

		$state['dropping'] = false;
		$upper = strtoupper($line);
		if ($state['depth'] === 0 && $upper === 'END:VTODO') {
			$state['done'] = true;

			return array_merge($added, [$line]);
		}

		if (str_starts_with($upper, 'BEGIN:') === true) {
			$state['depth'] = ((int)$state['depth'] + 1);

			return [$line];
		}

		if (str_starts_with($upper, 'END:') === true) {
			$state['depth'] = max(0, ((int)$state['depth'] - 1));

			return [$line];
		}

		$name = strtoupper((string)preg_split('/[;:]/', $line, 2)[0]);
		if ($state['depth'] === 0 && in_array($name, self::COMPLETION_PROPERTIES, true) === true) {
			$state['dropping'] = true;

			return [];
		}

		return [$line];

	}//end rewriteLine()

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
