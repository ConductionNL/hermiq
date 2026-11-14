<?php

/**
 * Maps a hermiq schedule's timing shape onto the engine's cron vocabulary.
 *
 * The shape half of schedules-onto-engine-triggers, split out of the bridge
 * so the mapping decisions (which shapes migrate, which stay local, and why)
 * live in one small class: a 5-field timezone-safe `kind=cron` passes
 * through, an expressible `kind=interval` derives a pure cadence, and
 * everything else answers null with a reason naming its staged phase.
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
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;

/**
 * The shape map, as one small decision surface.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleCadenceMapper {

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Reads owner/instance timezone for the safety check.
	 */
	public function __construct(
		private readonly IConfig $config,
	) {

	}//end __construct()

	/**
	 * The cron expression the engine should fire this schedule on, or null.
	 *
	 * `kind=cron` passes through when it has 5 fields AND the timezone
	 * difference between the owner and the server cannot change its meaning.
	 * `kind=interval` derives a pure-cadence expression when the minutes
	 * divide an hour, or whole hours divide a day; a cadence survives any
	 * fixed offset, so it needs no timezone check. `kind=once` never maps:
	 * a deadline is FlowTimerService's primitive, staged in phase 2.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return string|null The 5-field cron, or null when no safe form exists.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function mappedCron(ObjectEntity $schedule): ?string {
		$data = $schedule->getObject();
		$kind = (string)($data['kind'] ?? '');

		if ($kind === 'cron') {
			return $this->passthroughCron(
				cron: trim((string)($data['cronExpr'] ?? '')),
				owner: (string)($schedule->getOwner() ?? '')
			);
		}

		if ($kind === 'interval') {
			return $this->cronForInterval(minutes: (int)($data['intervalMinutes'] ?? 0));
		}

		return null;
	}//end mappedCron()

	/**
	 * Name the staged phase that covers this schedule's timing shape.
	 *
	 * @param array<string,mixed> $data The schedule payload.
	 *
	 * @return string The reason the shape stays local.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function gapReason(array $data): string {
		$kind = (string)($data['kind'] ?? '');

		if ($kind === 'once') {
			return 'once schedules stay local until the FlowTimerService one-shot lands (staged phase 2)';
		}

		if ($kind === 'interval') {
			return 'this interval has no 5-field cron form; it stays local (staged phase 2)';
		}

		return 'this cron is not a 5-field timezone-safe expression, and this OpenRegister cannot read a cron in the owner\'s timezone; it stays local';
	}//end gapReason()

	/**
	 * A `kind=cron` expression, when it is safe to hand to the engine.
	 *
	 * @param string $cron The stored expression.
	 * @param string $owner The schedule owner uid.
	 *
	 * @return string|null The expression, or null when it must stay local.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function passthroughCron(string $cron, string $owner): ?string {
		$fields = preg_split('/\s+/', $cron);
		if (is_array($fields) === false || count($fields) !== 5) {
			return null;
		}

		if ($this->timezoneSafe(fields: $fields, owner: $owner) === false && $this->engineReadsTimezone() === false) {
			return null;
		}

		return $cron;
	}//end passthroughCron()

	/**
	 * The timezone the mirror's trigger reads its cron in, or '' for the server's.
	 *
	 * Named only for a `kind=cron` schedule whose meaning the owner-vs-server
	 * difference changes, and only when the engine reads the key: then the
	 * engine fires it on the owner's wall clock, DST included, as the local
	 * dispatcher did. A timezone-safe cron keeps an unnamed timezone, so a
	 * mirror that already exists does not drift when the engine learns the key.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return string The owner's timezone identifier, or ''.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#phase-2-the-shapes-the-engine-cannot-time-yet-staged
	 */
	public function mirrorTimezone(ObjectEntity $schedule): string {
		$data = $schedule->getObject();
		if ((string)($data['kind'] ?? '') !== 'cron' || $this->engineReadsTimezone() === false) {
			return '';
		}

		$fields = preg_split('/\s+/', trim((string)($data['cronExpr'] ?? '')));
		if (is_array($fields) === false || count($fields) !== 5) {
			return '';
		}

		$owner = (string)($schedule->getOwner() ?? '');
		if ($this->timezoneSafe(fields: $fields, owner: $owner) === true) {
			return '';
		}

		return $this->ownerTimezoneName(owner: $owner);
	}//end mirrorTimezone()

	/**
	 * Whether the installed OpenRegister reads a schedule trigger's timezone.
	 *
	 * Older engines ignore an unknown config key and would read the cron in
	 * the server's timezone, so the key is only written when the engine
	 * declares it (`TriggerScheduleNode::CONFIG_TIMEZONE`).
	 *
	 * @return boolean True when the engine evaluates the cron in a named timezone.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/tasks.md#phase-2-the-shapes-the-engine-cannot-time-yet-staged
	 */
	protected function engineReadsTimezone(): bool {
		return defined('OCA\\OpenRegister\\Service\\Flow\\Nodes\\TriggerScheduleNode::CONFIG_TIMEZONE');
	}//end engineReadsTimezone()

	/**
	 * Whether owner-vs-server timezone difference can change this cron's meaning.
	 *
	 * The dispatcher evaluated cron in the OWNER's timezone; the engine
	 * evaluates in the server default. Safe when the two are the same zone,
	 * or when the expression is a pure minute cadence (minute `*` or a step,
	 * every other field `*`). An hour-anchored cron for an owner in another
	 * zone would silently shift, so it stays local. Shifting the hour field
	 * by the offset was rejected in design.md: DST makes that rewrite wrong
	 * twice a year.
	 *
	 * @param array<int,string> $fields The 5 cron fields.
	 * @param string $owner The schedule owner uid.
	 *
	 * @return boolean Whether the expression is timezone-safe to mirror.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function timezoneSafe(array $fields, string $owner): bool {
		if ($this->ownerTimezoneName(owner: $owner) === date_default_timezone_get()) {
			return true;
		}

		$minuteIsCadence = $this->isMinuteCadence(minute: $fields[0]);

		return $minuteIsCadence && $this->hourAndDateAreUnset(fields: $fields);
	}//end timezoneSafe()

	/**
	 * Whether a cron minute field is every minute or a pure every-N-minutes cadence.
	 *
	 * @param string $minute The minute field.
	 *
	 * @return boolean True for every minute or every N minutes.
	 */
	private function isMinuteCadence(string $minute): bool {
		return $minute === '*' || preg_match('/^\*\/\d+$/', $minute) === 1;
	}//end isMinuteCadence()

	/**
	 * Whether the hour, day, month and weekday fields are all `*`.
	 *
	 * @param array<int,string> $fields The 5 cron fields.
	 *
	 * @return boolean True when none of them is set.
	 */
	private function hourAndDateAreUnset(array $fields): bool {
		return $fields[1] === '*'
			&& $fields[2] === '*'
			&& $fields[3] === '*'
			&& $fields[4] === '*';
	}//end hourAndDateAreUnset()

	/**
	 * The owner's resolved timezone name, mirroring the dispatcher's rule.
	 *
	 * Owner's Nextcloud timezone, else the instance default, else UTC. The
	 * SAME fallback chain `ScheduleService::resolveTimezone()` applies, so
	 * the safety check compares the zones the two clocks would actually use.
	 *
	 * @param string $owner The owner uid.
	 *
	 * @return string The timezone name.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function ownerTimezoneName(string $owner): string {
		$tz = '';
		if ($owner !== '') {
			$tz = (string)$this->config->getUserValue($owner, 'core', 'timezone', '');
		}

		if ($tz === '') {
			$tz = (string)$this->config->getSystemValueString('default_timezone', 'UTC');
		}

		return $tz;
	}//end ownerTimezoneName()

	/**
	 * The 5-field cadence for an interval, or null when none expresses it.
	 *
	 * The mirror fires ALIGNED (every N minutes on the wall clock) where the
	 * poller fired ROLLING (N minutes after the last run). The cadence is
	 * preserved; the phase may shift once at migration. Accepted in
	 * design.md: rolling was an artefact of the poller, not a promise.
	 *
	 * @param int $minutes The interval in minutes.
	 *
	 * @return string|null The cron, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function cronForInterval(int $minutes): ?string {
		if ($this->isUnderAnHour(minutes: $minutes) === true) {
			return $this->minuteCadence(minutes: $minutes);
		}

		if ($this->isWholeHours(minutes: $minutes) === true) {
			return $this->hourCadence(hours: intdiv($minutes, 60));
		}

		return null;
	}//end cronForInterval()

	/**
	 * Whether an interval is 1 to 59 minutes.
	 *
	 * @param int $minutes The interval in minutes.
	 *
	 * @return boolean True when it is shorter than an hour.
	 */
	private function isUnderAnHour(int $minutes): bool {
		return $minutes >= 1 && $minutes < 60;
	}//end isUnderAnHour()

	/**
	 * Whether an interval is one or more whole hours.
	 *
	 * @param int $minutes The interval in minutes.
	 *
	 * @return boolean True when it is a multiple of 60, at least 60.
	 */
	private function isWholeHours(int $minutes): bool {
		return $minutes >= 60 && ($minutes % 60) === 0;
	}//end isWholeHours()

	/**
	 * A sub-hour cadence, when the minutes divide an hour evenly.
	 *
	 * @param int $minutes The interval in minutes (1..59).
	 *
	 * @return string|null The cron, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function minuteCadence(int $minutes): ?string {
		if ((60 % $minutes) !== 0) {
			return null;
		}

		if ($minutes === 1) {
			return '* * * * *';
		}

		return '*/' . $minutes . ' * * * *';
	}//end minuteCadence()

	/**
	 * A whole-hour cadence, when the hours divide a day evenly.
	 *
	 * @param int $hours The interval in whole hours.
	 *
	 * @return string|null The cron, or null.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	private function hourCadence(int $hours): ?string {
		if ($hours === 1) {
			return '0 * * * *';
		}

		if ($hours <= 23 && (24 % $hours) === 0) {
			return '0 */' . $hours . ' * * *';
		}

		return null;
	}//end hourCadence()
}//end class
