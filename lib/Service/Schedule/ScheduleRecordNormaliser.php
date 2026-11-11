<?php

/**
 * Hermiq ScheduleRecordNormaliser.
 *
 * Repairs OpenRegister read-modify-write round-trip artifacts on a schedule
 * record before it is saved back whole: `nextRun`/`runAt` date-times, the
 * nullable `repeat` object and the nullable `retryState` object. Also reads the
 * stored timestamps and retry state the dispatcher's due check relies on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Normalises a stored schedule record to the shape its schema accepts.
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
 */
final class ScheduleRecordNormaliser {

	/**
	 * Schedule properties declared as `date-time` in the schema.
	 *
	 * OpenRegister's getObject() returns stored date-times as `Y-m-d H:i:s`
	 * (space, no `T`), but saveObject re-validates the WHOLE object against the
	 * schema's `date-time` format, which requires ISO-8601 with `T`. Every one of
	 * these fields must be re-normalised to `format('c')` before a full-object
	 * save, or the write is rejected.
	 *
	 * @var array<int, string>
	 */
	private const DATE_TIME_FIELDS = ['nextRun', 'runAt'];

	/**
	 * Neutralise OpenRegister read-modify-write round-trip artifacts before a save.
	 *
	 * The dispatcher reads a whole object via getObject(), edits a few fields, and
	 * saves the whole array back — so OR re-validates the ENTIRE payload against the
	 * schema, including fields OR itself materialised on read in a shape the schema
	 * rejects. This repairs the artifacts on the fields the dispatcher owns
	 * (`nextRun`/`runAt` date-time format, the nullable `repeat` and `retryState`
	 * objects) so a full-object save passes OR's own validation. It repairs OR's own
	 * read-side shape only — it does not mask genuinely user-supplied invalid data.
	 *
	 * @param array<string,mixed> $data The payload about to be saved.
	 *
	 * @return array<string,mixed> The sanitised payload.
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
	 */
	public function sanitizeForSave(array $data): array {
		$data = $this->normaliseDates(data: $data);
		$data = $this->sanitizeRepeat(data: $data);
		$data = $this->sanitizeRetryState(data: $data);
		return $data;
	}//end sanitizeForSave()

	/**
	 * Parse a stored ISO-8601 timestamp into a UTC DateTimeImmutable.
	 *
	 * @param string $value The stored timestamp.
	 *
	 * @return DateTimeImmutable|null The parsed instant, or null when empty or unparseable.
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#3-scheduleservice-dispatch-logic
	 */
	public function parseDate(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}//end parseDate()

	/**
	 * Normalise a raw `retryState` value into a `{attempt:int, nextAttemptAt:string}`
	 * shape, or `null` when it is absent/invalid (run-reliability).
	 *
	 * @param mixed $raw The raw retryState value from the schedule payload.
	 *
	 * @return array{attempt:int, nextAttemptAt:string}|null The normalised state, or null.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	public function normaliseRetryState(mixed $raw): ?array {
		if (is_array($raw) === false) {
			return null;
		}

		$attempt = ($raw['attempt'] ?? null);
		$nextAttemptAt = ($raw['nextAttemptAt'] ?? null);
		if (is_numeric($attempt) === false || empty($nextAttemptAt) === true) {
			return null;
		}

		return [
			'attempt' => (int)$attempt,
			'nextAttemptAt' => (string)$nextAttemptAt,
		];
	}//end normaliseRetryState()

	/**
	 * Normalise all date-time fields in a schedule payload to ISO-8601 with `T`.
	 *
	 * Reparses each present `date-time` field (from any parseable form, notably OR's
	 * `Y-m-d H:i:s`) and rewrites it with `format('c')`. Null/empty and unparseable
	 * values are left as they are.
	 *
	 * @param array<string,mixed> $data The payload about to be saved.
	 *
	 * @return array<string,mixed> The payload with normalised date-time fields.
	 */
	private function normaliseDates(array $data): array {
		foreach (self::DATE_TIME_FIELDS as $field) {
			if (array_key_exists($field, $data) === true) {
				$data[$field] = $this->normaliseDate(value: $data[$field]);
			}
		}

		return $data;
	}//end normaliseDates()

	/**
	 * One stored date-time value as ISO-8601, or as given when it is null,
	 * empty or unparseable.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return mixed The normalised value.
	 */
	private function normaliseDate(mixed $value): mixed {
		if ($value === null || $value === '') {
			return $value;
		}

		$parsed = $this->parseDate(value: (string)$value);
		if ($parsed === null) {
			return $value;
		}

		return $parsed->format('c');
	}//end normaliseDate()

