<?php

/**
 * Hermiq connection reporter.
 *
 * Tells integriq's connection registry what hermiq observed about one of its
 * outside connections. Integriq owns the rows the Integrations page lists and
 * works out each status itself (hydra change connection-registry, design D4).
 * Hermiq only reports what it alone can see.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Connection;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports to integriq, at once or throttled.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = 'hermiq';

	/**
	 * Integriq's report event (ADR-041). Named by string so hermiq stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A key outside this set is a caller's typo, not a new connection. A unit
	 * test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = ['llm', 'llm-runner', 'speech', 'web-search', 'webhook-delivery', 'github-templates'];

	/**
	 * The statuses integriq accepts in a report (design D6), `limited` included.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['configured', 'limited', 'unconfigured', 'simulated', 'unavailable', 'error'];

	/**
	 * Prefix of the lazy app-config key that remembers the last throttled report.
	 *
	 * @var string
	 */
	public const MEMORY_KEY_PREFIX = 'connection_report_';

	/**
	 * Seconds after which the same status is reported again.
	 *
	 * @var int
	 */
	public const REPEAT_SECONDS = 3600;

	/**
	 * Seconds that must pass before a different status is reported.
	 *
	 * @var int
	 */
	public const CHANGE_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq event.
	 * @param IAppConfig       $appConfig       Keeps the throttle memory.
	 * @param ITimeFactory     $timeFactory     Tells the time for the throttle memory.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report a connection's status to integriq now.
	 *
	 * For a settings save or a test an admin ran. Never throws: this runs
	 * beside a request whose own answer is what the caller returns. Without
	 * integriq nothing is sent and nothing is logged, because a missing
	 * optional app is not a fault.
	 *
	 * @param string $key     One of {@see self::KEYS}.
	 * @param string $status  One of {@see self::STATUSES}.
	 * @param string $message What hermiq observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function report(string $key, string $status, string $message=''): bool {
		if ($this->isValid(key: $key, status: $status) === false) {
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(key: $key, eventClass: $eventClass, status: $status, message: $message);
	}//end report()

	/**
	 * Report an outcome hermiq met, at most once an hour per status.
	 *
	 * For an outcome that happens on its own, such as a store search or a
	 * webhook delivery, where a report per outcome would write integriq's row
	 * over and over (ADR-076). The same status goes again after an hour. A
	 * different status goes after five minutes, so two targets that disagree
	 * cannot report on every run. Without integriq nothing is read, stored,
	 * sent or logged. Never throws.
	 *
	 * @param string $key     One of {@see self::KEYS}.
	 * @param string $status  One of {@see self::STATUSES}.
	 * @param string $message What hermiq observed.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function reportThrottled(string $key, string $status, string $message=''): bool {
		if ($this->isValid(key: $key, status: $status) === false) {
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$now = $this->timeFactory->getTime();
			if ($this->isDue(key: $key, status: $status, now: $now) === false) {
				return false;
			}

			$sent = $this->send(key: $key, eventClass: $eventClass, status: $status, message: $message);
			if ($sent === true) {
				$this->appConfig->setValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, $status . '|' . $now, true);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ConnectionReporter] Could not keep the connection report memory',
				context: ['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end reportThrottled()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Whether the key and status are ones integriq would accept, warning when not.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status.
	 *
	 * @return bool
	 */
	private function isValid(string $key, string $status): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning(
				message: '[ConnectionReporter] Refusing to report an unknown connection key',
				context: ['key' => $key]
			);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning(
				message: '[ConnectionReporter] Refusing to report an unknown connection status',
				context: ['key' => $key, 'status' => $status]
			);
			return false;
		}

		return true;
	}//end isValid()

	/**
	 * Whether the throttle memory allows a report with this status now.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status observed.
	 * @param int    $now    The current Unix time.
	 *
	 * @return bool
	 */
	private function isDue(string $key, string $status, int $now): bool {
		$memory = $this->appConfig->getValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, '', true);
		$parts  = explode('|', $memory, 2);
		if (count($parts) !== 2 || ctype_digit($parts[1]) === false) {
			return true;
		}

		$elapsed = ($now - (int)$parts[1]);
		if ($parts[0] === $status) {
			return $elapsed >= self::REPEAT_SECONDS;
		}

		return $elapsed >= self::CHANGE_SECONDS;
	}//end isDue()

	/**
	 * Build and dispatch one report event, swallowing anything a listener throws.
	 *
	 * @param string $key        The connection key.
	 * @param string $eventClass The resolved event class.
	 * @param string $status     The status.
	 * @param string $message    The message.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, string $eventClass, string $status, string $message): bool {
		try {
			$event = new $eventClass(
				app: self::APP_ID,
				key: $key,
				status: $status,
				message: $message,
			);
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ConnectionReporter] Could not send a connection report to integriq',
				context: ['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
