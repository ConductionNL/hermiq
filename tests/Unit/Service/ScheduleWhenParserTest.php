<?php

/**
 * Plain words to a schedule rule (scheduling-overview-and-start-choice, REQ-SCHED-003).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\ScheduleWhenParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The phrase table, both languages, and what is refused.
 */
final class ScheduleWhenParserTest extends TestCase {

	/**
	 * Parse at a fixed moment: Friday 13 November 2026, 10:00 in Amsterdam.
	 *
	 * @param string $text   The phrase.
	 * @param string $locale The locale.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function parse(string $text, string $locale = 'en'): ?array {
		$zone = new DateTimeZone('Europe/Amsterdam');

		return (new ScheduleWhenParser())->parse(text: $text, locale: $locale, now: new DateTimeImmutable('2026-11-13 10:00:00', $zone), timezone: $zone);
	}//end parse()

	/**
	 * Phrase, locale, the stored rule, and the readback.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
	 */
	public static function phrases(): array {
		return [
			'weekday digest' => ['every weekday at eight', 'en', ['kind' => 'cron', 'cronExpr' => '0 8 * * 1-5'], 'Every weekday at 08:00'],
			'dutch monday' => ['elke maandag om negen uur', 'nl', ['kind' => 'cron', 'cronExpr' => '0 9 * * 1'], 'Elke maandag om 09:00'],
			'every day digits' => ['Every day at 7:30', 'en', ['kind' => 'cron', 'cronExpr' => '30 7 * * *'], 'Every day at 07:30'],
			'daily pm' => ['daily at 6 pm', 'en', ['kind' => 'cron', 'cronExpr' => '0 18 * * *'], 'Every day at 18:00'],
			'daily am dotted' => ['daily at 6 a.m.', 'en', ['kind' => 'cron', 'cronExpr' => '0 6 * * *'], 'Every day at 06:00'],
			'twelve am' => ['every day at 12am', 'en', ['kind' => 'cron', 'cronExpr' => '0 0 * * *'], 'Every day at 00:00'],
			'noon' => ['every day at noon', 'en', ['kind' => 'cron', 'cronExpr' => '0 12 * * *'], 'Every day at 12:00'],
			'midnight' => ['each day at midnight', 'en', ['kind' => 'cron', 'cronExpr' => '0 0 * * *'], 'Every day at 00:00'],
			'oclock' => ['every friday at five o\'clock', 'en', ['kind' => 'cron', 'cronExpr' => '0 5 * * 5'], 'Every Friday at 05:00'],
			'half past' => ['every monday at half past nine', 'en', ['kind' => 'cron', 'cronExpr' => '30 9 * * 1'], 'Every Monday at 09:30'],
			'quarter to' => ['every tuesday at quarter to nine', 'en', ['kind' => 'cron', 'cronExpr' => '45 8 * * 2'], 'Every Tuesday at 08:45'],
			'two days' => ['every monday and thursday at 9:15', 'en', ['kind' => 'cron', 'cronExpr' => '15 9 * * 1,4'], 'Every Monday and Thursday at 09:15'],
			'three days plural' => ['on mondays, wednesdays and fridays at 17:00', 'en', ['kind' => 'cron', 'cronExpr' => '0 17 * * 1,3,5'], 'Every Monday, Wednesday and Friday at 17:00'],
			'sunday' => ['every sunday at 23.45', 'en', ['kind' => 'cron', 'cronExpr' => '45 23 * * 0'], 'Every Sunday at 23:45'],
			'working day' => ['every working day at 08:00', 'en', ['kind' => 'cron', 'cronExpr' => '0 8 * * 1-5'], 'Every weekday at 08:00'],
			'nl werkdag' => ['elke werkdag om acht uur', 'nl', ['kind' => 'cron', 'cronExpr' => '0 8 * * 1-5'], 'Elke werkdag om 08:00'],
			'nl iedere maandag digits' => ['iedere maandag om 9:00', 'nl', ['kind' => 'cron', 'cronExpr' => '0 9 * * 1'], 'Elke maandag om 09:00'],
			'nl dagelijks' => ['dagelijks om 7 uur', 'nl', ['kind' => 'cron', 'cronExpr' => '0 7 * * *'], 'Elke dag om 07:00'],
			'nl elke dag half' => ['elke dag om half negen', 'nl', ['kind' => 'cron', 'cronExpr' => '30 8 * * *'], 'Elke dag om 08:30'],
			'nl kwart over' => ['elke vrijdag om kwart over vier', 'nl', ['kind' => 'cron', 'cronExpr' => '15 4 * * 5'], 'Elke vrijdag om 04:15'],
			'nl kwart voor' => ['elke dinsdag en donderdag om kwart voor twaalf', 'nl', ['kind' => 'cron', 'cronExpr' => '45 11 * * 2,4'], 'Elke dinsdag en donderdag om 11:45'],
			'nl middernacht' => ['elke zondag om middernacht', 'nl', ['kind' => 'cron', 'cronExpr' => '0 0 * * 0'], 'Elke zondag om 00:00'],
			'every 15 minutes' => ['every 15 minutes', 'en', ['kind' => 'interval', 'intervalMinutes' => 15], 'Every 15 minutes'],
			'every five minutes' => ['every five minutes', 'en', ['kind' => 'interval', 'intervalMinutes' => 5], 'Every 5 minutes'],
			'every minute' => ['every minute', 'en', ['kind' => 'interval', 'intervalMinutes' => 1], 'Every minute'],
			'quarter hour' => ['every quarter of an hour', 'en', ['kind' => 'interval', 'intervalMinutes' => 15], 'Every 15 minutes'],
			'half hour' => ['every half hour', 'en', ['kind' => 'interval', 'intervalMinutes' => 30], 'Every 30 minutes'],
			'hourly' => ['hourly', 'en', ['kind' => 'interval', 'intervalMinutes' => 60], 'Every hour'],
			'every 2 hours' => ['every 2 hours', 'en', ['kind' => 'interval', 'intervalMinutes' => 120], 'Every 2 hours'],
			'nl kwartier' => ['elk kwartier', 'nl', ['kind' => 'interval', 'intervalMinutes' => 15], 'Elke 15 minuten'],
			'nl half uur' => ['elk half uur', 'nl', ['kind' => 'interval', 'intervalMinutes' => 30], 'Elke 30 minuten'],
			'nl elk uur' => ['elk uur', 'nl', ['kind' => 'interval', 'intervalMinutes' => 60], 'Elk uur'],
			'nl drie uur' => ['elke drie uur', 'nl', ['kind' => 'interval', 'intervalMinutes' => 180], 'Elke 3 uur'],
			'nl tien minuten' => ['iedere 10 minuten', 'nl', ['kind' => 'interval', 'intervalMinutes' => 10], 'Elke 10 minuten'],
			'first of month' => ['the first day of the month at 9', 'en', ['kind' => 'cron', 'cronExpr' => '0 9 1 * *'], 'On the first day of every month at 09:00'],
			'monthly no time' => ['monthly', 'en', ['kind' => 'cron', 'cronExpr' => '0 0 1 * *'], 'On the first day of every month at 00:00'],
			'nl eerste van de maand' => ['de eerste dag van de maand om tien uur', 'nl', ['kind' => 'cron', 'cronExpr' => '0 10 1 * *'], 'Op de eerste dag van elke maand om 10:00'],
			'nl maandelijks' => ['maandelijks om 6:30', 'nl', ['kind' => 'cron', 'cronExpr' => '30 6 1 * *'], 'Op de eerste dag van elke maand om 06:30'],
			'tomorrow' => ['tomorrow at 14:30', 'en', ['kind' => 'once', 'runAt' => '2026-11-14T14:30:00+01:00'], 'Once on 2026-11-14 at 14:30'],
			'nl morgen' => ['morgen om acht uur', 'nl', ['kind' => 'once', 'runAt' => '2026-11-14T08:00:00+01:00'], 'Eenmalig op 2026-11-14 om 08:00'],
			'once iso' => ['once on 2026-12-01 at 9:00', 'en', ['kind' => 'once', 'runAt' => '2026-12-01T09:00:00+01:00'], 'Once on 2026-12-01 at 09:00'],
			'on day month' => ['on 3 march at 10am', 'en', ['kind' => 'once', 'runAt' => '2027-03-03T10:00:00+01:00'], 'Once on 2027-03-03 at 10:00'],
			'on month day year' => ['on june 15, 2027 at noon', 'en', ['kind' => 'once', 'runAt' => '2027-06-15T12:00:00+02:00'], 'Once on 2027-06-15 at 12:00'],
			'later this year' => ['on december 24 at 18:00', 'en', ['kind' => 'once', 'runAt' => '2026-12-24T18:00:00+01:00'], 'Once on 2026-12-24 at 18:00'],
			'nl op datum' => ['eenmalig op 5 mei 2027 om 11 uur', 'nl', ['kind' => 'once', 'runAt' => '2027-05-05T11:00:00+02:00'], 'Eenmalig op 2027-05-05 om 11:00'],
		];
	}//end phrases()