	/**
	 * Sanitise the nullable `repeat` object against its schema constraints.
	 *
	 * `repeat` is optional: a schedule with no valid finite-repeat is INFINITE and
	 * must serialise as `repeat = null`. OR's getObject() materialises the nullable
	 * object as `{"times": 0, "completed": 0}` on read, which then fails the schema's
	 * `repeat.times` `minimum: 1` when the whole object is saved back. This collapses
	 * any non-finite repeat (missing, non-array, or `times < 1`) to `null`, and keeps
	 * a genuine finite repeat only when `times >= 1` (with `completed` coerced to an
	 * int >= 0). It repairs OR's round-trip artifact, not user intent.
	 *
	 * @param array<string,mixed> $data The payload about to be saved.
	 *
	 * @return array<string,mixed> The payload with a schema-valid `repeat`.
	 */
	private function sanitizeRepeat(array $data): array {
		if (array_key_exists('repeat', $data) === false) {
			return $data;
		}

		$data['repeat'] = $this->finiteRepeat(repeat: $data['repeat']);
		return $data;
	}//end sanitizeRepeat()

	/**
	 * The finite repeat a stored `repeat` value describes, or null for an
	 * infinite schedule (malformed, or `times` below 1).
	 *
	 * @param mixed $repeat The stored repeat value.
	 *
	 * @return array{times:int, completed:int}|null
	 */
	private function finiteRepeat(mixed $repeat): ?array {
		if ($this->hasNumericTimes(repeat: $repeat) === false) {
			return null;
		}

		$times = (int)$repeat['times'];
		if ($times < 1) {
			// A times value below 1 means an infinite schedule; drop the artifact.
			return null;
		}

		return [
			'times' => $times,
			'completed' => $this->completedCount(repeat: $repeat),
		];
	}//end finiteRepeat()

	/**
	 * Whether a stored repeat value is an array with a numeric `times`.
	 *
	 * @param mixed $repeat The stored repeat value.
	 *
	 * @return bool
	 */
	private function hasNumericTimes(mixed $repeat): bool {
		return is_array($repeat) === true && isset($repeat['times']) === true && is_numeric($repeat['times']) === true;
	}//end hasNumericTimes()

	/**
	 * The completed count of a finite repeat: its numeric `completed`, at least 0,
	 * or 0 when absent or not numeric.
	 *
	 * @param array<string,mixed> $repeat The stored repeat object.
	 *
	 * @return int
	 */
	private function completedCount(array $repeat): int {
		if (isset($repeat['completed']) === true && is_numeric($repeat['completed']) === true) {
			return max(0, (int)$repeat['completed']);
		}

		return 0;
	}//end completedCount()

	/**
	 * Sanitise the nullable `retryState` object against its schema constraints
	 * (run-reliability) — mirrors sanitizeRepeat()'s OR round-trip repair.
	 *
	 * `retryState` is optional: no open retry sequence serialises as `null`. OR's
	 * getObject() may materialise the nullable object as `{}` (or an incomplete
	 * shape) on read, which this collapses back to `null`, and re-normalises a
	 * genuine retry state's `nextAttemptAt` to ISO-8601 (the same round-trip
	 * artifact `nextRun`/`runAt` have — see normaliseDates()).
	 *
	 * @param array<string,mixed> $data The payload about to be saved.
	 *
	 * @return array<string,mixed> The payload with a schema-valid `retryState`.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-per-schedule-opt-in-bounded-retry-with-exponential-backoff-mvp
	 */
	private function sanitizeRetryState(array $data): array {
		if (array_key_exists('retryState', $data) === false) {
			return $data;
		}

		$data['retryState'] = $this->storableRetryState(raw: $data['retryState']);
		return $data;
	}//end sanitizeRetryState()

	/**
	 * A well-formed retry state with its next attempt as ISO-8601 (as given when
	 * it does not parse), or null when the stored value is not one.
	 *
	 * @param mixed $raw The stored retryState value.
	 *
	 * @return array{attempt:int, nextAttemptAt:string}|null
	 */
	private function storableRetryState(mixed $raw): ?array {
		$state = $this->normaliseRetryState(raw: $raw);
		if ($state === null) {
			return null;
		}

		$nextAttemptAt = $state['nextAttemptAt'];
		$parsed = $this->parseDate(value: $nextAttemptAt);
		if ($parsed !== null) {
			$nextAttemptAt = $parsed->format('c');
		}

		return [
			'attempt' => $state['attempt'],
			'nextAttemptAt' => $nextAttemptAt,
		];
	}//end storableRetryState()
}//end class
