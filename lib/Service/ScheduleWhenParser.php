<?php

/**
 * Hermiq ScheduleWhenParser
 *
 * Reads a plain English or Dutch description of when a schedule runs and
 * turns it into a schedule rule (scheduling-overview-and-start-choice D3).
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A deterministic reader of the common schedule phrases.
 *
 * Covers, in English and Dutch: every N minutes or hours (and every quarter or
 * half hour), every day, every weekday or named weekdays at a time, the first
 * day of the month, tomorrow at a time, and once on a date at a time. The rule
 * it returns is what is stored; the readback is what the person confirms.
 * Anything else answers null, and the caller may ask a model instead.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 */
class ScheduleWhenParser {

	/**
	 * Number words, English and Dutch, up to twelve.
	 *
	 * @var array<string, int>
	 */
	private const NUMBERS = [
		'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
		'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20, 'thirty' => 30,
		'een' => 1, 'één' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vijf' => 5, 'zes' => 6, 'zeven' => 7, 'acht' => 8,
		'negen' => 9, 'tien' => 10, 'elf' => 11, 'twaalf' => 12, 'vijftien' => 15, 'twintig' => 20, 'dertig' => 30,
	];

	/**
	 * Weekday names to their cron number (0 is Sunday).
	 *
	 * @var array<string, int>
	 */
	private const DAYS = [
		'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6,
		'zondag' => 0, 'maandag' => 1, 'dinsdag' => 2, 'woensdag' => 3, 'donderdag' => 4, 'vrijdag' => 5, 'zaterdag' => 6,
	];

	/**
	 * Month names to their number.
	 *
	 * @var array<string, int>
	 */
	private const MONTHS = [
		'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
		'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
		'januari' => 1, 'februari' => 2, 'maart' => 3, 'mei' => 5, 'juni' => 6, 'juli' => 7, 'augustus' => 8, 'oktober' => 10,
	];

	/**
	 * Weekday names for the readback, by cron number.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const DAY_NAMES = [
		'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
		'nl' => ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'],
	];

	/**
	 * The readback words, by language.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const WORDS = [
		'en' => [
			'every' => 'Every', 'every minute' => 'Every minute', 'every hour' => 'Every hour', 'minutes' => 'minutes', 'hours' => 'hours',
			'every day' => 'Every day', 'every weekday' => 'Every weekday', 'and' => 'and', 'at' => 'at',
			'rule' => 'On the rule', 'monthly' => 'On the first day of every month at',
		],
		'nl' => [
			'every' => 'Elke', 'every minute' => 'Elke minuut', 'every hour' => 'Elk uur', 'minutes' => 'minuten', 'hours' => 'uur',
			'every day' => 'Elke dag', 'every weekday' => 'Elke werkdag', 'and' => 'en', 'at' => 'om',
			'rule' => 'Volgens de regel', 'monthly' => 'Op de eerste dag van elke maand om',
		],
	];

	/**
	 * The rule a phrase describes, or null when this parser cannot read it.
	 *
	 * @param string            $text     What the person typed.
	 * @param string            $locale   The readback language: `nl` for Dutch, anything else English.
	 * @param DateTimeImmutable $now      Now, for "tomorrow" and a date without a year.
	 * @param DateTimeZone      $timezone The timezone the times are in.
	 *
	 * @return array{kind: string, cronExpr?: string, intervalMinutes?: int, runAt?: string, readback: string}|null
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
	 */
	public function parse(string $text, string $locale, DateTimeImmutable $now, DateTimeZone $timezone): ?array {
		$phrase = $this->normalise(text: $text);
		if ($phrase === '') {
			return null;
		}

		$lang = 'en';
		if (str_starts_with(strtolower($locale), 'nl') === true) {
			$lang = 'nl';
		}

		$rule = ($this->interval(phrase: $phrase)
			?? $this->weekly(phrase: $phrase)
			?? $this->monthly(phrase: $phrase)
			?? $this->once(phrase: $phrase, now: $now->setTimezone($timezone)));
		if ($rule === null) {
			return null;
		}

		return $rule + ['readback' => $this->readback(rule: $rule, lang: $lang)];
	}//end parse()

