<?php

/**
 * Hermiq OccurrenceClaim
 *
 * Claims one scheduled occurrence for one worker, through OpenRegister's lock.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Run
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Run;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * The claim a background worker takes before it runs a scheduled occurrence.
 *
 * A schedule has at most one occurrence in flight, so the claim is a lock on
 * the schedule: OpenRegister's advisory lock, keyed on the schedule's uuid,
 * with the occurrence key as its process and the time-to-live as its
 * duration. The advisory lock is taken by compare-and-swap and refuses every
 * second holder whoever it runs as, which an object lock does not: two
 * workers impersonating the same schedule owner would both "hold" an object
 * lock, the second one extending the first. It also leaves the schedule
 * object writable, so the worker can record its run while it holds the
 * claim.
 *
 * A claim that cannot be taken for any reason is not taken: a worker that
 * does not hold it does not run, so an occurrence runs at most once.
 *
 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
 */
class OccurrenceClaim {

	/**
	 * The prefix of the advisory lock's identifier.
	 *
	 * @var string
	 */
	public const LOCK_PREFIX = 'hermiq-schedule-run:';

	/**
	 * The statuses of an occurrence that is in flight.
	 *
	 * @var string[]
	 */
	public const IN_FLIGHT = ['queued', 'running'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister's object service, for its advisory lock.
	 * @param LoggerInterface $logger        Logs a claim that failed for another reason than a holder.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the installed OpenRegister offers the advisory lock this claim uses.
	 *
	 * Without it no occurrence could ever be claimed, so the dispatcher keeps
	 * running due schedules in the tick's own process instead of queueing them.
	 *
	 * @var bool|null
	 */
	private ?bool $available = null;

	/**
	 * Whether occurrences can be claimed on this instance.
	 *
	 * @return bool True when OpenRegister's `lockObject(LockRequest, advisory)` is there.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function isAvailable(): bool {
		if ($this->available !== null) {
			return $this->available;
		}

		$this->available = false;
		if (class_exists(LockRequest::class) === false || class_exists(UnlockRequest::class) === false) {
			return false;
		}

		try {
			$names = array_map(
				static fn ($parameter): string => $parameter->getName(),
				(new ReflectionMethod($this->objectService, 'lockObject'))->getParameters()
			);
			new ReflectionMethod($this->objectService, 'unlockObject');
		} catch (ReflectionException $e) {
			return false;
		}

		$this->available = ($names[0] ?? '') === 'request' && in_array('advisory', $names, true) === true;

		return $this->available;
	}//end isAvailable()

	/**
	 * Claim the schedule's occurrence for this worker.
	 *
	 * @param string $scheduleUuid  The schedule.
	 * @param string $occurrenceKey The occurrence the worker runs.
	 * @param int    $ttlSeconds    How long the claim holds without a release.
	 *
	 * @return bool True when this worker holds the claim.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function claim(string $scheduleUuid, string $occurrenceKey, int $ttlSeconds): bool {
		if (trim($scheduleUuid) === '') {
			return false;
		}

		try {
			$this->objectService->lockObject(
				request: new LockRequest(
					identifier: self::LOCK_PREFIX . $scheduleUuid,
					process: $occurrenceKey,
					duration: max(1, $ttlSeconds)
				),
				advisory: true
			);
		} catch (LockedException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OccurrenceClaim] The claim on a schedule could not be taken; the occurrence does not run',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schedule' => $scheduleUuid, 'occurrence' => $occurrenceKey, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end claim()

	/**
	 * Release the claim on the schedule. Never throws: an unreleased claim expires.
	 *
	 * @param string $scheduleUuid The schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public function release(string $scheduleUuid): void {
		try {
			$this->objectService->unlockObject(
				request: new UnlockRequest(identifier: self::LOCK_PREFIX . $scheduleUuid, advisory: true)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OccurrenceClaim] The claim on a schedule could not be released; it expires on its own',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schedule' => $scheduleUuid, 'error' => $e->getMessage()]
			);
		}
	}//end release()

	/**
	 * The key of the occurrence a schedule is due for, before its commit.
	 *
	 * The schedule uuid and the due time; a retry attempt adds its number, so
	 * a retry after a lost worker runs under a new key.
	 *
	 * @param string               $scheduleUuid The schedule.
	 * @param array<string, mixed> $data         The schedule as it is before the commit.
	 *
	 * @return string The occurrence key.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-fire-due-schedules-and-run-the-agent-mvp
	 */
	public static function keyFor(string $scheduleUuid, array $data): string {
		$retryState = ($data['retryState'] ?? null);
		if (($data['retryEnabled'] ?? false) === true && is_array($retryState) === true && isset($retryState['attempt']) === true) {
			$due = (string)($retryState['nextAttemptAt'] ?? '');
			return $scheduleUuid . ':' . $due . ':a' . ((int)$retryState['attempt'] + 1);
		}

		return $scheduleUuid . ':' . (string)($data['nextRun'] ?? '');
	}//end keyFor()

	/**
	 * The occurrence a schedule has in flight, or '' when none.
	 *
	 * @param array<string, mixed> $data The schedule.
	 *
	 * @return string The occurrence key.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
	 */
	public static function pendingOf(array $data): string {
		$pending = ($data['pendingOccurrence'] ?? '');
		if (is_string($pending) === false) {
			return '';
		}

		return trim($pending);
	}//end pendingOf()

	/**
	 * Whether the occurrence in flight outlived its time-to-live without an outcome.
	 *
	 * Only the time is judged here; whether a worker still holds the claim is
	 * judged by trying to take it (the sweep in ScheduleService).
	 *
	 * @param array<string, mixed> $data       The schedule.
	 * @param DateTimeImmutable    $now        The tick's time.
	 * @param int                  $ttlSeconds The time-to-live.
	 *
	 * @return bool True when the occurrence is lost.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-a-lost-worker-is-recorded-as-a-failed-run-req-prun-002
	 */
	public static function isStale(array $data, DateTimeImmutable $now, int $ttlSeconds): bool {
		if (self::pendingOf(data: $data) === '' || in_array(($data['lastStatus'] ?? ''), self::IN_FLIGHT, true) === false) {
			return false;
		}

		$since = self::sinceOf(data: $data);
		if ($since === null) {
			// In flight with no time recorded: nothing says it is still going.
			return true;
		}

		return ($now->getTimestamp() - $since->getTimestamp()) > $ttlSeconds;
	}//end isStale()

	/**
	 * Whether the occurrence in flight is running within its time-to-live.
	 *
	 * @param array<string, mixed> $data       The schedule.
	 * @param DateTimeImmutable    $now        The time.
	 * @param int                  $ttlSeconds The time-to-live.
	 *
	 * @return bool True for a live run.
	 *
	 * @spec openspec/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
	 */
	public static function isLiveRun(array $data, DateTimeImmutable $now, int $ttlSeconds): bool {
		if (($data['lastStatus'] ?? '') !== 'running' || self::pendingOf(data: $data) === '') {
			return false;
		}

		$since = self::sinceOf(data: $data);
		return $since !== null && ($now->getTimestamp() - $since->getTimestamp()) <= $ttlSeconds;
	}//end isLiveRun()

	/**
	 * When the occurrence in flight was queued or started, or null.
	 *
	 * @param array<string, mixed> $data The schedule.
	 *
	 * @return DateTimeImmutable|null The time.
	 */
	private static function sinceOf(array $data): ?DateTimeImmutable {
		$since = ($data['pendingSince'] ?? '');
		if (is_string($since) === false || trim($since) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($since);
		} catch (Throwable $e) {
			return null;
		}
	}//end sinceOf()
}//end class