	/**
	 * @param string               $text     The phrase.
	 * @param string               $locale   The locale.
	 * @param array<string, mixed> $rule     The expected rule.
	 * @param string               $readback The expected readback.
	 */
	#[DataProvider('phrases')]
	public function testEachPhraseGivesItsRuleAndReadback(string $text, string $locale, array $rule, string $readback): void {
		self::assertSame($rule + ['readback' => $readback], self::parse(text: $text, locale: $locale));
	}//end testEachPhraseGivesItsRuleAndReadback()

	/**
	 * Text the parser cannot read, which goes to the model or is refused.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unreadable(): array {
		return [
			'empty' => ['   '],
			'nonsense' => ['whenever it feels right'],
			'unknown day' => ['every funday at 9'],
			'hour too large' => ['every day at 25:00'],
			'minute too large' => ['every day at 8:75'],
			'pm out of range' => ['every day at 13pm'],
			'zero interval' => ['every 0 minutes'],
			'unknown count' => ['every many minutes'],
			'no such date' => ['on 31 february at 9'],
			'unknown month' => ['on 3 smarch at 9'],
			'bad time word' => ['every monday at teatime'],
			'monthly bad time' => ['monthly at sometime'],
			'tomorrow bad time' => ['tomorrow at dawn'],
			'half of nothing' => ['every day at half past forever'],
		];
	}//end unreadable()

	/**
	 * @param string $text The phrase.
	 */
	#[DataProvider('unreadable')]
	public function testTextItCannotReadAnswersNull(string $text): void {
		self::assertNull(self::parse(text: $text));
	}//end testTextItCannotReadAnswersNull()

	public function testTheReadbackNamesARuleItDidNotWrite(): void {
		$parser = new ScheduleWhenParser();

		self::assertSame('On the rule */5 9-17 * * 1-5', $parser->readback(rule: ['kind' => 'cron', 'cronExpr' => '*/5 9-17 * * 1-5'], lang: 'en'));
		self::assertSame('Volgens de regel 0 9 15 * *', $parser->readback(rule: ['kind' => 'cron', 'cronExpr' => '0 9 15 * *'], lang: 'nl'));
		self::assertSame('On the rule 0 9 * 6 *', $parser->readback(rule: ['kind' => 'cron', 'cronExpr' => '0 9 * 6 *'], lang: 'en'));
		self::assertSame('Every 90 minutes', $parser->readback(rule: ['kind' => 'interval', 'intervalMinutes' => 90], lang: 'en'));
		self::assertSame('Elke minuut', $parser->readback(rule: ['kind' => 'interval', 'intervalMinutes' => 1], lang: 'nl'));
	}//end testTheReadbackNamesARuleItDidNotWrite()
}//end class