	/**
	 * The readback of a rule, for a rule this parser or a model produced.
	 *
	 * @param array<string, mixed> $rule The rule: kind and its field.
	 * @param string               $lang `en` or `nl`.
	 *
	 * @return string The readback, without the timezone.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
	 */
	public function readback(array $rule, string $lang): string {
		$nl = ($lang === 'nl');
		if (($rule['kind'] ?? '') === 'interval') {
			return $this->intervalReadback(minutes: (int)$rule['intervalMinutes'], dutch: $nl);
		}

		if (($rule['kind'] ?? '') === 'once') {
			$at = new DateTimeImmutable((string)$rule['runAt']);
			if ($nl === true) {
				return 'Eenmalig op ' . $at->format('Y-m-d') . ' om ' . $at->format('H:i');
			}

			return 'Once on ' . $at->format('Y-m-d') . ' at ' . $at->format('H:i');
		}

		return $this->cronReadback(cron: (string)($rule['cronExpr'] ?? ''), dutch: $nl);
	}//end readback()

	/**
	 * Lower case, single spaces, no trailing punctuation, "every"/"each" and "elke"/"iedere" kept.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function normalise(string $text): string {
		$text = mb_strtolower(trim($text));
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		return rtrim($text, " .!?");
	}//end normalise()

	/**
	 * Every N minutes or hours.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return array{kind: string, intervalMinutes: int}|null
	 */
	private function interval(string $phrase): ?array {
		$minutes = ($this->fixedInterval(phrase: $phrase) ?? $this->countedInterval(phrase: $phrase));
		if ($minutes === null) {
			return null;
		}

		return ['kind' => 'interval', 'intervalMinutes' => $minutes];
	}//end interval()

	/**
	 * The minutes of a named interval: a minute, a quarter, half an hour, an hour.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return int|null
	 */
	private function fixedInterval(string $phrase): ?int {
		$fixed = [
			'/^(?:every|each) (?:quarter of an hour|quarter hour)$/' => 15,
			'/^(?:elk|ieder|elke|iedere) kwartier$/' => 15,
			'/^(?:every|each) half(?: an)? hour$/' => 30,
			'/^(?:elk|ieder|elke|iedere) half uur$/' => 30,
			'/^(?:every|each) minute$/' => 1,
			'/^(?:elke|iedere) minuut$/' => 1,
			'/^(?:every|each) hour$|^hourly$/' => 60,
			'/^(?:elk|ieder|elke|iedere) uur$/' => 60,
		];
		foreach ($fixed as $pattern => $minutes) {
			if (preg_match($pattern, $phrase) === 1) {
				return $minutes;
			}
		}

		return null;
	}//end fixedInterval()

	/**
	 * The minutes of a counted interval: `every 5 minutes`, `elke twee uur`.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return int|null
	 */
	private function countedInterval(string $phrase): ?int {
		if (preg_match('/^(?:every|each|elke|iedere|elk|ieder) (\S+) (minutes?|mins?|minuten|minuut|hours?|uur|uren)$/u', $phrase, $m) !== 1) {
			return null;
		}

		$count = $this->number(word: $m[1]);
		if ($count === null || $count < 1) {
			return null;
		}

		if (in_array($m[2], ['hour', 'hours', 'uur', 'uren'], true) === true) {
			$count *= 60;
		}

		return $count;
	}//end countedInterval()

	/**
	 * Every day, every weekday, or named weekdays, at a time.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return array{kind: string, cronExpr: string}|null
	 */
	private function weekly(string $phrase): ?array {
		$m = self::firstMatch(
			patterns: ['/^(?:(?:every|each|on) (.+?)|(daily)) at (.+)$/u', '/^(?:(?:elke|iedere|op) (.+?)|(dagelijks)) om (.+)$/u'],
			phrase: $phrase
		);
		if ($m === null) {
			return null;
		}

		$days = $this->daysField(days: ($m[1] . $m[2]));
		$time = $this->time(text: $m[3]);
		if ($days === null || $time === null) {
			return null;
		}

		return ['kind' => 'cron', 'cronExpr' => sprintf('%d %d * * %s', $time[1], $time[0], $days)];
	}//end weekly()

	/**
	 * The groups of the first pattern that matches, or null.
	 *
	 * @param array<int, string> $patterns The patterns, in order.
	 * @param string             $phrase   The phrase.
	 *
	 * @return array<int, string>|null
	 */
	private static function firstMatch(array $patterns, string $phrase): ?array {
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $phrase, $m) === 1) {
				return $m;
			}
		}

		return null;
	}//end firstMatch()

	/**
	 * The cron day-of-week field of a day phrase: every day, weekdays or a list of names.
	 *
	 * @param string $days The day phrase.
	 *
	 * @return string|null `*`, `1-5` or a comma list, or null.
	 */
	private function daysField(string $days): ?string {
		if (in_array($days, ['day', 'daily', 'dag', 'dagelijks'], true) === true) {
			return '*';
		}

		if (in_array($days, ['weekday', 'weekdays', 'working day', 'workday', 'werkdag', 'werkdagen'], true) === true) {
			return '1-5';
		}

		$numbers = $this->dayNumbers(days: $days);
		if ($numbers === null || $numbers === []) {
			return null;
		}

		$numbers = array_values(array_unique($numbers));
		sort($numbers);

		return implode(',', $numbers);
	}//end daysField()

	/**
	 * The cron day numbers of a list of day names, or null for an unknown name.
	 *
	 * @param string $days The day names: `monday, wednesday and friday`.
	 *
	 * @return array<int, int>|null
	 */
	private function dayNumbers(string $days): ?array {
		$numbers = [];
		foreach (preg_split('/\s*(?:,|\band\b|\ben\b)\s*/u', $days) as $name) {
			$name = (string)preg_replace('/s$/', '', trim($name));
			if ($name === '') {
				continue;
			}

			if (isset(self::DAYS[$name]) === false) {
				return null;
			}

			$numbers[] = self::DAYS[$name];
		}

		return $numbers;
	}//end dayNumbers()

	/**
	 * The first day of the month, at a time or at midnight.
	 *
	 * @param string $phrase The phrase.
	 *
	 * @return array{kind: string, cronExpr: string}|null
	 */
	private function monthly(string $phrase): ?array {
		$patterns = [
			'/^(?:on )?the first(?: day)? of (?:the|every|each) month(?: at (.+))?$/u',
			'/^monthly(?: at (.+))?$/u',
			'/^(?:op )?de eerste(?: dag)? van (?:de|elke|iedere) maand(?: om (.+))?$/u',
			'/^maandelijks(?: om (.+))?$/u',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $phrase, $m) !== 1) {
				continue;
			}

			$time = [0, 0];
			if (($m[1] ?? '') !== '') {
				$time = $this->time(text: $m[1]);
			}

			if ($time === null) {
				return null;
			}

			return ['kind' => 'cron', 'cronExpr' => sprintf('%d %d 1 * *', $time[1], $time[0])];
		}

		return null;
	}//end monthly()

	/**
	 * Tomorrow at a time, or once on a date at a time.
	 *
	 * @param string            $phrase The phrase.
	 * @param DateTimeImmutable $now    Now, in the schedule's timezone.
	 *
	 * @return array{kind: string, runAt: string}|null
	 */
	private function once(string $phrase, DateTimeImmutable $now): ?array {
		$date = null;
		$time = null;
		if (preg_match('/^(?:tomorrow at|morgen om) (.+)$/u', $phrase, $m) === 1) {
			$date = $now->modify('+1 day');
			$time = $this->time(text: $m[1]);
		} elseif (preg_match('/^(?:once |eenmalig )?(?:on|op) (.+?) (?:at|om) (.+)$/u', $phrase, $m) === 1) {
			$date = $this->date(text: $m[1], now: $now);
			$time = $this->time(text: $m[2]);
		}

		if ($date === null || $time === null) {
			return null;
		}

		return ['kind' => 'once', 'runAt' => $date->setTime($time[0], $time[1])->format(DATE_ATOM)];
	}//end once()

	/**
	 * A calendar date: `2026-12-01`, `1 december [2026]` or `december 1[, 2026]`.
	 *
	 * A date without a year is the next one from today.
	 *
	 * @param string            $text The date text.
	 * @param DateTimeImmutable $now  Now.
	 *
	 * @return DateTimeImmutable|null The date at midnight, or null.
	 */
	private function date(string $text, DateTimeImmutable $now): ?DateTimeImmutable {
		$parts = self::dateParts(text: $text);
		if ($parts === null) {
			return null;
		}

		[$year, $month, $day] = $parts;
		$guess = $year ?? (int)$now->format('Y');
		if (checkdate($month, $day, $guess) === false) {
			return null;
		}

		$date = $now->setDate($guess, $month, $day)->setTime(0, 0);
		if ($year === null && $date < $now->setTime(0, 0)) {
			$date = $date->modify('+1 year');
		}

		return $date;
	}//end date()

	/**
	 * The year (null when not given), month and day of a date text.
	 *
	 * @param string $text The date text.
	 *
	 * @return array{0: int|null, 1: int, 2: int}|null
	 */
	private static function dateParts(string $text): ?array {
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
			return [(int)$m[1], (int)$m[2], (int)$m[3]];
		}

		// `1 december [2026]`, then `december 1[, 2026]`: [pattern, day group, month group].
		$named = [
			['/^(\d{1,2}) ([a-z]+)(?: (\d{4}))?$/u', 1, 2],
			['/^([a-z]+) (\d{1,2})(?:,? (\d{4}))?$/u', 2, 1],
		];
		foreach ($named as [$pattern, $dayGroup, $monthGroup]) {
			if (preg_match($pattern, $text, $m) === 1) {
				return self::namedDate(month: $m[$monthGroup], day: (int)$m[$dayGroup], year: ($m[3] ?? ''));
			}
		}

		return null;
	}//end dateParts()

	/**
	 * A date with a month name, or null for a name that is not a month.
	 *
	 * @param string $month The month name.
	 * @param int    $day   The day.
	 * @param string $year  The year digits, '' when not given.
	 *
	 * @return array{0: int|null, 1: int, 2: int}|null
	 */
	private static function namedDate(string $month, int $day, string $year): ?array {
		if (isset(self::MONTHS[$month]) === false) {
			return null;
		}

		return [self::yearOf(digits: $year), self::MONTHS[$month], $day];
	}//end namedDate()

	/**
	 * A time of day as [hour, minute].
	 *
	 * English: `8`, `8:30`, `08.30`, `8am`, `8 pm`, `eight`, `eight o'clock`,
	 * `half past eight`, `quarter past eight`, `quarter to nine`, `noon`,
	 * `midnight`. Dutch: `acht uur`, `9:00`, `9 uur`, `half negen` (08:30),
	 * `kwart over acht`, `kwart voor negen`, `middernacht`.
	 *
	 * @param string $text The time text.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function time(string $text): ?array {
		$text  = trim((string)preg_replace("/\s*(?:o'clock|uur)$/u", '', trim($text)));
		$named = ['noon' => [12, 0], 'midday' => [12, 0], 'midnight' => [0, 0], 'middernacht' => [0, 0]];
		if (isset($named[$text]) === true) {
			return $named[$text];
		}

		$relative = $this->relativeTime(text: $text);
		if ($relative !== false) {
			return $relative;
		}

		return $this->clockTime(text: $text);
	}//end time()

	/**
	 * A time told against an hour: `half past eight`, `kwart voor negen`, `half negen`.
	 *
	 * @param string $text The time text.
	 *
	 * @return array{0: int, 1: int}|null|false The time, null for a bad hour, false when not this form.
	 */
	private function relativeTime(string $text): array|null|false {
		$relative = [
			'/^half past (\S+)$/u' => [0, 30],
			'/^quarter past (\S+)$/u' => [0, 15],
			'/^kwart over (\S+)$/u' => [0, 15],
			'/^quarter to (\S+)$/u' => [-1, 45],
			'/^kwart voor (\S+)$/u' => [-1, 45],
			'/^half (\S+)$/u' => [-1, 30],
		];
		foreach ($relative as $pattern => [$shift, $minute]) {
			if (preg_match($pattern, $text, $m) === 1) {
				$hour = $this->number(word: $m[1]);
				if ($hour === null || $hour > 24) {
					return null;
				}

				return [(($hour + $shift + 24) % 24), $minute];
			}
		}

		return false;
	}//end relativeTime()

	/**
	 * A clock time: `8`, `8:30`, `08.30`, `8am`, `eight pm`.
	 *
	 * @param string $text The time text.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function clockTime(string $text): ?array {
		if (preg_match('/^(\d{1,2}|[a-zé]+)(?:[:.](\d{2}))?\s*(am|pm|a\.m\.?|p\.m\.?)?$/u', $text, $m) !== 1) {
			return null;
		}

		$hour   = $this->number(word: $m[1]);
		$minute = (int)($m[2] ?? 0);
		if (self::onTheClock(hour: $hour, minute: $minute) === false) {
			return null;
		}

		$hour = self::withMeridiem(hour: $hour, meridiem: str_replace('.', '', ($m[3] ?? '')));
		if ($hour === null) {
			return null;
		}

		return [$hour, $minute];
	}//end clockTime()

	/**
	 * Whether an hour and minute are on a 24-hour clock.
	 *
	 * @param int|null $hour   The hour, null when it was not a number.
	 * @param int      $minute The minute.
	 *
	 * @return bool
	 */
	private static function onTheClock(?int $hour, int $minute): bool {
		return $hour !== null && $hour <= 23 && $minute <= 59;
	}//end onTheClock()

	/**
	 * An hour read with `am` or `pm`, or as it is without one.
	 *
	 * @param int    $hour     The hour as written.
	 * @param string $meridiem `am`, `pm` or ''.
	 *
	 * @return int|null The hour on the 24-hour clock, or null for an hour that cannot carry am/pm.
	 */
	private static function withMeridiem(int $hour, string $meridiem): ?int {
		if ($meridiem === '') {
			return $hour;
		}

		if ($hour < 1 || $hour > 12) {
			return null;
		}

		return (($hour % 12) + (['pm' => 12][$meridiem] ?? 0));
	}//end withMeridiem()

	/**
	 * A count in digits or as a word.
	 *
	 * @param string $word The digits or the word.
	 *
	 * @return int|null
	 */
	private function number(string $word): ?int {
		if (ctype_digit($word) === true) {
			return (int)$word;
		}

		return (self::NUMBERS[$word] ?? null);
	}//end number()

	/**
	 * The readback of an interval.
	 *
	 * @param int  $minutes The interval.
	 * @param bool $dutch   Whether in Dutch.
	 *
	 * @return string
	 */
	private function intervalReadback(int $minutes, bool $dutch): string {
		$count = $minutes;
		$unit  = 'minute';
		if ($minutes % 60 === 0) {
			$count = intdiv($minutes, 60);
			$unit  = 'hour';
		}

		if ($count === 1) {
			return $this->word(key: 'every ' . $unit, dutch: $dutch);
		}

		return $this->word(key: 'every', dutch: $dutch) . ' ' . $count . ' ' . $this->word(key: $unit . 's', dutch: $dutch);
	}//end intervalReadback()

	/**
	 * The readback of a cron rule this parser produces; other rules are named as such.
	 *
	 * @param string $cron  The rule.
	 * @param bool   $dutch Whether in Dutch.
	 *
	 * @return string
	 */
	private function cronReadback(string $cron, bool $dutch): string {
		$parts = explode(' ', $cron);
		if (self::isDailyClock(parts: $parts) === false) {
			return $this->word(key: 'rule', dutch: $dutch) . ' ' . $cron;
		}

		$at = sprintf('%02d:%02d', (int)$parts[1], (int)$parts[0]);
		if ($parts[2] === '1' && $parts[4] === '*') {
			return $this->word(key: 'monthly', dutch: $dutch) . ' ' . $at;
		}

		if ($parts[2] !== '*') {
			return $this->word(key: 'rule', dutch: $dutch) . ' ' . $cron;
		}

		return $this->daysReadback(field: $parts[4], dutch: $dutch) . ' ' . $this->word(key: 'at', dutch: $dutch) . ' ' . $at;
	}//end cronReadback()

	/**
	 * Whether a cron expression runs at one fixed minute and hour, in every month.
	 *
	 * @param array<int, string> $parts The five cron fields.
	 *
	 * @return bool
	 */
	private static function isDailyClock(array $parts): bool {
		if (count($parts) !== 5 || $parts[3] !== '*') {
			return false;
		}

		return ctype_digit($parts[0]) === true && ctype_digit($parts[1]) === true;
	}//end isDailyClock()

	/**
	 * "Every day", "Every weekday" or "Every Monday and Thursday", in either language.
	 *
	 * @param string $field The day-of-week field.
	 * @param bool   $dutch Whether in Dutch.
	 *
	 * @return string
	 */
	private function daysReadback(string $field, bool $dutch): string {
		if ($field === '*') {
			return $this->word(key: 'every day', dutch: $dutch);
		}

		if ($field === '1-5') {
			return $this->word(key: 'every weekday', dutch: $dutch);
		}

		$names = [];
		foreach (explode(',', $field) as $number) {
			$names[] = (self::DAY_NAMES[self::lang(dutch: $dutch)][((int)$number) % 7] ?? $number);
		}

		$last = array_pop($names);
		$list = $last;
		if ($names !== []) {
			$list = implode(', ', $names) . ' ' . $this->word(key: 'and', dutch: $dutch) . ' ' . $last;
		}

		return $this->word(key: 'every', dutch: $dutch) . ' ' . $list;
	}//end daysReadback()

	/**
	 * A readback word in the reader's language.
	 *
	 * @param string $key   The word's key.
	 * @param bool   $dutch Whether in Dutch.
	 *
	 * @return string
	 */
	private function word(string $key, bool $dutch): string {
		return self::WORDS[self::lang(dutch: $dutch)][$key];
	}//end word()

	/**
	 * The readback language key.
	 *
	 * @param bool $dutch Whether in Dutch.
	 *
	 * @return string `nl` or `en`.
	 */
	private static function lang(bool $dutch): string {
		if ($dutch === true) {
			return 'nl';
		}

		return 'en';
	}//end lang()

	/**
	 * A four-digit year, or null when none is given.
	 *
	 * @param string $digits The year as written, or ''.
	 *
	 * @return int|null
	 */
	private static function yearOf(string $digits): ?int {
		if ($digits === '') {
			return null;
		}

		return (int)$digits;
	}//end yearOf()
}//end class
